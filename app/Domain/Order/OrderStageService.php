<?php

namespace App\Domain\Order;

use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Ops\ShopStats;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderStage;
use App\Domain\Settings\OrderSettings;
use App\Domain\Settings\ShopMailer;
use Illuminate\Support\Facades\DB;

class OrderStageService
{
    public function __construct(
        protected ShopMailer $mailer,
        protected InventoryService $inventory,
    ) {}

    public function findByCode(string $code): ?OrderStage
    {
        return OrderStage::withoutGlobalScope('shop')->where('code', $code)->first();
    }

    public function findById(int $id): ?OrderStage
    {
        return OrderStage::withoutGlobalScope('shop')->whereKey($id)->first();
    }

    public function requireByCode(string $code): OrderStage
    {
        $stage = $this->findByCode($code);
        if (! $stage || ! $stage->is_enabled) {
            abort(422, 'Unknown or disabled order stage.');
        }

        return $stage;
    }

    public function requireById(int $id): OrderStage
    {
        $stage = $this->findById($id);
        if (! $stage || ! $stage->is_enabled) {
            abort(422, 'Unknown or disabled order stage.');
        }

        return $stage;
    }

    /**
     * Resolve a stage from a numeric id (preferred) or legacy code string.
     */
    public function resolve(int|string $stageIdOrCode): OrderStage
    {
        if (is_int($stageIdOrCode) || (is_string($stageIdOrCode) && ctype_digit($stageIdOrCode))) {
            return $this->requireById((int) $stageIdOrCode);
        }

        return $this->requireByCode((string) $stageIdOrCode);
    }

    /**
     * Apply a journey stage to an order: status, payment, stock, mail, history.
     *
     * @param  int|string  $stageIdOrCode  Prefer order_stages.id; code still accepted for APIs/tests.
     */
    public function apply(Order $order, int|string $stageIdOrCode, ?string $comment = null): Order
    {
        OrderStageSchema::ensure();
        PaymentStatusCatalog::ensure();
        $stage = $this->resolve($stageIdOrCode);
        $previous = (string) $order->status;

        return DB::transaction(function () use ($order, $stage, $comment, $previous) {
            $previousPayment = (string) $order->payment_status;
            $updates = [
                'status' => $stage->code,
                'order_stage_id' => $stage->id,
            ];

            if ($stage->settle_payment && $order->payment_status !== 'refunded') {
                $updates['payment_status'] = 'paid';
                if ($paidId = PaymentStatusCatalog::idForCode('paid')) {
                    $updates['payment_status_id'] = $paidId;
                }
            }

            if ($stage->lane === 'void') {
                $updates['status'] = $stage->code;
                $updates['order_stage_id'] = $stage->id;
            }

            if ($stage->lane === 'reverse' || $stage->unlock_credit_slip) {
                $updates['payment_status'] = 'refunded';
                if ($refundedId = PaymentStatusCatalog::idForCode('refunded')) {
                    $updates['payment_status_id'] = $refundedId;
                }
            }

            $order->updateTrusted($updates);

            if (OrderSettings::shouldDeductForStatus($stage->code) && $previous !== $stage->code) {
                $this->deductOrderStock($order);
            }

            if (in_array($stage->code, ['processing', 'shipped', 'completed', 'delivered'], true) && $previous !== $stage->code) {
                $order->loadMissing(['items.product']);
                $preorders = app(\App\Domain\Catalog\Services\PreorderService::class);
                foreach ($order->items as $item) {
                    if (! is_array($item->preorder_data) || empty($item->preorder_data['enabled'])) {
                        continue;
                    }
                    if (in_array($stage->code, ['shipped', 'completed', 'delivered'], true)) {
                        $preorders->markItemFulfilled($item);
                    } else {
                        $preorders->autoAdvanceItem($item);
                        if (($item->fresh()->preorder_data['status'] ?? '') === 'awaiting_release') {
                            $preorders->markItemReady($item);
                        }
                    }
                }
            }

            if ($stage->stock_move === 'return' && $previous !== $stage->code) {
                $this->restockOrder($order);
            }

            $order->statusHistories()->create([
                'status' => $stage->code,
                'order_stage_id' => $stage->id,
                'comment' => $comment ?: ('Moved to '.$stage->name),
            ]);

            if ($stage->notify_buyer) {
                $template = $stage->mail_template ?: 'order_status';
                $this->mailer->sendTemplate($template, [
                    'order_number' => $order->number,
                    'status' => $stage->buyer_label ?: $stage->name,
                    'customer_name' => $order->billing_address['first_name'] ?? $order->customer?->name ?? 'Customer',
                    'customer_email' => $order->email,
                    'grand_total' => (string) $order->grand_total,
                ], $order->email);
            }

            $fresh = $order->fresh(['statusHistories', 'items.product', 'paymentMethod', 'shippingMethod', 'customer']);
            if ($previousPayment !== 'paid' && $fresh && $fresh->payment_status === 'paid') {
                app(\App\Domain\Catalog\Services\GiftCardService::class)->issueForPaidOrder($fresh);
            }

            ShopStats::flushOrders();

            return $fresh;
        });
    }

