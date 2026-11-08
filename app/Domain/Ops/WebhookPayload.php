<?php

namespace App\Domain\Ops;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;

final class WebhookPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function product(Product $product): array
    {
        $product->loadMissing('translations');
        $translation = $product->translation();

        return [
            'id' => (int) $product->id,
            'sku' => (string) $product->sku,
            'type' => (string) $product->type,
            'price' => (float) $product->price,
            'stock_qty' => (float) $product->stock_qty,
            'stock_status' => (string) $product->stock_status,
            'is_enabled' => (bool) $product->is_enabled,
            'name' => (string) ($translation?->name ?: $product->sku),
            'slug' => (string) ($translation?->slug ?: ''),
            'updated_at' => optional($product->updated_at)?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function customer(Customer $customer): array
    {
        return [
            'id' => (int) $customer->id,
            'email' => (string) $customer->email,
            'name' => (string) $customer->name,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'phone' => $customer->phone,
            'is_enabled' => (bool) $customer->is_enabled,
            'customer_group' => $customer->customer_group,
            'created_at' => optional($customer->created_at)?->toIso8601String(),
            'updated_at' => optional($customer->updated_at)?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function order(Order $order): array
    {
        $order->loadMissing('items');

        return [
            'id' => (int) $order->id,
            'number' => (string) $order->number,
            'email' => (string) $order->email,
            'status' => (string) $order->status,
            'payment_status' => (string) $order->payment_status,
            'currency_code' => (string) $order->currency_code,
            'subtotal' => (float) $order->subtotal,
            'tax_total' => (float) $order->tax_total,
            'shipping_total' => (float) $order->shipping_total,
            'discount_total' => (float) $order->discount_total,
            'grand_total' => (float) $order->grand_total,
            'customer_id' => $order->customer_id ? (int) $order->customer_id : null,
            'billing_address' => self::address($order->billing_address),
            'shipping_address' => self::address($order->shipping_address),
            'items' => $order->items->map(fn ($item) => [
                'id' => (int) $item->id,
                'product_id' => $item->product_id ? (int) $item->product_id : null,
                'sku' => (string) $item->sku,
                'name' => (string) $item->name,
                'qty' => (float) $item->qty,
                'unit_price' => (float) $item->unit_price,
                'total' => (float) $item->total,
            ])->all(),
            'created_at' => optional($order->created_at)?->toIso8601String(),
            'updated_at' => optional($order->updated_at)?->toIso8601String(),
        ];
    }

    /**
     * Safe snapshot of a catalog/CMS/customer row. Never includes secrets or cost.
     *
     * @return array<string, mixed>
     */
    public static function record(object $model): array
    {
        $out = ['id' => (int) $model->getKey()];
        foreach (['sku', 'number', 'status', 'code', 'email', 'type', 'rating', 'order_id', 'customer_id', 'product_id', 'is_enabled'] as $field) {
            if (! isset($model->{$field})) {
                continue;
            }
            $out[$field] = $model->{$field};
        }
        foreach (['name', 'title', 'label'] as $field) {
            if (! empty($model->{$field})) {
                $out[$field] = (string) $model->{$field};
                break;
            }
        }
        if (method_exists($model, 'translation')) {
            $translation = $model->translation();
            foreach (['name', 'title'] as $field) {
                if (filled($translation?->{$field} ?? null) && empty($out['name']) && empty($out['title'])) {
                    $out[$field] = (string) $translation->{$field};
                }
            }
        }
        unset($out['password'], $out['secret'], $out['totp_secret'], $out['cost_price'], $out['sandbox_payment_token']);

        return $out;
    }

    /**
     * @param  mixed  $address
     * @return array<string, mixed>|null
     */
    protected static function address(mixed $address): ?array
    {
        if (! is_array($address)) {
            return null;
        }
        unset($address['sandbox_payment_token'], $address['card_number'], $address['cvv'], $address['cvc']);

        return $address;
    }
}
