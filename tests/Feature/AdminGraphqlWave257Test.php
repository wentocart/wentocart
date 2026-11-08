<?php

namespace Tests\Feature;

use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave257Test extends TestCase
{
    use RefreshDatabase;

    public function test_store_rest_lives_under_versioned_wento_paths(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);
        ShopSetting::put('gdpr.enabled', true);

        $gdpr = $this->getJson('/api/v1/store/gdpr-status');
        $gdpr->assertOk();
        $this->assertTrue((bool) $gdpr->json('enabled'));

        $currencies = $this->getJson('/api/v1/store/currencies');
        $currencies->assertOk();
        $this->assertIsArray($currencies->json());
        $this->assertNotEmpty($currencies->json());

        $configs = $this->getJson('/api/v1/store/configs');
        $configs->assertOk();

        $playground = $this->get('/api/v1/store/playground');
        $playground->assertOk();

        $docs = $this->getJson('/api/v1/store/docs');
        $docs->assertOk();

        // Unversioned foreign-shaped aliases are retired (no path rewrite).
        $this->getJson('/api/shop/gdpr-status')->assertNotFound();
        $this->getJson('/api/admin/reporting/stats')->assertNotFound();
    }
}
