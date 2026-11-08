<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Localization\Money;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Settings\DocumentRenderer;
use App\Domain\Settings\Models\DocumentTemplate;

class SellerOrderDocument
{
    public function ensurePaid(SellerOrder $sellerOrder): void
    {
        if ($sellerOrder->payment_status === 'paid') {
            return;
        }

        abort(404);
    }

    public function ensureDeliverable(SellerOrder $sellerOrder): void
    {
        if (in_array($sellerOrder->status, ['shipped', 'completed'], true)) {
            return;
        }

        abort(404);
    }

    /**
     * @return array<string, mixed>
     */
    public function invoicePayload(SellerOrder $sellerOrder): array
    {
        $sellerOrder->loadMissing(['items', 'seller', 'order.paymentMethod', 'shippingMethod']);

        $parent = $sellerOrder->order;
        $billing = $sellerOrder->billing_address ?: ($parent?->billing_address ?? []);
        $shipping = $sellerOrder->shipping_address ?: ($parent?->shipping_address ?? []);

        return [
            'id' => $sellerOrder->id,
            'number' => $sellerOrder->number,
            'created_at' => $sellerOrder->created_at?->toIso8601String(),
            'currency_code' => $sellerOrder->currency_code,
            'payment_status' => $sellerOrder->payment_status,
            'payment_method' => $parent?->paymentMethod?->name,
            'email' => $sellerOrder->customer_email,
            'shop' => $this->shop($sellerOrder->seller),
            'billing' => $this->party($billing, $sellerOrder),
            'shipping' => $this->party($shipping, $sellerOrder),
            'items' => $sellerOrder->items->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'sku' => $item->sku,
                'qty' => (int) $item->qty,
                'unit_price' => (float) $item->unit_price,
                'total' => (float) $item->total,
            ])->values()->all(),
            'subtotal' => (float) $sellerOrder->subtotal,
            'discount_total' => (float) $sellerOrder->discount_total,
            'shipping_total' => (float) $sellerOrder->shipping_total,
            'tax_total' => (float) $sellerOrder->tax_total,
            'tax_label' => 'Tax',
            'grand_total' => (float) $sellerOrder->grand_total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function deliveryPayload(SellerOrder $sellerOrder): array
    {
        $sellerOrder->loadMissing(['items', 'seller', 'order', 'shippingMethod', 'shipments']);

        $parent = $sellerOrder->order;
        $shipping = $sellerOrder->shipping_address ?: ($parent?->shipping_address ?? []);
        $shipment = $sellerOrder->shipments
            ->where('status', '!=', 'cancelled')
            ->sortByDesc('id')
            ->first();

        return [
            'id' => $sellerOrder->id,
            'number' => $sellerOrder->number,
            'order_number' => $sellerOrder->number,
            'created_at' => $sellerOrder->created_at?->toIso8601String(),
            'delivered_at' => ($shipment?->updated_at ?? $sellerOrder->updated_at)?->toIso8601String()
                ?: $sellerOrder->created_at?->toIso8601String(),
            'currency_code' => $sellerOrder->currency_code,
            'shipping_method' => $sellerOrder->shippingMethod?->name ?: $parent?->shippingMethod?->name,
            'tracking_number' => $shipment?->tracking_number,
            'carrier' => $shipment?->carrier ?: $sellerOrder->shippingMethod?->name,
            'status' => $sellerOrder->status === 'completed' ? 'Delivered' : 'Shipped',
            'email' => $sellerOrder->customer_email,
            'shop' => $this->shop($sellerOrder->seller),
            'deliver_to' => $this->party($shipping, $sellerOrder),
            'ship_from' => $this->sellerParty($sellerOrder->seller),
            'items' => $sellerOrder->items->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'sku' => $item->sku,
                'qty' => (int) $item->qty,
                'unit_price' => (float) $item->unit_price,
                'total' => (float) $item->total,
            ])->values()->all(),
            'note' => 'Handle with care.'."\n\n".'Thank you for shopping with us!',
        ];
    }

    public function invoiceHtml(SellerOrder $sellerOrder): string
    {
        $parent = $sellerOrder->order ?? abort(404);

        return app(DocumentRenderer::class)->render(
            DocumentTemplate::TYPE_INVOICE,
            $parent,
            $this->invoicePayload($sellerOrder),
            fn () => $this->invoiceFallback($sellerOrder),
        );
    }

    public function deliveryHtml(SellerOrder $sellerOrder): string
    {
        $parent = $sellerOrder->order ?? abort(404);

        return app(DocumentRenderer::class)->render(
            DocumentTemplate::TYPE_DELIVERY_SLIP,
            $parent,
            $this->deliveryPayload($sellerOrder),
            fn () => $this->deliveryFallback($sellerOrder),
        );
    }

    public function invoiceFallback(SellerOrder $sellerOrder): string
    {
        $invoice = $this->invoicePayload($sellerOrder);
        $code = $invoice['currency_code'];
        $shop = $invoice['shop'];
        $rows = '';

        foreach ($invoice['items'] as $item) {
            $rows .= '<tr>'
                .'<td>'.e($item['name']).'<div style="color:#737373;font-size:12px;">'.e($item['sku'] ?: '').'</div></td>'
                .'<td>'.e($item['sku'] ?: '—').'</td>'
                .'<td style="text-align:right;">'.e(Money::format($item['unit_price'], $code)).'</td>'
                .'<td style="text-align:center;">'.(int) $item['qty'].'</td>'
                .'<td style="text-align:right;">'.e(Money::format($item['total'], $code)).'</td>'
                .'</tr>';
        }

        $discountRow = (float) $invoice['discount_total'] > 0
            ? '<tr><td>Discount</td><td style="text-align:right;">-'.e(Money::format($invoice['discount_total'], $code)).'</td></tr>'
            : '';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Invoice #'.e($invoice['number']).'</title>'
            .'<style>body{font-family:Arial,sans-serif;color:#171717;margin:32px;}table{width:100%;border-collapse:collapse;}th,td{padding:8px 0;border-bottom:1px solid #eee;text-align:left;font-size:14px;}th{color:#737373;font-size:12px;text-transform:uppercase;}h1{margin:0 0 8px;} .muted{color:#737373;font-size:13px;} .paid{color:#059669;font-weight:700;} .total{color:#6d28d9;font-size:20px;font-weight:700;}</style>'
            .'</head><body>'
            .'<table><tr><td>'
            .'<strong style="font-size:18px;">'.e($shop['name']).'</strong>'
            .'<div class="muted">'.nl2br(e($shop['address'])).'</div>'
            .($shop['email'] !== '' ? '<div class="muted">'.e($shop['email']).'</div>' : '')
            .'</td><td style="text-align:right;"><h1>INVOICE #'.e($invoice['number']).'</h1>'
            .'<div class="muted">Invoice Date: '.e($this->formatDate($invoice['created_at'])).'</div>'
            .'<div class="muted">Payment Method: '.e($invoice['payment_method'] ?: '—').'</div>'
            .'<div>Payment Status: <span class="paid">Paid</span></div>'
            .'</td></tr></table>'
            .'<table style="margin-top:24px;"><tr><td><div class="muted">Bill To</div><strong>'.e($invoice['billing']['name']).'</strong>'
            .'<div class="muted">'.nl2br(e($invoice['billing']['address'])).'</div>'
            .'<div class="muted">'.e($invoice['billing']['email']).'</div>'
            .'<div class="muted">'.e($invoice['billing']['phone']).'</div></td>'
            .'<td><div class="muted">Ship To</div><strong>'.e($invoice['shipping']['name']).'</strong>'
            .'<div class="muted">'.nl2br(e($invoice['shipping']['address'])).'</div>'
            .'<div class="muted">'.e($invoice['shipping']['email']).'</div>'
            .'<div class="muted">'.e($invoice['shipping']['phone']).'</div></td></tr></table>'
            .'<table style="margin-top:24px;"><thead><tr><th>Item</th><th>SKU</th><th style="text-align:right;">Price</th><th style="text-align:center;">Qty</th><th style="text-align:right;">Total</th></tr></thead><tbody>'
            .$rows
            .'</tbody></table>'
            .'<table style="width:280px;margin-left:auto;margin-top:16px;"><tr><td>Subtotal</td><td style="text-align:right;">'.e(Money::format($invoice['subtotal'], $code)).'</td></tr>'
            .$discountRow
            .'<tr><td>Shipping</td><td style="text-align:right;">'.e(Money::format($invoice['shipping_total'], $code)).'</td></tr>'
            .'<tr><td>'.e($invoice['tax_label']).'</td><td style="text-align:right;">'.e(Money::format($invoice['tax_total'], $code)).'</td></tr>'
            .'<tr><td class="total">Total</td><td class="total" style="text-align:right;">'.e(Money::format($invoice['grand_total'], $code)).'</td></tr></table>'
            .'</body></html>';
    }

    public function deliveryFallback(SellerOrder $sellerOrder): string
    {
        $deliverySlip = $this->deliveryPayload($sellerOrder);
        $code = $deliverySlip['currency_code'];
        $shop = $deliverySlip['shop'];
        $rows = '';

        foreach ($deliverySlip['items'] as $item) {
            $rows .= '<tr>'
                .'<td>'.e($item['name']).'<div style="color:#737373;font-size:12px;">'.e($item['sku'] ?: '').'</div></td>'
                .'<td>'.e($item['sku'] ?: '—').'</td>'
                .'<td style="text-align:center;">'.(int) $item['qty'].'</td>'
                .'<td style="text-align:right;">'.e(Money::format($item['unit_price'], $code)).'</td>'
                .'<td style="text-align:right;">'.e(Money::format($item['total'], $code)).'</td>'
                .'</tr>';
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Delivery Slip #'.e($deliverySlip['number']).'</title>'
            .'<style>body{font-family:Arial,sans-serif;color:#171717;margin:32px;}table{width:100%;border-collapse:collapse;}th,td{padding:8px 0;border-bottom:1px solid #eee;text-align:left;font-size:14px;}th{color:#737373;font-size:12px;text-transform:uppercase;}h1{margin:0 0 8px;} .muted{color:#737373;font-size:13px;} .ok{color:#16a34a;font-weight:700;}</style>'
            .'</head><body>'
            .'<table><tr><td>'
            .'<strong style="font-size:18px;">'.e($shop['name']).'</strong>'
            .'<div class="muted">'.nl2br(e($shop['address'])).'</div>'
            .($shop['email'] !== '' ? '<div class="muted">Email: '.e($shop['email']).'</div>' : '')
            .'</td><td style="text-align:right;"><h1>DELIVERY SLIP #'.e($deliverySlip['number']).'</h1>'
            .'<div class="muted">Order Number: #'.e($deliverySlip['order_number']).'</div>'
            .'<div class="muted">Shipping Method: '.e($deliverySlip['shipping_method'] ?: '—').'</div>'
            .'<div class="muted">Tracking Number: '.e($deliverySlip['tracking_number'] ?: '—').'</div>'
            .'<div>Status: <span class="ok">'.e($deliverySlip['status']).'</span></div>'
            .'</td></tr></table>'
            .'<table style="margin-top:24px;"><tr><td><div class="muted">Deliver To</div><strong>'.e($deliverySlip['deliver_to']['name']).'</strong>'
            .'<div class="muted">'.nl2br(e($deliverySlip['deliver_to']['address'])).'</div>'
            .'<div class="muted">'.e($deliverySlip['deliver_to']['email']).'</div>'
            .'<div class="muted">'.e($deliverySlip['deliver_to']['phone']).'</div></td>'
            .'<td><div class="muted">Ship From</div><strong>'.e($deliverySlip['ship_from']['name']).'</strong>'
            .'<div class="muted">'.nl2br(e($deliverySlip['ship_from']['address'])).'</div>'
            .'<div class="muted">'.e($deliverySlip['ship_from']['email']).'</div>'
            .'<div class="muted">'.e($deliverySlip['ship_from']['phone']).'</div></td></tr></table>'
            .'<table style="margin-top:24px;"><thead><tr><th>Item</th><th>SKU</th><th style="text-align:center;">Qty</th><th style="text-align:right;">Unit</th><th style="text-align:right;">Total</th></tr></thead><tbody>'
            .$rows
            .'</tbody></table>'
            .'</body></html>';
    }

    /**
     * @return array{name: string, email: string, address: string}
     */
    protected function shop(?Seller $seller): array
    {
        if (! $seller) {
            return ['name' => config('app.name'), 'email' => '', 'address' => ''];
        }

        $lines = array_values(array_filter([
            $seller->address_line1,
            $seller->address_line2,
            trim(implode(', ', array_filter([$seller->city, $seller->state, $seller->postal_code]))),
            $seller->country_code,
        ], fn ($line) => is_string($line) && trim($line) !== ''));

        return [
            'name' => $seller->shop_name ?: $seller->profile_name ?: config('app.name'),
            'email' => $seller->contact_email ?: $seller->email ?: '',
            'address' => implode("\n", $lines),
        ];
    }

    /**
     * @return array{name: string, address: string, email: string, phone: string, lines: list<string>}
     */
    protected function sellerParty(?Seller $seller): array
    {
        $shop = $this->shop($seller);
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $shop['address']) ?: []));

        return [
            'name' => $shop['name'],
            'address' => $shop['address'],
            'lines' => $lines,
            'email' => $shop['email'],
            'phone' => $seller?->contact_phone ?: $seller?->phone ?: '',
        ];
    }

    /**
     * @param  array<string, mixed>  $address
     * @return array{name: string, address: string, email: string, phone: string, lines: list<string>}
     */
    protected function party(array $address, SellerOrder $sellerOrder): array
    {
        $name = trim(($address['first_name'] ?? '').' '.($address['last_name'] ?? ''));
        if ($name === '') {
            $name = $sellerOrder->customer_email ?: 'Customer';
        }

        $lines = array_values(array_filter([
            $address['line1'] ?? $address['address1'] ?? null,
            $address['line2'] ?? $address['address2'] ?? null,
            trim(implode(', ', array_filter([
                $address['city'] ?? null,
                $address['state'] ?? null,
                $address['postal_code'] ?? $address['postcode'] ?? null,
            ]))),
            $address['country_code'] ?? $address['country'] ?? null,
        ], fn ($line) => is_string($line) && trim($line) !== ''));

        return [
            'name' => $name,
            'address' => implode("\n", $lines),
            'lines' => $lines,
            'email' => (string) ($address['email'] ?? $sellerOrder->customer_email ?: ''),
            'phone' => (string) ($address['phone'] ?? ''),
        ];
    }

    protected function formatDate(?string $iso): string
    {
        return $iso ? (string) date('M j, Y', strtotime($iso)) : '—';
    }
}
