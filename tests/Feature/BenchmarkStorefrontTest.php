<?php

namespace Tests\Feature;

use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BenchmarkStorefrontTest extends TestCase
{
    use RefreshDatabase;

    public function test_benchmark_command_saves_measured_results(): void
    {
        $this->seed();

        $exit = Artisan::call('wento:benchmark-storefront', [
            '--runs' => 1,
            '--save' => true,
            '--url' => 'http://localhost',
        ]);

        $this->assertSame(0, $exit);
        $saved = ShopSetting::get('performance.benchmark');
        $this->assertIsArray($saved);
        $this->assertArrayHasKey('paths', $saved);
        $this->assertArrayHasKey('measured_at', $saved);
        // In-process presenter path should always be present when products exist.
        $this->assertArrayHasKey('presenter_pdp', $saved['paths']);
        $this->assertNotNull($saved['paths']['presenter_pdp']['avg_ms'] ?? null);
    }

    public function test_performance_payload_includes_benchmark_key(): void
    {
        $this->seed();
        ShopSetting::put('performance.benchmark', [
            'measured_at' => now()->toIso8601String(),
            'paths' => ['home_api' => ['path' => '/api/v1/catalog/home', 'avg_ms' => 12, 'p95_ms' => 15]],
        ]);

        $admin = \App\Models\Admin::query()->first();
        $this->assertNotNull($admin);

        $this->actingAs($admin, 'admin')
            ->get('/admin/performance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Performance')
                ->has('benchmark.paths.home_api')
                ->where('benchmark.paths.home_api.path', '/api/v1/catalog/home')
            );
    }
}
