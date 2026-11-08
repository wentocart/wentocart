<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Catalog\Models\InventoryMovement;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerEarning;
use App\Domain\Marketplace\Models\SellerLedgerEntry;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\Models\SellerOrderItem;
use App\Domain\Marketplace\Models\SellerReturn;
use App\Domain\Marketplace\Models\SellerReturnItem;
use App\Domain\Order\Models\Order;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Marketplace\Events\MarketplaceEvent;
use App\Domain\Marketplace\Services\MarketplaceLifecycleBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Phase 15 — cancellation (partial), returns, and refunds.
 *
 * Financial rule: never mutate historical earning/ledger amounts.
 * Partial refunds accumulate refunded_* on SellerEarning and post ledger debits.
 */
class SellerReturnService
{
    /**
     * Customer requests a return for eligible items on one seller slice.
     *
     * @param  list<array{seller_order_item_id: int, qty: int}>  $lines
     */
    public function requestReturn(
        Customer $customer,
        Order $order,
        SellerOrder $sellerOrder,
        array $lines,
        array $meta = [],
    ): SellerReturn {
        if ((int) $order->customer_id !== (int) $customer->id) {
            abort(403, 'This order does not belong to the current customer.');
        }
        if ((int) $sellerOrder->order_id !== (int) $order->id) {
            abort(422, 'Seller order does not belong to this parent order.');
        }

        return DB::transaction(function () use ($customer, $order, $sellerOrder, $lines, $meta) {
            $sellerOrder = SellerOrder::query()->whereKey($sellerOrder->id)->lockForUpdate()->firstOrFail();
            $this->assertReturnEligible($order, $sellerOrder);

            $built = $this->buildReturnLines($sellerOrder, $lines);
            if ($built['items'] === []) {
                throw ValidationException::withMessages(['items' => 'Select at least one returnable item.']);
            }

            $row = SellerReturn::query()->create([
                'order_id' => $order->id,
                'seller_order_id' => $sellerOrder->id,
                'seller_id' => $sellerOrder->seller_id,
                'customer_id' => $customer->id,
                'number' => $this->nextNumber(),
                'status' => SellerReturn::STATUS_REQUESTED,
                'reason' => isset($meta['reason']) ? trim((string) $meta['reason']) : null,
                'customer_note' => $meta['customer_note'] ?? null,
                'refund_subtotal' => $built['refund_subtotal'],
                'refund_tax' => $built['refund_tax'],
                'refund_total' => $built['refund_total'],
                'commission_reversed' => $built['commission_reversed'],
                'seller_net_reversed' => $built['seller_net_reversed'],
                'restock' => array_key_exists('restock', $meta) ? (bool) $meta['restock'] : true,
                'requested_at' => now(),
            ]);

            foreach ($built['items'] as $item) {
                $row->items()->create($item);
            }

            Log::info('marketplace.return.requested', [
                'seller_return_id' => $row->id,
                'order_id' => $order->id,
                'seller_order_id' => $sellerOrder->id,
                'customer_id' => $customer->id,
            ]);

            $fresh = $row->fresh('items');
            app(MarketplaceLifecycleBroker::class)->dispatch(MarketplaceEvent::returnRequested($fresh, $customer));

            return $fresh;
        });
    }

    public function approve(SellerReturn $return, ?Seller $seller = null, ?int $adminId = null, ?string $note = null): SellerReturn
    {
        $fresh = $this->transition($return, SellerReturn::STATUS_APPROVED, $seller, $adminId, [
            'approved_at' => now(),
            'approved_by_admin_id' => $adminId,
            'seller_note' => $note ?? $return->seller_note,
            'admin_note' => $adminId ? ($note ?? $return->admin_note) : $return->admin_note,
        ]);
        app(MarketplaceLifecycleBroker::class)->dispatch(
            MarketplaceEvent::returnApproved($fresh, $adminId, $seller?->id),
        );

        return $fresh;
    }

