<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave240Test extends TestCase
{
    use RefreshDatabase;

    public function test_openapi_merges_live_laravel_routes(): void
    {
        $this->seed();

        $shop = $this->getJson('/api/v1/store/docs');
        $shop->assertOk();
        $shopPaths = $shop->json('paths') ?? [];
        $this->assertIsArray($shopPaths);
        $this->assertGreaterThanOrEqual(40, count($shopPaths), 'shop OpenAPI should discover many /shop routes');
        $this->assertNotEmpty($shop->json('paths./shop/customer/login.post'));
        $this->assertGreaterThanOrEqual(40, (int) $shop->json('x-wento-path-count'));
        // A route that may not be in the hand-curated list but exists in api.php
        $this->assertTrue(
            isset($shopPaths['/shop/eu-withdrawal-status'])
            || isset($shopPaths['/shop/newsletters'])
            || isset($shopPaths['/shop/contact-us']),
            'discovered shop routes should include non-curated endpoints'
        );

        $admin = $this->getJson('/api/v1/console/docs');
        $admin->assertOk();
        $adminPaths = $admin->json('paths') ?? [];
        $this->assertIsArray($adminPaths);
        $this->assertGreaterThanOrEqual(80, count($adminPaths), 'admin OpenAPI should discover many /admin routes');
        $this->assertNotEmpty($admin->json('paths./admin/dashboard/stats.get'));
        $this->assertGreaterThanOrEqual(80, (int) $admin->json('x-wento-path-count'));
        $this->assertTrue(
            isset($adminPaths['/admin/marketing/campaigns'])
            || isset($adminPaths['/admin/rma/requests'])
            || isset($adminPaths['/admin/settings/integrations']),
            'discovered admin routes should include marketing/RMA/integrations'
        );

        $landing = $this->getJson('/api/json');
        $landing->assertOk();
        $highlights = $landing->json('highlights_wento') ?? [];
        $this->assertTrue(
            collect($highlights)->contains(fn ($h) => str_contains((string) $h, 'live Laravel routes')),
            'landing should highlight route-scanned OpenAPI'
        );
    }
}
