<?php

namespace App\Domain\Checkout;

use App\Domain\Checkout\Models\Cart;
use App\Domain\Customer\Models\Customer;
use App\Domain\Localization\Models\Country;
use App\Domain\Localization\Models\Currency;
use App\Domain\Localization\Models\Language;
use App\Domain\Localization\Money;
use Illuminate\Http\Request;

/**
 * Shared cart/customer context for payment + shipping availability.
 */
final class CheckoutContext
{
    /**
     * @return array<string, mixed>
     */
    public static function fromCart(Cart $cart, ?Customer $customer = null, ?Request $request = null): array
    {
        $cart->loadMissing(['items.product.categories']);
        $productIds = [];
        $categoryIds = [];
        foreach ($cart->items as $item) {
            if ($item->product_id) {
                $productIds[] = (int) $item->product_id;
            }
            foreach ($item->product?->categories ?? [] as $category) {
                $categoryIds[] = (int) $category->id;
            }
        }

        $countryCode = $request?->input('country_code')
            ?: $request?->input('shipping_address.country_code')
            ?: $request?->input('billing_address.country_code');
        $countryId = null;
        if (is_string($countryCode) && $countryCode !== '') {
            $countryId = Country::query()->where('code', strtoupper($countryCode))->value('id');
        }

        $postalCode = $request?->input('postal_code')
            ?: $request?->input('shipping_address.postal_code')
            ?: $request?->input('shipping_address.zip');
        $state = $request?->input('state')
            ?: $request?->input('shipping_address.state')
            ?: $request?->input('shipping_address.province');
        $city = $request?->input('city')
            ?: $request?->input('shipping_address.city');

        $currencyCode = (string) ($request?->input('currency_code') ?: Money::currentCode());
        $currencyId = Currency::query()->where('code', strtoupper($currencyCode))->value('id');
        $languageId = Language::query()->where('code', Language::defaultCode())->value('id');

        $groupId = $customer?->customer_group_id;
        if (! $groupId && $customer?->customer_group) {
            $groupId = \App\Domain\Customer\Models\CustomerGroup::query()
                ->where('code', $customer->customer_group)
                ->value('id');
        }

        return [
            'product_ids' => array_values(array_unique($productIds)),
            'category_ids' => array_values(array_unique($categoryIds)),
            'customer_id' => $customer?->id,
            'customer_group_id' => $groupId ? (int) $groupId : null,
            'shipping_method_id' => $request?->integer('shipping_method_id') ?: null,
            'country_id' => $countryId ? (int) $countryId : null,
            'country_code' => is_string($countryCode) && $countryCode !== '' ? strtoupper($countryCode) : null,
            'postal_code' => is_string($postalCode) && $postalCode !== '' ? trim($postalCode) : null,
            'state' => is_string($state) && $state !== '' ? trim($state) : null,
            'city' => is_string($city) && $city !== '' ? trim($city) : null,
            'currency_id' => $currencyId ? (int) $currencyId : null,
            'currency_code' => strtoupper($currencyCode),
            'language_id' => $languageId ? (int) $languageId : null,
            'cart_subtotal' => (float) $cart->items->sum(fn ($i) => (float) $i->unit_price * (int) $i->qty),
            'cart_weight' => (float) $cart->items->sum(function ($item) {
                $product = $item->product;
                if (! $product || ! $product->requiresShipping()) {
                    return 0;
                }

                return ((float) ($product->weight ?? 0)) * (int) $item->qty;
            }),
            'cart' => $cart,
            'cart_meta' => is_array($cart->meta) ? $cart->meta : [],
            'customer' => $customer,
        ];
    }
}