    protected function restockOrder(Order $order): void
    {
        $order->loadMissing(['items.product']);
        $ref = $order->number ?? ('ORD-'.$order->id);
        $rentals = app(\App\Domain\Catalog\Services\RentalProductService::class);
        foreach ($order->items as $item) {
            if (! $item->product) {
                continue;
            }
            if ($item->product->isRental()) {
                $rentals->releaseOrderItem($item);

                continue;
            }
            if (is_array($item->preorder_data) && ! empty($item->preorder_data['enabled'])) {
                app(\App\Domain\Catalog\Services\PreorderService::class)->releaseOrderItem($item);

                continue;
            }
            $selection = is_array($item->bundle_selection) ? $item->bundle_selection : null;
            $warehouse = $this->inventory->warehouseForOrderItem(
                $item->warehouse_id ? (int) $item->warehouse_id : null,
                $item->product,
                (float) $item->qty,
                $ref
            );
            $this->inventory->release(
                $item->product,
                (int) $item->qty,
                $ref,
                $warehouse,
                $item->combination_id ? (int) $item->combination_id : null,
                $selection,
            );
            if (! OrderSettings::restockOnCancellation()) {
                continue;
            }
            $this->inventory->restock(
                $item->product,
                (int) $item->qty,
                \App\Domain\Catalog\Models\InventoryMovement::ACTION_RESTOCK,
                $ref,
                $warehouse,
                $item->combination_id ? (int) $item->combination_id : null,
                $selection,
            );
        }
    }

    protected function deductOrderStock(Order $order): void
    {
        $order->loadMissing(['items.product']);
        $ref = $order->number ?? ('ORD-'.$order->id);
        foreach ($order->items as $item) {
            $product = $item->product;
            if (! $product || $product->isGiftCard() || $product->isRental()) {
                continue;
            }
            if (is_array($item->preorder_data) && ! empty($item->preorder_data['enabled'])) {
                $preorders = app(\App\Domain\Catalog\Services\PreorderService::class);
                if (in_array($order->status, ['shipped', 'completed', 'delivered'], true)) {
                    $preorders->markItemFulfilled($item);
                } else {
                    $preorders->autoAdvanceItem($item);
                    $preorders->markItemReady($item);
                }
                continue;
            }
            if (! $product->manage_stock && ! $product->isComposite()) {
                continue;
            }
            $selection = is_array($item->bundle_selection) ? $item->bundle_selection : null;
            $warehouse = $this->inventory->warehouseForOrderItem(
                $item->warehouse_id ? (int) $item->warehouse_id : null,
                $product,
                (float) $item->qty,
                $ref
            );
            $this->inventory->release(
                $product,
                (int) $item->qty,
                $ref,
                $warehouse,
                $item->combination_id ? (int) $item->combination_id : null,
                $selection,
            );
            $this->inventory->deduct(
                $product,
                (int) $item->qty,
                \App\Domain\Catalog\Models\InventoryMovement::ACTION_SALE,
                $ref,
                $warehouse,
                $item->combination_id ? (int) $item->combination_id : null,
                $selection,
            );
        }
    }
}
