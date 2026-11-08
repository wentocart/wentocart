<?php

namespace Tests\Feature;

use App\Domain\Marketplace\MarketplaceSettings;
use App\Models\Admin;

class AdminGraphqlWave307Test extends MarketplaceTestCase
{
    public function test_rest_admin_marketplace_settings_rest(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketplace/settings');
        $show->assertOk();
        $this->assertArrayHasKey('defaultCommission', $show->json('data'));
        $this->assertArrayHasKey('cancelWarningRate', $show->json('data'));
        $this->assertArrayHasKey('redFlagLimit', $show->json('data'));

        $update = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/marketplace/settings', [
            'defaultCommission' => 18.5,
            'cancelWarningRate' => 3.0,
            'cancelCriticalRate' => 6.0,
            'redFlagLimit' => 5,
            'sellerFlagsEnabled' => true,
            'autoApproveProducts' => true,
        ]);
        $update->assertOk();
        $this->assertSame(18.5, (float) ($update->json('data.defaultCommission') ?? 0));
        $this->assertSame(3.0, (float) ($update->json('data.cancelWarningRate') ?? 0));
        $this->assertSame(5, (int) ($update->json('data.redFlagLimit') ?? 0));
        $this->assertTrue((bool) $update->json('data.autoApproveProducts'));

        $this->assertSame(18.5, MarketplaceSettings::defaultCommission());
        $this->assertSame(3.0, MarketplaceSettings::cancelWarningRate());
        $this->assertSame(5, MarketplaceSettings::redFlagLimit());
        $this->assertTrue(MarketplaceSettings::autoApproveProducts());

        $bad = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/marketplace/settings', [
            'defaultCommission' => 150,
        ]);
        $bad->assertStatus(422);
    }
}
