<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\InventoryMovement;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\PurchaseOrder;
use App\Domain\Catalog\Models\PurchaseOrderReceipt;
use App\Domain\Catalog\Models\Warehouse;
use App\Domain\Catalog\Models\WarehouseStock;
use App\Domain\Catalog\Services\PurchaseOrderService;
use App\Domain\Settings\ShopSetting;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseOrderAdversarialQaTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PurchaseOrderService
    {
        return app(PurchaseOrderService::class);
    }

    private function seedProductWarehouse(): array
    {
        $this->seed();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        return [$product, $warehouse];
    }

    private function makeOrderedPo(Product $product, Warehouse $warehouse, float $qty = 100): PurchaseOrder
    {
        $order = $this->service()->save(null, [
            'warehouse_id' => $warehouse->id,
            'items' => [['sku' => $product->sku, 'product_id' => $product->id, 'qty' => $qty, 'unit_cost' => 2.5]],
        ]);
        $this->service()->markOrdered($order);

        return $order->fresh(['items']);
    }

    public function test_invalid_status_transitions_are_rejected(): void
    {
        [$product, $warehouse] = $this->seedProductWarehouse();
        $admin = Admin::query()->firstOrFail();
        $order = $this->makeOrderedPo($product, $warehouse, 5);
        $this->service()->receive($order);
        $order = $order->fresh();
        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $order->status);

        $this->expectException(ValidationException::class);
        $this->service()->save($order, [
            'warehouse_id' => $warehouse->id,
            'notes' => 'hack',
            'items' => [['sku' => $product->sku, 'qty' => 5, 'unit_cost' => 1]],
        ]);
    }

    public function test_received_cannot_be_received_cancelled_or_deleted_via_http(): void
    {
        [$product, $warehouse] = $this->seedProductWarehouse();
        $admin = Admin::query()->firstOrFail();
        $order = $this->makeOrderedPo($product, $warehouse, 3);
        $this->service()->receive($order);
        $id = $order->id;

        $this->actingAs($admin, 'admin')->post('/admin/purchase-orders/'.$id.'/receive')->assertSessionHasErrors();
        $this->actingAs($admin, 'admin')->post('/admin/purchase-orders/'.$id.'/cancel')->assertSessionHasErrors();
        $this->actingAs($admin, 'admin')->delete('/admin/purchase-orders/'.$id)->assertSessionHasErrors();
        $this->actingAs($admin, 'admin')->post('/admin/purchase-orders/'.$id.'/ordered')->assertSessionHasErrors();
        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $order->fresh()->status);
    }

    public function test_cancelled_cannot_be_edited_or_received(): void
    {
        [$product, $warehouse] = $this->seedProductWarehouse();
        $order = $this->service()->save(null, [
            'warehouse_id' => $warehouse->id,
            'items' => [['sku' => $product->sku, 'qty' => 2, 'unit_cost' => 1]],
        ]);
        $this->service()->cancel($order);
        $order = $order->fresh();

        try {
            $this->service()->save($order, [
                'warehouse_id' => $warehouse->id,
                'items' => [['sku' => $product->sku, 'qty' => 2, 'unit_cost' => 1]],
            ]);
            $this->fail('Expected edit of cancelled PO to fail');
        } catch (ValidationException) {
            // expected
        }

        try {
            $this->service()->receive($order);
            $this->fail('Expected receive of cancelled PO to fail');
        } catch (ValidationException) {
            // expected
        }

        try {
            $this->service()->markOrdered($order);
            $this->fail('Expected markOrdered of cancelled PO to fail');
        } catch (ValidationException) {
            // expected
        }
    }

    public function test_full_receive_inventory_and_receipt_integrity(): void
    {
        [$product, $warehouse] = $this->seedProductWarehouse();
        $before = (float) $product->stock_qty;
        $whBefore = (float) (WarehouseStock::query()->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->value('qty') ?? 0);
        $order = $this->makeOrderedPo($product, $warehouse, 100);
        $itemId = $order->items->first()->id;

        $this->service()->receive($order, [], Admin::query()->value('id'));

        $order = $order->fresh(['items', 'receipts']);
        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $order->status);
        $this->assertEqualsWithDelta(100, (float) $order->items->first()->qty_received, 0.001);
        $this->assertEqualsWithDelta($before + 100, (float) $product->fresh()->stock_qty, 0.001);
        $this->assertEqualsWithDelta($whBefore + 100, (float) WarehouseStock::query()->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->value('qty'), 0.001);
        $this->assertCount(1, $order->receipts);
        $this->assertTrue((bool) $order->receipts->first()->is_final);
        $this->assertSame(1, InventoryMovement::query()
            ->where('product_id', $product->id)
            ->where('action', InventoryMovement::ACTION_PURCHASE)
            ->where('reference', 'like', 'PO:'.$order->number.'#'.$itemId.'%')
            ->count());
    }

    public function test_multiple_partial_receives_sum_exactly_to_ordered(): void
    {
        [$product, $warehouse] = $this->seedProductWarehouse();
        $before = (float) $product->stock_qty;
        $order = $this->makeOrderedPo($product, $warehouse, 100);
        $itemId = $order->items->first()->id;

        foreach ([20, 30, 10, 40] as $qty) {
            $this->service()->receive($order->fresh(), [['id' => $itemId, 'qty' => $qty]]);
        }

        $order = $order->fresh(['items', 'receipts']);
        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $order->status);
        $this->assertEqualsWithDelta(100, (float) $order->items->first()->qty_received, 0.001);
        $this->assertEqualsWithDelta($before + 100, (float) $product->fresh()->stock_qty, 0.001);
        $this->assertCount(4, $order->receipts->whereNull('reversed_at'));
        $this->assertEqualsWithDelta(100, (float) $order->receipts->whereNull('reversed_at')->sum('qty'), 0.001);
    }

    public function test_over_receive_is_capped_and_negative_rejected_by_http(): void
    {
        [$product, $warehouse] = $this->seedProductWarehouse();
        $admin = Admin::query()->firstOrFail();
        $before = (float) $product->stock_qty;
        $order = $this->makeOrderedPo($product, $warehouse, 100);
        $itemId = $order->items->first()->id;

        $this->service()->receive($order, [['id' => $itemId, 'qty' => 101]]);
        $this->assertEqualsWithDelta(100, (float) $order->fresh()->items->first()->qty_received, 0.001);
        $this->assertEqualsWithDelta($before + 100, (float) $product->fresh()->stock_qty, 0.001);

        $order2 = $this->makeOrderedPo($product->fresh(), $warehouse, 10);
        $item2 = $order2->items->first()->id;
        $this->actingAs($admin, 'admin')
            ->post('/admin/purchase-orders/'.$order2->id.'/receive', [
                'items' => [['id' => $item2, 'qty' => -5]],
            ])
            ->assertSessionHasErrors();
    }

    public function test_zero_receive_payload_errors_without_stock_change(): void
    {
        [$product, $warehouse] = $this->seedProductWarehouse();
        $before = (float) $product->stock_qty;
        $order = $this->makeOrderedPo($product, $warehouse, 10);
        $itemId = $order->items->first()->id;

        try {
            $this->service()->receive($order, [['id' => $itemId, 'qty' => 0]]);
            $this->fail('Expected zero receive to fail');
        } catch (ValidationException) {
            // expected
        }

        $this->assertEqualsWithDelta($before, (float) $product->fresh()->stock_qty, 0.001);
        $this->assertSame(PurchaseOrder::STATUS_ORDERED, $order->fresh()->status);
    }

    public function test_duplicate_full_receive_does_not_double_stock(): void
    {
        [$product, $warehouse] = $this->seedProductWarehouse();
        $before = (float) $product->stock_qty;
        $order = $this->makeOrderedPo($product, $warehouse, 25);

        $this->service()->receive($order);
        try {
            $this->service()->receive($order->fresh());
            $this->fail('Second full receive should fail');
        } catch (ValidationException) {
            // expected
        }

        $this->assertEqualsWithDelta($before + 25, (float) $product->fresh()->stock_qty, 0.001);
        $this->assertSame(1, PurchaseOrderReceipt::query()->where('purchase_order_id', $order->id)->whereNull('reversed_at')->count());
    }

    public function test_reverse_keeps_purchase_movement_and_adds_adjustment(): void
    {
        [$product, $warehouse] = $this->seedProductWarehouse();
        $before = (float) $product->stock_qty;
        $order = $this->makeOrderedPo($product, $warehouse, 40);
        $this->service()->receive($order);
        $receipt = PurchaseOrderReceipt::query()->where('purchase_order_id', $order->id)->firstOrFail();
        $purchaseRef = $receipt->inventory_reference;

        $this->service()->reverseReceipt($receipt, Admin::query()->value('id'));

        $this->assertNotNull(InventoryMovement::query()->where('reference', $purchaseRef)->where('action', InventoryMovement::ACTION_PURCHASE)->first());
        $this->assertNotNull(InventoryMovement::query()->where('reference', 'PO-REV:'.$receipt->id)->where('action', InventoryMovement::ACTION_ADJUSTMENT)->first());
        $this->assertEqualsWithDelta($before, (float) $product->fresh()->stock_qty, 0.001);
        $this->assertNotNull($receipt->fresh()->reversed_at);
        $this->assertEqualsWithDelta(0, (float) $order->fresh()->items->first()->qty_received, 0.001);
        $this->assertSame(PurchaseOrder::STATUS_ORDERED, $order->fresh()->status);
    }

    public function test_guest_cannot_access_purchase_order_endpoints(): void
    {
        [$product, $warehouse] = $this->seedProductWarehouse();
        $order = $this->makeOrderedPo($product, $warehouse, 2);

        $this->get('/admin/purchase-orders')->assertRedirect();
        $this->post('/admin/purchase-orders/'.$order->id.'/receive')->assertRedirect();
        $this->get('/admin/purchase-orders/'.$order->id.'/pdf')->assertRedirect();
        $this->get('/admin/purchase-orders/'.$order->id.'/print')->assertRedirect();
    }

    public function test_manage_stock_false_still_tracks_qty_received_without_movement(): void
    {
        [$product, $warehouse] = $this->seedProductWarehouse();
        $product->update(['manage_stock' => false]);
        $before = (float) $product->stock_qty;
        $movementsBefore = InventoryMovement::query()->where('product_id', $product->id)->count();

        $order = $this->makeOrderedPo($product, $warehouse, 7);
        $this->service()->receive($order);

        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $order->fresh()->status);
        $this->assertEqualsWithDelta(7, (float) $order->fresh()->items->first()->qty_received, 0.001);
        $this->assertEqualsWithDelta($before, (float) $product->fresh()->stock_qty, 0.001);
        $this->assertSame($movementsBefore, InventoryMovement::query()->where('product_id', $product->id)->count());
        $this->assertSame(1, PurchaseOrderReceipt::query()->where('purchase_order_id', $order->id)->count());
    }

    public function test_csv_cannot_overwrite_received_purchase_order(): void
    {
        [$product, $warehouse] = $this->seedProductWarehouse();
        $order = $this->makeOrderedPo($product, $warehouse, 4);
        $this->service()->receive($order);
        $number = $order->fresh()->number;

        $csv = implode("\n", [
            'id;number;supplier;warehouse;sku;qty;unit_cost;status',
            ';'.$number.';;'.$warehouse->code.';'.$product->sku.';99;1;draft',
        ]);
        \App\Domain\Catalog\Support\PurchaseOrderCsvSchema::import($csv);

        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $order->fresh()->status);
        $this->assertEqualsWithDelta(4, (float) $order->fresh()->items->first()->qty_ordered, 0.001);
    }

    public function test_draft_to_received_via_receive_sets_ordered_at(): void
    {
        [$product, $warehouse] = $this->seedProductWarehouse();
        $order = $this->service()->save(null, [
            'warehouse_id' => $warehouse->id,
            'items' => [['sku' => $product->sku, 'qty' => 3, 'unit_cost' => 1]],
        ]);
        $this->assertNull($order->ordered_at);
        $this->service()->receive($order);
        $order = $order->fresh();
        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $order->status);
        $this->assertNotNull($order->ordered_at);
        $this->assertNotNull($order->received_at);
    }

    public function test_inventory_disabled_allows_receive_bookkeeping_without_stock_change(): void
    {
        [$product, $warehouse] = $this->seedProductWarehouse();
        ShopSetting::put('inventory.enabled', false);
        $before = (float) $product->stock_qty;
        $order = $this->makeOrderedPo($product, $warehouse, 6);
        $this->service()->receive($order);

        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $order->fresh()->status);
        $this->assertEqualsWithDelta(6, (float) $order->fresh()->items->first()->qty_received, 0.001);
        $this->assertEqualsWithDelta($before, (float) $product->fresh()->stock_qty, 0.001);
        ShopSetting::put('inventory.enabled', true);
    }
}
