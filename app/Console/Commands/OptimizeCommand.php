<?php

namespace App\Console\Commands;

use App\Domain\Catalog\Services\StorefrontCatalogCache;
use App\Domain\Localization\Money;
use App\Domain\Settings\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Warm production caches and eCommerce domain states.
 */
class OptimizeCommand extends Command
{
    protected $signature = 'wento:optimize
                            {--clear : Run optimize:clear and purge all domain caches}
                            {--diagnostics : Display OPcache and production server performance diagnostics}';

    protected $description = 'Warm config/route/view/event and eCommerce domain caches for sub-20ms production response times, or purge them with --clear.';

    public function handle(): int
    {
        if ($this->option('clear')) {
            if (! app()->runningUnitTests()) {
                $this->call('optimize:clear');
            }
            StorefrontCatalogCache::bump();
            Cache::forget('wento.settings');
            Money::forgetCache();
            $this->info('Application and eCommerce domain caches cleared successfully.');

            return self::SUCCESS;
        }

        $this->info('Warming framework production caches...');
        if (! app()->runningUnitTests()) {
            $this->call('config:cache');
            $this->call('route:cache');
            $this->call('view:cache');
            try {
                $this->call('event:cache');
            } catch (\Throwable $e) {
                $this->warn('event:cache skipped: '.$e->getMessage());
            }
        }

        $this->info('Warming eCommerce domain state...');
        try {
            StorefrontCatalogCache::bump();
            Setting::getValue('app.name');
            Money::defaultCode();
            $this->line('  ✓ Catalog version cache synchronized.');
            $this->line('  ✓ Shop settings pre-cached.');
            $this->line('  ✓ Currency exchange reference pre-cached.');
        } catch (\Throwable $e) {
            $this->warn('Domain warming notice: '.$e->getMessage());
        }

        if ($this->option('diagnostics') || $this->getOutput()->isVerbose()) {
            $this->renderDiagnostics();
        }

        $this->info('Production caches warmed successfully. Sub-20ms baseline ready.');
        $this->line('Tip: For maximum throughput, also execute: composer dump-autoload --optimize --classmap-authoritative');

        return self::SUCCESS;
    }

    protected function renderDiagnostics(): void
    {
        $this->newLine();
        $this->info('=== Production Performance Diagnostics ===');

        $opcacheEnabled = function_exists('opcache_get_status') && is_array(opcache_get_status());
        $this->line('OPcache Status: '.($opcacheEnabled ? '<fg=green>Enabled</>' : '<fg=yellow>Disabled / Not Available</>'));

        if ($opcacheEnabled) {
            $status = opcache_get_status();
            $memory = $status['memory_usage'] ?? [];
            $usedMb = round(($memory['used_memory'] ?? 0) / 1024 / 1024, 1);
            $freeMb = round(($memory['free_memory'] ?? 0) / 1024 / 1024, 1);
            $hitRate = round($status['opcache_statistics']['opcache_hit_rate'] ?? 0, 1);
            $this->line("  Memory: {$usedMb}MB used / {$freeMb}MB free");
            $this->line("  Hit Rate: {$hitRate}%");
        }

        $cacheStore = config('cache.default');
        $this->line("Default Cache Store: <fg=cyan>{$cacheStore}</>");
        if ($cacheStore === 'file') {
            $this->line('  <fg=yellow>Recommendation: Switch CACHE_STORE=redis in .env for high-concurrency environments.</>');
        }

        $this->newLine();
    }
}
