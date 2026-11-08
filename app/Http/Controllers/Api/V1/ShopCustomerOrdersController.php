<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Customer\Models\Customer;
use App\Domain\Localization\Money;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Store\StoreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shop REST customer-orders REST under /api/v1/store/customer-orders.
 */
class ShopCustomerOrdersController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $query = $customer->orders()
            ->with(['items', 'shippingMethod', 'paymentMethod'])
            ->latest('id');

        $status = trim((string) $request->query('status', ''));
        if ($status !== '') {
            $query->whereIn('status', $this->statusFilterValues($status));
        }
        $orders = $query->limit(10)->get();
        $rows = $orders->map(fn (Order $order) => $this->listRow($order))->values()->all();

        return response()->json($rows);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $customer = $this->customer($request);
        $order = $customer->orders()
            ->with(['items.product', 'shippingMethod', 'paymentMethod', 'shipments'])
            ->whereKey($id)
            ->first();

        if (! $order) {
            return response()->json([
                'detail' => 'Customer order with ID "'.$id.'" not found',
                'message' => 'Customer order with ID "'.$id.'" not found',
            ], 404);
        }

        return response()->json($this->detailRow($order));
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

    /**
     * @return list<string>
     */
    private function statusFilterValues(string $compatStatus): array
    {
        $key = strtolower($compatStatus);

        return match ($key) {
            'canceled', 'cancelled' => ['canceled', 'cancelled'],
            'closed' => ['closed', 'refunded'],
            'completed' => ['completed', 'delivered'],
            'pending_payment' => ['pending_payment', 'awaiting_payment'],
            default => [$key],
        };
    }

    private function mapStatus(string $wento): string
    {
        return match (strtolower($wento)) {
            'cancelled' => 'canceled',
            'refunded' => 'closed',
            'delivered' => 'completed',
            'awaiting_payment' => 'pending_payment',
            default => strtolower($wento) ?: 'pending',
        };
    }

    /** @return array<string, mixed> */
    private function listRow(Order $order): array
    {
        $order->loadMissing(['items', 'shippingMethod']);
        $shipCode = (string) ($order->shippingMethod?->code ?: $order->shippingMethod?->type ?: '');
        $shipKey = $shipCode !== '' ? $shipCode.'_'.$shipCode : null;
        $qty = (float) $order->items->sum(fn (OrderItem $i) => (float) $i->qty);
        $currency = strtoupper((string) ($order->currency_code ?: Money::currentCode()));
        $base = strtoupper((string) (Money::defaultCode() ?: $currency));
        $name = $this->customerName($order);

        return [
            'id' => (int) $order->id,
            'incrementId' => (string) $order->number,
            'status' => $this->mapStatus((string) $order->status),
            'storeName' => (string) ($order->channel ?: 'default'),
            'customerEmail' => (string) ($order->email ?? ''),
            'customerFirstName' => $name['first'],
            'customerLastName' => $name['last'],
            'shippingMethod' => $shipKey,
            'shippingTitle' => $order->shippingMethod?->name,
            'couponCode' => data_get($order->payment_details, 'coupon_code')
                ?? data_get($order->fulfillment_details, 'coupon_code'),
            'totalItemCount' => $order->items->count(),
            'totalQtyOrdered' => fmod($qty, 1.0) === 0.0 ? (int) $qty : $qty,
            'grandTotal' => (float) $order->grand_total,
            'baseGrandTotal' => (float) $order->grand_total,
            'subTotal' => (float) $order->subtotal,
            'baseSubTotal' => (float) $order->subtotal,
            'taxAmount' => (float) $order->tax_total,
            'shippingAmount' => (float) $order->shipping_total,
            'discountAmount' => (float) $order->discount_total,
            'orderCurrencyCode' => $currency,
            'baseCurrencyCode' => $base,
            'createdAt' => optional($order->created_at)?->toIso8601String(),
            'updatedAt' => optional($order->updated_at)?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function detailRow(Order $order): array
    {
        $list = $this->listRow($order);
        $order->loadMissing(['items.product', 'paymentMethod', 'shipments', 'shippingMethod']);
        $name = $this->customerName($order);
        $currency = $list['orderCurrencyCode'];
        $base = $list['baseCurrencyCode'];
        $shipCode = (string) ($order->shippingMethod?->code ?: $order->shippingMethod?->type ?: '');
        $storeId = (int) ($order->store_id ?: app(StoreContext::class)->id() ?: 0);
        $refunded = (float) ($order->refunded_total ?? 0);
        $invoiced = in_array((string) $order->payment_status, ['paid', 'partial', 'partially_refunded', 'refunded'], true)
            ? (float) $order->grand_total
            : 0.0;
        if ($refunded > 0 && $invoiced > 0) {
            $invoiced = max(0, $invoiced - $refunded);
        }

        $shippingAmt = (float) $order->shipping_total;
        $taxAmt = (float) $order->tax_total;
        $discountAmt = (float) $order->discount_total;
        $sub = (float) $order->subtotal;

        return array_merge($list, [
            'isGuest' => $order->customer_id ? 0 : 1,
            'isGift' => ! empty(($order->fulfillment_details ?? [])['gift']) ? 1 : 0,
            'customerId' => $order->customer_id ? (int) $order->customer_id : null,
            'storeId' => $storeId,
            'cartId' => null,
            'customerFullName' => trim($name['first'].' '.$name['last']),
            'shippingDescription' => (string) ($order->shippingMethod?->description ?? ''),
            'appliedCartDiscountRuleIds' => null,
            'discountPercent' => 0,
            'grandTotalInvoiced' => $invoiced,
            'baseGrandTotalInvoiced' => $invoiced,
            'grandTotalRefunded' => $refunded,
            'baseGrandTotalRefunded' => $refunded,
            'subTotalInvoiced' => $invoiced > 0 ? $sub : 0.0,
            'baseSubTotalInvoiced' => $invoiced > 0 ? $sub : 0.0,
            'subTotalRefunded' => $refunded > 0 ? min($sub, $refunded) : 0.0,
            'baseSubTotalRefunded' => $refunded > 0 ? min($sub, $refunded) : 0.0,
            'subTotalInclTax' => round($sub + $taxAmt, 2),
            'baseSubTotalInclTax' => round($sub + $taxAmt, 2),
            'discountInvoiced' => $invoiced > 0 ? $discountAmt : 0.0,
            'baseDiscountInvoiced' => $invoiced > 0 ? $discountAmt : 0.0,
            'discountRefunded' => 0.0,
            'baseDiscountRefunded' => 0.0,
            'baseDiscountAmount' => $discountAmt,
            'taxAmountInvoiced' => $invoiced > 0 ? $taxAmt : 0.0,
            'baseTaxAmountInvoiced' => $invoiced > 0 ? $taxAmt : 0.0,
            'taxAmountRefunded' => 0.0,
            'baseTaxAmountRefunded' => 0.0,
            'baseTaxAmount' => $taxAmt,
            'shippingInvoiced' => $invoiced > 0 ? $shippingAmt : 0.0,
            'baseShippingInvoiced' => $invoiced > 0 ? $shippingAmt : 0.0,
            'shippingRefunded' => 0.0,
            'baseShippingRefunded' => 0.0,
            'baseShippingAmount' => $shippingAmt,
            'shippingDiscountAmount' => 0.0,
            'baseShippingDiscountAmount' => 0.0,
            'shippingTaxAmount' => 0.0,
            'baseShippingTaxAmount' => 0.0,
            'shippingTaxRefunded' => 0.0,
            'baseShippingTaxRefunded' => 0.0,
            'shippingAmountInclTax' => $shippingAmt,
            'baseShippingAmountInclTax' => $shippingAmt,
            'storeCurrencyCode' => $base,
            'items' => $order->items->map(fn (OrderItem $item) => $this->itemRow($item))->values()->all(),
            'addresses' => $this->addresses($order),
            'payment' => $order->paymentMethod ? [
                'id' => (int) $order->paymentMethod->id,
                'method' => (string) ($order->paymentMethod->code ?: $order->paymentMethod->type ?: ''),
                'methodTitle' => $order->paymentMethod->textForLocale('name'),
            ] : null,
            'shipments' => $order->shipments->map(fn (Shipment $s) => [
                'id' => (int) $s->id,
                'status' => (string) $s->status,
                'carrier' => (string) ($s->carrier ?? ''),
                'trackNumber' => (string) ($s->tracking_number ?? ''),
                'trackingUrl' => $s->tracking_url,
                'createdAt' => optional($s->created_at)?->toIso8601String(),
            ])->values()->all(),
            'orderCurrencyCode' => $currency,
            'baseCurrencyCode' => $base,
        ]);
    }

    /** @return array<string, mixed> */
    private function itemRow(OrderItem $item): array
    {
        $qty = (float) $item->qty;
        $shipped = (float) ($item->shipped_qty ?? 0);
        $invoiced = (float) ($item->invoiced_qty ?? $qty);
        $canceled = (float) ($item->canceled_qty ?? 0);
        $refunded = (float) ($item->returned_qty ?? 0);
        $unit = (float) $item->unit_price;
        $total = (float) $item->total;
        $tax = (float) $item->tax_amount;
        $type = (string) ($item->product?->type ?? 'simple');

        return [
            'id' => (int) $item->id,
            'sku' => (string) ($item->sku ?? ''),
            'name' => (string) ($item->name ?? ''),
            'type' => $type,
            'productType' => $type,
            'productId' => $item->product_id ? (int) $item->product_id : null,
            'qtyOrdered' => fmod($qty, 1.0) === 0.0 ? (int) $qty : $qty,
            'qtyShipped' => fmod($shipped, 1.0) === 0.0 ? (int) $shipped : $shipped,
            'qtyInvoiced' => fmod($invoiced, 1.0) === 0.0 ? (int) $invoiced : $invoiced,
            'qtyCanceled' => fmod($canceled, 1.0) === 0.0 ? (int) $canceled : $canceled,
            'qtyRefunded' => fmod($refunded, 1.0) === 0.0 ? (int) $refunded : $refunded,
            'price' => $unit,
            'basePrice' => $unit,
            'priceInclTax' => round($unit + ((float) $item->tax_rate > 0 ? $unit * ((float) $item->tax_rate / 100) : 0), 2),
            'total' => $total,
            'baseTotal' => $total,
            'totalInclTax' => round($total + $tax, 2),
            'discountPercent' => 0,
            'discountAmount' => 0,
            'taxPercent' => (float) ($item->tax_rate ?? 0),
            'taxAmount' => $tax,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function addresses(Order $order): array
    {
        $out = [];
        foreach (['billing' => 'order_billing', 'shipping' => 'order_shipping'] as $key => $type) {
            $addr = is_array($order->{$key.'_address'} ?? null) ? $order->{$key.'_address'} : [];
            if ($addr === []) {
                continue;
            }
            $out[] = [
                'id' => (int) ($addr['id'] ?? 0),
                'addressType' => $type,
                'firstName' => (string) ($addr['first_name'] ?? ''),
                'lastName' => (string) ($addr['last_name'] ?? ''),
                'gender' => $addr['gender'] ?? null,
                'companyName' => $addr['company'] ?? null,
                'vatId' => $addr['vat_id'] ?? null,
                'address' => (string) ($addr['line1'] ?? $addr['address'] ?? ''),
                'city' => (string) ($addr['city'] ?? ''),
                'state' => (string) ($addr['state'] ?? ''),
                'country' => (string) ($addr['country_code'] ?? $addr['country'] ?? ''),
                'postcode' => (string) ($addr['postal_code'] ?? $addr['postcode'] ?? ''),
                'email' => (string) ($addr['email'] ?? $order->email ?? ''),
                'phone' => (string) ($addr['phone'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return array{first: string, last: string}
     */
    private function customerName(Order $order): array
    {
        $billing = is_array($order->billing_address) ? $order->billing_address : [];
        $first = (string) ($billing['first_name'] ?? $order->customer?->first_name ?? '');
        $last = (string) ($billing['last_name'] ?? $order->customer?->last_name ?? '');
        if ($first === '' && $last === '' && $order->customer) {
            $full = trim((string) ($order->customer->name ?? ''));
            if ($full !== '') {
                $parts = preg_split('/\s+/', $full, 2) ?: [];
                $first = (string) ($parts[0] ?? '');
                $last = (string) ($parts[1] ?? '');
            }
        }

        return ['first' => $first, 'last' => $last];
    }
}
