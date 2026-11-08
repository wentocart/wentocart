<?php

namespace App\Domain\Shipping\Services;

use App\Domain\Order\Models\Order;
use App\Domain\Shipping\Contracts\BuysShippingLabels;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Shipping\ShippingCarrierRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LabelService
{
    public function __construct(protected ShippingCarrierRegistry $carriers) {}

    public function buy(Order $order, ?\App\Models\Admin $admin = null): Shipment
    {
        $order->loadMissing(['shippingMethod', 'items']);
        if (in_array($order->status, ['cancelled', 'refunded'], true)) {
            throw ValidationException::withMessages(['shipment' => 'This order cannot be shipped.']);
        }

        $existing = Shipment::query()
            ->where('order_id', $order->id)
            ->whereNull('seller_order_id')
            ->where('status', '!=', Shipment::STATUS_CANCELLED)
            ->first();
        if ($existing && $existing->tracking_number) {
            return $existing;
        }

        $method = $order->shippingMethod;
        $label = $this->resolveLabel($order, $method);

        return DB::transaction(function () use ($order, $method, $label, $existing, $admin) {
            $shipment = $existing ?: Shipment::query()->create([
                'order_id' => $order->id,
                'shipping_method_id' => $method?->id,
                'shipping_cost' => (float) $order->shipping_total,
                'status' => Shipment::STATUS_SHIPPED,
                'carrier' => $label['carrier'],
                'tracking_number' => $label['tracking_number'],
                'tracking_url' => $label['tracking_url'],
                'shipped_at' => now(),
                'shipping_address' => $order->shipping_address,
                'notes' => 'Shipping label',
            ]);

            if ($existing) {
                $shipment->update([
                    'status' => Shipment::STATUS_SHIPPED,
                    'carrier' => $label['carrier'],
                    'tracking_number' => $label['tracking_number'],
                    'tracking_url' => $label['tracking_url'],
                    'shipped_at' => $shipment->shipped_at ?: now(),
                    'notes' => $shipment->notes ?: 'Shipping label',
                ]);
            }

            if ($shipment->items()->count() === 0) {
                foreach ($order->items as $item) {
                    $shipment->items()->create([
                        'order_item_id' => $item->id,
                        'qty' => (int) $item->qty,
                    ]);
                }
            }

            $details = is_array($order->fulfillment_details) ? $order->fulfillment_details : [];
            if ($admin) {
                $label['processed_by'] = [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                    'at' => now()->toIso8601String(),
                ];
            }
            $details['label'] = $label;
            $order->updateTrusted(['fulfillment_details' => $details]);

            $adminComment = $admin ? ' by '.$admin->name : '';
            $order->statusHistories()->create([
                'status' => $order->status,
                'comment' => 'Shipping label '.$label['tracking_number'].' ('.$label['carrier'].')'.$adminComment,
            ]);

            return $shipment->fresh('items');
        });
    }

    public function void(Order $order, ?\App\Models\Admin $admin = null): Shipment
    {
        $order->loadMissing(['shippingMethod']);
        $shipment = Shipment::query()
            ->where('order_id', $order->id)
            ->whereNull('seller_order_id')
            ->where('status', '!=', Shipment::STATUS_CANCELLED)
            ->first();

        if (! $shipment) {
            throw ValidationException::withMessages(['shipment' => 'No active shipment found to void.']);
        }

        $method = $order->shippingMethod;
        $driver = strtolower(trim((string) ($method?->driver ?: 'offline')));
        $carrier = $driver !== '' && $driver !== 'offline' ? $this->carriers->get($driver) : null;

        $details = is_array($order->fulfillment_details) ? $order->fulfillment_details : [];
        $labelData = is_array($details['label'] ?? null) ? $details['label'] : [];

        if (! ($carrier instanceof \App\Domain\Shipping\Contracts\RefundsShippingLabels)) {
            throw ValidationException::withMessages([
                'shipment' => 'This shipping method does not support voiding labels.',
            ]);
        }

        $carrier->refundLabel($order, $method, $labelData);

        return DB::transaction(function () use ($order, $shipment, $details, $admin) {
            $shipment->update([
                'status' => Shipment::STATUS_CANCELLED,
                'notes' => 'Shipping label voided',
            ]);

            if (isset($details['label'])) {
                $details['label']['voided'] = true;
                $details['label']['voided_at'] = now()->toIso8601String();
                if ($admin) {
                    $details['label']['voided_by'] = [
                        'id' => $admin->id,
                        'name' => $admin->name,
                        'email' => $admin->email,
                    ];
                }
            }
            $order->updateTrusted(['fulfillment_details' => $details]);

            $adminComment = $admin ? ' by '.$admin->name : '';
            $order->statusHistories()->create([
                'status' => $order->status,
                'comment' => 'Shipping label '.$shipment->tracking_number.' voided'.$adminComment,
            ]);

            return $shipment->fresh();
        });
    }

    /**
     * @return array{carrier: string, tracking_number: string, tracking_url: ?string}
     */
    protected function resolveLabel(Order $order, ?ShippingMethod $method): array
    {
        $driver = strtolower(trim((string) ($method?->driver ?: 'offline')));
        $carrier = $driver !== '' && $driver !== 'offline' ? $this->carriers->get($driver) : null;
        if ($carrier instanceof BuysShippingLabels) {
            return $carrier->buyLabel($order, $method);
        }

        throw ValidationException::withMessages([
            'shipment' => 'This shipping method cannot buy a label.',
        ]);
    }
}
