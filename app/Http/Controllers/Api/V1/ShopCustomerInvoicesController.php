<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Customer\Models\Customer;
use App\Domain\Localization\Money;
use App\Domain\Order\InvoiceDocument;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Settings\DocumentCommerceSettings;
use App\Domain\Settings\DocumentPdf;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shop REST customer-invoices REST under /api/v1/store/customer-invoices.
 * Wento invoices are order-derived (order id = invoice id).
 */
class ShopCustomerInvoicesController extends Controller
{
    public function __construct(private readonly InvoiceDocument $docs) {}

    public function index(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        if (! DocumentCommerceSettings::invoicesEnabled()) {
            return response()->json([]);
        }

        $orderId = (int) ($request->query('order_id') ?? $request->query('orderId') ?? 0);
        $state = trim((string) $request->query('state', ''));

        $query = $customer->orders()->with(['items'])->orderByDesc('id');
        if ($orderId > 0) {
            $query->whereKey($orderId);
        }

        $rows = $query->get()
            ->filter(fn (Order $o) => $this->docs->isAvailable($o))
            ->map(fn (Order $o) => $this->row($o))
            ->when($state !== '', fn ($c) => $c->filter(fn (array $r) => ($r['state'] ?? '') === $state))
            ->values()
            ->all();

        return response()->json($rows);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $order = $this->ownedInvoiceOrder($request, $id);
        if (! $order) {
            return $this->notFound($id);
        }

        return response()->json($this->row($order));
    }

    public function pdf(Request $request, int $id): Response|JsonResponse
    {
        $order = $this->ownedInvoiceOrder($request, $id);
        if (! $order) {
            return $this->notFound($id);
        }

        $binary = app(DocumentPdf::class)->output($this->docs->html($order));
        $filename = 'invoice-'.($order->created_at?->format('d-m-Y') ?: now()->format('d-m-Y')).'.pdf';

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
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

    private function ownedInvoiceOrder(Request $request, int $id): ?Order
    {
        $customer = $this->customer($request);
        if (! DocumentCommerceSettings::invoicesEnabled()) {
            return null;
        }
        $order = $customer->orders()->with(['items'])->whereKey($id)->first();
        if (! $order || ! $this->docs->isAvailable($order)) {
            return null;
        }

        return $order;
    }

    private function notFound(int $id): JsonResponse
    {
        return response()->json([
            'detail' => 'Customer invoice with ID "'.$id.'" not found',
            'message' => 'Customer invoice with ID "'.$id.'" not found',
        ], 404);
    }

    /** @return array<string, mixed> */
    private function row(Order $order): array
    {
        $order->loadMissing('items');
        $payload = $this->docs->payload($order);
        $increment = (string) ($payload['number'] ?? $order->number);
        $sub = (float) $order->subtotal;
        $tax = (float) $order->tax_total;
        $ship = (float) $order->shipping_total;
        $discount = (float) $order->discount_total;
        $grand = (float) $order->grand_total;
        $currency = strtoupper((string) ($order->currency_code ?: Money::currentCode()));
        $qty = (float) $order->items->sum(fn (OrderItem $i) => (float) $i->qty);
        $details = is_array($order->fulfillment_details) ? $order->fulfillment_details : [];
        $emailSent = ! empty($details['invoice_emailed_at']);

        return [
            'id' => (int) $order->id,
            'incrementId' => $increment,
            'state' => $this->mapState($order),
            'emailSent' => $emailSent,
            'totalQty' => fmod($qty, 1.0) === 0.0 ? (int) $qty : $qty,
            'orderCurrencyCode' => $currency,
            'subTotal' => $sub,
            'baseSubTotal' => $sub,
            'subTotalInclTax' => round($sub + $tax, 2),
            'baseSubTotalInclTax' => round($sub + $tax, 2),
            'grandTotal' => $grand,
            'baseGrandTotal' => $grand,
            'shippingAmount' => $ship,
            'baseShippingAmount' => $ship,
            'shippingAmountInclTax' => $ship,
            'baseShippingAmountInclTax' => $ship,
            'taxAmount' => $tax,
            'baseTaxAmount' => $tax,
            'shippingTaxAmount' => 0.0,
            'baseShippingTaxAmount' => 0.0,
            'discountAmount' => $discount,
            'baseDiscountAmount' => $discount,
            'reminders' => (int) ($details['invoice_reminders'] ?? 0),
            'downloadUrl' => url('/api/v1/store/customer-invoices/'.$order->id.'/pdf'),
            'items' => $order->items->map(fn (OrderItem $item) => [
                'id' => (int) $item->id,
                'sku' => (string) ($item->sku ?? ''),
                'name' => (string) ($item->name ?? ''),
                'qty' => (float) $item->qty,
                'price' => (float) $item->unit_price,
                'total' => (float) $item->total,
            ])->values()->all(),
            'addresses' => $this->addresses($order),
            'createdAt' => optional($order->created_at)?->toIso8601String(),
            'updatedAt' => optional($order->updated_at)?->toIso8601String(),
        ];
    }

    private function mapState(Order $order): string
    {
        $pay = strtolower((string) $order->payment_status);

        return match ($pay) {
            'paid', 'partial', 'partially_refunded', 'refunded' => 'paid',
            'pending_payment', 'awaiting_payment' => 'pending_payment',
            'overdue' => 'overdue',
            default => 'pending',
        };
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
}