    public function reject(SellerReturn $return, ?Seller $seller = null, ?int $adminId = null, ?string $note = null): SellerReturn
    {
        return $this->transition($return, SellerReturn::STATUS_REJECTED, $seller, $adminId, [
            'rejected_at' => now(),
            'rejected_by_admin_id' => $adminId,
            'seller_note' => $note ?? $return->seller_note,
            'admin_note' => $adminId ? ($note ?? $return->admin_note) : $return->admin_note,
        ]);
    }

    public function markReceived(SellerReturn $return, ?Seller $seller = null, ?int $adminId = null): SellerReturn
    {
        return $this->transition($return, SellerReturn::STATUS_RECEIVED, $seller, $adminId, [
            'received_at' => now(),
        ]);
    }

    /**
     * Finalize refund: payment state, earnings adjustments, ledger debit, optional restock.
     * Idempotent when already refunded.
     */
    public function refund(SellerReturn $return, ?Seller $seller = null, ?int $adminId = null, ?string $note = null): SellerReturn
    {
        return DB::transaction(function () use ($return, $seller, $adminId, $note) {
            $locked = SellerReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();
            $this->assertActor($locked, $seller, $adminId);

            if ($locked->status === SellerReturn::STATUS_REFUNDED) {
                return $locked->fresh(['items', 'sellerOrder', 'order']);
            }

            if (! in_array($locked->status, [SellerReturn::STATUS_APPROVED, SellerReturn::STATUS_RECEIVED], true)) {
                if ($adminId && $locked->status === SellerReturn::STATUS_REQUESTED) {
                    // Admin may approve+refund in one step.
                    if (! $locked->canTransitionTo(SellerReturn::STATUS_APPROVED)) {
                        throw ValidationException::withMessages(['status' => 'Return cannot be approved.']);
                    }
                    $locked->fill([
                        'status' => SellerReturn::STATUS_APPROVED,
                        'approved_at' => now(),
                        'approved_by_admin_id' => $adminId,
                    ])->save();
                } else {
                    throw ValidationException::withMessages([
                        'status' => 'Return must be approved (or received) before refund.',
                    ]);
                }
            }

            if (! $locked->canTransitionTo(SellerReturn::STATUS_REFUNDED)
                && $locked->status !== SellerReturn::STATUS_APPROVED
                && $locked->status !== SellerReturn::STATUS_RECEIVED) {
                throw ValidationException::withMessages(['status' => 'Invalid return refund transition.']);
            }

            $locked->loadMissing(['items', 'sellerOrder.items', 'order']);
            $this->includeShippingIfFullyReturning($locked);
            $this->applyFinancialReversal($locked);
            $this->applyInventoryRestock($locked);
            $this->syncSellerOrderAfterRefund($locked);
            $this->applyPaymentState($locked);

            $locked->fill([
                'status' => SellerReturn::STATUS_REFUNDED,
                'refunded_at' => now(),
                'refunded_by_admin_id' => $adminId,
                'admin_note' => $adminId ? ($note ?? $locked->admin_note) : $locked->admin_note,
                'seller_note' => $seller && ! $adminId ? ($note ?? $locked->seller_note) : $locked->seller_note,
            ])->save();

            Log::info('marketplace.return.refunded', [
                'seller_return_id' => $locked->id,
                'refund_total' => (float) $locked->refund_total,
                'seller_net_reversed' => (float) $locked->seller_net_reversed,
            ]);

            $fresh = $locked->fresh(['items', 'sellerOrder', 'order']);
            app(MarketplaceLifecycleBroker::class)->dispatch(
                MarketplaceEvent::refundProcessed($fresh, $adminId, $seller?->id),
            );

            return $fresh;
        });
    }

