<?php

namespace App\Domain\Settings;

use App\Domain\Localization\Models\Language;
use App\Domain\Localization\Money;
use App\Domain\Order\Models\Order;
use App\Domain\Settings\Models\DocumentTemplate;
use App\Domain\Settings\Models\EmailTemplate;

final class DocumentRenderer
{
    public function templateFor(string $type): ?DocumentTemplate
    {
        DocumentTemplateCatalog::seed();
        TemplateContentLocales::ensure();

        return DocumentTemplate::query()
            ->where('type', $type)
            ->where('is_in_use', true)
            ->where('is_enabled', true)
            ->orderBy('position')
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function render(string $type, Order $order, array $payload, ?callable $fallback = null): string
    {
        $template = $this->templateFor($type);
        if (! $template) {
            return $fallback ? (string) $fallback() : '';
        }
        $locale = $this->localeFor($order, $payload);
        $localized = TemplateContentLocales::resolveDocument($template, $locale);
        if (trim($localized['body']) === '') {
            return $fallback ? (string) $fallback() : '';
        }

        return EmailTemplate::replaceVars($localized['body'], $this->variables($type, $order, $payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function emailSubject(string $type, Order $order, array $payload, string $default): string
    {
        $template = $this->templateFor($type);
        $locale = $this->localeFor($order, $payload);
        $localized = $template
            ? TemplateContentLocales::resolveDocument($template, $locale)
            : ['email_subject' => $default];
        $subject = trim((string) ($localized['email_subject'] ?: $default));

        return EmailTemplate::replaceVars($subject, $this->variables($type, $order, $payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function localeFor(Order $order, array $payload): string
    {
        foreach ([$payload['language_code'] ?? null, $order->language_code ?? null, Language::currentCode()] as $code) {
            if (is_string($code) && trim($code) !== '') {
                return trim($code);
            }
        }

        return TemplateContentLocales::defaultCode();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    public function variables(string $type, Order $order, array $payload): array
    {
        $shop = $payload['shop'] ?? [];
        $currency = (string) ($payload['currency_code'] ?? $order->currency_code ?? 'USD');
        $money = fn ($amount) => Money::format((float) $amount, $currency);

        $billing = $payload['billing'] ?? [];
        $shipping = $payload['shipping'] ?? $payload['deliver_to'] ?? [];
        $shipFrom = $payload['ship_from'] ?? $shop;
        $deliver = $payload['deliver_to'] ?? $shipping;

        $vars = [
            'shop_name' => (string) ($shop['name'] ?? ''),
            'shop_email' => (string) ($shop['email'] ?? ''),
            'shop_phone' => (string) ($shop['phone'] ?? ''),
            'shop_address' => (string) ($shop['address'] ?? ''),
            'shop_address_html' => nl2br(e((string) ($shop['address'] ?? ''))),
            'shop_vat' => (string) ($shop['vat'] ?? ''),
            'shop_gst' => (string) ($shop['gst'] ?? ''),
            'shop_business_number' => (string) ($shop['business_number'] ?? ''),
            'order_number' => (string) ($payload['number'] ?? $payload['order_number'] ?? $order->number),
            'order_date' => $this->date($payload['created_at'] ?? $order->created_at),
            'customer_name' => (string) ($order->customer?->displayName() ?: ($billing['name'] ?? '')),
            'customer_email' => (string) ($payload['email'] ?? $order->email ?: $order->customer?->email ?: ''),
            'currency_code' => $currency,
            'items_count' => (string) count($payload['items'] ?? []),
            'items_rows' => $this->itemRows($type, $payload['items'] ?? [], $currency),
            'subtotal' => $money($payload['subtotal'] ?? 0),
            'discount_total' => $money($payload['discount_total'] ?? 0),
            'shipping_total' => $money($payload['shipping_total'] ?? 0),
            'tax_total' => $money($payload['tax_total'] ?? 0),
            'tax_label' => (string) ($payload['tax_label'] ?? 'Tax'),
            'grand_total' => $money($payload['grand_total'] ?? 0),
            'discount_row' => ((float) ($payload['discount_total'] ?? 0) > 0)
                ? '<tr><td>Discount</td><td style="text-align:right;">-'.$money($payload['discount_total']).'</td></tr>'
                : '',
            'billing_name' => (string) ($billing['name'] ?? ''),
            'billing_address' => (string) ($billing['address'] ?? ''),
            'billing_address_html' => nl2br(e((string) ($billing['address'] ?? ''))),
            'billing_email' => (string) ($billing['email'] ?? ''),
            'billing_phone' => (string) ($billing['phone'] ?? ''),
            'shipping_name' => (string) ($shipping['name'] ?? ''),
            'shipping_address' => (string) ($shipping['address'] ?? ''),
            'shipping_address_html' => nl2br(e((string) ($shipping['address'] ?? ''))),
            'shipping_email' => (string) ($shipping['email'] ?? ''),
            'shipping_phone' => (string) ($shipping['phone'] ?? ''),
            'payment_method' => (string) ($payload['payment_method'] ?? '—'),
            'payment_status' => (string) ($payload['payment_status'] ?? 'Paid'),
            'note' => (string) ($payload['note'] ?? ''),
        ];

        if ($type === DocumentTemplate::TYPE_INVOICE) {
            $vars['invoice_date'] = $this->date($payload['created_at'] ?? $order->created_at);
            $vars['payment_status'] = 'Paid';
        }

        if ($type === DocumentTemplate::TYPE_CREDIT_SLIP) {
            $vars['credit_date'] = $this->date($payload['created_at'] ?? $order->created_at);
            $vars['refunded_total'] = $money($payload['refunded_total'] ?? $payload['grand_total'] ?? 0);
        }

        if ($type === DocumentTemplate::TYPE_DELIVERY_SLIP) {
            $vars['delivery_date'] = $this->date($payload['delivered_at'] ?? $payload['created_at'] ?? $order->created_at);
            $vars['shipping_method'] = (string) ($payload['shipping_method'] ?? '—');
            $vars['tracking_number'] = (string) ($payload['tracking_number'] ?? '—');
            $vars['carrier'] = (string) ($payload['carrier'] ?? $payload['shipping_method'] ?? '—');
            $vars['status'] = (string) ($payload['status'] ?? $order->status);
            $vars['deliver_name'] = (string) ($deliver['name'] ?? '');
            $vars['deliver_address'] = (string) ($deliver['address'] ?? '');
            $vars['deliver_address_html'] = nl2br(e((string) ($deliver['address'] ?? '')));
            $vars['deliver_email'] = (string) ($deliver['email'] ?? '');
            $vars['deliver_phone'] = (string) ($deliver['phone'] ?? '');
            $vars['ship_from_name'] = (string) ($shipFrom['name'] ?? '');
            $vars['ship_from_address'] = (string) ($shipFrom['address'] ?? '');
            $vars['ship_from_address_html'] = nl2br(e((string) ($shipFrom['address'] ?? '')));
            $vars['ship_from_email'] = (string) ($shipFrom['email'] ?? '');
        }

        return $vars;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    protected function itemRows(string $type, array $items, string $currency): string
    {
        $rows = '';
        foreach ($items as $item) {
            $name = e((string) ($item['name'] ?? ''));
            $sku = e((string) ($item['sku'] ?? ''));
            $skuLabel = $sku !== '' ? $sku : '—';
            $qty = (int) ($item['qty'] ?? 0);
            $unit = Money::format((float) ($item['unit_price'] ?? 0), $currency);
            $total = Money::format((float) ($item['total'] ?? 0), $currency);

            if ($type === DocumentTemplate::TYPE_CREDIT_SLIP) {
                $refund = Money::format((float) ($item['refund_total'] ?? $item['total'] ?? 0), $currency);
                $rows .= '<tr><td>'.$name.'<div style="color:#737373;font-size:12px;">'.$sku.'</div></td>'
                    .'<td>'.$skuLabel.'</td>'
                    .'<td style="text-align:right;">'.$unit.'</td>'
                    .'<td style="text-align:center;">'.$qty.'</td>'
                    .'<td style="text-align:right;">'.$refund.'</td>'
                    .'<td style="text-align:right;">'.$total.'</td></tr>';

                continue;
            }

            if ($type === DocumentTemplate::TYPE_DELIVERY_SLIP) {
                $rows .= '<tr><td>'.$name.'</td><td>'.$skuLabel.'</td>'
                    .'<td style="text-align:center;">'.$qty.'</td>'
                    .'<td style="text-align:right;">'.$unit.'</td>'
                    .'<td style="text-align:right;">'.$total.'</td></tr>';

                continue;
            }

            $rows .= '<tr><td>'.$name.'<div style="color:#737373;font-size:12px;">'.$sku.'</div></td>'
                .'<td>'.$skuLabel.'</td>'
                .'<td style="text-align:right;">'.$unit.'</td>'
                .'<td style="text-align:center;">'.$qty.'</td>'
                .'<td style="text-align:right;">'.$total.'</td></tr>';
        }

        return $rows;
    }

    protected function date(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('M j, Y');
        }
        if (is_string($value) && $value !== '') {
            try {
                return \Carbon\Carbon::parse($value)->format('M j, Y');
            } catch (\Throwable) {
                return $value;
            }
        }

        return '—';
    }
}
