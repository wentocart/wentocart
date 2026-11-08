<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DemoMediaCommand extends Command
{
    protected $signature = 'wento:demo-media
        {--download : Deprecated — Unsplash demo pack removed}
        {--refresh : Rebuild storefront media from the merchant pack}
        {--force : Passed through to wento:merchant-store}
        {--rewrite-theme : Deprecated}
        {--compose-home : Deprecated}';

    protected $description = 'Deprecated. Use wento:merchant-store — only merchant-owned images ship with the product.';

    public function handle(): int
    {
        $this->warn('Third-party Unsplash / Classic sample photo packs were removed for public licensing safety.');
        $this->line('Shipped media lives in themes/classic/assets/merchant only.');

        if ((bool) $this->option('refresh') || (bool) $this->option('force')) {
            $this->info('Running wento:merchant-store…');

            return $this->call('wento:merchant-store', [
                '--force' => true,
            ]);
        }

        $this->line('Rebuild the demo shop with:');
        $this->line('  php artisan wento:merchant-store --force');

        return self::SUCCESS;
    }
}
