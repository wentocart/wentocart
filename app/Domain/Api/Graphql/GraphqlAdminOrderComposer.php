<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Localization\Models\Currency;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Services\OrderNumberService;
use App\Domain\Ops\ShopStats;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Support\Facades\DB;

/**
 */
final class GraphqlAdminOrderComposer
{
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function run(array $args): array
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! $admin->hasPermission('orders.manage')) {
            throw new UserError('Forbidden.');
        }

        $customer = Customer::query()->find((int) $args['customerId']);
        if (! $customer) {
            throw new UserError('Customer not found.');
        }

        $rawItems = $args['items'] ?? [];
        if (! is_array($rawItems) || $rawItems === []) {
            throw new UserError('At least one line item is required.');
        }

        $lines = [];
        $subtotal = 0.0;
        foreach ($rawItems as $item) {
            $productId = (int) ($item['productId'] ?? 0);
            $qty = (float) ($item['quantity'] ?? 0);
            if ($productId < 1 || $qty <= 0) {
                throw new UserError('Each item needs a productId and quantity > 0.');
            }
            $product = Product::query()->where('is_enabled', true)->find($productId);
            if (! $product) {
                throw new UserError('Product '.$productId.' was not found or is disabled.');
            }
            if ($product->isService() || $product->isRental()) {
                throw new UserError(
                    'Booking products cannot be added to an admin draft order. Booking purchases must be made through the customer storefront.'
                );
            }
            $combinationId = isset($item['combinationId']) && $item['combinationId'] !== null && $item['combinationId'] !== ''
                ? (int) $item['combinationId']
                : null;
            $unit = (float) ($product->price ?? 0);
            $sku = (string) $product->sku;
            if ($product->isCombination()) {
                if (! $combinationId) {
                    throw new UserError('selectedVariantOption is required for combination products.');
                }
                $variant = ProductVariant::query()
                    ->where('is_enabled', true)
                    ->where('product_id', $product->id)
                    ->whereKey($combinationId)
                    ->first();
                if (! $variant) {
                    throw new UserError('Selected configurable option was not found for product '.$productId.'.');
                }
                $unit = (float) ($variant->price ?? $unit);
                $sku = (string) ($variant->sku ?: $sku);
            } elseif ($combinationId) {
                throw new UserError('combinationId is only valid for combination products.');
            }
            $lineTotal = round($unit * $qty, 2);
            $subtotal += $lineTotal;
            $locale = app()->getLocale();
            $lines[] = [
                'product' => $product,
                'product_id' => $product->id,
                'combination_id' => $combinationId,
                'sku' => $sku,
                'name' => (string) ($product->translation($locale)?->name ?: $product->sku),
                'qty' => $qty,
                'unit_price' => $unit,
                'tax_rate' => 0.0,
                'tax_amount' => 0.0,
                'total' => $lineTotal,
            ];
        }

        $billing = is_array($args['billingAddress'] ?? null)
            ? $args['billingAddress']
            : self::addressFromCustomer($customer, 'billing');
        $shipping = is_array($args['shippingAddress'] ?? null)
            ? $args['shippingAddress']
            : self::addressFromCustomer($customer, 'shipping');
        $currency = strtoupper((string) (
            $args['currencyCode']
            ?? Currency::query()->where('is_default', true)->value('code')
            ?? 'USD'
        ));
        $shippingTotal = round(max(0, (float) ($args['shippingTotal'] ?? 0)), 2);
        $discountTotal = round(max(0, min($subtotal, (float) ($args['discountTotal'] ?? 0))), 2);
        $grandTotal = round(max(0, $subtotal - $discountTotal + $shippingTotal), 2);
        $inventory = app(InventoryService::class);
        $paymentMethodId = isset($args['paymentMethodId']) ? (int) $args['paymentMethodId'] : null;
        $shippingMethodId = isset($args['shippingMethodId']) ? (int) $args['shippingMethodId'] : null;

        $order = DB::transaction(function () use ($args, $customer, $lines, $subtotal, $shippingTotal, $discountTotal, $grandTotal, $billing, $shipping, $currency, $inventory, $paymentMethodId, $shippingMethodId) {
            $order = Order::createTrusted([
                'number' => app(OrderNumberService::class)->next(),
                'customer_id' => $customer->id,
                'email' => $customer->email,
                'status' => ! empty($args['draft']) ? 'pending' : 'pending',
                'currency_code' => $currency,
                'subtotal' => $subtotal,
                'tax_total' => 0,
                'shipping_total' => $shippingTotal,
                'discount_total' => $discountTotal,
                'grand_total' => $grandTotal,
                'amount_paid' => 0,
                'amount_due' => $grandTotal,
                'payment_status' => 'pending',
                'payment_method_id' => $paymentMethodId ?: null,
                'shipping_method_id' => $shippingMethodId ?: null,
                'billing_address' => $billing,
                'shipping_address' => $shipping,
                'customer_note' => isset($args['customerNote']) ? (string) $args['customerNote'] : null,
            ]);

            foreach ($lines as $line) {
                /** @var Product $product */
                $product = $line['product'];
                unset($line['product']);
                $warehouseId = null;
                if ($product->manage_stock || $product->isComposite()) {
                    $warehouseId = $inventory->pickWarehouse($product, (float) $line['qty'])->id;
                }
                $order->items()->create(array_merge($line, ['warehouse_id' => $warehouseId]));

                if ($product->manage_stock && empty($args['draft'])) {
                    $warehouse = $warehouseId
                        ? \App\Domain\Catalog\Models\Warehouse::query()->find($warehouseId)
                        : null;
                    $inventory->deduct(
                        $product,
                        (float) $line['qty'],
                        'order',
                        $order->number ?? ('ORD-'.$order->id),
                        $warehouse,
                    );
                }
            }

            $order->statusHistories()->create([
                'status' => 'pending',
                'comment' => ! empty($args['draft'])
                    ? 'Draft order created by admin GraphQL'
                    : 'Order created by admin GraphQL',
            ]);

            return $order;
        });

        ShopStats::flushOrders();

        $fresh = $order->fresh(['items']) ?? $order;

        return [
            'id' => (string) $fresh->id,
            'number' => $fresh->number,
            'email' => $fresh->email,
            'status' => $fresh->status,
            'payment_status' => $fresh->payment_status,
            'customer_id' => $fresh->customer_id ? (string) $fresh->customer_id : null,
            'customerId' => $fresh->customer_id ? (string) $fresh->customer_id : null,
            'subtotal' => $fresh->subtotal !== null ? (float) $fresh->subtotal : null,
            'discount_total' => $fresh->discount_total !== null ? (float) $fresh->discount_total : 0.0,
            'shipping_total' => $fresh->shipping_total !== null ? (float) $fresh->shipping_total : 0.0,
            'grand_total' => (float) $fresh->grand_total,
            'refunded_total' => $fresh->refunded_total !== null ? (float) $fresh->refunded_total : 0.0,
            'currency_code' => $fresh->currency_code,
            'created_at' => optional($fresh->created_at)?->toIso8601String(),
            'items' => $fresh->items->map(fn ($item) => [
                'id' => (string) $item->id,
                'sku' => $item->sku,
                'name' => $item->name,
                'qty' => (float) $item->qty,
                'price' => (float) ($item->unit_price ?? $item->price ?? 0),
                'total' => (float) ($item->total ?? ((float) ($item->unit_price ?? 0) * (float) $item->qty)),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function addressFromCustomer(Customer $customer, string $type): array
    {
        $address = $type === 'shipping'
            ? $customer->addresses()->where('is_default_shipping', true)->first()
            : $customer->addresses()->where('is_default_billing', true)->first();
        $address ??= $customer->addresses()->first();

        $first = trim((string) ($address?->first_name ?: $customer->first_name ?: $customer->name ?: 'Customer'));
        $last = trim((string) ($address?->last_name ?: $customer->last_name ?: ''));

        return [
            'first_name' => $first !== '' ? $first : 'Customer',
            'last_name' => $last,
            'phone' => (string) ($address?->phone ?: $customer->phone ?: ''),
            'line1' => (string) ($address?->line1 ?: 'Address pending'),
            'line2' => $address?->line2,
            'city' => (string) ($address?->city ?: 'City'),
            'state' => $address?->state,
            'postal_code' => $address?->postal_code,
            'country_code' => strtoupper((string) ($address?->country_code ?: 'US')),
        ];
    }
}
