<?php

namespace App\Domain\Checkout\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Checkout\Models\Cart;
use App\Domain\Checkout\Models\CartItem;
use App\Domain\Customer\Models\Customer;
use App\Domain\Plugin\Support\ExtensionRuntime;
use App\Domain\Shipping\Models\ShippingMethod;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CartShippingSplitter
{
    public function __construct(
        protected CartService $carts,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return list<array{method: ?ShippingMethod, items: Collection<int, CartItem>, totals: array<string, mixed>}>
     */
    public function groups(Cart $cart, array $payload, array $context, ?Customer $customer = null): array
    {
        $cart->loadMissing(['items.product.shippingMethods']);
        $selections = $this->selectionsFromPayload($payload);
        $checkoutMethodId = (int) ($payload['shipping_method_id'] ?? 0) ?: null;
        $countryCode = $payload['shipping_address']['country_code']
            ?? $payload['billing_address']['country_code']
            ?? ($context['country_code'] ?? null);
        $regionCode = $payload['shipping_address']['state']
            ?? $payload['shipping_address']['region_code']
            ?? $payload['shipping_address']['state_code']
            ?? $payload['billing_address']['state']
            ?? null;
        $currency = $payload['currency_code'] ?? ($context['currency_code'] ?? null);

        /** @var array<int, array{method: ?ShippingMethod, items: Collection<int, CartItem>}> $buckets */
        $buckets = [];
        foreach ($cart->items as $item) {
            $method = $this->resolveItemMethod($item, $checkoutMethodId, $selections, $context);
            $key = $method?->id ?? 0;
            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'method' => $method,
                    'items' => collect(),
                ];
            }
            $buckets[$key]['items']->push($item);
        }

        uasort($buckets, function (array $a, array $b): int {
            $aPos = $a['method']?->position ?? 100000;
            $bPos = $b['method']?->position ?? 100000;
            if ($aPos !== $bPos) {
                return $aPos <=> $bPos;
            }

            return ((int) ($a['method']?->id ?? 0)) <=> ((int) ($b['method']?->id ?? 0));
        });

        $single = count($buckets) < 2;
        $groups = [];
        foreach ($buckets as $bucket) {
            $items = $bucket['items']->values();
            $method = $bucket['method'];
            if ($single) {
                $totals = $this->carts->summary(
                    $cart,
                    $this->carts->quoteShipping($cart, $method, is_string($countryCode) ? $countryCode : null),
                    is_string($countryCode) ? $countryCode : null,
                    is_string($regionCode) ? $regionCode : null,
                    $customer,
                    is_string($currency) ? $currency : null,
                    true,
                    $method,
                );
            } else {
                $totals = $this->carts->summaryForItems(
                    $cart,
                    $items,
                    $this->carts->quoteShipping($cart, $method, is_string($countryCode) ? $countryCode : null, $items),
                    is_string($countryCode) ? $countryCode : null,
                    is_string($regionCode) ? $regionCode : null,
                    $customer,
                    is_string($currency) ? $currency : null,
                    applyPayableCredits: false,
                    shippingMethod: $method,
                );
            }
            $groups[] = [
                'method' => $method,
                'items' => $items,
                'totals' => $totals,
            ];
        }

        if (! $single) {
            $groups = $this->allocatePayableCredits($cart, $groups, $customer);
        }

        return array_values($groups);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, int>
     */
    public function selectionsFromPayload(array $payload): array
    {
        $out = [];
        foreach ((array) ($payload['shipping_selections'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $itemId = (int) ($row['cart_item_id'] ?? 0);
            $methodId = (int) ($row['shipping_method_id'] ?? 0);
            if ($itemId > 0 && $methodId > 0) {
                $out[$itemId] = $methodId;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, int>  $selections
     * @param  array<string, mixed>  $context
     */
    public function resolveItemMethod(
        CartItem $item,
        ?int $checkoutMethodId,
        array $selections,
        array $context,
    ): ?ShippingMethod {
        $product = $item->product;
        if (! $product || ! $product->requiresShipping()) {
            return null;
        }

        $allowed = $this->allowedMethods($product, $context);
        $explicitId = (int) ($selections[(int) $item->id] ?? 0);
        if ($explicitId < 1) {
            $explicitId = (int) ($item->shipping_method_id ?? 0);
        }

        if ($explicitId > 0) {
            $chosen = $allowed->firstWhere('id', $explicitId);
            if (! $chosen) {
                throw ValidationException::withMessages([
                    'shipping_method_id' => 'Shipping method is not available for '.$this->itemLabel($item).'.',
                ]);
            }

            return $chosen;
        }

        if ($checkoutMethodId && $allowed->contains(fn (ShippingMethod $method) => (int) $method->id === $checkoutMethodId)) {
            return $allowed->firstWhere('id', $checkoutMethodId);
        }

        if ($allowed->count() === 1) {
            return $allowed->first();
        }

        if ($allowed->isEmpty()) {
            throw ValidationException::withMessages([
                'shipping_method_id' => 'No shipping method is available for '.$this->itemLabel($item).'.',
            ]);
        }

        throw ValidationException::withMessages([
            'shipping_method_id' => 'Choose a shipping method for '.$this->itemLabel($item).'.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return Collection<int, ShippingMethod>
     */
    public function allowedMethods(Product $product, array $context): Collection
    {
        $sellerId = (int) ($product->seller_id ?? 0);
        $query = ShippingMethod::query()->where('is_enabled', true);
        $query->where(function ($inner) use ($sellerId) {
            $inner->whereNull('seller_id');
            if ($sellerId > 0) {
                $inner->orWhere('seller_id', $sellerId);
            }
        });

        if (! $product->available_all_carriers) {
            $ids = $product->shippingMethods->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($ids === []) {
                return collect();
            }
            $query->whereIn('id', $ids);
        }

        return $query->orderBy('position')->orderBy('id')->get()
            ->filter(fn (ShippingMethod $method) => $method->matchesContext($context)
                && ExtensionRuntime::isActive(data_get($method->config, 'extension')))
            ->values();
    }

    /**
     * @param  list<array{method: ?ShippingMethod, items: Collection<int, CartItem>, totals: array<string, mixed>}>  $groups
     * @return list<array{method: ?ShippingMethod, items: Collection<int, CartItem>, totals: array<string, mixed>}>
     */
    protected function allocatePayableCredits(Cart $cart, array $groups, ?Customer $customer): array
    {
        $preCredits = [];
        $combined = 0.0;
        foreach ($groups as $index => $group) {
            $pre = round((float) ($group['totals']['grand_total'] ?? 0), 2);
            $preCredits[$index] = $pre;
            $combined = round($combined + $pre, 2);
        }

        $credited = $this->carts->applyPayableCredits($cart, $combined, $customer, (string) ($groups[0]['totals']['currency'] ?? 'USD'));
        $creditDelta = round($combined - (float) $credited['grand_total'], 2);
        $wrap = (float) ($credited['gift_wrap_fee'] ?? 0);
        $giftCard = (float) ($credited['gift_card_credit'] ?? 0);
        $store = (float) ($credited['store_credit'] ?? 0);
        $loyalty = (float) ($credited['loyalty'] ?? 0);

        $allocated = 0.0;
        $last = count($groups) - 1;
        foreach ($groups as $index => $group) {
            $share = $combined > 0.009
                ? round(($preCredits[$index] / $combined) * $creditDelta, 2)
                : ($index === $last ? $creditDelta : 0.0);
            if ($index === $last) {
                $share = round($creditDelta - $allocated, 2);
            } else {
                $allocated = round($allocated + $share, 2);
            }
            $totals = $group['totals'];
            $totals['grand_total'] = round($preCredits[$index] - $share, 2);
            $totals['gift_wrap'] = $wrap > 0;
            $totals['gift_wrap_fee'] = $index === 0 ? $wrap : 0.0;
            $totals['gift_card_code'] = $credited['gift_card_code'] ?? null;
            $totals['gift_card_credit'] = $index === 0 ? $giftCard : 0.0;
            $totals['store_credit'] = $index === 0 ? $store : 0.0;
            $totals['loyalty'] = $index === 0 ? $loyalty : 0.0;
            $totals['store_credit_balance'] = $credited['store_credit_balance'] ?? 0;
            $totals['loyalty_points'] = $credited['loyalty_points'] ?? 0;
            $totals['apply_store_credit'] = $credited['apply_store_credit'] ?? true;
            $totals['apply_loyalty'] = $credited['apply_loyalty'] ?? true;
            if ($index === 0) {
                $totals['fee_total'] = round((float) ($totals['fee_total'] ?? 0) + $wrap, 2);
            }
            $groups[$index]['totals'] = $totals;
        }

        return $groups;
    }

    protected function itemLabel(CartItem $item): string
    {
        $name = $item->product?->translation()?->name ?: $item->product?->sku;

        return $name ? '"'.$name.'"' : 'a cart item';
    }
}