    /**
     * Partial cancel of line quantities on a pre-fulfillment seller order.
     *
     * @param  list<array{seller_order_item_id: int, qty: int}>  $lines
     */
    public function cancelItems(Seller $seller, SellerOrder $sellerOrder, array $lines): SellerOrder
    {
        if ((int) $sellerOrder->seller_id !== (int) $seller->id) {
            abort(403, 'This order does not belong to this seller.');
        }

        return DB::transaction(function () use ($seller, $sellerOrder, $lines) {
            $locked = SellerOrder::query()->whereKey($sellerOrder->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['pending', 'processing'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Only pending/processing seller orders can cancel items.',
                ]);
            }

            $locked->loadMissing('items');
            $cancelledAny = false;
            $allGone = true;

            foreach ($lines as $line) {
                $itemId = (int) ($line['seller_order_item_id'] ?? 0);
                $qty = (int) ($line['qty'] ?? 0);
                if ($itemId < 1 || $qty < 1) {
                    continue;
                }
                $item = $locked->items->firstWhere('id', $itemId);
                if (! $item) {
                    throw ValidationException::withMessages([
                        'items' => 'Seller order item '.$itemId.' was not found on this order.',
                    ]);
                }
                $open = max(0, (int) $item->qty - (int) $item->cancelled_qty - (int) $item->returned_qty);
                if ($qty > $open) {
                    throw ValidationException::withMessages([
                        'items' => 'Cannot cancel more than remaining quantity for '.$item->name.'.',
                    ]);
                }

                $item->update(['cancelled_qty' => (int) $item->cancelled_qty + $qty]);
                $this->reverseEarningPortion($locked, $item, $qty, 'cancel');
                $this->restockItemQty($item, $qty, 'cancel:seller_order_item:'.$item->id.':'.((int) $item->cancelled_qty));
                $cancelledAny = true;
            }

            if (! $cancelledAny) {
                throw ValidationException::withMessages(['items' => 'Select at least one cancellable item.']);
            }

            $locked->load('items');
            foreach ($locked->items as $item) {
                if (((int) $item->qty - (int) $item->cancelled_qty - (int) $item->returned_qty) > 0) {
                    $allGone = false;
                    break;
                }
            }

            if ($allGone) {
                SellerEarning::query()
                    ->where('seller_order_id', $locked->id)
                    ->whereIn('status', [SellerEarning::STATUS_PENDING, SellerEarning::STATUS_AVAILABLE])
                    ->update(['status' => SellerEarning::STATUS_REFUNDED]);

                $locked->update(['status' => 'cancelled']);

                $core = $locked->order;
                if ($core) {
                    $core->loadMissing('sellerOrders');
                    $activeOthers = $core->sellerOrders
                        ->where('id', '!=', $locked->id)
                        ->whereNotIn('status', ['cancelled', 'refunded']);
                    if ($activeOthers->isEmpty()) {
                        $core->update(['status' => 'cancelled']);
                    }
                }

                return $locked->fresh(['items', 'earnings']);
            }

            return $locked->fresh(['items', 'earnings']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function present(SellerReturn $return): array
    {
        $return->loadMissing(['items', 'seller:id,shop_name,shop_slug', 'sellerOrder:id,number,status']);

        return [
            'id' => (int) $return->id,
            'number' => (string) $return->number,
            'order_id' => (int) $return->order_id,
            'seller_order_id' => (int) $return->seller_order_id,
            'seller_id' => (int) $return->seller_id,
            'shop_name' => $return->seller?->shop_name,
            'status' => (string) $return->status,
            'reason' => $return->reason,
            'customer_note' => $return->customer_note,
            'seller_note' => $return->seller_note,
            'admin_note' => $return->admin_note,
            'refund_subtotal' => (float) $return->refund_subtotal,
            'refund_tax' => (float) $return->refund_tax,
            'refund_total' => (float) $return->refund_total,
            'commission_reversed' => (float) $return->commission_reversed,
            'seller_net_reversed' => (float) $return->seller_net_reversed,
            'restock' => (bool) $return->restock,
            'requested_at' => $return->requested_at?->toIso8601String(),
            'approved_at' => $return->approved_at?->toIso8601String(),
            'rejected_at' => $return->rejected_at?->toIso8601String(),
            'received_at' => $return->received_at?->toIso8601String(),
            'refunded_at' => $return->refunded_at?->toIso8601String(),
            'items' => $return->items->map(fn (SellerReturnItem $item) => [
                'id' => (int) $item->id,
                'seller_order_item_id' => (int) $item->seller_order_item_id,
                'order_item_id' => $item->order_item_id ? (int) $item->order_item_id : null,
                'product_id' => $item->product_id ? (int) $item->product_id : null,
                'qty' => (int) $item->qty,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
                'tax_amount' => (float) $item->tax_amount,
                'gross_amount' => (float) $item->gross_amount,
                'commission_amount' => (float) $item->commission_amount,
                'net_amount' => (float) $item->net_amount,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function transition(
        SellerReturn $return,
        string $to,
        ?Seller $seller,
        ?int $adminId,
        array $extra = [],
    ): SellerReturn {
        return DB::transaction(function () use ($return, $to, $seller, $adminId, $extra) {
            $locked = SellerReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();
            $this->assertActor($locked, $seller, $adminId);

            if ($locked->status === $to) {
                return $locked->fresh('items');
            }

            if (! $locked->canTransitionTo($to)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot change return from {$locked->status} to {$to}.",
                ]);
            }

            $locked->fill(array_merge(['status' => $to], $extra))->save();

            return $locked->fresh('items');
        });
    }

    protected function assertActor(SellerReturn $return, ?Seller $seller, ?int $adminId): void
    {
        if ($adminId) {
            return;
        }
        if (! $seller || (int) $seller->id !== (int) $return->seller_id) {
            abort(403, 'This return does not belong to this seller.');
        }
    }

    protected function assertReturnEligible(Order $order, SellerOrder $sellerOrder): void
    {
        if (in_array($order->payment_status, ['pending', 'failed'], true)) {
            throw ValidationException::withMessages(['order' => 'Unpaid orders cannot be returned.']);
        }
        if (in_array($sellerOrder->status, ['cancelled', 'refunded', 'pending'], true)) {
            throw ValidationException::withMessages([
                'order' => 'This seller order is not eligible for return yet.',
            ]);
        }

        $fulfilled = in_array($sellerOrder->status, ['shipped', 'completed'], true)
            || Shipment::query()
                ->where('seller_order_id', $sellerOrder->id)
                ->whereIn('status', [Shipment::STATUS_SHIPPED, Shipment::STATUS_DELIVERED])
                ->exists();

        if (! $fulfilled) {
            throw ValidationException::withMessages([
                'order' => 'Returns are only available after the seller has shipped.',
            ]);
        }

        $window = MarketplaceSettings::returnWindowDays();
        if ($window > 0) {
            $anchor = Shipment::query()
                ->where('seller_order_id', $sellerOrder->id)
                ->whereNotNull('delivered_at')
                ->orderByDesc('delivered_at')
                ->value('delivered_at');
            if (! $anchor) {
                $anchor = Shipment::query()
                    ->where('seller_order_id', $sellerOrder->id)
                    ->whereNotNull('shipped_at')
                    ->orderByDesc('shipped_at')
                    ->value('shipped_at');
            }
            if ($anchor && now()->gt(\Illuminate\Support\Carbon::parse($anchor)->addDays($window))) {
                throw ValidationException::withMessages([
                    'order' => 'The return window has expired.',
                ]);
            }
        }
    }

    /**
     * @param  list<array{seller_order_item_id: int, qty: int}>  $lines
     * @return array{
     *   items: list<array<string, mixed>>,
     *   refund_subtotal: float,
     *   refund_tax: float,
     *   refund_total: float,
     *   commission_reversed: float,
     *   seller_net_reversed: float
     * }
     */
    protected function buildReturnLines(SellerOrder $sellerOrder, array $lines): array
    {
        $sellerOrder->loadMissing('items');
        $openRequests = SellerReturnItem::query()
            ->whereHas('sellerReturn', function ($q) use ($sellerOrder) {
                $q->where('seller_order_id', $sellerOrder->id)
                    ->whereNotIn('status', [
                        SellerReturn::STATUS_REJECTED,
                        SellerReturn::STATUS_CANCELLED,
                        SellerReturn::STATUS_REFUNDED,
                    ]);
            })
            ->get()
            ->groupBy('seller_order_item_id')
            ->map(fn ($rows) => (int) $rows->sum('qty'));

        $earningsByOrderItem = SellerEarning::query()
            ->where('seller_order_id', $sellerOrder->id)
            ->whereIn('order_item_id', $sellerOrder->items->pluck('order_item_id')->filter()->values())
            ->get()
            ->keyBy('order_item_id');

        $items = [];
        $refundSubtotal = 0.0;
        $refundTax = 0.0;
        $commission = 0.0;
        $net = 0.0;

        foreach ($lines as $line) {
            $itemId = (int) ($line['seller_order_item_id'] ?? 0);
            $qty = (int) ($line['qty'] ?? 0);
            if ($itemId < 1 || $qty < 1) {
                continue;
            }
            $item = $sellerOrder->items->firstWhere('id', $itemId);
            if (! $item) {
                throw ValidationException::withMessages([
                    'items' => 'Item '.$itemId.' is not on this seller order.',
                ]);
            }

            $pending = (int) ($openRequests[$itemId] ?? 0);
            $available = max(0, $item->returnableQty() - $pending);
            if ($qty > $available) {
                throw ValidationException::withMessages([
                    'items' => 'Only '.$available.' unit(s) of '.$item->name.' can be returned.',
                ]);
            }

            $ratio = ((int) $item->qty) > 0 ? ($qty / (int) $item->qty) : 0;
            $lineTotal = round((float) $item->total * $ratio, 2);
            $tax = round((float) $item->tax_amount * $ratio, 2);

            $earning = $earningsByOrderItem->get($item->order_item_id);

            $grossShare = $earning
                ? round(min($earning->remainingGross(), (float) $earning->gross_amount * $ratio), 2)
                : $lineTotal;
            $commissionShare = $earning
                ? round(min($earning->remainingCommission(), (float) $earning->commission_amount * $ratio), 2)
                : 0.0;
            $netShare = $earning
                ? round(min($earning->remainingNet(), (float) $earning->net_amount * $ratio), 2)
                : round(max(0, $grossShare - $commissionShare), 2);

            $items[] = [
                'seller_order_item_id' => $item->id,
                'order_item_id' => $item->order_item_id,
                'product_id' => $item->product_id,
                'qty' => $qty,
                'unit_price' => (float) $item->unit_price,
                'line_total' => $lineTotal,
                'tax_amount' => $tax,
                'gross_amount' => $grossShare,
                'commission_amount' => $commissionShare,
                'net_amount' => $netShare,
            ];

            $refundSubtotal = round($refundSubtotal + $lineTotal, 2);
            $refundTax = round($refundTax + $tax, 2);
            $commission = round($commission + $commissionShare, 2);
            $net = round($net + $netShare, 2);
        }

        return [
            'items' => $items,
            'refund_subtotal' => $refundSubtotal,
            'refund_tax' => $refundTax,
            'refund_total' => round($refundSubtotal + $refundTax, 2),
            'commission_reversed' => $commission,
            'seller_net_reversed' => $net,
        ];
    }

    protected function applyFinancialReversal(SellerReturn $return): void
    {
        $return->loadMissing(['items', 'sellerOrder']);
        $sellerOrder = $return->sellerOrder;
        if (! $sellerOrder) {
            return;
        }

        foreach ($return->items as $returnItem) {
            $soi = SellerOrderItem::query()->whereKey($returnItem->seller_order_item_id)->lockForUpdate()->first();
            if (! $soi) {
                continue;
            }
            $soi->update([
                'returned_qty' => (int) $soi->returned_qty + (int) $returnItem->qty,
            ]);

            $earning = SellerEarning::query()
                ->where('seller_order_id', $sellerOrder->id)
                ->where('order_item_id', $soi->order_item_id)
                ->lockForUpdate()
                ->first();
            if (! $earning) {
                continue;
            }

            $newGross = round((float) $earning->refunded_gross + (float) $returnItem->gross_amount, 2);
            $newCommission = round((float) $earning->refunded_commission + (float) $returnItem->commission_amount, 2);
            $newNet = round((float) $earning->refunded_net + (float) $returnItem->net_amount, 2);

            $updates = [
                'refunded_gross' => min((float) $earning->gross_amount, $newGross),
                'refunded_commission' => min((float) $earning->commission_amount, $newCommission),
                'refunded_net' => min((float) $earning->net_amount, $newNet),
            ];

            if ($updates['refunded_net'] + 0.009 >= (float) $earning->net_amount
                && in_array($earning->status, [SellerEarning::STATUS_PENDING, SellerEarning::STATUS_AVAILABLE], true)) {
                $updates['status'] = SellerEarning::STATUS_REFUNDED;
            }

            $earning->update($updates);
        }

        app(SellerLedgerService::class)->debitReturnAdjustment($return);
    }

    protected function applyInventoryRestock(SellerReturn $return): void
    {
        if (! $return->restock) {
            return;
        }

        $inventory = app(InventoryService::class);
        $return->loadMissing('items');

        foreach ($return->items as $returnItem) {
            if (! $returnItem->product_id) {
                continue;
            }
            $product = Product::query()->find($returnItem->product_id);
            if (! $product || ! $product->manage_stock) {
                continue;
            }
            $reference = 'return:'.$return->id.':item:'.$returnItem->id;
            $exists = InventoryMovement::query()->where('reference', $reference)->exists();
            if ($exists) {
                continue;
            }
            $inventory->restock(
                $product,
                (int) $returnItem->qty,
                InventoryMovement::ACTION_RETURN,
                $reference
            );
        }
    }

    protected function applyPaymentState(SellerReturn $return): void
    {
        $order = Order::query()->whereKey($return->order_id)->lockForUpdate()->first();
        if (! $order) {
            return;
        }

        $refundAmount = round((float) $return->refund_total, 2);
        if ($refundAmount <= 0) {
            return;
        }

        app(\App\Domain\Order\Services\PaymentRefundService::class)->refundAmount(
            $order,
            $refundAmount,
            'Marketplace return '.$return->number.' refunded '.$refundAmount,
        );

        $order->refresh();

        $activeSlices = SellerOrder::query()
            ->where('order_id', $order->id)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->count();

        if ($activeSlices === 0 && (float) $order->refunded_total > 0.009) {
            Order::withoutEvents(fn () => $order->updateTrusted(['status' => 'refunded']));
        }
    }

    /**
     * When this return completes the seller slice, include allocated shipping in customer refund.
     */
    protected function includeShippingIfFullyReturning(SellerReturn $return): void
    {
        $sellerOrder = $return->sellerOrder;
        if (! $sellerOrder) {
            return;
        }

        $sellerOrder->loadMissing('items');
        $return->loadMissing('items');

        $returningByItem = $return->items->groupBy('seller_order_item_id')
            ->map(fn ($rows) => (int) $rows->sum('qty'));

        foreach ($sellerOrder->items as $item) {
            $willReturn = (int) $item->returned_qty + (int) ($returningByItem[$item->id] ?? 0) + (int) $item->cancelled_qty;
            if ($willReturn < (int) $item->qty) {
                return;
            }
        }

        $shipping = round((float) $sellerOrder->shipping_total, 2);
        if ($shipping <= 0) {
            return;
        }

        $return->update([
            'refund_total' => round((float) $return->refund_total + $shipping, 2),
        ]);
        $return->refresh();
    }

    protected function syncSellerOrderAfterRefund(SellerReturn $return): void
    {
        $sellerOrder = SellerOrder::query()->whereKey($return->seller_order_id)->lockForUpdate()->first();
        if (! $sellerOrder) {
            return;
        }
        $sellerOrder->load('items');
        $allReturned = $sellerOrder->items->every(
            fn (SellerOrderItem $item) => ((int) $item->returned_qty + (int) $item->cancelled_qty) >= (int) $item->qty
        );
        if ($allReturned && $sellerOrder->status !== 'cancelled') {
            $sellerOrder->updateTrusted([
                'status' => 'refunded',
                'payment_status' => 'refunded',
            ]);
        }
    }

    /**
     * Reverse a proportional earning for partial cancel (pre-fulfillment).
     */
    protected function reverseEarningPortion(SellerOrder $sellerOrder, SellerOrderItem $item, int $qty, string $kind): void
    {
        $earning = SellerEarning::query()
            ->where('seller_order_id', $sellerOrder->id)
            ->where('order_item_id', $item->order_item_id)
            ->lockForUpdate()
            ->first();
        if (! $earning) {
            return;
        }

        $ratio = ((int) $item->qty) > 0 ? ($qty / (int) $item->qty) : 0;
        $gross = round(min($earning->remainingGross(), (float) $earning->gross_amount * $ratio), 2);
        $commission = round(min($earning->remainingCommission(), (float) $earning->commission_amount * $ratio), 2);
        $net = round(min($earning->remainingNet(), (float) $earning->net_amount * $ratio), 2);

        $updates = [
            'refunded_gross' => round((float) $earning->refunded_gross + $gross, 2),
            'refunded_commission' => round((float) $earning->refunded_commission + $commission, 2),
            'refunded_net' => round((float) $earning->refunded_net + $net, 2),
        ];
        if ($updates['refunded_net'] + 0.009 >= (float) $earning->net_amount
            && in_array($earning->status, [SellerEarning::STATUS_PENDING, SellerEarning::STATUS_AVAILABLE], true)) {
            $updates['status'] = SellerEarning::STATUS_REFUNDED;
        }
        $earning->update($updates);

        if ($net > 0.009 && $kind === 'cancel') {
            // Ledger debit only when earning credit already posted.
            $creditExists = SellerLedgerEntry::query()
                ->where('seller_order_id', $sellerOrder->id)
                ->where('direction', SellerLedgerEntry::DIRECTION_CREDIT)
                ->exists();
            if ($creditExists) {
                app(SellerLedgerService::class)->debitPartialAdjustment(
                    $sellerOrder,
                    $net,
                    'cancel_adjustment:seller_order_item:'.$item->id.':qty:'.$qty,
                    SellerLedgerEntry::TYPE_CANCEL_ADJUSTMENT,
                    'Partial cancel for '.$item->name,
                );
            }
        }
    }

    protected function restockItemQty(SellerOrderItem $item, int $qty, string $reference): void
    {
        if (! $item->product_id || $qty < 1) {
            return;
        }
        $product = Product::query()->find($item->product_id);
        if (! $product || ! $product->manage_stock) {
            return;
        }
        if (InventoryMovement::query()->where('reference', $reference)->exists()) {
            return;
        }
        $inventory = app(InventoryService::class);
        $item->loadMissing('orderItem');
        $warehouse = $inventory->warehouseForOrderItem(
            $item->orderItem?->warehouse_id ? (int) $item->orderItem->warehouse_id : null,
            $product,
            (float) $qty,
            $reference
        );
        $inventory->restock($product, $qty, InventoryMovement::ACTION_RESTOCK, $reference, $warehouse);
    }

    protected function nextNumber(): string
    {
        do {
            $number = 'RTN-'.now()->format('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        } while (SellerReturn::query()->where('number', $number)->exists());

        return $number;
    }
}
