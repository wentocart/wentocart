<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Warehouse;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GraphqlInventoryConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_configuration_and_admin_inventory_update(): void
    {
        $this->seed();

        $shop = $this->postJson('/api/v1/graphql', [
            'query' => '{ configuration { store_name currency locale guest_checkout registration_enabled } }',
        ]);
        $shop->assertOk();
        $this->assertArrayNotHasKey('errors', $shop->json() ?? []);
        $this->assertNotEmpty($shop->json('data.configuration.currency'));
        $this->assertIsBool((bool) $shop->json('data.configuration.registration_enabled'));

        $admin = Admin::query()->first();
        $this->assertNotNull($admin);
        $adminCfg = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ configuration { store_name store_email currency locale } }',
        ]);
        $adminCfg->assertOk();
        $this->assertArrayNotHasKey('errors', $adminCfg->json() ?? []);
        $this->assertNotEmpty($adminCfg->json('data.configuration.currency'));

        $product = Product::query()->where('is_enabled', true)->first();
        $warehouse = Warehouse::query()->where('is_enabled', true)->orderByDesc('is_default')->first()
            ?? Warehouse::query()->first();
        $this->assertNotNull($product);
        $this->assertNotNull($warehouse);

        $update = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($p: ID!, $w: ID!) {
                updateInventory(productId: $p, warehouseId: $w, qty: 42, reservedQty: 2) {
                    product_id warehouse_id qty reserved_qty available_qty
                }
            }',
            'variables' => [
                'p' => (string) $product->id,
                'w' => (string) $warehouse->id,
            ],
        ]);
        $update->assertOk();
        $this->assertArrayNotHasKey('errors', $update->json() ?? []);
        $this->assertSame(42.0, (float) $update->json('data.updateInventory.qty'));
        $this->assertSame(40.0, (float) $update->json('data.updateInventory.available_qty'));

        $list = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($p: ID!) { inventory(productId: $p) { warehouse_id qty available_qty } }',
            'variables' => ['p' => (string) $product->id],
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? []);
        $this->assertNotEmpty($list->json('data.inventory'));
    }
}
