<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\InventoryMovement;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\PurchaseOrder;
use App\Domain\Catalog\Models\PurchaseOrderItem;
use App\Domain\Catalog\Models\PurchaseOrderReceipt;
use App\Domain\Catalog\Models\Warehouse;
use App\Domain\Settings\InventorySettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderService
{
    public function __construct(protected InventoryService $inventory) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(?PurchaseOrder $order, array $data): PurchaseOrder
    {
        $order ??= new PurchaseOrder();
        if ($order->exists && ! in_array($order->status, [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_ORDERED], true)) {
            throw ValidationException::withMessages(['purchase_order' => 'Received or cancelled orders cannot be edited.']);
        }

        $discountType = $data['discount_type'] ?? $order->discount_type;
        if ($discountType === '' || $discountType === null) {
            $discountType = null;
        } elseif (! in_array($discountType, ['percent', 'fixed'], true)) {
            throw ValidationException::withMessages([
                'discount_type' => 'Discount type must be percent, fixed, or empty.',
            ]);
        }

        return DB::transaction(function () use ($order, $data, $discountType) {
            $order->fill([
                'supplier_id' => $data['supplier_id'] ?? $order->supplier_id,
                'warehouse_id' => $data['warehouse_id'] ?? $order->warehouse_id,
                'status' => $order->status ?: PurchaseOrder::STATUS_DRAFT,
                'expected_at' => $data['expected_at'] ?? $order->expected_at,
                'notes' => $data['notes'] ?? $order->notes,
                'discount_type' => $discountType,
                'discount_value' => max(0, (float) ($data['discount_value'] ?? $order->discount_value ?? 0)),
                'shipping_cost' => max(0, (float) ($data['shipping_cost'] ?? $order->shipping_cost ?? 0)),
            ]);
            if (! $order->number) {
                $order->number = $this->nextNumber();
            }
            $order->save();
            $this->syncItems($order, is_array($data['items'] ?? null) ? $data['items'] : []);

            return $order->fresh(['items', 'supplier', 'warehouse']);
        });
    }

    public function markOrdered(PurchaseOrder $order): PurchaseOrder
    {
        if ($order->status !== PurchaseOrder::STATUS_DRAFT) {
            throw ValidationException::withMessages(['purchase_order' => 'Only a draft can be marked ordered.']);
        }
        if ($order->items()->count() === 0) {
            throw ValidationException::withMessages(['items' => 'Add at least one line before ordering.']);
        }
        if (! $order->warehouse_id) {
            throw ValidationException::withMessages(['warehouse_id' => 'Choose a warehouse before marking ordered.']);
        }
        $order->update([
            'status' => PurchaseOrder::STATUS_ORDERED,
            'ordered_at' => now(),
        ]);

        return $order->fresh(['items', 'supplier', 'warehouse']);
    }

    /**
     * @param  list<array{id?: int, qty?: float|int|string}>  $received
     */
    public function receive(PurchaseOrder $order, array $received = [], ?int $adminId = null): PurchaseOrder
    {
        if (! in_array($order->status, [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_ORDERED], true)) {
            throw ValidationException::withMessages(['purchase_order' => 'This purchase order cannot be received.']);
        }
        $warehouse = $order->warehouse ?: Warehouse::query()->where('is_default', true)->first();
        if (! $warehouse) {
            throw ValidationException::withMessages(['warehouse_id' => 'Choose a warehouse before receiving.']);
        }

        return DB::transaction(function () use ($order, $received, $warehouse, $adminId) {
            $order = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_ORDERED], true)) {
                throw ValidationException::withMessages(['purchase_order' => 'This purchase order cannot be received.']);
            }

            $items = PurchaseOrderItem::query()
                ->where('purchase_order_id', $order->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $items->load('product');

            $byId = [];
            foreach ($received as $row) {
                if (! empty($row['id'])) {
                    $byId[(int) $row['id']] = (float) ($row['qty'] ?? $row['qty_received'] ?? 0);
                }
            }

            $receivedAny = false;
            $skipped = [];
            $createdReceiptIds = [];

            foreach ($items as $item) {
                $remaining = $item->remaining();
                if ($remaining <= 0) {
                    continue;
                }
                $qty = $byId === []
                    ? $remaining
                    : max(0, min($remaining, (float) ($byId[$item->id] ?? 0)));
                if ($qty <= 0) {
                    continue;
                }
                if (! $item->product) {
                    $skipped[] = $item->sku ?: ('#'.$item->id);

                    continue;
                }

                $before = (float) $item->qty_received;
                $tracksStock = $this->productTracksStock($item->product);
                $reference = null;
                if ($tracksStock) {
                    // Unique reference per receive slice so retries of the same
                    // remaining qty cannot double-count inventory while still
                    // allowing multiple legitimate partial receives.
                    $reference = sprintf(
                        'PO:%s#%d@%.3f+%.3f',
                        $order->number,
                        $item->id,
                        $before,
                        $qty
                    );
                    $applied = $this->inventory->restock(
                        $item->product,
                        $qty,
                        InventoryMovement::ACTION_PURCHASE,
                        $reference,
                        $warehouse
                    );
                    if (! $applied) {
                        // Duplicate submit (alreadyRecorded) — do not bump qty_received.
                        continue;
                    }
                }

                $item->update(['qty_received' => $before + $qty]);
                $receipt = PurchaseOrderReceipt::query()->create([
                    'purchase_order_id' => $order->id,
                    'purchase_order_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'warehouse_id' => $warehouse->id,
                    'admin_id' => $adminId,
                    'qty' => $qty,
                    'unit_cost' => (float) $item->unit_cost,
                    'inventory_reference' => $reference,
                    'is_final' => false,
                ]);
                $createdReceiptIds[] = $receipt->id;
                $receivedAny = true;
            }

            if ($skipped !== []) {
                throw ValidationException::withMessages([
                    'items' => 'Cannot receive lines without a product: '.implode(', ', $skipped),
                ]);
            }

            $order->unsetRelation('items');
            $order->load('items');

            if (! $receivedAny) {
                $stillOpen = $order->items->first(fn (PurchaseOrderItem $row) => $row->remaining() > 0.0001);
                if ($byId !== []) {
                    throw ValidationException::withMessages([
                        'items' => 'Enter a receive quantity greater than zero for at least one open line.',
                    ]);
                }
                if ($stillOpen) {
                    throw ValidationException::withMessages([
                        'items' => 'No stock was received. Check inventory settings and that products manage stock.',
                    ]);
                }
            }

            $open = $order->items->first(fn (PurchaseOrderItem $row) => $row->remaining() > 0.0001);
            $fullyReceived = ! $open;
            $order->update([
                'status' => $fullyReceived ? PurchaseOrder::STATUS_RECEIVED : PurchaseOrder::STATUS_ORDERED,
                'ordered_at' => $order->ordered_at ?: now(),
                'received_at' => $fullyReceived ? now() : $order->received_at,
                'warehouse_id' => $order->warehouse_id ?: $warehouse->id,
            ]);

            if ($fullyReceived && $createdReceiptIds !== []) {
                PurchaseOrderReceipt::query()
                    ->whereIn('id', $createdReceiptIds)
                    ->update(['is_final' => true]);
            }

            return $order->fresh(['items', 'supplier', 'warehouse', 'receipts']);
        });
    }

    public function reverseReceipt(PurchaseOrderReceipt $receipt, ?int $adminId = null): PurchaseOrder
    {
        $qty = (float) $receipt->qty;
        if ($qty <= 0) {
            throw ValidationException::withMessages([
                'receipt' => 'Cannot reverse a receipt with zero quantity.',
            ]);
        }

        return DB::transaction(function () use ($receipt, $adminId, $qty) {
            $receipt = PurchaseOrderReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            $order = PurchaseOrder::query()->whereKey($receipt->purchase_order_id)->lockForUpdate()->firstOrFail();
            $item = PurchaseOrderItem::query()->whereKey($receipt->purchase_order_item_id)->lockForUpdate()->firstOrFail();

            if ($receipt->isReversed()) {
                $expected = (float) PurchaseOrderReceipt::query()
                    ->where('purchase_order_item_id', $item->id)
                    ->whereNull('reversed_at')
                    ->sum('qty');
                // Repair path after a crash between reverse mark and qty sync.
                if (abs((float) $item->qty_received - $expected) > 0.001) {
                    $this->syncItemReceivedFromReceipts($item);
                    $this->reopenIfNeeded($order);

                    return $order->fresh(['items', 'supplier', 'warehouse', 'receipts']);
                }

                throw ValidationException::withMessages([
                    'receipt' => 'This receipt has already been reversed.',
                ]);
            }

            $reversalReference = 'PO-REV:'.$receipt->id;

            $product = $receipt->product_id
                ? Product::query()->find($receipt->product_id)
                : null;
            $warehouse = $receipt->warehouse_id
                ? Warehouse::query()->find($receipt->warehouse_id)
                : ($order->warehouse ?: null);

            if ($product && $this->productTracksStock($product)) {
                $alreadyReversedStock = InventoryMovement::query()
                    ->where('product_id', $product->id)
                    ->where('action', InventoryMovement::ACTION_ADJUSTMENT)
                    ->where('reference', $reversalReference)
                    ->exists();
                if (! $alreadyReversedStock) {
                    $this->inventory->deduct(
                        $product,
                        $qty,
                        InventoryMovement::ACTION_ADJUSTMENT,
                        $reversalReference,
                        $warehouse
                    );
                }
            }

            // Mark reversed first, then rebuild qty_received from active receipts
            // so retries after a partial crash cannot double-decrement.
            $receipt->update([
                'reversed_at' => now(),
                'reversed_by_admin_id' => $adminId,
                'reversal_reference' => $reversalReference,
                'is_final' => false,
            ]);
            $this->syncItemReceivedFromReceipts($item);
            $this->reopenIfNeeded($order);

            return $order->fresh(['items', 'supplier', 'warehouse', 'receipts']);
        });
    }

    public function cancel(PurchaseOrder $order): PurchaseOrder
    {
        if ($order->status === PurchaseOrder::STATUS_RECEIVED) {
            throw ValidationException::withMessages(['purchase_order' => 'Received purchase orders cannot be cancelled.']);
        }
        if ($this->hasReceivedQty($order)) {
            throw ValidationException::withMessages([
                'purchase_order' => 'Cannot cancel a purchase order that already has received stock. Finish receiving or keep it ordered.',
            ]);
        }
        $order->update(['status' => PurchaseOrder::STATUS_CANCELLED]);

        return $order->fresh();
    }

    public function delete(PurchaseOrder $order): void
    {
        if ($order->status === PurchaseOrder::STATUS_RECEIVED) {
            throw ValidationException::withMessages(['purchase_order' => 'Received purchase orders cannot be deleted.']);
        }
        if ($this->hasReceivedQty($order)) {
            throw ValidationException::withMessages([
                'purchase_order' => 'Cannot delete a purchase order that already has received stock.',
            ]);
        }
        $order->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function presentTotals(PurchaseOrder $order): array
    {
        $order->loadMissing('items');

        return [
            'line_subtotal' => $order->lineSubtotal(),
            'discount_type' => $order->discount_type,
            'discount_value' => (float) $order->discount_value,
            'discount_amount' => $order->discountAmount(),
            'shipping_cost' => (float) $order->shipping_cost,
            'grand_total' => $order->grandTotal(),
            'is_overdue' => $order->isOverdue(),
            'overdue_days' => $order->overdueDays(),
            'expected_at' => $order->expected_at?->toDateString(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    protected function syncItems(PurchaseOrder $order, array $rows): void
    {
        $keep = [];
        $missingSkus = [];
        $errors = [];
        $seenProductIds = [];

        foreach ($rows as $index => $row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            $productId = (int) ($row['product_id'] ?? 0);
            $product = $productId > 0
                ? Product::query()->find($productId)
                : ($sku !== '' ? Product::query()->where('sku', $sku)->first() : null);

            if ($sku === '' && $productId <= 0) {
                continue;
            }
            if (! $product) {
                $missingSkus[] = $sku !== '' ? $sku : ('row '.($index + 1));

                continue;
            }

            if (isset($seenProductIds[$product->id])) {
                throw ValidationException::withMessages([
                    'items' => 'Duplicate product on this purchase order: '.($product->sku ?: ('#'.$product->id)),
                ]);
            }
            $seenProductIds[$product->id] = true;

            $qty = max(0, (float) ($row['qty_ordered'] ?? $row['qty'] ?? 0));
            if ($qty <= 0) {
                $errors["items.$index.qty"] = 'Quantity must be greater than zero.';

                continue;
            }

            $item = null;
            if (! empty($row['id'])) {
                $item = $order->items()->whereKey((int) $row['id'])->first();
            }

            $alreadyReceived = $item ? (float) $item->qty_received : 0.0;
            if ($qty + 0.0001 < $alreadyReceived) {
                $errors["items.$index.qty"] = 'Ordered quantity cannot be less than already received ('.$alreadyReceived.').';

                continue;
            }

            $unitCostRaw = $row['unit_cost'] ?? null;
            $unitCostProvided = ! ($unitCostRaw === null || $unitCostRaw === '');
            $unitCost = $unitCostProvided ? max(0, (float) $unitCostRaw) : 0.0;
            if ((! $unitCostProvided || $unitCost <= 0) && $order->supplier_id) {
                $pivot = $product->suppliers()->where('suppliers.id', $order->supplier_id)->first();
                if ($pivot && $pivot->pivot->wholesale_price !== null && $pivot->pivot->wholesale_price !== '') {
                    $unitCost = max(0, (float) $pivot->pivot->wholesale_price);
                }
            }

            $payload = [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->translation()?->name ?: $product->sku,
                'qty_ordered' => $qty,
                'unit_cost' => $unitCost,
            ];
            if ($item) {
                $item->update($payload);
            } else {
                $item = $order->items()->create($payload);
            }
            $keep[] = $item->id;
        }

        if ($missingSkus !== []) {
            throw ValidationException::withMessages([
                'items' => 'Unknown SKU(s): '.implode(', ', array_unique($missingSkus)),
            ]);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $blocked = $order->items()
            ->whereNotIn('id', $keep === [] ? [0] : $keep)
            ->where('qty_received', '>', 0)
            ->get(['id', 'sku', 'qty_received']);
        if ($blocked->isNotEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'Cannot remove lines that already have received stock: '
                    .$blocked->map(fn (PurchaseOrderItem $row) => $row->sku ?: ('#'.$row->id))->implode(', '),
            ]);
        }

        $order->items()->whereNotIn('id', $keep === [] ? [0] : $keep)->delete();
    }

    protected function hasReceivedQty(PurchaseOrder $order): bool
    {
        return $order->items()->where('qty_received', '>', 0)->exists();
    }

    protected function syncItemReceivedFromReceipts(PurchaseOrderItem $item): void
    {
        $sum = (float) PurchaseOrderReceipt::query()
            ->where('purchase_order_item_id', $item->id)
            ->whereNull('reversed_at')
            ->sum('qty');
        $item->update(['qty_received' => max(0, round($sum, 3))]);
    }

    protected function reopenIfNeeded(PurchaseOrder $order): void
    {
        $order->unsetRelation('items');
        $order->load('items');
        $hasRemaining = $order->items->first(fn (PurchaseOrderItem $row) => $row->remaining() > 0.0001);
        if ($order->status === PurchaseOrder::STATUS_RECEIVED && $hasRemaining) {
            $order->update([
                'status' => PurchaseOrder::STATUS_ORDERED,
                'received_at' => null,
            ]);
        }
    }

    protected function productTracksStock(Product $product): bool
    {
        if (! InventorySettings::enabled()) {
            return false;
        }
        if ($product->isComposite() && ($product->inventory_mode ?: 'derived') === 'derived') {
            return true;
        }

        return (bool) $product->manage_stock;
    }

    protected function nextNumber(): string
    {
        $n = (int) PurchaseOrder::query()->max('id') + 1;
        do {
            $number = 'PO-'.str_pad((string) $n, 5, '0', STR_PAD_LEFT);
            $n++;
        } while (PurchaseOrder::query()->where('number', $number)->exists());

        return $number;
    }
}
