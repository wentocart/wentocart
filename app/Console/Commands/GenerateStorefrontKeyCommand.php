<?php

namespace App\Console\Commands;

use App\Domain\Api\Models\StorefrontApiKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 */
class GenerateStorefrontKeyCommand extends Command
{
    protected $signature = 'wento:generate-storefront-key
        {--name= : Descriptive name (unique recommended)}
        {--rate-limit=100 : Requests per hour (empty or 0 = unlimited; capped at 5000)}
        {--no-activation : Create key inactive}';

    protected $description = 'Generate a Storefront API key for X-STOREFRONT-KEY.';

    public function handle(): int
    {
        if (! Schema::hasTable('storefront_api_keys')) {
            $this->error('Table storefront_api_keys is missing — run installer/migrations first.');

            return self::FAILURE;
        }

        $name = trim((string) ($this->option('name') ?? ''));
        if ($name === '') {
            $name = (string) $this->ask('Key name', 'Default Storefront Key');
        }
        $name = trim($name);
        if ($name === '') {
            $this->error('Name is required.');

            return self::FAILURE;
        }

        if (StorefrontApiKey::query()->where('name', $name)->exists()) {
            $this->error('A storefront key with that name already exists. Choose a unique name.');

            return self::FAILURE;
        }

        $rateOpt = $this->option('rate-limit');
        $rateLimit = null;
        if ($rateOpt === null || $rateOpt === '') {
            $rateLimit = null;
        } else {
            $rate = (int) $rateOpt;
            if ($rate <= 0) {
                $rateLimit = null;
            } else {
                if ($rate > 5000) {
                    $this->warn('Rate limit capped at 5000 requests/hour.');
                }
                $rateLimit = min(5000, $rate);
            }
        }

        $issued = StorefrontApiKey::issue($name, null, $rateLimit);
        /** @var StorefrontApiKey $row */
        $row = $issued['model'];
        $plain = (string) $issued['plain'];

        if ($this->option('no-activation')) {
            $row->is_enabled = false;
            $row->save();
        }

        $limitLabel = $row->rate_limit === null
            ? 'Unlimited'
            : ((int) $row->rate_limit).' requests/hour';

        $this->info('Storefront key generated successfully!');
        $this->newLine();
        $this->line('Key Details:');
        $this->line('  ID : '.$row->id);
        $this->line('  Name : '.$row->name);
        $this->line('  Key : '.$plain);
        $this->line('  Rate Limit : '.$limitLabel);
        $this->line('  Status : '.($row->is_enabled ? 'Active' : 'Inactive'));
        $this->newLine();
        $this->warn('Copy this key now — the plaintext is shown once.');
        $this->line('Use it in the X-STOREFRONT-KEY header for Shop REST and GraphQL.');

        return self::SUCCESS;
    }
}
