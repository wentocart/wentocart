<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Warehouse;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave60Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_settings_inventory_locales_currencies_and_promos_rest_aliases(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $sources = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{
                adminSettingsWarehouses(limit: 10, status: 1) { id code name status is_enabled }
                adminSettingsLocales(enabled: true, limit: 10) { id code name status direction }
                adminSettingsCurrencies(enabled: true, limit: 10) { id code symbol status is_default }
            }',
        ]);
        $sources->assertOk();
        $this->assertArrayNotHasKey('errors', $sources->json() ?? [], json_encode($sources->json()));
        $this->assertNotEmpty($sources->json('data.adminSettingsWarehouses'));
        $this->assertNotEmpty($sources->json('data.adminSettingsLocales'));
        $this->assertNotEmpty($sources->json('data.adminSettingsCurrencies'));

        $warehouseId = (string) $sources->json('data.adminSettingsWarehouses.0.id');
        $localeCode = (string) $sources->json('data.adminSettingsLocales.0.code');
        $currencyCode = (string) $sources->json('data.adminSettingsCurrencies.0.code');

        $detail = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($wid: ID!, $lc: String!, $cc: String!) {
                adminSettingsWarehouse(id: $wid) { id code status }
                adminSettingsLocale(code: $lc) { id code }
                adminSettingsCurrency(code: $cc) { id code }
            }',
            'variables' => ['wid' => $warehouseId, 'lc' => $localeCode, 'cc' => $currencyCode],
        ]);
        $detail->assertOk();
        $this->assertArrayNotHasKey('errors', $detail->json() ?? [], json_encode($detail->json()));
        $this->assertSame($warehouseId, (string) $detail->json('data.adminSettingsWarehouse.id'));

        $code = 'W60-'.strtoupper(substr(uniqid(), -4));
        $created = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($code: String!) {
                createAdminSettingsWarehouse(
                    name: "Wave60 Source"
                    code: $code
                    email: "w60@example.com"
                    address: "1 Test Way"
                    city: "Austin"
                    postcode: "78701"
                    country: "US"
                    state: "TX"
                    enabled: true
                ) { id code status is_enabled }
            }',
            'variables' => ['code' => $code],
        ]);
        $created->assertOk();
        $this->assertArrayNotHasKey('errors', $created->json() ?? [], json_encode($created->json()));
        $newId = (string) $created->json('data.createAdminSettingsWarehouse.id');
        $this->assertSame(1, (int) $created->json('data.createAdminSettingsWarehouse.status'));

        $updated = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateAdminSettingsWarehouse(id: $id, name: "Wave60 Source Updated") { id name }
            }',
            'variables' => ['id' => $newId],
        ]);
        $updated->assertOk();
        $this->assertArrayNotHasKey('errors', $updated->json() ?? []);
        $this->assertSame('Wave60 Source Updated', (string) $updated->json('data.updateAdminSettingsWarehouse.name'));

        $sku = 'W60P-'.strtoupper(substr(uniqid(), -5));
        $productId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createAdminCatalogProduct(sku: $sku, name: "Wave60 Inv", price: 12, stockQty: 2, enabled: true) { id }
            }',
            'variables' => ['sku' => $sku],
        ])->json('data.createAdminCatalogProduct.id');

        $inv = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($pid: ID!, $wid: ID!) {
                updateAdminCatalogProductInventory(productId: $pid, warehouseId: $wid, qty: 8) {
                    product_id warehouse_id qty quantity available_qty
                }
            }',
            'variables' => ['pid' => $productId, 'wid' => $warehouseId],
        ]);
        $inv->assertOk();
        $this->assertArrayNotHasKey('errors', $inv->json() ?? [], json_encode($inv->json()));
        $this->assertSame(8.0, (float) $inv->json('data.updateAdminCatalogProductInventory.quantity'));

        $invList = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($pid: ID!) {
                adminCatalogProductInventories(productId: $pid) { warehouse_id qty quantity }
            }',
            'variables' => ['pid' => $productId],
        ]);
        $invList->assertOk();
        $this->assertArrayNotHasKey('errors', $invList->json() ?? [], json_encode($invList->json()));
        $this->assertNotEmpty($invList->json('data.adminCatalogProductInventories'));

        $couponCode = 'W60C'.strtoupper(substr(uniqid(), -4));
        $promo = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($code: String!) {
                createAdminCoupon(code: $code, name: "Wave60 Coupon", action: "percent", value: 10) { id code apply_as }
                createAdminPromotion(name: "Wave60 Cart Promo", applyAs: "cart", action: "percent", value: 5) { id apply_as }
            }',
            'variables' => ['code' => $couponCode],
        ]);
        $promo->assertOk();
        $this->assertArrayNotHasKey('errors', $promo->json() ?? [], json_encode($promo->json()));
        $this->assertSame('coupon', (string) $promo->json('data.createAdminCoupon.apply_as'));

        $lists = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($q: String!) {
                adminCoupons(q: $q, limit: 10) { id code apply_as }
                adminPromotions(limit: 10) { id apply_as }
                adminAttributes(limit: 5) { id code }
            }',
            'variables' => ['q' => $couponCode],
        ]);
        $lists->assertOk();
        $this->assertArrayNotHasKey('errors', $lists->json() ?? [], json_encode($lists->json()));
        $this->assertNotEmpty($lists->json('data.adminCoupons'));
        $this->assertNotEmpty($lists->json('data.adminPromotions'));

        $deleted = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                deleteAdminSettingsWarehouse(id: $id) { ok id }
            }',
            'variables' => ['id' => $newId],
        ]);
        $deleted->assertOk();
        $this->assertArrayNotHasKey('errors', $deleted->json() ?? [], json_encode($deleted->json()));
        $this->assertTrue((bool) $deleted->json('data.deleteAdminSettingsWarehouse.ok'));
        $this->assertNull(Warehouse::query()->find((int) $newId));
        $this->assertNotNull(Product::query()->find((int) $productId));
    }
}
