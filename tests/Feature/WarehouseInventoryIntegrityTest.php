<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\InventoryMovement;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Warehouse;
use App\Domain\Catalog\Models\WarehouseStock;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Order\OrderStageService;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Settings\ShopSetting;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WarehouseInventoryIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function inventory(): InventoryService
    {
        return app(InventoryService::class);
    }

    protected function assertProductMatchesWarehouseSum(Product $product): void
    {
        $product->refresh();
        $sumQty = (float) WarehouseStock::query()->where('product_id', $product->id)->sum('qty');
        $sumReserved = (float) WarehouseStock::query()->where('product_id', $product->id)->sum('reserved_qty');
        $this->assertEqualsWithDelta($sumQty, (float) $product->stock_qty, 0.001);
        $this->assertEqualsWithDelta($sumReserved, (float) $product->reserved_qty, 0.001);
    }

    protected function makeEastWarehouse(): Warehouse
    {
        return Warehouse::query()->create([
            'name' => 'East DC',
            'code' => 'EAST',
            'is_enabled' => true,
            'is_default' => false,
        ]);
    }

    public function test_can_delete_empty_non_default_warehouse(): void
    {
        $admin = Admin::query()->firstOrFail();
        $east = $this->makeEastWarehouse();
        WarehouseStock::query()->create([
            'warehouse_id' => $east->id,
            'product_id' => Product::query()->where('sku', 'DEMO-001')->value('id'),
            'qty' => 0,
            'reserved_qty' => 0,
        ]);

        $this->actingAs($admin, 'admin')
            ->delete('/admin/warehouses/'.$east->id)
            ->assertRedirect();

        $this->assertDatabaseMissing('warehouses', ['id' => $east->id]);
    }

    public function test_cannot_delete_warehouse_with_stock(): void
    {
        $admin = Admin::query()->firstOrFail();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $east = $this->makeEastWarehouse();
        WarehouseStock::query()->create([
            'warehouse_id' => $east->id,
            'product_id' => $product->id,
            'qty' => 5,
            'reserved_qty' => 0,
        ]);

        $this->actingAs($admin, 'admin')
            ->from('/admin/warehouses')
            ->delete('/admin/warehouses/'.$east->id)
            ->assertRedirect('/admin/warehouses')
            ->assertSessionHasErrors('warehouse');

        $this->assertDatabaseHas('warehouses', ['id' => $east->id]);
        $this->assertDatabaseHas('warehouse_stocks', [
            'warehouse_id' => $east->id,
            'qty' => 5,
        ]);
    }

    public function test_cannot_delete_default_or_last_warehouse(): void
    {
        $admin = Admin::query()->firstOrFail();
        $default = Warehouse::query()->where('is_default', true)->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->from('/admin/warehouses')
            ->delete('/admin/warehouses/'.$default->id)
            ->assertRedirect('/admin/warehouses')
            ->assertSessionHasErrors('warehouse');

        Warehouse::query()->where('is_default', false)->delete();
        $this->actingAs($admin, 'admin')
            ->from('/admin/warehouses')
            ->delete('/admin/warehouses/'.$default->id)
            ->assertRedirect('/admin/warehouses')
            ->assertSessionHasErrors('warehouse');
    }

    public function test_deduct_keeps_product_and_warehouse_qty_in_sync(): void
    {
        ShopSetting::put('inventory.scope', 'warehouse');
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $default = Warehouse::query()->where('is_default', true)->firstOrFail();
        $east = $this->makeEastWarehouse();
        WarehouseStock::query()->updateOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $default->id],
            ['qty' => 0, 'reserved_qty' => 0]
        );
        WarehouseStock::query()->updateOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $east->id],
            ['qty' => 10, 'reserved_qty' => 0]
        );
        $product->update(['stock_qty' => 10, 'reserved_qty' => 0, 'manage_stock' => true, 'allow_backorder' => false]);

        $this->inventory()->deduct($product->fresh(), 3, InventoryMovement::ACTION_SALE, 'sale-sync-1', $east);

        $product->refresh();
        $eastStock = WarehouseStock::query()->where('product_id', $product->id)->where('warehouse_id', $east->id)->firstOrFail();
        $this->assertEqualsWithDelta(7, (float) $product->stock_qty, 0.001);
        $this->assertEqualsWithDelta(7, (float) $eastStock->qty, 0.001);
        $this->assertProductMatchesWarehouseSum($product);
    }

    public function test_deduct_fails_when_warehouse_stock_insufficient_without_negative(): void
    {
        ShopSetting::put('inventory.allow_negative', false);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $east = $this->makeEastWarehouse();
        WarehouseStock::query()->updateOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $east->id],
            ['qty' => 2, 'reserved_qty' => 0]
        );
        $product->update(['stock_qty' => 2, 'manage_stock' => true, 'allow_backorder' => false]);

        $this->expectException(ValidationException::class);
        $this->inventory()->deduct($product->fresh(), 5, InventoryMovement::ACTION_SALE, 'sale-fail-1', $east);
    }

    public function test_deduct_exact_stock_zeros_location_and_product(): void
    {
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $east = $this->makeEastWarehouse();
        WarehouseStock::query()->updateOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $east->id],
            ['qty' => 4, 'reserved_qty' => 0]
        );
        $product->update(['stock_qty' => 4, 'manage_stock' => true, 'allow_backorder' => false]);

        $this->inventory()->deduct($product->fresh(), 4, InventoryMovement::ACTION_SALE, 'sale-exact-1', $east);

        $product->refresh();
        $eastStock = WarehouseStock::query()->where('product_id', $product->id)->where('warehouse_id', $east->id)->firstOrFail();
        $this->assertEqualsWithDelta(0, (float) $product->stock_qty, 0.001);
        $this->assertEqualsWithDelta(0, (float) $eastStock->qty, 0.001);
        $this->assertProductMatchesWarehouseSum($product);
    }

    public function test_deduct_allows_negative_at_product_and_warehouse_when_enabled(): void
    {
        ShopSetting::put('inventory.allow_negative', true);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $east = $this->makeEastWarehouse();
        WarehouseStock::query()->updateOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $east->id],
            ['qty' => 1, 'reserved_qty' => 0]
        );
        $product->update(['stock_qty' => 1, 'manage_stock' => true, 'allow_backorder' => false]);

        $this->inventory()->deduct($product->fresh(), 3, InventoryMovement::ACTION_SALE, 'sale-neg-1', $east);

        $product->refresh();
        $eastStock = WarehouseStock::query()->where('product_id', $product->id)->where('warehouse_id', $east->id)->firstOrFail();
        $this->assertEqualsWithDelta(-2, (float) $product->stock_qty, 0.001);
        $this->assertEqualsWithDelta(-2, (float) $eastStock->qty, 0.001);
        $this->assertProductMatchesWarehouseSum($product);
    }

    public function test_cancel_restocks_original_warehouse(): void
    {
        ShopSetting::put('inventory.scope', 'warehouse');
        ShopSetting::put('orders.restock_on_cancellation', true);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $default = Warehouse::query()->where('is_default', true)->firstOrFail();
        $east = $this->makeEastWarehouse();
        WarehouseStock::query()->updateOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $default->id],
            ['qty' => 0, 'reserved_qty' => 0]
        );
        WarehouseStock::query()->updateOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $east->id],
            ['qty' => 20, 'reserved_qty' => 0]
        );
        $product->update(['stock_qty' => 20, 'manage_stock' => true]);

        $payment = PaymentMethod::query()->firstOrFail();
        $shipping = ShippingMethod::query()->where('is_enabled', true)->firstOrFail();
        $customer = Customer::factory()->create();
        $this->actingAs($customer, 'customer')->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 2])->assertOk();
        $placed = $this->actingAs($customer, 'customer')->postJson('/api/v1/checkout', [
            'email' => $customer->email,
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated();

        $order = Order::query()->where('number', $placed->json('order.number'))->with('items')->firstOrFail();
        $this->assertSame($east->id, (int) $order->items->first()->warehouse_id);

        $eastQtyAfterSale = (float) WarehouseStock::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $east->id)
            ->value('qty');
        $this->assertEqualsWithDelta(18, $eastQtyAfterSale, 0.001);

        \App\Domain\Order\OrderStageCatalog::seed();
        app(OrderStageService::class)->apply($order, 'cancelled', 'Cancel for warehouse test');

        $eastQtyAfterCancel = (float) WarehouseStock::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $east->id)
            ->value('qty');
        $defaultQty = (float) WarehouseStock::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $default->id)
            ->value('qty');

        $this->assertEqualsWithDelta(20, $eastQtyAfterCancel, 0.001);
        $this->assertEqualsWithDelta(0, $defaultQty, 0.001);
        $this->assertProductMatchesWarehouseSum($product->fresh());
    }

    public function test_reserve_does_not_seed_full_product_qty_on_new_location_row(): void
    {
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $east = $this->makeEastWarehouse();
        $product->update(['stock_qty' => 50, 'reserved_qty' => 0, 'manage_stock' => true]);
        WarehouseStock::query()->where('product_id', $product->id)->delete();

        $this->inventory()->reserve($product->fresh(), 3, 'reserve-seed-1', $east);

        $stock = WarehouseStock::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $east->id)
            ->firstOrFail();
        $this->assertEqualsWithDelta(0, (float) $stock->qty, 0.001);
        $this->assertEqualsWithDelta(3, (float) $stock->reserved_qty, 0.001);
        $this->assertEqualsWithDelta(3, (float) $product->fresh()->reserved_qty, 0.001);
    }

    public function test_csv_export_import_round_trip_is_idempotent(): void
    {
        $admin = Admin::query()->firstOrFail();
        $before = Warehouse::query()->count();
        $csv = \App\Domain\Catalog\Support\WarehouseCsvSchema::exportCsv();
        $this->assertStringContainsString('EAST-DEPOT', \App\Domain\Catalog\Support\WarehouseCsvSchema::sampleCsv());

        $imported = \App\Domain\Catalog\Support\WarehouseCsvSchema::import($csv);
        $this->assertGreaterThan(0, $imported);
        $this->assertSame($before, Warehouse::query()->count());
        $this->assertSame(1, Warehouse::query()->where('is_default', true)->count());
        $this->assertTrue((bool) Warehouse::query()->where('is_default', true)->value('is_enabled'));

        $this->actingAs($admin, 'admin')
            ->get('/admin/warehouses/export')
            ->assertOk();
    }

    public function test_sync_from_form_treats_submitted_rows_as_source_of_truth(): void
    {
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $default = Warehouse::query()->where('is_default', true)->firstOrFail();
        $east = $this->makeEastWarehouse();
        WarehouseStock::query()->updateOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $default->id],
            ['qty' => 5, 'reserved_qty' => 0]
        );
        WarehouseStock::query()->updateOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $east->id],
            ['qty' => 7, 'reserved_qty' => 0]
        );
        $product->update(['stock_qty' => 12, 'reserved_qty' => 0, 'manage_stock' => true]);

        $this->inventory()->syncFromForm($product->fresh(), [
            'warehouse_stocks' => [
                ['warehouse_id' => $east->id, 'qty' => 9, 'reserved_qty' => 0],
            ],
        ], 12);

        $product->refresh();
        $this->assertEqualsWithDelta(9, (float) $product->stock_qty, 0.001);
        $this->assertEqualsWithDelta(9, (float) WarehouseStock::query()->where('product_id', $product->id)->where('warehouse_id', $east->id)->value('qty'), 0.001);
        $this->assertEqualsWithDelta(0, (float) WarehouseStock::query()->where('product_id', $product->id)->where('warehouse_id', $default->id)->value('qty'), 0.001);
        $this->assertSame(2, InventoryMovement::query()->where('product_id', $product->id)->where('action', InventoryMovement::ACTION_ADJUSTMENT)->where('reference', 'Manual')->count());
    }

    public function test_sync_from_form_empty_rows_leave_existing_stock(): void
    {
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $default = Warehouse::query()->where('is_default', true)->firstOrFail();
        WarehouseStock::query()->updateOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $default->id],
            ['qty' => 25, 'reserved_qty' => 0]
        );
        $product->update(['stock_qty' => 25, 'reserved_qty' => 0, 'manage_stock' => true]);

        $this->inventory()->syncFromForm($product->fresh(), [
            'warehouse_stocks' => [],
        ], 25);

        $this->assertEqualsWithDelta(25, (float) $product->fresh()->stock_qty, 0.001);
        $this->assertEqualsWithDelta(25, (float) WarehouseStock::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $default->id)
            ->value('qty'), 0.001);
    }

    public function test_admin_adjust_targets_explicit_warehouse(): void
    {
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $east = $this->makeEastWarehouse();
        WarehouseStock::query()->updateOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $east->id],
            ['qty' => 2, 'reserved_qty' => 0]
        );
        $product->update(['stock_qty' => 2, 'manage_stock' => true]);

        app(\App\Domain\Catalog\Services\CatalogInventoryService::class)->adjust($product->fresh(), 3, 'restock east', $east->id);

        $this->assertEqualsWithDelta(5, (float) $product->fresh()->stock_qty, 0.001);
        $this->assertEqualsWithDelta(5, (float) WarehouseStock::query()->where('product_id', $product->id)->where('warehouse_id', $east->id)->value('qty'), 0.001);
        $this->assertProductMatchesWarehouseSum($product->fresh());
    }

    public function test_default_warehouse_cannot_be_disabled(): void
    {
        $admin = Admin::query()->firstOrFail();
        $default = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->makeEastWarehouse();

        $this->actingAs($admin, 'admin')
            ->from('/admin/warehouses')
            ->put('/admin/warehouses/'.$default->id, [
                'name' => $default->name,
                'code' => $default->code,
                'is_enabled' => false,
                'is_default' => true,
                'email' => 'warehouse@example.com',
                'address' => '123 Main Street',
                'city' => 'Springfield',
                'postcode' => '62701',
                'country' => 'US',
                'state' => 'IL',
            ])
            ->assertRedirect('/admin/warehouses')
            ->assertSessionHasErrors('is_enabled');
    }

    public function test_shop_scope_aliases_to_global(): void
    {
        ShopSetting::put('inventory.scope', 'shop');
        $this->assertSame('global', \App\Domain\Settings\InventorySettings::scope());
    }

    public function test_warehouse_permission_allows_access_without_products_permission(): void
    {
        $profile = \App\Domain\Auth\Models\Profile::query()->create([
            'name' => 'Warehouse only',
            'slug' => 'warehouse-only-test',
            'description' => 'Warehouses',
            'is_enabled' => true,
        ]);
        $profile->permissions()->sync(
            \App\Domain\Auth\Models\Permission::query()->whereIn('slug', ['catalog.warehouses'])->pluck('id')
        );
        $staff = Admin::factory()->create(['email' => 'wh-only@store.test']);
        $staff->applyProfile($profile);

        $this->actingAs($staff->fresh(['profile.permissions', 'roles']), 'admin')
            ->get('/admin/warehouses')
            ->assertOk();

        $ordersOnly = \App\Domain\Auth\Models\Profile::query()->create([
            'name' => 'Orders only',
            'slug' => 'orders-only-wh-test',
            'description' => 'Orders',
            'is_enabled' => true,
        ]);
        $ordersOnly->permissions()->sync(
            \App\Domain\Auth\Models\Permission::query()->whereIn('slug', ['orders.manage'])->pluck('id')
        );
        $blocked = Admin::factory()->create(['email' => 'orders-only-wh@store.test']);
        $blocked->applyProfile($ordersOnly);

        $this->actingAs($blocked->fresh(['profile.permissions', 'roles']), 'admin')
            ->get('/admin/warehouses')
            ->assertForbidden();
    }
}
