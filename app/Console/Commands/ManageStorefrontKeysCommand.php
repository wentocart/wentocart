<?php

namespace App\Console\Commands;

use App\Domain\Api\Models\StorefrontApiKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Beats Note: immediate rotate (old disabled), activate, rate-limit update.
 */
class ManageStorefrontKeysCommand extends Command
{
    protected $signature = 'wento:manage-storefront-keys
        {action : rotate|deactivate|activate|status|unused|summary|cleanup|list|rate-limit}
        {--key= : Key id or exact name}
        {--reason= : Deactivation reason (logged to output)}
        {--unused=90 : Unused days threshold}
        {--rate-limit= : New rate limit (0/empty = unlimited; for rate-limit action)}
        {--force : Skip confirmation prompts}';

    protected $description = 'Manage Storefront API keys.';

    public function handle(): int
    {
        if (! Schema::hasTable('storefront_api_keys')) {
            $this->error('Table storefront_api_keys is missing.');

            return self::FAILURE;
        }

        $action = strtolower(trim((string) $this->argument('action')));

        return match ($action) {
            'rotate' => $this->rotate(),
            'deactivate' => $this->deactivate(),
            'activate' => $this->activate(),
            'status' => $this->status(),
            'unused' => $this->unused(),
            'summary' => $this->summary(),
            'cleanup' => $this->cleanup(),
            'list' => $this->listKeys(),
            'rate-limit', 'ratelimit' => $this->rateLimit(),
            default => $this->badAction($action),
        };
    }

    private function badAction(string $action): int
    {
        $this->error('Unknown action: '.$action);
        $this->line('Actions: rotate, deactivate, activate, status, unused, summary, cleanup, list, rate-limit');

        return self::FAILURE;
    }

    private function resolveKey(): ?StorefrontApiKey
    {
        $ref = trim((string) ($this->option('key') ?? ''));
        if ($ref === '') {
            $this->error('--key= is required (id or exact name).');

            return null;
        }
        if (ctype_digit($ref)) {
            $row = StorefrontApiKey::query()->find((int) $ref);
        } else {
            $row = StorefrontApiKey::query()->where('name', $ref)->first();
        }
        if (! $row) {
            $this->error('Storefront key not found: '.$ref);

            return null;
        }

        return $row;
    }

    private function confirmAction(string $question): bool
    {
        if ($this->option('force')) {
            return true;
        }

        return $this->confirm($question, false);
    }

    private function rotate(): int
    {
        $row = $this->resolveKey();
        if (! $row) {
            return self::FAILURE;
        }
        if (! $this->confirmAction('Rotate key "'.$row->name.'"? Old key will be disabled immediately.')) {
            $this->warn('Cancelled.');

            return self::SUCCESS;
        }

        $name = (string) $row->name;
        $rate = $row->rate_limit;
        $storeId = $row->store_id ? (int) $row->store_id : null;
        $oldId = (int) $row->id;

        $row->name = mb_substr($name.' (rotated '.now()->format('Y-m-d').')', 0, 120);
        $row->is_enabled = false;
        $row->save();

        $issued = StorefrontApiKey::issue($name, $storeId, $rate);
        /** @var StorefrontApiKey $new */
        $new = $issued['model'];
        $plain = (string) $issued['plain'];

        $this->info('Key rotated successfully (old key disabled immediately — beats 7-day grace).');
        $this->line('Old Key ID: '.$oldId);
        $this->line('New Key ID: '.$new->id);
        $this->line('New Key: '.$plain);
        $this->warn('Copy the new key now — plaintext shown once.');

        return self::SUCCESS;
    }

    private function deactivate(): int
    {
        $row = $this->resolveKey();
        if (! $row) {
            return self::FAILURE;
        }
        if (! $this->confirmAction('Deactivate key "'.$row->name.'"?')) {
            $this->warn('Cancelled.');

            return self::SUCCESS;
        }
        $row->is_enabled = false;
        $row->save();
        $reason = trim((string) ($this->option('reason') ?? ''));
        $this->info('Key deactivated: '.$row->name.' (id '.$row->id.')');
        if ($reason !== '') {
            $this->line('Reason: '.$reason);
        }

        return self::SUCCESS;
    }

    private function activate(): int
    {
        $row = $this->resolveKey();
        if (! $row) {
            return self::FAILURE;
        }
        $row->is_enabled = true;
        $row->save();
        $this->info('Key activated: '.$row->name.' (id '.$row->id.')');

        return self::SUCCESS;
    }

