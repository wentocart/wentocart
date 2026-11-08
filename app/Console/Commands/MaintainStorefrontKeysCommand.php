<?php

namespace App\Console\Commands;

use App\Domain\Api\Models\StorefrontApiKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 */
class MaintainStorefrontKeysCommand extends Command
{
    protected $signature = 'wento:maintain-storefront-keys
        {--cleanup : Delete inactive keys unused for --unused days}
        {--invalidate : Disable rotated (name suffix) keys that are still enabled (safety net)}
        {--notify : Print unused/inactive warnings (hook for future mail)}
        {--all : Run cleanup + invalidate + notify}
        {--unused=90 : Unused-days threshold for cleanup/notify}';

    protected $description = 'Maintain Storefront API keys.';

    public function handle(): int
    {
        if (! Schema::hasTable('storefront_api_keys')) {
            $this->warn('Table storefront_api_keys is missing — skipped.');

            return self::SUCCESS;
        }

        $all = (bool) $this->option('all');
        $doCleanup = $all || (bool) $this->option('cleanup');
        $doInvalidate = $all || (bool) $this->option('invalidate');
        $doNotify = $all || (bool) $this->option('notify');

        if (! $doCleanup && ! $doInvalidate && ! $doNotify) {
            $this->error('Specify --cleanup, --invalidate, --notify, or --all.');

            return self::FAILURE;
        }

        $this->info('Starting storefront key maintenance...');
        $days = max(1, (int) $this->option('unused'));

        if ($doCleanup) {
            $this->line('Cleaning inactive keys unused ≥ '.$days.' day(s)...');
            $cutoff = now()->subDays($days);
            $q = StorefrontApiKey::query()
                ->where('is_enabled', false)
                ->where(function ($q) use ($cutoff) {
                    $q->whereNull('last_used_at')->orWhere('last_used_at', '<', $cutoff);
                });
            $count = (clone $q)->count();
            $q->delete();
            $this->line($count > 0 ? "  Deleted {$count} inactive unused key(s)." : '  No inactive unused keys to clean up.');
        }

        if ($doInvalidate) {
            $this->line('Invalidating still-enabled rotated-name keys...');
            $updated = StorefrontApiKey::query()
                ->where('is_enabled', true)
                ->where('name', 'like', '%(rotated %')
                ->update(['is_enabled' => false]);
            $this->line($updated > 0 ? "  Disabled {$updated} rotated key(s)." : '  No rotated keys to invalidate.');
        }

        if ($doNotify) {
            $this->line('Reviewing unused / inactive keys...');
            $unused = StorefrontApiKey::query()
                ->where(function ($q) use ($days) {
                    $q->whereNull('last_used_at')->orWhere('last_used_at', '<', now()->subDays($days));
                })
                ->count();
            $inactive = StorefrontApiKey::query()->where('is_enabled', false)->count();
            $this->line("  Unused (≥{$days}d): {$unused}");
            $this->line("  Inactive: {$inactive}");
            if ($unused > 0 || $inactive > 0) {
                $this->warn('  Review with: php artisan wento:manage-storefront-keys unused --unused='.$days);
            }
        }

        $this->info('Storefront key maintenance complete.');

        return self::SUCCESS;
    }
}
