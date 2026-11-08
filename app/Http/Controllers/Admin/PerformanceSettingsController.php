<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Services\StorefrontCatalogCache;
use App\Domain\Settings\Models\FileChangeLog;
use App\Domain\Settings\PerformanceReport;
use App\Domain\Settings\ShopSetting;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PerformanceSettingsController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Settings/Performance', PerformanceReport::payload());
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'catalog_ttl' => ['required', 'in:auto,0,600,3600'],
            'api_cache' => ['sometimes', 'boolean'],
        ]);

        ShopSetting::put('performance.catalog_ttl', $data['catalog_ttl']);
        if (array_key_exists('api_cache', $data)) {
            ShopSetting::put('performance.api_cache', (bool) $data['api_cache']);
        }
        StorefrontCatalogCache::bump();
        FileChangeLog::record('updated', 'performance/catalog-cache');

        return back()->with('success', 'Storefront cache saved.');
    }

    public function updateDebug(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        if ($data['enabled'] && app()->isProduction()) {
            return back()->withErrors([
                'enabled' => 'Debug mode cannot be switched on while the shop runs in production. Use a staging copy to see full error pages.',
            ]);
        }

        ShopSetting::put('performance.debug', $data['enabled'] ? '1' : '0');
        config(['app.debug' => $data['enabled']]);
        FileChangeLog::record('updated', 'performance/debug');

        return back()->with('success', $data['enabled'] ? 'Debug mode is on.' : 'Debug mode is off.');
    }

    public function refreshStorefront(): RedirectResponse
    {
        StorefrontCatalogCache::bump();
        FileChangeLog::record('cleared', 'performance/storefront-cache');

        return back()->with('success', 'Storefront pages will reload on the next visit.');
    }

    public function clear(): RedirectResponse
    {
        Cache::flush();
        try {
            Artisan::call('optimize:clear');
        } catch (Throwable) {
            // Fine when some caches were never built.
        }
        StorefrontCatalogCache::bump();
        FileChangeLog::record('cleared', 'cache');

        return back()->with('success', 'Application cache cleared.');
    }

    public function runBenchmark(Request $request): RedirectResponse
    {
        $runs = max(1, min(20, (int) $request->input('runs', 3)));
        try {
            Artisan::call('wento:benchmark-storefront', [
                '--runs' => $runs,
                '--save' => true,
                '--url' => rtrim((string) config('app.url'), '/'),
            ]);
        } catch (Throwable $e) {
            return back()->withErrors(['benchmark' => $e->getMessage()]);
        }
        FileChangeLog::record('updated', 'performance/benchmark');

        return back()->with('success', 'Benchmark saved. Numbers are for this machine only.');
    }
}
