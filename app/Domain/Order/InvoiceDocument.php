<?php

namespace App\Domain\Order;

use App\Domain\Localization\Money;
use App\Domain\Order\Models\Order;
use App\Domain\Settings\DocumentCommerceSettings;
use App\Domain\Settings\DocumentRenderer;
use App\Domain\Settings\Models\DocumentTemplate;
use App\Domain\Store\ShopIdentity;
use App\Domain\Store\StoreContext;
use Illuminate\Database\Eloquent\Builder;

class InvoiceDocument
{
    public const DISABLED_MESSAGE = 'Invoices are turned off. Turn them on under Configure → Orders → Invoices.';

    public function paidQuery(): Builder
    {
        if (! DocumentCommerceSettings::invoicesEnabled()) {
            return Order::query()->whereRaw('1 = 0');
        }

        $codes = DocumentCommerceSettings::createStatuses();

        return Order::query()->where(function ($query) use ($codes) {
            if ($codes !== []) {
                $query->whereIn('status', $codes);
            } else {
                $query->whereRaw('1 = 0');
            }
            $query->orWhere(function ($created) {
                $created->whereNotNull('fulfillment_details->invoice_number')
                    ->where('fulfillment_details->invoice_number', '!=', '');
            });
        });
    }

    public function ensurePaid(Order $order): void
    {
        if (! DocumentCommerceSettings::invoicesEnabled()) {
            abort(403, self::DISABLED_MESSAGE);
        }
        if (! $this->isAvailable($order)) {
            abort(404);
        }
    }

    public function isAvailable(Order $order): bool
    {
        if (! DocumentCommerceSettings::invoicesEnabled()) {
            return false;
        }

        return $this->matchesCreatePoint($order);
    }

    public function matchesCreatePoint(Order $order): bool
    {
        $details = is_array($order->fulfillment_details) ? $order->fulfillment_details : [];
        if (! empty($details['invoice_number']) && is_string($details['invoice_number'])) {
            return true;
        }

        return in_array((string) $order->status, DocumentCommerceSettings::createStatuses(), true);
    }

    public function maybeEmail(Order $order, \App\Domain\Settings\ShopMailer $mailer): void
    {
        if (! DocumentCommerceSettings::invoicesEnabled()) {
            return;
        }
        if (in_array((string) $order->status, DocumentCommerceSettings::createStatuses(), true)) {
            DocumentCommerceSettings::formatInvoiceNumber($order);
            $order->refresh();
        }
        if (! DocumentCommerceSettings::autoSend() || ! $this->isAvailable($order)) {
            return;
        }

        $details = is_array($order->fulfillment_details) ? $order->fulfillment_details : [];
        if (! empty($details['invoice_emailed_at'])) {
            return;
        }

        $to = $order->email ?: $order->customer?->email;
        if (! is_string($to) || $to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $shop = $this->shop()['name'];
        $subject = app(DocumentRenderer::class)->emailSubject(
            DocumentTemplate::TYPE_INVOICE,
            $order,
            $this->payload($order),
            'Invoice #'.$order->number.' from '.$shop,
        );
        $sent = $mailer->sendHtml($to, $subject, $this->html($order), 'invoice');
        if (! $sent) {
            return;
        }

        $details['invoice_emailed_at'] = now()->toIso8601String();
        $order->updateTrusted(['fulfillment_details' => $details]);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Order $order): array
    {
        $order->loadMissing(['items', 'customer', 'paymentMethod']);

        $rates = collect($order->tax_details ?? [])->pluck('rate')->unique()->filter(fn ($rate) => (float) $rate > 0);
        $taxLabel = $rates->count() === 1
            ? 'Tax ('.rtrim(rtrim(number_format((float) $rates->first(), 2, '.', ''), '0'), '.').'%)'
            : 'Tax';

        return [
            'id' => $order->id,
            'number' => DocumentCommerceSettings::formatInvoiceNumber($order),
            'created_at' => $order->created_at?->toIso8601String(),
            'currency_code' => $order->currency_code,
            'payment_status' => $order->payment_status,
            'payment_method' => $order->paymentMethod?->name,
            'email' => $order->email ?: $order->customer?->email,
            'shop' => $this->shop(),
            'billing' => $this->party($order->billing_address ?? [], $order),
            'shipping' => $this->party($order->shipping_address ?? [], $order),
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'sku' => $item->sku,
                'qty' => (int) $item->qty,
                'unit_price' => (float) $item->unit_price,
                'total' => (float) $item->total,
                'booking_date' => $item->booking_date?->toDateString(),
                'booking_end_date' => $item->booking_end_date?->toDateString(),
                'rental_duration' => $item->rental_duration,
                'rental_data' => is_array($item->rental_data) ? $item->rental_data : null,
                'preorder_data' => is_array($item->preorder_data) ? $item->preorder_data : null,
            ])->values()->all(),
            'subtotal' => (float) $order->subtotal,
            'discount_total' => (float) $order->discount_total,
            'shipping_total' => (float) $order->shipping_total,
            'tax_total' => (float) $order->tax_total,
            'tax_label' => $taxLabel,
            'grand_total' => (float) $order->grand_total,
        ];
    }

    public function html(Order $order): string
    {
        return app(DocumentRenderer::class)->render(
            DocumentTemplate::TYPE_INVOICE,
            $order,
            $this->payload($order),
            fn () => $this->fallbackHtml($order),
        );
    }

    public function fallbackHtml(Order $order): string
    {
        $invoice = $this->payload($order);
        $code = $invoice['currency_code'];
        $shop = $invoice['shop'];
        $rows = '';
        foreach ($invoice['items'] as $item) {
            $booking = '';
            if (! empty($item['booking_date'])) {
                $booking = '<div style="color:#737373;font-size:12px;">Rental: '.e($item['booking_date']);
                if (! empty($item['booking_end_date'])) {
                    $booking .= ' → '.e($item['booking_end_date']);
                }
                if (! empty($item['rental_duration'])) {
                    $booking .= ' ('.e((string) $item['rental_duration']).')';
                }
                $booking .= '</div>';
                $deposit = (float) (($item['rental_data']['deposit'] ?? 0));
                if ($deposit > 0) {
                    $booking .= '<div style="color:#737373;font-size:12px;">Deposit: '.e(number_format($deposit, 2)).'</div>';
                }
            }
            $rows .= '<tr>'
                .'<td>'.e($item['name']).'<div style="color:#737373;font-size:12px;">'.e($item['sku'] ?: '').'</div>'.$booking.'</td>'
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
            .'<div class="muted">Invoice Date: '.e($order->created_at?->format('M j, Y') ?: '—').'</div>'
            .'<div class="muted">Order Date: '.e($order->created_at?->format('M j, Y') ?: '—').'</div>'
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
            .'<table style="margin-top:48px;"><tr><td class="muted">Thank you for shopping with us!</td><td style="text-align:right;" class="muted">Authorized Signature<br><span style="display:inline-block;margin-top:32px;border-top:1px solid #d4d4d4;width:140px;"></span></td></tr></table>'
            .'</body></html>';
    }

    /**
     * @return array{name: string, email: string, address: string, phone: string, vat: string, gst: string, business_number: string}
     */
    public function shop(): array
    {
        return ShopIdentity::forDocuments(app(StoreContext::class)->store());
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
}
