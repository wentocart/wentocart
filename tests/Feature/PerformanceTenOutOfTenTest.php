<?php

namespace Tests\Feature;

use App\Domain\Catalog\Services\StorefrontCatalogCache;
use App\Domain\Localization\Money;
use App\Domain\Settings\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PerformanceTenOutOfTenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_wento_optimize_warms_caches_and_domain_state(): void
    {
        $this->artisan('wento:optimize', ['--diagnostics' => true])
            ->assertSuccessful();

        // Verify domain caches are live
        $this->assertGreaterThanOrEqual(1, StorefrontCatalogCache::version());
        $this->assertNotEmpty(Money::defaultCode());
    }

    public function test_wento_optimize_clear_purges_caches_cleanly(): void
    {
        $this->artisan('wento:optimize', ['--clear' => true])
            ->expectsOutputToContain('Application and eCommerce domain caches cleared successfully')
            ->assertExitCode(0);

        $this->assertGreaterThanOrEqual(1, StorefrontCatalogCache::version());
    }

    public function test_storefront_catalog_cache_versioning_invalidates_atomically(): void
    {
        $calls = 0;
        $fetcher = function () use (&$calls) {
            $calls++;
            return ['status' => 'fresh', 'calls' => $calls];
        };

        // 1. Initial retrieval computes value
        $result1 = StorefrontCatalogCache::remember('test_payload', 60, $fetcher);
        $this->assertSame(1, $calls);
        $this->assertSame('fresh', $result1['status']);

        // 2. Subsequent retrieval uses cached value without executing callback
        $result2 = StorefrontCatalogCache::remember('test_payload', 60, $fetcher);
        $this->assertSame(1, $calls);

        // 3. Bump cache version atomically
        StorefrontCatalogCache::bump();

        // 4. Next retrieval triggers callback again because cache key namespace changed
        $result3 = StorefrontCatalogCache::remember('test_payload', 60, $fetcher);
        $this->assertSame(2, $calls);
        $this->assertSame(2, $result3['calls']);
    }

    public function test_catalog_cache_follows_the_performance_setting(): void
    {
        \App\Domain\Settings\ShopSetting::put('performance.catalog_ttl', '0');
        $calls = 0;
        $fetcher = function () use (&$calls) {
            $calls++;

            return $calls;
        };
        StorefrontCatalogCache::remember('ttl_off', 60, $fetcher);
        StorefrontCatalogCache::remember('ttl_off', 60, $fetcher);
        $this->assertSame(2, $calls);

        \App\Domain\Settings\ShopSetting::put('performance.catalog_ttl', 'auto');
        $calls = 0;
        StorefrontCatalogCache::remember('ttl_auto', 60, $fetcher);
        StorefrontCatalogCache::remember('ttl_auto', 60, $fetcher);
        $this->assertSame(1, $calls);
    }

    public function test_vite_manifest_contains_isolated_vendor_chunks(): void
    {
        $manifestPath = public_path('build/manifest.json');
        if (! file_exists($manifestPath)) {
            $this->markTestSkipped('Vite build manifest not found in public/build/manifest.json');
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $this->assertIsArray($manifest);

        $files = array_column($manifest, 'file');
        $filesString = implode(' ', $files);

        // Verify heavy third-party vendor libraries are isolated in their own split chunks
        $this->assertMatchesRegularExpression('/vendor-grapesjs-.*\.js/', $filesString);
        $this->assertMatchesRegularExpression('/vendor-echarts-.*\.js/', $filesString);
        $this->assertMatchesRegularExpression('/vendor-react-.*\.js/', $filesString);
    }
}
