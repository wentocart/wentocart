<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\InventoryMovement;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\PurchaseOrder;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Catalog\Models\Warehouse;
use App\Domain\Catalog\Services\PurchaseOrderService;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseOrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_receive_keeps_ordered_then_completes(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $before = (float) $product->stock_qty;

        $order = app(PurchaseOrderService::class)->save(null, [
            'supplier_id' => Supplier::query()->value('id'),
            'warehouse_id' => $warehouse->id,
            'items' => [
                ['sku' => $product->sku, 'qty' => 10, 'unit_cost' => 2.5],
            ],
        ]);
        app(PurchaseOrderService::class)->markOrdered($order);
        $itemId = $order->fresh()->items->first()->id;

        $this->actingAs($admin, 'admin')
            ->post('/admin/purchase-orders/'.$order->id.'/receive', [
                'items' => [['id' => $itemId, 'qty' => 4]],
            ])
            ->assertRedirect();

        $order->refresh();
        $this->assertSame(PurchaseOrder::STATUS_ORDERED, $order->status);
        $this->assertEqualsWithDelta(4, (float) $order->items()->first()->qty_received, 0.01);
        $this->assertEqualsWithDelta($before + 4, (float) $product->fresh()->stock_qty, 0.01);

        $this->actingAs($admin, 'admin')
            ->post('/admin/purchase-orders/'.$order->id.'/receive', [
                'items' => [['id' => $itemId, 'qty' => 6]],
            ])
            ->assertRedirect();

        $order->refresh();
        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $order->status);
        $this->assertEqualsWithDelta(10, (float) $order->items()->first()->qty_received, 0.01);
        $this->assertEqualsWithDelta($before + 10, (float) $product->fresh()->stock_qty, 0.01);
        $this->assertSame(2, InventoryMovement::query()
            ->where('product_id', $product->id)
            ->where('action', InventoryMovement::ACTION_PURCHASE)
            ->where('reference', 'like', 'PO:'.$order->number.'#'.$itemId.'%')
            ->count());
    }

    public function test_receive_cannot_exceed_remaining_quantity(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $before = (float) $product->stock_qty;

        $service = app(PurchaseOrderService::class);
        $order = $service->save(null, [
            'warehouse_id' => $warehouse->id,
            'items' => [['sku' => $product->sku, 'qty' => 10, 'unit_cost' => 1]],
        ]);
        $service->markOrdered($order);
        $item = $order->fresh()->items->first();

        $service->receive($order, [['id' => $item->id, 'qty' => 4]]);
        $service->receive($order->fresh(), [['id' => $item->id, 'qty' => 999]]);

        $order->refresh();
        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $order->status);
        $this->assertEqualsWithDelta(10, (float) $order->items()->first()->qty_received, 0.01);
        $this->assertEqualsWithDelta($before + 10, (float) $product->fresh()->stock_qty, 0.01);
    }

    public function test_cannot_cancel_or_delete_after_partial_receive(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $service = app(PurchaseOrderService::class);

        $order = $service->save(null, [
            'warehouse_id' => $warehouse->id,
            'items' => [['sku' => $product->sku, 'qty' => 5, 'unit_cost' => 1]],
        ]);
        $service->markOrdered($order);
        $itemId = $order->fresh()->items->first()->id;
        $service->receive($order, [['id' => $itemId, 'qty' => 2]]);

        $this->actingAs($admin, 'admin')
            ->post('/admin/purchase-orders/'.$order->id.'/cancel')
            ->assertSessionHasErrors();

        $this->actingAs($admin, 'admin')
            ->delete('/admin/purchase-orders/'.$order->id)
            ->assertSessionHasErrors();

        $this->assertSame(PurchaseOrder::STATUS_ORDERED, $order->fresh()->status);
        $this->assertNotNull(PurchaseOrder::query()->find($order->id));
    }

    public function test_unknown_sku_is_rejected_on_save(): void
    {
        $this->seed();
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $this->expectException(ValidationException::class);
        app(PurchaseOrderService::class)->save(null, [
            'warehouse_id' => $warehouse->id,
            'items' => [['sku' => 'DOES-NOT-EXIST', 'qty' => 3, 'unit_cost' => 1]],
        ]);
    }

    public function test_cannot_reduce_qty_below_received(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $service = app(PurchaseOrderService::class);

        $order = $service->save(null, [
            'warehouse_id' => $warehouse->id,
            'items' => [['sku' => $product->sku, 'qty' => 10, 'unit_cost' => 1]],
        ]);
        $service->markOrdered($order);
        $item = $order->fresh()->items->first();
        $service->receive($order, [['id' => $item->id, 'qty' => 4]]);

        $this->expectException(ValidationException::class);
        $service->save($order->fresh(), [
            'warehouse_id' => $warehouse->id,
            'items' => [['id' => $item->id, 'sku' => $product->sku, 'qty' => 2, 'unit_cost' => 1]],
        ]);
    }

    public function test_mark_ordered_requires_warehouse(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $service = app(PurchaseOrderService::class);

        $order = $service->save(null, [
            'warehouse_id' => null,
            'items' => [['sku' => $product->sku, 'qty' => 2, 'unit_cost' => 1]],
        ]);

        $this->expectException(ValidationException::class);
        $service->markOrdered($order);
    }

    public function test_list_supports_status_filter_and_pagination(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $service = app(PurchaseOrderService::class);

        $order = $service->save(null, [
            'warehouse_id' => $warehouse->id,
            'items' => [['sku' => $product->sku, 'qty' => 1, 'unit_cost' => 1]],
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/purchase-orders?status=draft')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.status', 'draft')
                ->has('orders.data', 1)
                ->where('orders.data.0.number', $order->number));
    }
}
