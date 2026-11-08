<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Customer\Models\Customer;
use App\Domain\Shipping\Models\Shipment;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shop REST customer-shipments REST under /api/v1/store/customer-shipments.
 */
class ShopCustomerShipmentsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $orderId = (int) ($request->query('order_id') ?? $request->query('orderId') ?? 0);

        $query = Shipment::query()
            ->whereHas('order', fn ($q) => $q->where('customer_id', $customer->id))
            ->with(['order:id,number,customer_id', 'items.orderItem'])
            ->orderByDesc('id');

        if ($orderId > 0) {
            $owned = $customer->orders()->whereKey($orderId)->exists();
            if (! $owned) {
                return response()->json([
                    'detail' => 'Customer order with ID "'.$orderId.'" not found',
                    'message' => 'Customer order with ID "'.$orderId.'" not found',
                ], 404);
            }
            $query->where('order_id', $orderId);
        }

        $rows = $query->get()->map(fn (Shipment $s) => $this->row($s))->values()->all();

        return response()->json($rows);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $customer = $this->customer($request);
        $shipment = Shipment::query()
            ->whereKey($id)
            ->whereHas('order', fn ($q) => $q->where('customer_id', $customer->id))
            ->with(['order:id,number,customer_id', 'items.orderItem'])
            ->first();

        if (! $shipment) {
            return response()->json([
                'detail' => 'Customer shipment with ID "'.$id.'" not found',
                'message' => 'Customer shipment with ID "'.$id.'" not found',
            ], 404);
        }

        return response()->json($this->row($shipment));
    }

    private function customer(Request $request): Customer
    {
        $user = $request->user('customer');
        if (! $user instanceof Customer) {
            abort(response()->json([
                'detail' => 'Unauthenticated. Please login to perform this action',
                'message' => 'Unauthenticated. Please login to perform this action',
            ], 403));
        }

        return $user;
    }

    /** @return array<string, mixed> */
    private function row(Shipment $s): array
    {
        $qty = (float) $s->items->sum(fn ($item) => (float) ($item->qty ?? 0));
        $status = $this->mapStatus((string) $s->status);
        $carrier = (string) ($s->carrier ?? '');
        $track = (string) ($s->tracking_number ?? '');

        return [
            'id' => (int) $s->id,
            '_id' => (int) $s->id,
            'orderId' => (int) $s->order_id,
            'incrementId' => (string) ($s->order?->number ?? ''),
            'status' => $status,
            'trackNumber' => $track !== '' ? $track : null,
            'trackingNumber' => $track !== '' ? $track : null,
            'carrier' => $carrier !== '' ? $carrier : null,
            'carrierTitle' => $carrier !== '' ? $carrier : null,
            'trackingUrl' => $s->tracking_url,
            'shippingNumber' => 'SHP-'.$s->id,
            'totalQty' => fmod($qty, 1.0) === 0.0 ? (int) $qty : $qty,
            'shippedAt' => optional($s->shipped_at)?->toIso8601String(),
            'deliveredAt' => optional($s->delivered_at)?->toIso8601String(),
            'createdAt' => optional($s->created_at)?->toIso8601String(),
            'items' => $s->items->map(fn ($item) => [
                'id' => (int) $item->id,
                'name' => (string) ($item->orderItem?->name ?? ''),
                'sku' => (string) ($item->orderItem?->sku ?? ''),
                'qty' => (float) ($item->qty ?? 0),
            ])->values()->all(),
        ];
    }

    private function mapStatus(string $wento): string
    {
        return match (strtolower($wento)) {
            'processing', 'ready_to_ship' => 'pending',
            'shipped' => 'shipped',
            'delivered' => 'delivered',
            'cancelled', 'canceled' => 'cancelled',
            default => strtolower($wento) ?: 'pending',
        };
    }
}
