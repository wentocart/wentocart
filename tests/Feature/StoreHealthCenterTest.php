<?php

namespace Tests\Feature;

use App\Domain\Health\Models\HealthScan;
use App\Domain\Health\ScanEngine;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StoreHealthCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
    }

    public function test_health_pages_load(): void
    {
        $this->get('/admin/health')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Health/Overview', false));
        $this->get('/admin/health/issues')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Health/Issues', false));
        $this->get('/admin/health/seo')->assertOk();
        $this->get('/admin/health/content')->assertOk();
        $this->get('/admin/health/images')->assertOk();
        $this->get('/admin/health/performance')->assertOk();
        $this->get('/admin/health/technical')->assertOk();
        $this->get('/admin/health/cleanup')->assertOk();
        $this->get('/admin/health/fix-center')->assertOk();
        $this->get('/admin/health/ai')->assertOk();
        $this->get('/admin/health/inspector')->assertOk();
        $this->get('/admin/health/history')->assertOk();
        $this->get('/admin/health/settings')->assertOk();
    }

    public function test_full_scan_creates_findings_and_score(): void
    {
        $this->post('/admin/health/scan', ['type' => 'full', 'inline' => 1])->assertRedirect();

        $scan = HealthScan::query()->latest('id')->first();
        $this->assertNotNull($scan);
        $this->assertSame('completed', $scan->status);
        $this->assertNotNull($scan->overall_score);
        $this->assertDatabaseHas('health_findings', ['scan_id' => $scan->id]);

        $this->get('/admin/health')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Health/Overview', false)
                ->where('has_scan', true)
                ->has('score')
            );
    }

    public function test_scan_engine_execute_is_idempotent_for_running_guard(): void
    {
        $scan = app(ScanEngine::class)->start('full', true);
        $this->assertSame('completed', $scan->fresh()->status);
    }
}
