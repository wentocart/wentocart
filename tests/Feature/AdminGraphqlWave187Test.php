<?php

namespace Tests\Feature;

use App\Domain\Settings\ConfigurationCatalog;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave187Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_configuration_rest_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $slugs = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/configuration/slugs');
        $slugs->assertOk();
        $list = $slugs->json('data') ?? [];
        $this->assertContains('orders', $list);
        $this->assertSame(ConfigurationCatalog::slugs(), $list);

        $menu = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/configuration/menu?slug=orders&include_values=1');
        $menu->assertOk();
        $this->assertSame('orders', (string) $menu->json('slug'));
        $this->assertNotEmpty($menu->json('fields'));
        $this->assertIsArray($menu->json('values'));

        $missing = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/configuration');
        $missing->assertStatus(400);

        $update = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/configuration', [
            'slug' => 'orders',
            'values' => [
                'orders.admin_reorder_enabled' => '0',
                'checkout.guest_checkout' => '1',
            ],
        ]);
        $update->assertOk();
        $this->assertTrue((bool) $update->json('ok'));
        $this->assertSame('0', (string) ($update->json('values')['orders.admin_reorder_enabled'] ?? ''));

        $values = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/configuration?slug=orders');
        $values->assertOk();
        $this->assertSame('0', (string) ($values->json('values')['orders.admin_reorder_enabled'] ?? ''));

        $bad = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/configuration', [
            'slug' => 'orders',
            'values' => [
                'catalog_per_page' => '12',
            ],
        ]);
        $this->assertContains($bad->status(), [400, 422]);

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ listAdminConfigurationSlug }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
    }
}
