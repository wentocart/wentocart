<?php

namespace App\Domain\Settings;

use App\Domain\Settings\Models\DocumentTemplate;

final class DocumentTemplateCatalog
{
    public static function seed(): void
    {
        foreach (self::defaults() as $row) {
            DocumentTemplate::query()->firstOrCreate(
                ['code' => $row['code']],
                $row,
            );
        }

        self::ensureInUse();
    }

    /**
     * Invoice, credit slip, and delivery slip each attach one template.
     *
     * @return list<string>
     */
    public static function attachableTypes(): array
    {
        return [
            DocumentTemplate::TYPE_INVOICE,
            DocumentTemplate::TYPE_CREDIT_SLIP,
            DocumentTemplate::TYPE_DELIVERY_SLIP,
        ];
    }

    public static function markInUse(DocumentTemplate $template): void
    {
        DocumentTemplate::query()
            ->where('type', $template->type)
            ->whereKeyNot($template->id)
            ->update(['is_in_use' => false]);

        $template->update([
            'is_in_use' => true,
            'is_enabled' => true,
        ]);
    }

    /**
     * Keep one enabled template flagged for each attachable type.
     */
    public static function ensureInUse(): void
    {
        foreach (self::attachableTypes() as $type) {
            $current = DocumentTemplate::query()
                ->where('type', $type)
                ->where('is_in_use', true)
                ->where('is_enabled', true)
                ->orderBy('position')
                ->orderBy('id')
                ->first();

            if ($current) {
                DocumentTemplate::query()
                    ->where('type', $type)
                    ->whereKeyNot($current->id)
                    ->where('is_in_use', true)
                    ->update(['is_in_use' => false]);

                continue;
            }

            $winner = DocumentTemplate::query()
                ->where('type', $type)
                ->where('is_enabled', true)
                ->orderBy('position')
                ->orderBy('id')
                ->first();

            if ($winner) {
                self::markInUse($winner);
            }
        }
    }

