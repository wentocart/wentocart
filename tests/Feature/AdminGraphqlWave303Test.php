<?php

namespace Tests\Feature;

use App\Models\Admin;

class AdminGraphqlWave303Test extends MarketplaceTestCase
{
    public function test_rest_admin_marketplace_stats_overview_rest(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $stats = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketplace/stats');
        $stats->assertOk();
        $this->assertIsArray($stats->json('data.sellers'));
        $this->assertArrayHasKey('total', $stats->json('data.sellers'));
        $this->assertArrayHasKey('pendingProducts', $stats->json('data.attention'));
        $this->assertArrayHasKey('openReturns', $stats->json('data.attention'));
        $this->assertArrayHasKey('gmvLast30Days', $stats->json('data.performance'));
        $this->assertIsNumeric($stats->json('data.performance.gmvLast30Days'));

        $this->flushSession();
        auth('admin')->logout();
        $guest = $this->getJson('/api/v1/console/marketplace/stats');
        $guest->assertStatus(401);
    }
}
