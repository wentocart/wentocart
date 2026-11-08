<?php

namespace App\Console\Commands;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\CatalogPresenter;
use App\Domain\Settings\ShopSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Measures real storefront timings on this machine. Prints honest numbers — never invents them.
 */
class BenchmarkStorefrontCommand extends Command
{
    protected $signature = 'wento:benchmark-storefront
        {--url= : Base URL (defaults to APP_URL)}
        {--runs=5 : Timed GETs per path}
        {--save : Persist results to shop settings for the Performance screen}';

    protected $description = 'Measure storefront/API response times and optionally save them for admin Performance.';

    public function handle(CatalogPresenter $presenter): int
    {
        $base = rtrim((string) ($this->option('url') ?: config('app.url')), '/');
        $runs = max(1, min(50, (int) $this->option('runs')));
        $paths = [
            'home_api' => '/api/v1/catalog/home',
            'shop_api' => '/api/v1/store',
            'search_api' => '/api/v1/catalog/search?q=a',
        ];
        $product = Product::query()->where('is_enabled', true)->first();
        if ($product) {
            $slug = $product->translation('en')?->slug;
            if ($slug) {
                $paths['product_api'] = '/api/v1/catalog/product/'.$slug;
            }
        }

        $results = [
            'measured_at' => now()->toIso8601String(),
            'base_url' => $base,
            'runs' => $runs,
            'php' => PHP_VERSION,
            'cache_driver' => (string) config('cache.default'),
            'product_count' => Product::query()->count(),
            'paths' => [],
        ];

        foreach ($paths as $name => $path) {
            $samples = [];
            for ($i = 0; $i < $runs; $i++) {
                $started = hrtime(true);
                try {
                    $response = Http::timeout(30)->acceptJson()->get($base.$path);
                    $ms = (hrtime(true) - $started) / 1e6;
                    $samples[] = [
                        'ms' => round($ms, 1),
                        'status' => $response->status(),
                        'cache' => $response->header('X-Wento-Cache'),
                    ];
                } catch (\Throwable $e) {
                    $samples[] = ['ms' => null, 'status' => 0, 'error' => $e->getMessage()];
                }
            }
            $ok = collect($samples)->whereNotNull('ms')->pluck('ms');
            $row = [
                'path' => $path,
                'samples' => $samples,
                'avg_ms' => $ok->isEmpty() ? null : round($ok->avg(), 1),
                'p95_ms' => $ok->isEmpty() ? null : round($ok->sort()->values()->get((int) floor(($ok->count() - 1) * 0.95)), 1),
            ];
            $results['paths'][$name] = $row;
            $this->line(sprintf(
                '%-12s avg=%s ms  p95=%s ms  %s',
                $name,
                $row['avg_ms'] ?? 'err',
                $row['p95_ms'] ?? 'err',
                $path
            ));
        }

        // In-process presenter micro-benchmark (no HTTP).
        if ($product) {
            $times = [];
            for ($i = 0; $i < $runs; $i++) {
                $t0 = hrtime(true);
                $presenter->product($product->fresh(), 'en', true);
                $times[] = (hrtime(true) - $t0) / 1e6;
            }
            $results['paths']['presenter_pdp'] = [
                'path' => 'in-process CatalogPresenter::product',
                'avg_ms' => round(collect($times)->avg(), 1),
                'p95_ms' => round(collect($times)->sort()->values()->get((int) floor((count($times) - 1) * 0.95)), 1),
            ];
            $this->line(sprintf('presenter_pdp avg=%s ms (in-process)', $results['paths']['presenter_pdp']['avg_ms']));
        }

        if ($this->option('save')) {
            ShopSetting::put('performance.benchmark', $results);
            $this->info('Saved to performance.benchmark (Performance screen can show these).');
        }

        $this->newLine();
        $this->warn('These numbers are for THIS machine and dataset only. Do not claim style 10M figures unless you measured them.');

        return self::SUCCESS;
    }
}
