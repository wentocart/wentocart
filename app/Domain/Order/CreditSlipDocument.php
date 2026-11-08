<?php

namespace App\Domain\Order;

use App\Domain\Localization\Money;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderStage;
use App\Domain\Settings\DocumentRenderer;
use App\Domain\Settings\Models\DocumentTemplate;
use App\Domain\Settings\Models\Setting;
use App\Domain\Store\StoreContext;
use Illuminate\Database\Eloquent\Builder;

class CreditSlipDocument
{
    public function refundedQuery(): Builder
    {
        $codes = OrderStage::query()->where('unlock_credit_slip', true)->pluck('code')->all();

        return Order::query()->where(function ($query) use ($codes) {
            $query->where('payment_status', 'refunded')
                ->orWhere('payment_status', 'partially_refunded')
                ->orWhere('status', 'refunded')
                ->orWhere('refunded_total', '>', 0);
            if ($codes !== []) {
                $query->orWhereIn('status', $codes);
            }
        });
    }

    public function ensureRefunded(Order $order): void
    {
        if ((float) ($order->refunded_total ?? 0) > 0) {
            return;
        }
        if (in_array($order->payment_status, ['refunded', 'partially_refunded'], true) || $order->status === 'refunded') {
            return;
        }

        $stage = OrderStage::query()->where('code', $order->status)->first();
        if ($stage?->unlock_credit_slip) {
            return;
        }

        abort(404);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Order $order): array
    {
        $order->loadMissing(['items.product:id,cover_path', 'customer', 'paymentMethod', 'statusHistories']);

        $refundEvent = $order->statusHistories
            ->where('status', 'refunded')
            ->sortByDesc('created_at')
            ->first();

        $rates = collect($order->tax_details ?? [])->pluck('rate')->unique()->filter(fn ($rate) => (float) $rate > 0);
        $taxLabel = $rates->count() === 1
            ? 'Tax ('.rtrim(rtrim(number_format((float) $rates->first(), 2, '.', ''), '0'), '.').'%)'
            : 'Tax';

        $details = is_array($order->payment_details) ? $order->payment_details : [];
        $refundRows = is_array($details['refunds'] ?? null) ? $details['refunds'] : [];
        $refundedItems = collect($refundRows)
            ->flatMap(fn ($row) => is_array($row['items'] ?? null) ? $row['items'] : [])
            ->map(fn ($item, $index) => [
                'id' => $item['order_item_id'] ?? $index,
                'name' => $item['name'] ?? 'Item',
                'sku' => $item['sku'] ?? '',
                'qty' => (float) ($item['qty'] ?? 0),
                'unit_price' => (float) ($item['unit_price'] ?? 0),
                'refund_total' => (float) ($item['total'] ?? 0),
                'total' => (float) ($item['total'] ?? 0),
                'cover_path' => null,
            ])
            ->values()
            ->all();

        $items = $refundedItems !== []
            ? $refundedItems
            : $order->items->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'sku' => $item->sku,
                'qty' => (int) $item->qty,
                'unit_price' => (float) $item->unit_price,
                'refund_total' => (float) $item->total,
                'total' => (float) $item->total,
                'cover_path' => $item->product?->cover_path,
            ])->values()->all();

        $refundedTotal = (float) ($order->refunded_total ?? 0);
        if ($refundedTotal <= 0) {
            $refundedTotal = (float) ($details['paid_total'] ?? $order->grand_total);
        }

        return [
            'id' => $order->id,
            'number' => $order->number,
            'credit_number' => \App\Domain\Settings\DocumentCommerceSettings::formatCreditNumber($order),
            'order_number' => $order->number,
            'created_at' => $order->created_at?->toIso8601String(),
            'refunded_at' => $refundEvent?->created_at?->toIso8601String() ?: $order->updated_at?->toIso8601String(),
            'currency_code' => $order->currency_code,
            'payment_status' => $order->payment_status,
            'payment_method' => $order->paymentMethod?->name,
            'refund_method' => $order->paymentMethod?->name,
            'refund_reason' => $refundEvent?->comment ?: 'Payment refunded',
            'refund_status' => 'Refunded',
            'transaction_id' => null,
            'email' => $order->email ?: $order->customer?->email,
            'shop' => $this->shop(),
            'billing' => $this->party($order->billing_address ?? [], $order),
            'shipping' => $this->party($order->shipping_address ?? [], $order),
            'items' => $items,
            'subtotal' => (float) $order->subtotal,
            'discount_total' => (float) $order->discount_total,
            'shipping_total' => (float) $order->shipping_total,
            'tax_total' => (float) $order->tax_total,
            'tax_label' => $taxLabel,
            'grand_total' => (float) $order->grand_total,
            'refunded_total' => $refundedTotal,
            'note' => 'Thank you for your purchase. We hope to see you again!',
        ];
    }

    public function html(Order $order): string
    {
        return app(DocumentRenderer::class)->render(
            DocumentTemplate::TYPE_CREDIT_SLIP,
            $order,
            $this->payload($order),
            fn () => $this->fallbackHtml($order),
        );
    }

    public function fallbackHtml(Order $order): string
    {
        $creditSlip = $this->payload($order);
        $code = $creditSlip['currency_code'];
        $shop = $creditSlip['shop'];
        $rows = '';

        foreach ($creditSlip['items'] as $item) {
            $rows .= '<tr>'
                .'<td>'.e($item['name']).'<div style="color:#737373;font-size:12px;">'.e($item['sku'] ?: '').'</div></td>'
                .'<td>'.e($item['sku'] ?: '—').'</td>'
                .'<td style="text-align:right;">'.e(Money::format($item['unit_price'], $code)).'</td>'
                .'<td style="text-align:center;">'.(int) $item['qty'].'</td>'
                .'<td style="text-align:right;">'.e(Money::format($item['refund_total'], $code)).'</td>'
                .'<td style="text-align:right;">'.e(Money::format($item['total'], $code)).'</td>'
                .'</tr>';
        }

        $discountRow = (float) $creditSlip['discount_total'] > 0
            ? '<tr><td>Discount</td><td style="text-align:right;">-'.e(Money::format($creditSlip['discount_total'], $code)).'</td></tr>'
            : '';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Credit Slip #'.e($creditSlip['number']).'</title>'
            .'<style>body{font-family:Arial,sans-serif;color:#171717;margin:32px;}table{width:100%;border-collapse:collapse;}th,td{padding:8px 0;border-bottom:1px solid #eee;text-align:left;font-size:14px;}th{color:#737373;font-size:12px;text-transform:uppercase;}h1{margin:0 0 8px;} .muted{color:#737373;font-size:13px;} .paid{color:#16a34a;font-weight:700;} .total{color:#6d28d9;font-size:20px;font-weight:700;} .box{border:1px solid #e5e7eb;border-radius:12px;padding:16px;} .grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}</style>'
            .'</head><body>'
            .'<table><tr><td>'
            .'<strong style="font-size:18px;">'.e($shop['name']).'</strong>'
            .'<div class="muted">'.nl2br(e($shop['address'])).'</div>'
            .($shop['email'] !== '' ? '<div class="muted">Email: '.e($shop['email']).'</div>' : '')
            .'</td><td style="text-align:right;"><h1>CREDIT SLIP #'.e($creditSlip['number']).'</h1>'
            .'<div class="muted">Credit note: '.e($creditSlip['credit_number'] ?? $creditSlip['number']).'</div>'
            .'<div class="muted">Credit Slip Date: '.e($this->formatDate($creditSlip['refunded_at'])).'</div>'
            .'<div class="muted">Order Number: #'.e($creditSlip['order_number']).'</div>'
            .'<div class="muted">Order Date: '.e($this->formatDate($creditSlip['created_at'])).'</div>'
            .'<div class="muted">Reason: '.e($creditSlip['refund_reason']).'</div>'
            .'<div class="muted">Refund Method: '.e($creditSlip['refund_method'] ?: '—').'</div>'
            .'<div>Status: <span class="paid">Refunded</span></div>'
            .'</td></tr></table>'
            .'<table style="margin-top:24px;"><tr><td><div class="muted">Bill To</div><strong>'.e($creditSlip['billing']['name']).'</strong>'
            .'<div class="muted">'.nl2br(e($creditSlip['billing']['address'])).'</div>'
            .'<div class="muted">'.e($creditSlip['billing']['email']).'</div>'
            .'<div class="muted">'.e($creditSlip['billing']['phone']).'</div></td>'
            .'<td><div class="muted">Ship To</div><strong>'.e($creditSlip['shipping']['name']).'</strong>'
            .'<div class="muted">'.nl2br(e($creditSlip['shipping']['address'])).'</div>'
            .'<div class="muted">'.e($creditSlip['shipping']['email']).'</div>'
            .'<div class="muted">'.e($creditSlip['shipping']['phone']).'</div></td></tr></table>'
            .'<table style="margin-top:24px;"><thead><tr><th>Item</th><th>SKU</th><th style="text-align:right;">Price</th><th style="text-align:center;">Qty</th><th style="text-align:right;">Refund</th><th style="text-align:right;">Total</th></tr></thead><tbody>'
            .$rows
            .'</tbody></table>'
            .'<div class="grid" style="margin-top:24px;"><div class="box"><strong>Credit & Refund Summary</strong>'
            .'<table style="margin-top:12px;"><tr><td>Subtotal</td><td style="text-align:right;">'.e(Money::format($creditSlip['subtotal'], $code)).'</td></tr>'
            .$discountRow
            .'<tr><td>Shipping</td><td style="text-align:right;">'.e(Money::format($creditSlip['shipping_total'], $code)).'</td></tr>'
            .'<tr><td>'.e($creditSlip['tax_label']).'</td><td style="text-align:right;">'.e(Money::format($creditSlip['tax_total'], $code)).'</td></tr>'
            .'<tr><td class="total">Total Refunded</td><td class="total" style="text-align:right;">'.e(Money::format($creditSlip['refunded_total'], $code)).'</td></tr></table></div>'
            .'<div><div class="box"><strong>Refund To</strong><table style="margin-top:12px;">'
            .'<tr><td>Method</td><td style="text-align:right;">'.e($creditSlip['refund_method'] ?: '—').'</td></tr>'
            .'<tr><td>Transaction ID</td><td style="text-align:right;">'.e($creditSlip['transaction_id'] ?: '—').'</td></tr>'
            .'<tr><td>Refunded On</td><td style="text-align:right;">'.e($this->formatDateTime($creditSlip['refunded_at'])).'</td></tr>'
            .'</table></div><div class="box" style="margin-top:16px;"><strong>Note</strong><div class="muted" style="margin-top:8px;">'.e($creditSlip['note']).'</div></div></div></div>'
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
