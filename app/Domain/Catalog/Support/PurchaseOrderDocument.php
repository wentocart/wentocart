<?php

namespace App\Domain\Catalog\Support;

use App\Domain\Catalog\Models\PurchaseOrder;
use App\Domain\Catalog\Models\PurchaseOrderItem;
use App\Domain\Settings\Models\Setting;

final class PurchaseOrderDocument
{
    public function html(PurchaseOrder $order): string
    {
        $order->loadMissing(['items', 'supplier.translations', 'warehouse']);
        $shop = (string) (Setting::getValue('shop_name', config('app.name')) ?: config('app.name') ?: 'Store');
        $supplier = e($order->supplier?->displayName() ?: '—');
        $warehouse = e($order->warehouse?->name ?: '—');
        $number = e($order->number ?: '');
        $status = e($order->status ?: '');
        $expected = e($order->expected_at?->toDateString() ?: '—');
        $ordered = e($order->ordered_at?->toDateTimeString() ?: '—');
        $notes = e($order->notes ?: '');
        $subtotal = number_format($order->lineSubtotal(), 2, '.', ',');
        $discount = number_format($order->discountAmount(), 2, '.', ',');
        $shipping = number_format((float) $order->shipping_cost, 2, '.', ',');
        $grand = number_format($order->grandTotal(), 2, '.', ',');
        $discountLabel = $order->discount_type
            ? e($order->discount_type).' ('.e((string) $order->discount_value).')'
            : '—';

        $rows = '';
        foreach ($order->items as $item) {
            /** @var PurchaseOrderItem $item */
            $rows .= '<tr>'
                .'<td>'.e($item->sku).'</td>'
                .'<td>'.e($item->name).'</td>'
                .'<td style="text-align:right">'.e(number_format((float) $item->qty_ordered, 3, '.', ',')).'</td>'
                .'<td style="text-align:right">'.e(number_format((float) $item->unit_cost, 4, '.', ',')).'</td>'
                .'<td style="text-align:right">'.e(number_format($item->lineTotal(), 2, '.', ',')).'</td>'
                .'</tr>';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Purchase Order {$number}</title>
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; margin: 24px; }
  h1 { font-size: 20px; margin: 0 0 4px; }
  .meta { color: #555; margin-bottom: 18px; }
  table { width: 100%; border-collapse: collapse; margin-top: 12px; }
  th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
  th { background: #f3f3f3; }
  .totals { width: 280px; margin-left: auto; margin-top: 16px; }
  .totals td { border: none; padding: 4px 0; }
  .totals .label { color: #555; }
  .totals .grand { font-weight: bold; font-size: 14px; border-top: 1px solid #999; padding-top: 8px; }
  .notes { margin-top: 20px; }
</style>
</head>
<body>
  <h1>{$shop}</h1>
  <div class="meta">Purchase Order <strong>{$number}</strong> · Status: {$status}</div>
  <table>
    <tr><th>Supplier</th><td>{$supplier}</td><th>Warehouse</th><td>{$warehouse}</td></tr>
    <tr><th>Expected</th><td>{$expected}</td><th>Ordered</th><td>{$ordered}</td></tr>
  </table>
  <table>
    <thead>
      <tr>
        <th>SKU</th>
        <th>Product</th>
        <th style="text-align:right">Qty</th>
        <th style="text-align:right">Unit cost</th>
        <th style="text-align:right">Line total</th>
      </tr>
    </thead>
    <tbody>
      {$rows}
    </tbody>
  </table>
  <table class="totals">
    <tr><td class="label">Subtotal</td><td style="text-align:right">{$subtotal}</td></tr>
    <tr><td class="label">Discount ({$discountLabel})</td><td style="text-align:right">{$discount}</td></tr>
    <tr><td class="label">Shipping</td><td style="text-align:right">{$shipping}</td></tr>
    <tr class="grand"><td>Grand total</td><td style="text-align:right">{$grand}</td></tr>
  </table>
  <div class="notes"><strong>Notes</strong><br>{$notes}</div>
</body>
</html>
HTML;
    }
}
