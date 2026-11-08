<?php

namespace App\Domain\Checkout\Services;

use App\Domain\Checkout\CheckoutContext;
use App\Domain\Checkout\CheckoutSnapshot;
use App\Domain\Checkout\Models\Cart;
use App\Domain\Customer\Models\Customer;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Settings\CartCheckoutSettings;
use App\Domain\Settings\InventorySettings;
use App\Domain\Settings\OrderSettings;
use App\Domain\Settings\ProductSettings;
use App\Domain\Shipping\Models\ShippingMethod;

class CheckoutSnapshotBuilder
{
    public function __construct(
        protected CartService $carts,
        protected CartShippingSplitter $shippingGroups,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function build(Cart $cart, array $payload, ?Customer $customer = null): CheckoutSnapshot
    {
        $cart = Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
        $cart->load([
            'items.product.translations',
            'items.product.seller',
            'items.product.shippingMethods',
            'discountRule',
        ]);

        if ($cart->items->isEmpty()) {
            abort(422, 'Your cart is empty.');
        }

        if ($customer && $cart->customer_id && (int) $cart->customer_id !== (int) $customer->id) {
            abort(403, 'This cart does not belong to the current customer.');
        }

        if (ProductSettings::catalogMode()) {
            abort(422, 'This shop is catalog-only.');
        }

        $preorders = app(\App\Domain\Catalog\Services\PreorderService::class);
        foreach ($cart->items as $item) {
            $product = $item->product;
            if (! $product) {
                abort(422, 'A cart product is no longer available.');
            }
            if ((float) $item->qty <= 0) {
                abort(422, 'Cart quantity is invalid.');
            }
            $data = is_array($item->preorder_data) ? $item->preorder_data : [];
            if ($preorders->isFinalPaymentData($data)) {
                $sourceId = (int) ($data['source_order_item_id'] ?? 0);
                $source = $sourceId > 0 ? \App\Domain\Order\Models\OrderItem::query()->with(['order', 'product.preorderSetting'])->find($sourceId) : null;
                if (! $source || ! $source->order || (int) $source->order->customer_id !== (int) ($customer?->id ?? 0)) {
                    abort(422, 'This pre-order final payment is no longer valid.');
                }
                $preorders->assertCanStartFinalPayment($source);
                $remaining = (float) ($preorders->settleLine($source)['remaining'] ?? 0);
                $lineTotal = round((float) $item->unit_price * (float) $item->qty, 2);
                if ($remaining <= 0.009) {
                    abort(422, 'This pre-order is already fully paid.');
                }
                if ($lineTotal - $remaining > 0.009) {
                    abort(422, 'Final payment cannot exceed the remaining pre-order amount.');
                }

                continue;
            }
            $this->carts->assertPurchasable($product);
            $isPreorder = (is_array($item->preorder_data) && ! empty($item->preorder_data['enabled']))
                || $preorders->isActive($product);
            if (
                ! $product->isRental()
                && ! $isPreorder
                && CartCheckoutSettings::stockValidation()
                && InventorySettings::enabled()
                && ! InventorySettings::allowNegative()
                && ! $product->inStockFor($item->qty)
            ) {
                abort(422, 'Not enough stock for '.$product->sku);
            }
        }

        $payment = PaymentMethod::query()->where('is_enabled', true)->findOrFail($payload['payment_method_id']);
        $requiresShipping = $this->cartRequiresShipping($cart);
        $context = CheckoutContext::fromCart($cart, $customer);
        $countryCode = $payload['shipping_address']['country_code']
            ?? $payload['billing_address']['country_code']
            ?? ($context['country_code'] ?? null);
        $regionCode = $payload['shipping_address']['state']
            ?? $payload['shipping_address']['region_code']
            ?? $payload['shipping_address']['state_code']
            ?? $payload['billing_address']['state']
            ?? null;
        if (is_string($countryCode) && $countryCode !== '') {
            $context['country_code'] = strtoupper($countryCode);
            $countryId = \App\Domain\Localization\Models\Country::query()
                ->where('code', strtoupper($countryCode))
                ->value('id');
            $context['country_id'] = $countryId ? (int) $countryId : ($context['country_id'] ?? null);
        }
        $groups = $this->shippingGroups->groups($cart, $payload, $context, $customer);
        $shipping = $this->primaryShippingMethod($groups, $payload, $requiresShipping);
        if ($shipping && $shipping->type === 'local_pickup') {
            $payload['shipping_address'] = $payload['shipping_address'] ?? $payload['billing_address'] ?? [];
            $payload['shipping_address']['pickup'] = true;
            $payload['shipping_address']['pickup_location'] = $shipping->name;
        }

        $totals = $this->combineGroupTotals($groups);

        $this->carts->validateGiftCardForCart($cart, $totals);

        $grand = round((float) $totals['grand_total'], 2);

        if (\App\Domain\Customer\Services\NetTerms::isInvoice($payment)) {
            \App\Domain\Customer\Services\NetTerms::assertCanCharge($customer, $grand);
        }

        OrderSettings::assertWithinTotal($grand, (string) ($totals['currency'] ?? ''));

        $hasDueNow = array_key_exists('due_now', $totals) && $totals['due_now'] !== null;
        $dueNow = $hasDueNow ? round((float) $totals['due_now'], 2) : $grand;
        if ($dueNow < 0) {
            $dueNow = 0.0;
        }
        if ($dueNow > $grand) {
            $dueNow = $grand;
        }
        $amountDue = $hasDueNow ? round(max(0, $grand - $dueNow), 2) : null;

        $paymentContext = is_array($payload['payment_context'] ?? null) ? $payload['payment_context'] : [];

        return new CheckoutSnapshot(
            cart: $cart,
            payload: $payload,
            totals: $totals,
            paymentMethod: $payment,
            shippingMethod: $shipping,
            customer: $customer,
            grandTotal: $grand,
            captureAmount: $dueNow,
            amountDue: $amountDue,
            hasDueNow: $hasDueNow,
            paymentContext: $paymentContext,
            idempotencyKey: isset($payload['idempotency_key']) ? trim((string) $payload['idempotency_key']) : null,
            shippingGroups: $groups,
        );
    }

    /**
     * @param  list<array{method: ?ShippingMethod, items: mixed, totals: array<string, mixed>}>  $groups
     */
    protected function primaryShippingMethod(array $groups, array $payload, bool $requiresShipping): ?ShippingMethod
    {
        $payloadId = (int) ($payload['shipping_method_id'] ?? 0);
        foreach ($groups as $group) {
            $method = $group['method'] ?? null;
            if ($method instanceof ShippingMethod && $payloadId > 0 && (int) $method->id === $payloadId) {
                return $method;
            }
        }
        foreach ($groups as $group) {
            $method = $group['method'] ?? null;
            if ($method instanceof ShippingMethod) {
                return $method;
            }
        }
        if ($requiresShipping && $payloadId > 0) {
            return ShippingMethod::query()->where('is_enabled', true)->find($payloadId);
        }

        return null;
    }

    /**
     * @param  list<array{method: ?ShippingMethod, items: mixed, totals: array<string, mixed>}>  $groups
     * @return array<string, mixed>
     */
    protected function combineGroupTotals(array $groups): array
    {
        if (count($groups) === 1) {
            return $groups[0]['totals'];
        }

        $first = $groups[0]['totals'];
        $combined = $first;
        $combined['subtotal'] = 0.0;
        $combined['discount'] = 0.0;
        $combined['tax'] = 0.0;
        $combined['shipping'] = 0.0;
        $combined['fee_total'] = 0.0;
        $combined['grand_total'] = 0.0;
        $combined['lines'] = [];
        $combined['tax_details'] = [];
        $combined['adjustments'] = [];
        $dueNow = null;
        foreach ($groups as $group) {
            $row = $group['totals'];
            $combined['subtotal'] = round($combined['subtotal'] + (float) ($row['subtotal'] ?? 0), 2);
            $combined['discount'] = round($combined['discount'] + (float) ($row['discount'] ?? 0), 2);
            $combined['tax'] = round($combined['tax'] + (float) ($row['tax'] ?? 0), 2);
            $combined['shipping'] = round($combined['shipping'] + (float) ($row['shipping'] ?? 0), 2);
            $combined['fee_total'] = round($combined['fee_total'] + (float) ($row['fee_total'] ?? 0), 2);
            $combined['grand_total'] = round($combined['grand_total'] + (float) ($row['grand_total'] ?? 0), 2);
            $combined['lines'] = array_merge($combined['lines'], $row['lines'] ?? []);
            $combined['tax_details'] = array_merge($combined['tax_details'], $row['tax_details'] ?? []);
            $combined['adjustments'] = array_merge($combined['adjustments'], $row['adjustments'] ?? []);
            if (array_key_exists('due_now', $row) && $row['due_now'] !== null) {
                $dueNow = round(($dueNow ?? 0) + (float) $row['due_now'], 2);
            }
        }
        $combined['due_now'] = $dueNow;
        $combined['balance_due'] = $dueNow !== null
            ? round(max(0, (float) $combined['grand_total'] - $dueNow), 2)
            : 0.0;
        $combined['gift_card_code'] = $first['gift_card_code'] ?? null;
        $combined['gift_card_credit'] = $first['gift_card_credit'] ?? 0;
        $combined['store_credit'] = $first['store_credit'] ?? 0;
        $combined['loyalty'] = $first['loyalty'] ?? 0;
        $combined['gift_wrap_fee'] = $first['gift_wrap_fee'] ?? 0;
        $combined['discount_rule_id'] = $first['discount_rule_id'] ?? null;

        return $combined;
    }

    protected function cartRequiresShipping(Cart $cart): bool
    {
        $preorders = app(\App\Domain\Catalog\Services\PreorderService::class);
        foreach ($cart->items as $item) {
            if ($preorders->isFinalPaymentData(is_array($item->preorder_data) ? $item->preorder_data : null)) {
                continue;
            }
            if ($item->product?->requiresShipping()) {
                return true;
            }
        }

        return false;
    }
}
