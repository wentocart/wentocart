<?php

namespace App\Domain\Order\Services;

use App\Domain\Catalog\Models\InventoryMovement;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Notify\AdminInbox;
use App\Domain\Order\Models\ShopReturn;
use App\Domain\Promo\Services\RefundVoucherIssuer;
use App\Domain\Settings\ReturnSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShopReturnService
{
    /**
     * @param  list<array{order_item_id: int, qty: int}>  $lines
     */
    public function request(Customer $customer, Order $order, array $lines, array $meta = []): ShopReturn
    {
        if ((int) $order->customer_id !== (int) $customer->id) {
            abort(403, 'This order does not belong to the current customer.');
        }
        if (! ReturnSettings::enabled()) {
            throw ValidationException::withMessages(['return' => 'Returns are disabled.']);
        }
        $this->assertWindow($order);
        $reason = trim((string) ($meta['reason'] ?? ''));
        if (ReturnSettings::reasonRequired() && $reason === '' && empty($meta['reason_id'])) {
            throw ValidationException::withMessages(['reason' => 'A return reason is required.']);
        }
        if (! ReturnSettings::partialReturns()) {
            $this->assertFullReturn($order, $lines);
        }

        $row = DB::transaction(function () use ($customer, $order, $lines, $meta) {
            $built = $this->applyReturnAdjustments($order, $this->buildLines($order, $lines));
            if ($built['items'] === []) {
                throw ValidationException::withMessages(['items' => 'Select at least one returnable item.']);
            }

            $auto = ReturnSettings::approval() === 'automatic';
            $row = ShopReturn::query()->create([
                'order_id' => $order->id,
                'customer_id' => $customer->id,
                'number' => $this->nextNumber(),
                'status' => $auto ? ShopReturn::STATUS_APPROVED : ShopReturn::STATUS_REQUESTED,
                'reason' => isset($meta['reason']) ? trim((string) $meta['reason']) : null,
                'reason_id' => isset($meta['reason_id']) ? (int) $meta['reason_id'] : null,
                'resolution' => in_array($meta['resolution'] ?? '', ['refund', 'store_credit', 'replace'], true)
                    ? $meta['resolution']
                    : 'refund',
                'customer_note' => $meta['customer_note'] ?? null,
                'refund_subtotal' => $built['refund_subtotal'],
                'refund_tax' => $built['refund_tax'],
                'refund_total' => $built['refund_total'],
                'restock' => array_key_exists('restock', $meta) ? (bool) $meta['restock'] : ReturnSettings::restock() === 'automatic',
                'requested_at' => now(),
                'approved_at' => $auto ? now() : null,
            ]);

            foreach ($built['items'] as $item) {
                $row->items()->create($item);
            }

            return $row->fresh('items');
        });

        AdminInbox::returnRequested($row);

        return $row;
    }

    public function approve(ShopReturn $return, ?int $adminId = null, ?string $note = null): ShopReturn
    {
        if (! in_array($return->status, [ShopReturn::STATUS_REQUESTED], true)) {
            throw ValidationException::withMessages(['status' => 'This return cannot be approved.']);
        }
        $return->update([
            'status' => ShopReturn::STATUS_APPROVED,
            'approved_at' => now(),
            'approved_by_admin_id' => $adminId,
            'admin_note' => $note ?? $return->admin_note,
        ]);

        return $return->fresh('items');
    }

    public function reject(ShopReturn $return, ?int $adminId = null, ?string $note = null): ShopReturn
    {
        if (! in_array($return->status, [ShopReturn::STATUS_REQUESTED, ShopReturn::STATUS_APPROVED], true)) {
            throw ValidationException::withMessages(['status' => 'This return cannot be rejected.']);
        }
        $return->update([
            'status' => ShopReturn::STATUS_REJECTED,
            'rejected_at' => now(),
            'rejected_by_admin_id' => $adminId,
            'admin_note' => $note ?? $return->admin_note,
        ]);

        return $return->fresh('items');
    }

    public function receive(ShopReturn $return, ?int $adminId = null, ?string $note = null): ShopReturn
    {
        if (! in_array($return->status, [ShopReturn::STATUS_APPROVED], true)) {
            throw ValidationException::withMessages(['status' => 'Approve the return before marking it received.']);
        }
        $return->update([
            'status' => ShopReturn::STATUS_RECEIVED,
            'received_at' => now(),
            'admin_note' => $note ?? $return->admin_note,
        ]);

        return $return->fresh('items');
    }

    public function refund(ShopReturn $return, ?int $adminId = null): ShopReturn
    {
        return DB::transaction(function () use ($return, $adminId) {
            $locked = ShopReturn::query()->whereKey($return->id)->lockForUpdate()->with('items.orderItem.product')->firstOrFail();
            if ($locked->status === ShopReturn::STATUS_REFUNDED) {
                return $locked;
            }
            if (! in_array($locked->status, [ShopReturn::STATUS_APPROVED, ShopReturn::STATUS_RECEIVED], true)) {
                throw ValidationException::withMessages(['status' => 'Approve the return before refunding.']);
            }

            $inventory = app(InventoryService::class);
            $order = Order::query()->whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
            foreach ($locked->items as $line) {
                $item = $line->orderItem;
                if (! $item) {
                    continue;
                }
                $item->returned_qty = (float) $item->returned_qty + (float) $line->qty;
                $item->save();
                if ($locked->restock && $item->product) {
                    if ($item->product->isRental()) {
                        app(\App\Domain\Catalog\Services\RentalProductService::class)->releaseOrderItem($item);
                    } elseif (is_array($item->preorder_data) && ! empty($item->preorder_data['enabled'])) {
                        app(\App\Domain\Catalog\Services\PreorderService::class)->releaseOrderItem($item);
                    } elseif ($item->product->manage_stock || $item->product->isComposite()) {
                        $warehouse = $inventory->warehouseForOrderItem(
                            $item->warehouse_id ? (int) $item->warehouse_id : null,
                            $item->product,
                            (float) $line->qty,
                            $order->number ?? ('ORD-'.$order->id)
                        );
                        $inventory->restock(
                            $item->product,
                            (float) $line->qty,
                            InventoryMovement::ACTION_RETURN,
                            'return:'.$locked->number,
                            $warehouse,
                            $item->combination_id ? (int) $item->combination_id : null,
                            is_array($item->bundle_selection) ? $item->bundle_selection : null,
                        );
                    }
                }
            }

            $order->refunded_total = round((float) $order->refunded_total + (float) $locked->refund_total, 2);
            if ($order->refunded_total + 0.009 >= (float) $order->grand_total) {
                $order->payment_status = 'refunded';
            } else {
                $order->payment_status = 'partially_refunded';
            }
            $order->save();

            if (ReturnSettings::refundMethod() === 'voucher' && $order->customer_id && (float) $locked->refund_total > 0) {
                app(RefundVoucherIssuer::class)->issue($order, (float) $locked->refund_total);
            }

            $locked->update([
                'status' => ShopReturn::STATUS_REFUNDED,
                'refunded_at' => now(),
                'refunded_by_admin_id' => $adminId,
            ]);

            return $locked->fresh('items');
        });
    }

    /** @return array<string, mixed> */
    public function present(ShopReturn $row): array
    {
        $row->loadMissing('items');

        return [
            'id' => (int) $row->id,
            'number' => $row->number,
            'status' => $row->status,
            'reason' => $row->reason,
            'refund_total' => (float) $row->refund_total,
            'items' => $row->items->map(fn ($item) => [
                'id' => (int) $item->id,
                'order_item_id' => (int) $item->order_item_id,
                'qty' => (float) $item->qty,
                'line_total' => (float) $item->line_total,
            ])->values()->all(),
        ];
    }

    protected function assertWindow(Order $order): void
    {
        $days = ReturnSettings::windowDays();
        if ($days <= 0) {
            return;
        }
        if ($order->created_at && $order->created_at->lt(now()->subDays($days))) {
            throw ValidationException::withMessages(['return' => 'The return window for this order has closed.']);
        }
    }

    /**
     * @param  list<array{order_item_id?: int, qty?: float|int}>  $lines
     * @return array{items: list<array<string, mixed>>, refund_subtotal: float, refund_tax: float, refund_total: float}
     */
    protected function buildLines(Order $order, array $lines): array
    {
        $order->loadMissing('items');
        $out = [];
        $subtotal = 0.0;
        $tax = 0.0;
        foreach ($lines as $line) {
            $id = (int) ($line['order_item_id'] ?? 0);
            $qty = (float) ($line['qty'] ?? 0);
            $item = $order->items->firstWhere('id', $id);
            if (! $item instanceof OrderItem || $qty <= 0) {
                continue;
            }
            $qty = min($qty, $item->returnableQty());
            if ($qty <= 0) {
                continue;
            }
            $share = ((float) $item->total) * ($qty / max(0.001, (float) $item->qty));
            $taxShare = ((float) $item->tax_amount) * ($qty / max(0.001, (float) $item->qty));
            $out[] = [
                'order_item_id' => $item->id,
                'qty' => $qty,
                'unit_price' => (float) $item->unit_price,
                'tax_amount' => round($taxShare, 2),
                'line_total' => round($share, 2),
            ];
            $subtotal += $share;
            $tax += $taxShare;
        }

        return [
            'items' => $out,
            'refund_subtotal' => round($subtotal, 2),
            'refund_tax' => round($tax, 2),
            'refund_total' => round($subtotal + $tax, 2),
        ];
    }

    /**
     * @param  list<array{order_item_id?: int, qty?: float|int}>  $lines
     */
    protected function assertFullReturn(Order $order, array $lines): void
    {
        $order->loadMissing('items');
        foreach ($order->items as $item) {
            $returnable = (float) $item->returnableQty();
            if ($returnable <= 0) {
                continue;
            }
            $sent = 0.0;
            foreach ($lines as $line) {
                if ((int) ($line['order_item_id'] ?? 0) === (int) $item->id) {
                    $sent += (float) ($line['qty'] ?? 0);
                }
            }
            if (abs($sent - $returnable) > 0.001) {
                throw ValidationException::withMessages([
                    'items' => 'Partial returns are turned off. Return the full quantity of every item.',
                ]);
            }
        }
    }

    /**
     * @param  array{items: list<array<string, mixed>>, refund_subtotal: float, refund_tax: float, refund_total: float}  $built
     * @return array{items: list<array<string, mixed>>, refund_subtotal: float, refund_tax: float, refund_total: float}
     */
    protected function applyReturnAdjustments(Order $order, array $built): array
    {
        if ($built['items'] === []) {
            return $built;
        }

        $order->loadMissing('items');
        $full = true;
        foreach ($order->items as $item) {
            $returnable = (float) $item->returnableQty();
            if ($returnable <= 0) {
                continue;
            }
            $sent = 0.0;
            foreach ($built['items'] as $line) {
                if ((int) ($line['order_item_id'] ?? 0) === (int) $item->id) {
                    $sent += (float) ($line['qty'] ?? 0);
                }
            }
            if (abs($sent - $returnable) > 0.001) {
                $full = false;
                break;
            }
        }

        $shipping = round((float) $order->shipping_total, 2);
        $orderSubtotal = (float) $order->subtotal;
        $share = $orderSubtotal > 0 ? min(1, $built['refund_subtotal'] / $orderSubtotal) : ($full ? 1 : 0);
        $extra = 0.0;
        if (ReturnSettings::refundShipping() === 'yes') {
            $extra += round($shipping * ($full ? 1 : $share), 2);
        }

        $fee = ReturnSettings::restockingFeePercent();
        if ($fee > 0) {
            $extra -= round($built['refund_subtotal'] * ($fee / 100), 2);
        }

        $built['refund_total'] = max(0, round($built['refund_subtotal'] + $built['refund_tax'] + $extra, 2));

        return $built;
    }

    protected function nextNumber(): string
    {
        return 'RMA-'.str_pad((string) \App\Domain\Settings\DocumentSequence::next('rma', 10001), 5, '0', STR_PAD_LEFT);
    }
}
