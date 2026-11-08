<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\InventoryMovement;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Warehouse;
use App\Domain\Catalog\Models\WarehouseStock;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Settings\ShopSetting;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WarehouseTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        ShopSetting::put('inventory.scope', 'warehouse');
    }

    protected function makeEastWarehouse(): Warehouse
    {
        return Warehouse::query()->create([
            'name' => 'East DC',
            'code' => 'EAST-XFER',
            'is_enabled' => true,
            'is_default' => false,
        ]);
    }

    public function test_transfer_moves_stock_keeps_product_sum_and_writes_journal(): void
    {
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $default = Warehouse::query()->where('is_default', true)->firstOrFail();
        $east = $this->makeEastWarehouse();

        app(InventoryService::class)->setLocationQty($product, $default, 20, 0);
        app(InventoryService::class)->setLocationQty($product, $east, 0, 0);
        app(InventoryService::class)->rollupProductStock($product->fresh());
        $beforeSum = (float) $product->fresh()->stock_qty;

        $result = app(InventoryService::class)->transfer($product->fresh(), $default, $east, 7);

        $this->assertSame(7.0, (float) $result['qty']);
        $this->assertSame((int) $default->id, (int) $result['from_warehouse_id']);
        $this->assertSame((int) $east->id, (int) $result['to_warehouse_id']);

        $this->assertEqualsWithDelta(13.0, (float) WarehouseStock::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $default->id)
            ->value('qty'), 0.001);
        $this->assertEqualsWithDelta(7.0, (float) WarehouseStock::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $east->id)
            ->value('qty'), 0.001);
        $this->assertEqualsWithDelta($beforeSum, (float) $product->fresh()->stock_qty, 0.001);

        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'warehouse_id' => $default->id,
            'action' => InventoryMovement::ACTION_TRANSFER_OUT,
            'reference' => $result['reference'],
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'warehouse_id' => $east->id,
            'action' => InventoryMovement::ACTION_TRANSFER_IN,
            'reference' => $result['reference'],
        ]);
    }

    public function test_transfer_rejects_insufficient_available_qty(): void
    {
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $default = Warehouse::query()->where('is_default', true)->firstOrFail();
        $east = $this->makeEastWarehouse();

        app(InventoryService::class)->setLocationQty($product, $default, 5, 3);
        app(InventoryService::class)->setLocationQty($product, $east, 0, 0);
        app(InventoryService::class)->rollupProductStock($product->fresh());

        $this->expectException(ValidationException::class);
        app(InventoryService::class)->transfer($product->fresh(), $default, $east, 3);
    }

    public function test_admin_can_transfer_via_inventory_route(): void
    {
        $admin = Admin::query()->firstOrFail();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $default = Warehouse::query()->where('is_default', true)->firstOrFail();
        $east = $this->makeEastWarehouse();

        app(InventoryService::class)->setLocationQty($product, $default, 12, 0);
        app(InventoryService::class)->setLocationQty($product, $east, 0, 0);
        app(InventoryService::class)->rollupProductStock($product->fresh());

        $this->actingAs($admin, 'admin')
            ->from('/admin/inventory')
            ->post('/admin/inventory/'.$product->id.'/transfer', [
                'from_warehouse_id' => $default->id,
                'to_warehouse_id' => $east->id,
                'qty' => 4,
            ])
            ->assertRedirect('/admin/inventory');

        $this->assertEqualsWithDelta(8.0, (float) WarehouseStock::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $default->id)
            ->value('qty'), 0.001);
        $this->assertEqualsWithDelta(4.0, (float) WarehouseStock::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $east->id)
            ->value('qty'), 0.001);

        $show = $this->actingAs($admin, 'admin')->get('/admin/inventory');
        $show->assertOk();
        $show->assertInertia(fn ($page) => $page
            ->component('Catalog/Inventory/Index')
            ->has('warehouses.0.id')
            ->has('warehouses.1.id'));
    }
}
