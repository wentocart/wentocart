<?php

namespace App\Domain\Order;

use App\Domain\Localization\Money;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderStage;
use App\Domain\Settings\DocumentCommerceSettings;
use App\Domain\Settings\DocumentRenderer;
use App\Domain\Settings\Models\DocumentTemplate;
use App\Domain\Settings\Models\Setting;
use App\Domain\Store\StoreContext;
use Illuminate\Database\Eloquent\Builder;

class DeliverySlipDocument
{
    public function deliveryQuery(): Builder
    {
        if (! DocumentCommerceSettings::packingSlips()) {
            return Order::query()->whereRaw('1 = 0');
        }
        $codes = OrderStage::query()->where('unlock_delivery_slip', true)->pluck('code')->all();
        $fallback = ['shipped', 'completed'];

        return Order::query()->whereIn('status', array_values(array_unique(array_merge($fallback, $codes))));
    }

    public function ensureDeliverable(Order $order): void
    {
        if (! DocumentCommerceSettings::packingSlips()) {
            abort(404);
        }
        if (in_array($order->status, ['shipped', 'completed'], true)) {
            return;
        }

        $stage = OrderStage::query()->where('code', $order->status)->first();
        if ($stage?->unlock_delivery_slip) {
            return;
        }

        abort(404);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Order $order): array
    {
        $order->loadMissing(['items.product:id,cover_path', 'customer', 'shippingMethod']);

        return [
            'id' => $order->id,
            'number' => $order->number,
            'order_number' => $order->number,
            'created_at' => $order->created_at?->toIso8601String(),
            'delivered_at' => $order->updated_at?->toIso8601String() ?: $order->created_at?->toIso8601String(),
            'currency_code' => $order->currency_code,
            'shipping_method' => $order->shippingMethod?->name,
            'tracking_number' => null,
            'carrier' => $order->shippingMethod?->name,
            'status' => $order->status === 'completed' ? 'Delivered' : 'Shipped',
            'email' => $order->email ?: $order->customer?->email,
            'shop' => $this->shop(),
            'deliver_to' => $this->party($order->shipping_address ?? [], $order),
            'ship_from' => $this->shopParty(),
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'sku' => $item->sku,
                'qty' => (int) $item->qty,
                'unit_price' => (float) $item->unit_price,
                'total' => (float) $item->total,
                'cover_path' => $item->product?->cover_path,
            ])->values()->all(),
            'note' => 'Handle with care.'."\n\n".'Thank you for shopping with us!',
        ];
    }

    public function html(Order $order): string
    {
        return app(DocumentRenderer::class)->render(
            DocumentTemplate::TYPE_DELIVERY_SLIP,
            $order,
            $this->payload($order),
            fn () => $this->fallbackHtml($order),
        );
    }

    public function fallbackHtml(Order $order): string
    {
        $deliverySlip = $this->payload($order);
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
            .'<style>body{font-family:Arial,sans-serif;color:#171717;margin:32px;}table{width:100%;border-collapse:collapse;}th,td{padding:8px 0;border-bottom:1px solid #eee;text-align:left;font-size:14px;}th{color:#737373;font-size:12px;text-transform:uppercase;}h1{margin:0 0 8px;} .muted{color:#737373;font-size:13px;} .ok{color:#16a34a;font-weight:700;} .box{border:1px solid #e5e7eb;border-radius:12px;padding:16px;} .grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;} .strip{margin-top:24px;border:1px solid #ede9fe;background:#f5f3ff;border-radius:12px;padding:16px;} </style>'
            .'</head><body>'
            .'<table><tr><td>'
            .'<strong style="font-size:18px;">'.e($shop['name']).'</strong>'
            .'<div class="muted">'.nl2br(e($shop['address'])).'</div>'
            .($shop['email'] !== '' ? '<div class="muted">Email: '.e($shop['email']).'</div>' : '')
            .'</td><td style="text-align:right;"><h1>DELIVERY SLIP #'.e($deliverySlip['number']).'</h1>'
            .'<div class="muted">Delivery Date: '.e($this->formatDate($deliverySlip['delivered_at'])).'</div>'
            .'<div class="muted">Order Number: #'.e($deliverySlip['order_number']).'</div>'
            .'<div class="muted">Order Date: '.e($this->formatDate($deliverySlip['created_at'])).'</div>'
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
            .'<div class="strip"><table><tr><td>Carrier</td><td>'.e($deliverySlip['carrier'] ?: '—').'</td><td>Tracking Number</td><td>'.e($deliverySlip['tracking_number'] ?: '—').'</td><td>Shipped On</td><td>'.e($this->formatDateTime($deliverySlip['delivered_at'])).'</td></tr></table></div>'
            .'<table style="margin-top:24px;"><thead><tr><th>Item</th><th>SKU</th><th style="text-align:center;">Qty</th><th style="text-align:right;">Unit</th><th style="text-align:right;">Total</th></tr></thead><tbody>'
            .$rows
            .'</tbody></table>'
            .'<div style="text-align:right;margin-top:16px;font-weight:700;">Total Items: '.count($deliverySlip['items']).'</div>'
            .'<div class="grid" style="margin-top:24px;"><div class="box"><strong>Package Note</strong><div class="muted" style="margin-top:8px;white-space:pre-line;">'.e($deliverySlip['note']).'</div></div>'
            .'<div class="box"><strong>Received By</strong><table style="margin-top:12px;"><tr><td>Name</td><td style="text-align:right;">____________________</td></tr><tr><td>Signature</td><td style="text-align:right;">____________________</td></tr><tr><td>Date</td><td style="text-align:right;">____________________</td></tr></table></div></div>'
            .'</body></html>';
    }

    /**
     * @return array{name: string, email: string, address: string}
     */
    public function shop(): array
    {
        $store = app(StoreContext::class)->store();

        return [
            'name' => $store?->name ?: (string) Setting::getValue('shop_name', config('app.name')),
            'email' => $store?->email ?: (string) Setting::getValue('shop_email', ''),
            'address' => $store?->address ?: (string) Setting::getValue('shop_address', ''),
        ];
    }

    /**
     * @return array{name: string, address: string, email: string, phone: string, lines: list<string>}
     */
    protected function shopParty(): array
    {
        $shop = $this->shop();
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $shop['address'])));

        return [
            'name' => $shop['name'],
            'address' => $shop['address'],
            'lines' => $lines,
            'email' => $shop['email'],
            'phone' => '',
        ];
    }

    /**
     * @param  array<string, mixed>  $address
     * @return array{name: string, address: string, email: string, phone: string, lines: list<string>}
     */
    protected function party(array $address, Order $order): array
    {
        $name = trim(($address['first_name'] ?? '').' '.($address['last_name'] ?? ''));
        if ($name === '') {
            $name = $order->customer?->displayName() ?: ($order->email ?: 'Customer');
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
            'email' => (string) ($address['email'] ?? $order->email ?: $order->customer?->email ?: ''),
            'phone' => (string) ($address['phone'] ?? ''),
        ];
    }

    protected function formatDate(?string $iso): string
    {
        return $iso ? (string) date('M j, Y', strtotime($iso)) : '—';
    }

    protected function formatDateTime(?string $iso): string
    {
        return $iso ? (string) date('M j, Y h:i A', strtotime($iso)) : '—';
    }
}