    private function status(): int
    {
        $row = $this->resolveKey();
        if (! $row) {
            return self::FAILURE;
        }
        $this->line('Key Status: '.$row->name);
        $this->newLine();
        $this->line('Active: '.($row->is_enabled ? 'Yes' : 'No'));
        $this->line('Usable: '.($row->is_enabled ? 'Yes' : 'No'));
        $this->line('Prefix: '.$row->key_prefix);
        $limit = $row->rate_limit === null ? 'Unlimited' : ((int) $row->rate_limit).'/hour';
        $this->line('Rate Limit: '.$limit);
        $this->line('Last Used: '.($row->last_used_at?->toDateTimeString() ?? 'Never'));
        $this->line('Created: '.($row->created_at?->toDateTimeString() ?? 'n/a'));

        return self::SUCCESS;
    }

    private function unused(): int
    {
        $days = max(1, (int) $this->option('unused'));
        $cutoff = now()->subDays($days);
        $rows = StorefrontApiKey::query()
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('last_used_at')->orWhere('last_used_at', '<', $cutoff);
            })
            ->orderBy('id')
            ->get();
        $this->info('Unused keys (no use in '.$days.' day(s)): '.$rows->count());
        foreach ($rows as $row) {
            $this->line(sprintf(
                '  #%d %s · enabled=%s · last=%s',
                $row->id,
                $row->name,
                $row->is_enabled ? 'yes' : 'no',
                $row->last_used_at?->toDateTimeString() ?? 'never'
            ));
        }

        return self::SUCCESS;
    }

    private function summary(): int
    {
        $total = StorefrontApiKey::query()->count();
        $active = StorefrontApiKey::query()->where('is_enabled', true)->count();
        $inactive = $total - $active;
        $unused90 = StorefrontApiKey::query()
            ->where(function ($q) {
                $q->whereNull('last_used_at')->orWhere('last_used_at', '<', now()->subDays(90));
            })
            ->count();
        $rotated = StorefrontApiKey::query()
            ->where('name', 'like', '%(rotated %')
            ->count();

        $this->info('Storefront API Key Summary');
        $this->line('Total Keys: '.$total);
        $this->line('Active Keys: '.$active);
        $this->line('Inactive Keys: '.$inactive);
        $this->line('Unused Keys (90 days): '.$unused90);
        $this->line('Rotated (name suffix): '.$rotated);

        return self::SUCCESS;
    }

    private function cleanup(): int
    {
        if (! $this->confirmAction('Delete inactive storefront keys? This cannot be undone.')) {
            $this->warn('Cancelled.');

            return self::SUCCESS;
        }
        $deleted = StorefrontApiKey::query()->where('is_enabled', false)->delete();
        $this->info('Deleted '.$deleted.' inactive key(s).');

        return self::SUCCESS;
    }

    private function listKeys(): int
    {
        $rows = StorefrontApiKey::query()->orderByDesc('id')->limit(100)->get();
        $this->table(
            ['ID', 'Name', 'Prefix', 'Rate', 'Enabled', 'Last used'],
            $rows->map(fn (StorefrontApiKey $r) => [
                $r->id,
                $r->name,
                $r->key_prefix,
                $r->rate_limit === null ? '∞' : $r->rate_limit,
                $r->is_enabled ? 'yes' : 'no',
                $r->last_used_at?->toDateTimeString() ?? 'never',
            ])->all()
        );

        return self::SUCCESS;
    }

    private function rateLimit(): int
    {
        $row = $this->resolveKey();
        if (! $row) {
            return self::FAILURE;
        }
        $raw = $this->option('rate-limit');
        if ($raw === null) {
            $this->error('--rate-limit= is required (use 0 or empty for unlimited).');

            return self::FAILURE;
        }
        if ($raw === '' || (int) $raw <= 0) {
            $row->rate_limit = null;
        } else {
            $rate = (int) $raw;
            if ($rate > 5000) {
                $this->warn('Capped at 5000/hour.');
            }
            $row->rate_limit = min(5000, $rate);
        }
        $row->save();
        $label = $row->rate_limit === null ? 'Unlimited' : ((int) $row->rate_limit).'/hour';
        $this->info('Updated rate limit for "'.$row->name.'" → '.$label.' (cannot change limits from CLI).');

        return self::SUCCESS;
    }
}
