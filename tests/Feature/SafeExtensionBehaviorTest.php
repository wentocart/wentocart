<?php

namespace Tests\Feature;

use App\Domain\Plugin\PluginKernel;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Safe-app behaviour against test fixtures only.
 * Do not depend on a mass in-repo extensions/wento seed.
 */
class SafeExtensionBehaviorTest extends TestCase
{
    use RefreshDatabase;

    public function test_sample_notice_fixture_mutates_storefront_header_hook(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        PluginKernel::reset();

        $this->installFixtureExtension('wento', 'sample-notice');
        $this->post('/admin/apps/wento/sample-notice/enable', ['enabled' => true])
            ->assertRedirect();

        $announcement = $this->get('/api/v1/store')->assertOk()->json('shop.announcement');
        $this->assertIsString($announcement);
        $this->assertStringContainsString(
            'This notice comes from a Safe app, not from core PHP.',
            $announcement
        );
    }

    public function test_sample_kit_fixture_appears_in_admin_apps(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        PluginKernel::reset();

        $this->installFixtureExtension('wento', 'sample-kit');

        $this->get('/admin/apps')
            ->assertOk()
            ->assertSee('Sample kit');
    }
}