    public static function customBody(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{shop_name}} #{{order_number}}</title>
<style>
body{font-family:Arial,sans-serif;color:#171717;margin:32px;}
table{width:100%;border-collapse:collapse;}
th,td{padding:8px 0;border-bottom:1px solid #eee;text-align:left;font-size:14px;}
.muted{color:#737373;font-size:13px;}
</style>
</head>
<body>
<strong style="font-size:18px;">{{shop_name}}</strong>
<div class="muted">{{shop_address_html}}</div>
<h1>{{order_number}}</h1>
<p class="muted">{{order_date}} · {{customer_name}}</p>
<table>
<thead><tr><th>Item</th><th>SKU</th><th style="text-align:right;">Price</th><th style="text-align:center;">Qty</th><th style="text-align:right;">Total</th></tr></thead>
<tbody>{{items_rows}}</tbody>
</table>
</body>
</html>
HTML;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            [
                'code' => 'invoice',
                'name' => 'Invoice',
                'type' => DocumentTemplate::TYPE_INVOICE,
                'email_subject' => 'Invoice #{{order_number}} from {{shop_name}}',
                'body' => self::invoiceBody(),
                'design_json' => null,
                'is_system' => true,
                'is_custom' => false,
                'is_enabled' => true,
                'is_in_use' => true,
                'position' => 10,
            ],
            [
                'code' => 'credit-slip',
                'name' => 'Credit slip',
                'type' => DocumentTemplate::TYPE_CREDIT_SLIP,
                'email_subject' => 'Credit slip #{{order_number}} from {{shop_name}}',
                'body' => self::creditSlipBody(),
                'design_json' => null,
                'is_system' => true,
                'is_custom' => false,
                'is_enabled' => true,
                'is_in_use' => true,
                'position' => 20,
            ],
            [
                'code' => 'delivery-slip',
                'name' => 'Delivery slip',
                'type' => DocumentTemplate::TYPE_DELIVERY_SLIP,
                'email_subject' => 'Delivery slip #{{order_number}} from {{shop_name}}',
                'body' => self::deliverySlipBody(),
                'design_json' => null,
                'is_system' => true,
                'is_custom' => false,
                'is_enabled' => true,
                'is_in_use' => true,
                'position' => 30,
            ],
        ];
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public static function variablesFor(?string $type = null): array
    {
        $shared = [
            ['key' => 'shop_name', 'label' => 'Shop name'],
            ['key' => 'shop_email', 'label' => 'Shop email'],
            ['key' => 'shop_phone', 'label' => 'Shop phone'],
            ['key' => 'shop_address', 'label' => 'Shop address'],
            ['key' => 'shop_vat', 'label' => 'VAT number'],
            ['key' => 'shop_gst', 'label' => 'GST number'],
            ['key' => 'shop_business_number', 'label' => 'Business number'],
            ['key' => 'order_number', 'label' => 'Order number'],
            ['key' => 'order_date', 'label' => 'Order date'],
            ['key' => 'customer_name', 'label' => 'Customer name'],
            ['key' => 'customer_email', 'label' => 'Customer email'],
            ['key' => 'items_rows', 'label' => 'Line items (HTML rows)'],
            ['key' => 'items_count', 'label' => 'Item count'],
        ];

        $money = [
            ['key' => 'subtotal', 'label' => 'Subtotal'],
            ['key' => 'discount_total', 'label' => 'Discount'],
            ['key' => 'discount_row', 'label' => 'Discount row (HTML)'],
            ['key' => 'shipping_total', 'label' => 'Shipping'],
            ['key' => 'tax_total', 'label' => 'Tax'],
            ['key' => 'tax_label', 'label' => 'Tax label'],
            ['key' => 'grand_total', 'label' => 'Grand total'],
            ['key' => 'currency_code', 'label' => 'Currency'],
        ];

        return match ($type) {
            DocumentTemplate::TYPE_INVOICE => [
                ...$shared,
                ['key' => 'invoice_date', 'label' => 'Invoice date'],
                ['key' => 'payment_method', 'label' => 'Payment method'],
                ['key' => 'payment_status', 'label' => 'Payment status'],
                ['key' => 'billing_name', 'label' => 'Billing name'],
                ['key' => 'billing_address', 'label' => 'Billing address'],
                ['key' => 'billing_email', 'label' => 'Billing email'],
                ['key' => 'billing_phone', 'label' => 'Billing phone'],
                ['key' => 'shipping_name', 'label' => 'Shipping name'],
                ['key' => 'shipping_address', 'label' => 'Shipping address'],
                ['key' => 'shipping_email', 'label' => 'Shipping email'],
                ['key' => 'shipping_phone', 'label' => 'Shipping phone'],
                ...$money,
            ],
            DocumentTemplate::TYPE_CREDIT_SLIP => [
                ...$shared,
                ['key' => 'credit_date', 'label' => 'Credit date'],
                ['key' => 'payment_method', 'label' => 'Payment method'],
                ['key' => 'refunded_total', 'label' => 'Refunded total'],
                ['key' => 'note', 'label' => 'Note'],
                ['key' => 'billing_name', 'label' => 'Billing name'],
                ['key' => 'billing_address', 'label' => 'Billing address'],
                ['key' => 'billing_email', 'label' => 'Billing email'],
                ['key' => 'billing_phone', 'label' => 'Billing phone'],
                ['key' => 'shipping_name', 'label' => 'Shipping name'],
                ['key' => 'shipping_address', 'label' => 'Shipping address'],
                ...$money,
            ],
            DocumentTemplate::TYPE_DELIVERY_SLIP => [
                ...$shared,
                ['key' => 'delivery_date', 'label' => 'Delivery date'],
                ['key' => 'shipping_method', 'label' => 'Shipping method'],
                ['key' => 'tracking_number', 'label' => 'Tracking number'],
                ['key' => 'carrier', 'label' => 'Carrier'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'note', 'label' => 'Package note'],
                ['key' => 'deliver_name', 'label' => 'Deliver-to name'],
                ['key' => 'deliver_address', 'label' => 'Deliver-to address'],
                ['key' => 'deliver_email', 'label' => 'Deliver-to email'],
                ['key' => 'deliver_phone', 'label' => 'Deliver-to phone'],
                ['key' => 'ship_from_name', 'label' => 'Ship-from name'],
                ['key' => 'ship_from_address', 'label' => 'Ship-from address'],
                ['key' => 'ship_from_email', 'label' => 'Ship-from email'],
            ],
            default => [
                ...$shared,
                ...$money,
                ['key' => 'billing_name', 'label' => 'Billing name'],
                ['key' => 'billing_address', 'label' => 'Billing address'],
                ['key' => 'shipping_name', 'label' => 'Shipping name'],
                ['key' => 'shipping_address', 'label' => 'Shipping address'],
                ['key' => 'payment_method', 'label' => 'Payment method'],
                ['key' => 'note', 'label' => 'Note'],
            ],
        };
    }

    protected static function invoiceBody(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Invoice #{{order_number}}</title>
<style>
body{font-family:Arial,sans-serif;color:#171717;margin:32px;}
table{width:100%;border-collapse:collapse;}
th,td{padding:8px 0;border-bottom:1px solid #eee;text-align:left;font-size:14px;}
th{color:#737373;font-size:12px;text-transform:uppercase;}
h1{margin:0 0 8px;}
.muted{color:#737373;font-size:13px;}
.paid{color:#059669;font-weight:700;}
.total{color:#6d28d9;font-size:20px;font-weight:700;}
</style>
</head>
<body>
<table>
<tr>
<td>
<strong style="font-size:18px;">{{shop_name}}</strong>
<div class="muted">{{shop_address_html}}</div>
<div class="muted">{{shop_email}}</div>
</td>
<td style="text-align:right;">
<h1>INVOICE #{{order_number}}</h1>
<div class="muted">Invoice Date: {{invoice_date}}</div>
<div class="muted">Order Date: {{order_date}}</div>
<div class="muted">Payment Method: {{payment_method}}</div>
<div>Payment Status: <span class="paid">{{payment_status}}</span></div>
</td>
</tr>
</table>
<table style="margin-top:24px;">
<tr>
<td>
<div class="muted">Bill To</div>
<strong>{{billing_name}}</strong>
<div class="muted">{{billing_address_html}}</div>
<div class="muted">{{billing_email}}</div>
<div class="muted">{{billing_phone}}</div>
</td>
<td>
<div class="muted">Ship To</div>
<strong>{{shipping_name}}</strong>
<div class="muted">{{shipping_address_html}}</div>
<div class="muted">{{shipping_email}}</div>
<div class="muted">{{shipping_phone}}</div>
</td>
</tr>
</table>
<table style="margin-top:24px;">
<thead>
<tr><th>Item</th><th>SKU</th><th style="text-align:right;">Price</th><th style="text-align:center;">Qty</th><th style="text-align:right;">Total</th></tr>
</thead>
<tbody>{{items_rows}}</tbody>
</table>
<table style="width:280px;margin-left:auto;margin-top:16px;">
<tr><td>Subtotal</td><td style="text-align:right;">{{subtotal}}</td></tr>
{{discount_row}}
<tr><td>Shipping</td><td style="text-align:right;">{{shipping_total}}</td></tr>
<tr><td>{{tax_label}}</td><td style="text-align:right;">{{tax_total}}</td></tr>
<tr><td class="total">Total</td><td class="total" style="text-align:right;">{{grand_total}}</td></tr>
</table>
<table style="margin-top:48px;">
<tr>
<td class="muted">Thank you for shopping with us!</td>
<td style="text-align:right;" class="muted">Authorized Signature<br><span style="display:inline-block;margin-top:32px;border-top:1px solid #d4d4d4;width:140px;"></span></td>
</tr>
</table>
</body>
</html>
HTML;
    }

    protected static function creditSlipBody(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Credit Slip #{{order_number}}</title>
<style>
body{font-family:Arial,sans-serif;color:#171717;margin:32px;}
table{width:100%;border-collapse:collapse;}
th,td{padding:8px 0;border-bottom:1px solid #eee;text-align:left;font-size:14px;}
th{color:#737373;font-size:12px;text-transform:uppercase;}
h1{margin:0 0 8px;}
.muted{color:#737373;font-size:13px;}
.total{color:#6d28d9;font-size:20px;font-weight:700;}
</style>
</head>
<body>
<table>
<tr>
<td>
<strong style="font-size:18px;">{{shop_name}}</strong>
<div class="muted">{{shop_address_html}}</div>
<div class="muted">{{shop_email}}</div>
</td>
<td style="text-align:right;">
<h1>CREDIT SLIP #{{order_number}}</h1>
<div class="muted">Credit Date: {{credit_date}}</div>
<div class="muted">Order Date: {{order_date}}</div>
<div class="muted">Payment Method: {{payment_method}}</div>
</td>
</tr>
</table>
<table style="margin-top:24px;">
<tr>
<td>
<div class="muted">Bill To</div>
<strong>{{billing_name}}</strong>
<div class="muted">{{billing_address_html}}</div>
</td>
<td>
<div class="muted">Ship To</div>
<strong>{{shipping_name}}</strong>
<div class="muted">{{shipping_address_html}}</div>
</td>
</tr>
</table>
<table style="margin-top:24px;">
<thead>
<tr><th>Item</th><th>SKU</th><th style="text-align:right;">Price</th><th style="text-align:center;">Qty</th><th style="text-align:right;">Refund</th><th style="text-align:right;">Total</th></tr>
</thead>
<tbody>{{items_rows}}</tbody>
</table>
<table style="width:280px;margin-left:auto;margin-top:16px;">
<tr><td>Subtotal</td><td style="text-align:right;">{{subtotal}}</td></tr>
{{discount_row}}
<tr><td>Shipping</td><td style="text-align:right;">{{shipping_total}}</td></tr>
<tr><td>{{tax_label}}</td><td style="text-align:right;">{{tax_total}}</td></tr>
<tr><td class="total">Refunded</td><td class="total" style="text-align:right;">{{refunded_total}}</td></tr>
</table>
<p class="muted" style="margin-top:32px;">{{note}}</p>
</body>
</html>
HTML;
    }

    protected static function deliverySlipBody(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Delivery Slip #{{order_number}}</title>
<style>
body{font-family:Arial,sans-serif;color:#171717;margin:32px;}
table{width:100%;border-collapse:collapse;}
th,td{padding:8px 0;border-bottom:1px solid #eee;text-align:left;font-size:14px;}
th{color:#737373;font-size:12px;text-transform:uppercase;}
h1{margin:0 0 8px;}
.muted{color:#737373;font-size:13px;}
.ok{color:#059669;font-weight:700;}
.box{border:1px solid #e5e7eb;border-radius:12px;padding:16px;}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.strip{margin-top:16px;padding:12px;background:#f7f8fa;border-radius:8px;}
</style>
</head>
<body>
<table>
<tr>
<td>
<strong style="font-size:18px;">{{shop_name}}</strong>
<div class="muted">{{shop_address_html}}</div>
<div class="muted">Email: {{shop_email}}</div>
</td>
<td style="text-align:right;">
<h1>DELIVERY SLIP #{{order_number}}</h1>
<div class="muted">Delivery Date: {{delivery_date}}</div>
<div class="muted">Order Number: #{{order_number}}</div>
<div class="muted">Order Date: {{order_date}}</div>
<div class="muted">Shipping Method: {{shipping_method}}</div>
<div class="muted">Tracking Number: {{tracking_number}}</div>
<div>Status: <span class="ok">{{status}}</span></div>
</td>
</tr>
</table>
<table style="margin-top:24px;">
<tr>
<td>
<div class="muted">Deliver To</div>
<strong>{{deliver_name}}</strong>
<div class="muted">{{deliver_address_html}}</div>
<div class="muted">{{deliver_email}}</div>
<div class="muted">{{deliver_phone}}</div>
</td>
<td>
<div class="muted">Ship From</div>
<strong>{{ship_from_name}}</strong>
<div class="muted">{{ship_from_address_html}}</div>
<div class="muted">{{ship_from_email}}</div>
</td>
</tr>
</table>
<div class="strip">
<table>
<tr>
<td>Carrier</td><td>{{carrier}}</td>
<td>Tracking Number</td><td>{{tracking_number}}</td>
<td>Shipped On</td><td>{{delivery_date}}</td>
</tr>
</table>
</div>
<table style="margin-top:24px;">
<thead>
<tr><th>Item</th><th>SKU</th><th style="text-align:center;">Qty</th><th style="text-align:right;">Unit</th><th style="text-align:right;">Total</th></tr>
</thead>
<tbody>{{items_rows}}</tbody>
</table>
<div style="text-align:right;margin-top:16px;font-weight:700;">Total Items: {{items_count}}</div>
<div class="grid" style="margin-top:24px;">
<div class="box"><strong>Package Note</strong><div class="muted" style="margin-top:8px;white-space:pre-line;">{{note}}</div></div>
<div class="box"><strong>Received By</strong><table style="margin-top:12px;"><tr><td>Name</td><td style="text-align:right;">____________________</td></tr><tr><td>Signature</td><td style="text-align:right;">____________________</td></tr><tr><td>Date</td><td style="text-align:right;">____________________</td></tr></table></div>
</div>
</body>
</html>
HTML;
    }
}
