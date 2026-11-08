<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\MarketplaceFulfilmentCarriers;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Order\Models\Order;
use App\Domain\Settings\ShopMailer;
use App\Domain\Marketplace\Events\MarketplaceEvent;
use App\Domain\Marketplace\Services\MarketplaceLifecycleBroker;
use App\Domain\Shipping\Models\Shipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Seller-order fulfillment: one active shipment per SellerOrder (Phase 08).
 * Parent order status aggregates all SellerOrders — never marks delivered when only one seller finishes.
 */
class SellerFulfillmentService
{
    /**
     * Create (or return) the active shipment for a SellerOrder, shipping all of its items.
     *
     * @param  array<string, mixed>  $data
     */
    public function createShipment(Seller $seller, SellerOrder $sellerOrder, array $data = []): Shipment
    {
        $this->assertOwns($seller, $sellerOrder);

        if (in_array($sellerOrder->status, ['cancelled', 'refunded'], true)) {
            throw ValidationException::withMessages([
                'shipment' => 'This seller order cannot be shipped.',
            ]);
        }
        $this->assertCarrierCode($data['carrier'] ?? null);

        return DB::transaction(function () use ($seller, $sellerOrder, $data) {
            $sellerOrder = SellerOrder::query()->whereKey($sellerOrder->id)->lockForUpdate()->firstOrFail();
            $wasPending = $sellerOrder->status === 'pending';
            $this->assertOwns($seller, $sellerOrder);

            $existing = Shipment::query()
                ->where('seller_order_id', $sellerOrder->id)
                ->where('status', '!=', Shipment::STATUS_CANCELLED)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing->load('items');
            }

            $sellerOrder->loadMissing(['items', 'order']);
            $parent = $sellerOrder->order;
            if (! $parent) {
                throw ValidationException::withMessages(['shipment' => 'Parent order is missing.']);
            }

            $shipment = Shipment::query()->create([
                'order_id' => $parent->id,
                'seller_order_id' => $sellerOrder->id,
                'seller_id' => $seller->id,
                'shipping_method_id' => $sellerOrder->shipping_method_id
                    ? (int) $sellerOrder->shipping_method_id
                    : ($parent->shipping_method_id ? (int) $parent->shipping_method_id : null),
                'shipping_cost' => round((float) $sellerOrder->shipping_total, 2),
                'status' => Shipment::STATUS_PROCESSING,
                'carrier' => isset($data['carrier']) ? trim((string) $data['carrier']) : null,
                'tracking_number' => isset($data['tracking_number']) ? trim((string) $data['tracking_number']) : null,
                'tracking_url' => isset($data['tracking_url']) ? trim((string) $data['tracking_url']) : null,
                'shipping_address' => $sellerOrder->shipping_address ?: $parent->shipping_address,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($sellerOrder->items as $item) {
                $shipment->items()->create([
                    'order_item_id' => $item->order_item_id,
                    'seller_order_item_id' => $item->id,
                    'qty' => (int) $item->qty,
                ]);
            }

            if ($sellerOrder->status === 'pending') {
                $sellerOrder->updateTrusted(['status' => 'processing']);
            }

            $this->syncParentOrderStatus((int) $parent->id);

            Log::info('marketplace.fulfillment.shipment_created', [
                'shipment_id' => $shipment->id,
                'seller_order_id' => $sellerOrder->id,
                'seller_id' => $seller->id,
                'order_id' => $parent->id,
            ]);

            $freshShipment = $shipment->fresh('items');
            if ($wasPending && $sellerOrder->status === 'processing') {
                app(MarketplaceLifecycleBroker::class)->dispatch(
                    MarketplaceEvent::sellerOrderConfirmed($sellerOrder->fresh(), (int) $seller->id),
                );
            }

            return $freshShipment;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateTracking(Seller $seller, Shipment $shipment, array $data): Shipment
    {
        $shipment = $this->ownedShipment($seller, $shipment);

        if (in_array($shipment->status, [Shipment::STATUS_DELIVERED, Shipment::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages([
                'tracking' => 'Tracking cannot be changed for this shipment.',
            ]);
        }
        if (array_key_exists('carrier', $data)) {
            $this->assertCarrierCode($data['carrier']);
        }

        $shipment->update([
            'carrier' => array_key_exists('carrier', $data)
                ? (trim((string) $data['carrier']) ?: null)
                : $shipment->carrier,
            'tracking_number' => array_key_exists('tracking_number', $data)
                ? (trim((string) $data['tracking_number']) ?: null)
                : $shipment->tracking_number,
            'tracking_url' => array_key_exists('tracking_url', $data)
                ? (trim((string) $data['tracking_url']) ?: null)
                : $shipment->tracking_url,
            'notes' => array_key_exists('notes', $data) ? ($data['notes'] ?: null) : $shipment->notes,
        ]);

        return $shipment->fresh('items');
    }

    public function markReady(Seller $seller, Shipment $shipment): Shipment
    {
        return $this->transition($seller, $shipment, Shipment::STATUS_READY_TO_SHIP);
    }

    public function markShipped(Seller $seller, Shipment $shipment, array $data = []): Shipment
    {
        $shipment = $this->ownedShipment($seller, $shipment);

        if (! empty($data)) {
            $shipment = $this->updateTracking($seller, $shipment, $data);
        }

        if (! $shipment->tracking_number) {
            throw ValidationException::withMessages([
                'tracking_number' => 'A tracking number is required to mark the shipment as shipped.',
            ]);
        }

        return $this->transition($seller, $shipment->fresh(), Shipment::STATUS_SHIPPED, [
            'shipped_at' => now(),
        ]);
    }

    public function markDelivered(Seller $seller, Shipment $shipment): Shipment
    {
        return $this->transition($seller, $shipment, Shipment::STATUS_DELIVERED, [
            'delivered_at' => now(),
        ]);
    }

    public function cancelShipment(Seller $seller, Shipment $shipment): Shipment
    {
        return $this->transition($seller, $shipment, Shipment::STATUS_CANCELLED);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function transition(Seller $seller, Shipment $shipment, string $to, array $extra = []): Shipment
    {
        return DB::transaction(function () use ($seller, $shipment, $to, $extra) {
            $shipment = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $this->assertShipmentOwns($seller, $shipment);

            if ($shipment->status === $to) {
                return $shipment->fresh('items');
            }

            if (! $shipment->canTransitionTo($to)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot change shipment from {$shipment->status} to {$to}.",
                ]);
            }

            $shipment->fill(array_merge(['status' => $to], $extra))->save();

            $sellerOrder = SellerOrder::query()->whereKey($shipment->seller_order_id)->lockForUpdate()->first();
            if ($sellerOrder) {
                $this->syncSellerOrderStatus($sellerOrder, $to);
                $this->syncParentOrderStatus($sellerOrder->order_id);

                if ($to === Shipment::STATUS_DELIVERED) {
                    app(SellerEarningAvailabilityService::class)->handleSellerOrderDelivered(
                        $sellerOrder->fresh(),
                        $shipment->delivered_at ?? now(),
                    );
                }
            }

            $fresh = $shipment->fresh('items');
            $broker = app(MarketplaceLifecycleBroker::class);
            if ($sellerOrder && $to === Shipment::STATUS_SHIPPED) {
                $broker->dispatch(MarketplaceEvent::sellerOrderShipped(
                    $sellerOrder->fresh(),
                    (int) $seller->id,
                    $fresh->tracking_number,
                ));
            }
            if ($sellerOrder && $to === Shipment::STATUS_DELIVERED) {
                $broker->dispatch(MarketplaceEvent::sellerOrderDelivered(
                    $sellerOrder->fresh(),
                    (int) $seller->id,
                ));
            }

            return $fresh;
        });
    }

    protected function syncSellerOrderStatus(SellerOrder $sellerOrder, string $shipmentStatus): void
    {
        $map = [
            Shipment::STATUS_PROCESSING => 'processing',
            Shipment::STATUS_READY_TO_SHIP => 'processing',
            Shipment::STATUS_SHIPPED => 'shipped',
            Shipment::STATUS_DELIVERED => 'completed',
            Shipment::STATUS_CANCELLED => 'processing',
        ];

        $next = $map[$shipmentStatus] ?? null;
        if (! $next || $sellerOrder->status === 'cancelled') {
            return;
        }

        if ($shipmentStatus === Shipment::STATUS_CANCELLED) {
            $hasActive = Shipment::query()
                ->where('seller_order_id', $sellerOrder->id)
                ->where('status', '!=', Shipment::STATUS_CANCELLED)
                ->exists();
            if ($hasActive) {
                return;
            }
            $next = 'pending';
        }

        $sellerOrder->updateTrusted(['status' => $next]);
    }

    protected function syncParentOrderStatus(int $orderId): void
    {
        $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
        if (! $order || in_array($order->status, ['cancelled', 'refunded'], true)) {
            return;
        }

        $order->loadMissing('sellerOrders');
        $slices = $order->sellerOrders->whereNotIn('status', ['cancelled']);
        if ($slices->isEmpty()) {
            return;
        }

        $allCompleted = $slices->every(fn (SellerOrder $row) => $row->status === 'completed');
        $allShippedOrDone = $slices->every(
            fn (SellerOrder $row) => in_array($row->status, ['shipped', 'completed'], true)
        );
        $anyProgress = $slices->contains(
            fn (SellerOrder $row) => in_array($row->status, ['processing', 'shipped', 'completed'], true)
        );

        if ($allCompleted) {
            $next = 'completed';
        } elseif ($allShippedOrDone) {
            $next = 'shipped';
        } elseif ($anyProgress) {
            // Mixed fulfillment: keep parent in processing — do not claim fully shipped.
            $next = 'processing';
        } else {
            return;
        }

        if ($order->status === $next) {
            return;
        }

        // Never downgrade from shipped/completed because one seller cancelled a draft shipment.
        if (in_array($order->status, ['shipped', 'completed'], true)
            && in_array($next, ['pending', 'processing'], true)) {
            return;
        }

        $previous = $order->status;
        $order->updateTrusted(['status' => $next]);
        $order->statusHistories()->create([
            'status' => $next,
            'comment' => 'Marketplace fulfillment update (from '.$previous.')',
        ]);

        // Notify customer only when the whole order reaches shipped/completed.
        if (in_array($next, ['shipped', 'completed'], true) && $previous !== $next) {
            try {
                $mailer = app(ShopMailer::class);
                $mailer->sendTemplate(
                    'order_status',
                    array_merge($mailer->orderVars($order->fresh()), [
                        'order_status' => $next,
                    ]),
                    $order->email
                );
            } catch (\Throwable $e) {
                Log::warning('marketplace.fulfillment.mail_failed', [
                    'order_id' => $order->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function ownedShipment(Seller $seller, Shipment $shipment): Shipment
    {
        $shipment->loadMissing('sellerOrder');
        $this->assertShipmentOwns($seller, $shipment);

        return $shipment;
    }

    protected function assertShipmentOwns(Seller $seller, Shipment $shipment): void
    {
        if ((int) $shipment->seller_id !== (int) $seller->id) {
            abort(403, 'This shipment does not belong to this seller.');
        }
        if ($shipment->seller_order_id
            && (int) ($shipment->sellerOrder?->seller_id ?? 0) !== (int) $seller->id) {
            abort(403, 'This shipment does not belong to this seller.');
        }
    }

    protected function assertOwns(Seller $seller, SellerOrder $sellerOrder): void
    {
        if ((int) $sellerOrder->seller_id !== (int) $seller->id) {
            abort(403, 'This order does not belong to this seller.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function presentShipment(Shipment $shipment): array
    {
        $shipment->loadMissing(['items', 'seller:id,shop_name,shop_slug', 'shippingMethod:id,name,code']);

        return [
            'id' => (int) $shipment->id,
            'order_id' => (int) $shipment->order_id,
            'seller_order_id' => $shipment->seller_order_id ? (int) $shipment->seller_order_id : null,
            'seller_id' => $shipment->seller_id ? (int) $shipment->seller_id : null,
            'shop_name' => $shipment->seller?->shop_name,
            'shop_slug' => $shipment->seller?->shop_slug,
            'status' => (string) $shipment->status,
            'shipping_method_id' => $shipment->shipping_method_id ? (int) $shipment->shipping_method_id : null,
            'shipping_method' => $shipment->shippingMethod?->name,
            'shipping_cost' => (float) ($shipment->shipping_cost ?? 0),
            'carrier' => $shipment->carrier,
            'tracking_number' => $shipment->tracking_number,
            'tracking_url' => $shipment->tracking_url,
            'shipped_at' => $shipment->shipped_at?->toIso8601String(),
            'delivered_at' => $shipment->delivered_at?->toIso8601String(),
            'item_count' => $shipment->items->count(),
            'items' => $shipment->items->map(fn ($item) => [
                'id' => (int) $item->id,
                'order_item_id' => $item->order_item_id ? (int) $item->order_item_id : null,
                'seller_order_item_id' => $item->seller_order_item_id ? (int) $item->seller_order_item_id : null,
                'qty' => (int) $item->qty,
            ])->values()->all(),
        ];
    }

    private function assertCarrierCode(mixed $carrier): void
    {
        $code = trim((string) ($carrier ?? ''));
        if ($code === '') {
            return;
        }
        if (! MarketplaceFulfilmentCarriers::isAllowedCode($code)) {
            throw ValidationException::withMessages([
                'carrier' => 'Carrier is not available for marketplace fulfilment. Configure it under Settings → Marketplace fulfilment carriers, or use self_ship.',
            ]);
        }
    }
}
