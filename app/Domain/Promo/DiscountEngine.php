<?php

namespace App\Domain\Promo;

use App\Domain\Catalog\Models\Product;
use App\Domain\Checkout\Models\Cart;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Customer\Models\CustomerProductPrice;
use App\Domain\Localization\Models\Country;
use App\Domain\Localization\Models\CountryRegion;
use App\Domain\Localization\Models\Currency;
use App\Domain\Localization\Money;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\MarketplaceDiscountJoin;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Marketplace\SellerDiscountJoinSchema;
use App\Domain\Promo\Models\DiscountRule;
use App\Domain\Promo\Models\DiscountRedemption;
use App\Domain\Promo\Models\DiscountRuleCondition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class DiscountEngine
{
    /** @var Collection<int, DiscountRule>|null */
    protected ?Collection $catalogRules = null;

    /** @var Collection<int, DiscountRule>|null */
    protected ?Collection $cartRules = null;

    /** @var array<string, string>|null */
    protected ?array $countryIdToCode = null;

    /** @var array<string, string>|null */
    protected ?array $regionIdToCode = null;

    /** @var array<string, string>|null */
    protected ?array $currencyIdToCode = null;

    /** @var array<string, string>|null */
    protected ?array $groupIdToCode = null;

    public function flush(): void
    {
        $this->catalogRules = null;
        $this->cartRules = null;
        $this->countryIdToCode = null;
        $this->regionIdToCode = null;
        $this->currencyIdToCode = null;
        $this->groupIdToCode = null;
    }

    public function catalogUnitPrice(Product $product, ?DiscountContext $context = null): float
    {
        $context ??= DiscountContext::current();
        $qty = max(0, (float) ($context->qty ?: 0));
        if ($qty <= 0) {
            $qty = 1;
        }
        $negotiated = $this->negotiatedPrice($product, $context);
        if ($negotiated !== null) {
            return max(0, $negotiated);
        }
        $break = $product->unitPriceForQty($qty);
        $base = $break ?? $product->saleAdjustedPrice();
        $itemContext = $context->forProduct($product, $qty);

        $reduction = $this->bestReduction($this->catalogRules(), $itemContext, $base, null);
        $price = max(0, round($base - $reduction['amount'], 2));
        $price = \App\Domain\Settings\CustomerSettings::applyGroupDiscount($price, $itemContext->customer);

        return \App\Domain\Settings\PricingSettings::roundMoney($price);
    }

    protected function negotiatedPrice(Product $product, DiscountContext $context): ?float
    {
        $customer = $context->customer;
        if (! $customer || ! \Illuminate\Support\Facades\Schema::hasTable('customer_product_prices')) {
            return null;
        }

        $row = CustomerProductPrice::query()
            ->where('customer_id', $customer->id)
            ->where('product_id', $product->id)
            ->first();
        if (! $row || ! $row->isLive()) {
            return null;
        }

        return round((float) $row->price, 2);
    }

    /**
     * Cart / coupon layer on top of catalog unit prices.
     *
     * @param  list<array{product: ?Product, qty: int, line_total: float}>  $lines
     * @return array{amount: float, rule: ?DiscountRule}
     */
    public function cartReduction(Cart $cart, DiscountContext $context, array $lines): array
    {
        $rules = $this->cartDiscountRules();
        $applied = $cart->discountRule;
        if ($applied && $applied->apply_as === 'coupon' && $applied->isLive()) {
            $applied->loadMissing('conditions');
            $rules = $rules
                ->reject(fn (DiscountRule $rule) => $rule->id === $applied->id)
                ->push($applied)
                ->sortBy([['priority', 'asc'], ['id', 'asc']])
                ->values();
        }

        return $this->bestReduction($rules, $context, $context->cartSubtotal, $lines);
    }

    public function findLiveCoupon(string $code): ?DiscountRule
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }

        return DiscountRule::query()
            ->live()
            ->with('conditions')
            ->where('apply_as', 'coupon')
            ->whereRaw('UPPER(code) = ?', [$code])
            ->orderBy('priority')
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  list<array{product: ?Product, qty: int, line_total: float}>|null  $lines
     */
    public function applies(DiscountRule $rule, DiscountContext $context, ?array $lines = null): bool
    {
        $rule->loadMissing('conditions');
        if (! $this->withinPerCustomerLimit($rule, $context)) {
            return false;
        }
        $fallback = $lines === null
            ? ($context->product ? $context->product->saleAdjustedPrice() : $context->cartSubtotal)
            : $context->cartSubtotal;

        return $this->eligibleBase($rule, $context, $fallback, $lines) !== null;
    }

    /**
     * A per-customer cap hides the rule once this shopper (account or email)
     * has redeemed it that many times. Only queries when a cap is set.
     */
    public function withinPerCustomerLimit(DiscountRule $rule, DiscountContext $context): bool
    {
        $limit = $rule->max_uses_per_customer;
        if ($limit === null) {
            return true;
        }
        $customer = $context->customer;
        $used = DiscountRedemption::usedByRule(
            (int) $rule->id,
            $customer?->id,
            $customer?->email
        );

        return $used < max(1, (int) $limit);
    }

    /**
     * @param  Collection<int, DiscountRule>  $rules
     * @param  list<array{product: ?Product, qty: int, line_total: float}>|null  $lines
     * @return array{amount: float, rule: ?DiscountRule}
     */
    protected function bestReduction(Collection $rules, DiscountContext $context, float $fallbackBase, ?array $lines): array
    {
        $considered = [];

        foreach ($rules as $rule) {
            $base = $this->eligibleBase($rule, $context, $fallbackBase, $lines);
            if ($base === null) {
                continue;
            }
            $considered[] = ['rule' => $rule, 'base' => $base];
            if ($rule->stop_further) {
                break;
            }
        }

        $best = 0.0;
        $bestRule = null;
        foreach ($considered as $row) {
            $amount = $row['rule']->action === 'buy_x_get_y'
                ? $this->bogoReduction($row['rule'], $context, $lines)
                : $row['rule']->reductionFor($row['base']);
            if ($amount > $best) {
                $best = $amount;
                $bestRule = $row['rule'];
            }
        }

        return ['amount' => $best, 'rule' => $bestRule];
    }

    /**
     * @param  list<array{product: ?Product, qty: int, line_total: float}>|null  $lines
     */
    protected function eligibleBase(DiscountRule $rule, DiscountContext $context, float $fallbackBase, ?array $lines): ?float
    {
        if (! $this->contextMatches($rule, $context)) {
            return null;
        }

        if ($lines === null) {
            if ($rule->action === 'buy_x_get_y') {
                return null;
            }
            if (! $this->itemMatches($rule, $context)) {
                return null;
            }
            if ($rule->min_qty !== null && $context->qty < (int) $rule->min_qty) {
                return null;
            }
            if (! $this->meetsMinCart($rule, $context->cartSubtotal)) {
                return null;
            }

            return $fallbackBase;
        }

        $matching = [];
        $qty = 0;
        foreach ($lines as $line) {
            $product = $line['product'] ?? null;
            if (! $product instanceof Product) {
                continue;
            }
            if (! $this->cartLineBelongsToRuleSeller($rule, $product)) {
                continue;
            }
            $itemContext = $context->forProduct($product, (int) $line['qty']);
            if (! $this->itemMatches($rule, $itemContext)) {
                continue;
            }
            $matching[] = $line;
            $qty += (int) $line['qty'];
        }

        if ($matching === []) {
            return null;
        }

        if ($rule->min_qty !== null && $qty < (int) $rule->min_qty) {
            return null;
        }
        if ($rule->action === 'buy_x_get_y') {
            $buy = max(1, (int) $rule->value);
            if ($qty < ($buy + 1)) {
                return null;
            }
        }
        if (! $this->meetsMinCart($rule, $context->cartSubtotal)) {
            return null;
        }

        return (float) array_sum(array_map(fn (array $line) => (float) $line['line_total'], $matching));
    }

    /**
     * Cheapest matching units are free: buy X get 1 (every X+1th item).
     *
     * @param  list<array{product: ?Product, qty: int, line_total: float}>|null  $lines
     */
    protected function bogoReduction(DiscountRule $rule, DiscountContext $context, ?array $lines): float
    {
        if ($lines === null || $lines === []) {
            return 0.0;
        }
        $buy = max(1, (int) $rule->value);
        $units = [];
        foreach ($lines as $line) {
            $product = $line['product'] ?? null;
            if (! $product instanceof Product) {
                continue;
            }
            if (! $this->cartLineBelongsToRuleSeller($rule, $product)) {
                continue;
            }
            $itemContext = $context->forProduct($product, (int) $line['qty']);
            if (! $this->itemMatches($rule, $itemContext)) {
                continue;
            }
            $qty = max(0, (int) ($line['qty'] ?? 0));
            if ($qty < 1) {
                continue;
            }
            $unit = ((float) $line['line_total']) / $qty;
            for ($i = 0; $i < $qty; $i++) {
                $units[] = $unit;
            }
        }
        sort($units);
        $free = intdiv(count($units), $buy + 1);
        if ($free < 1) {
            return 0.0;
        }

        return round(array_sum(array_slice($units, 0, $free)), 2);
    }

    protected function meetsMinCart(DiscountRule $rule, float $cartSubtotal): bool
    {
        if ($rule->min_cart === null) {
            return true;
        }

        $minCart = (float) $rule->convertAmount((float) $rule->min_cart, Money::defaultCode());

        return $cartSubtotal >= $minCart;
    }

    protected function contextMatches(DiscountRule $rule, DiscountContext $context): bool
    {
        $rule->loadMissing('conditions');
        $conditions = $rule->conditions->whereIn('type', DiscountRuleCondition::CONTEXT_TYPES);
        if ($conditions->isEmpty()) {
            return true;
        }

        foreach ($conditions as $condition) {
            if (! $this->contextConditionMatches($condition, $context)) {
                return false;
            }
        }

        return true;
    }

    protected function itemMatches(DiscountRule $rule, DiscountContext $context): bool
    {
        if (! $this->sellerMayUseAdminCatalogDiscountRule($rule, $context->product)) {
            return false;
        }

        $rule->loadMissing('conditions');
        $conditions = $rule->conditions->whereIn('type', DiscountRuleCondition::ITEM_TYPES);
        if ($conditions->isEmpty()) {
            return true;
        }

        foreach ($conditions as $condition) {
            if (! $this->itemConditionMatches($condition, $context)) {
                return false;
            }
        }

        return true;
    }

    /** multi-seller carts: store rules ↔ platform lines; seller deals ↔ that seller only. */
    protected function cartLineBelongsToRuleSeller(DiscountRule $rule, Product $product): bool
    {
        if (in_array($rule->apply_as, ['cart', 'coupon'], true)) {
            if ($rule->seller_id === null) {
                return $product->seller_id === null;
            }

            return (int) $product->seller_id === (int) $rule->seller_id;
        }

        return true;
    }

    /**
     * Catalog "Sell as Yours" offers are never included.
     */
    protected function sellerMayUseAdminCatalogDiscountRule(DiscountRule $rule, ?Product $product): bool
    {
        if (! $product || $rule->seller_id !== null || $rule->apply_as !== 'catalog') {
            return true;
        }
        if (! $product->seller_id) {
            return true; // platform-owned catalog SKU
        }
        if (! MarketplaceSettings::enableProductDiscountJoin()) {
            return false;
        }
        if (Schema::hasColumn('discount_rules', 'allow_sellers_to_join')
            && $rule->allow_sellers_to_join === false) {
            return false;
        }
        SellerDiscountJoinSchema::ensure();
        if (SellerProduct::query()
            ->where('seller_id', $product->seller_id)
            ->where('product_id', $product->id)
            ->where('is_catalog_offer', true)
            ->exists()) {
            return false;
        }

        return MarketplaceDiscountJoin::query()
            ->where('seller_id', $product->seller_id)
            ->where('discount_rule_id', $rule->id)
            ->where('status', MarketplaceDiscountJoin::STATUS_APPROVED)
            ->exists();
    }

    protected function itemConditionMatches(DiscountRuleCondition $condition, DiscountContext $context): bool
    {
        if ($condition->type === 'catalog') {
            return true;
        }

        $values = $condition->values();
        if ($values === []) {
            return true;
        }

        $product = $context->product;
        if (! $product) {
            return false;
        }

        return match ($condition->type) {
            'product' => in_array((string) $product->id, $values, true),
            'brand' => $product->brand_id !== null && in_array((string) $product->brand_id, $values, true),
            'category' => count(array_intersect($this->productCategoryIds($product), $values)) > 0,
            'seller' => $product->seller_id !== null && in_array((string) $product->seller_id, $values, true),
            default => false,
        };
    }

    protected function contextConditionMatches(DiscountRuleCondition $condition, DiscountContext $context): bool
    {
        $values = $condition->values();
        if ($values === []) {
            return true;
        }

        return match ($condition->type) {
            'customer' => $context->customer !== null
                && in_array((string) $context->customer->id, $values, true),
            'customer_group' => \App\Domain\Settings\CustomerSettings::groupSpecificDiscounts()
                && $this->customerGroupMatches($context, $values),
            'country' => $this->codeMatches($context->countryCode, $values, $this->countryCodeMap()),
            'region' => $this->codeMatches($context->regionCode, $values, $this->regionCodeMap()),
            'currency' => $this->codeMatches(
                $context->currencyCode ?: Money::defaultCode(),
                $values,
                $this->currencyCodeMap(),
            ),
            default => false,
        };
    }

    /** @param  list<string>  $values */
    protected function customerGroupMatches(DiscountContext $context, array $values): bool
    {
        $customer = $context->customer;
        if (! $customer) {
            return false;
        }

        if ($customer->customer_group_id && in_array((string) $customer->customer_group_id, $values, true)) {
            return true;
        }

        $code = strtoupper(trim((string) ($customer->customer_group ?? '')));
        if ($code === '') {
            return false;
        }

        if (in_array($code, array_map('strtoupper', $values), true)) {
            return true;
        }

        $allowed = [];
        foreach ($values as $value) {
            $allowed[] = strtoupper($this->groupCodeMap()[$value] ?? $value);
        }

        return in_array($code, $allowed, true);
    }

    /**
     * Country / region rules do not apply when the request has no location.
     *
     * @param  list<string>  $values
     * @param  array<string, string>  $idToCode
     */
    protected function codeMatches(?string $needle, array $values, array $idToCode): bool
    {
        $needle = strtoupper(trim((string) $needle));
        if ($needle === '') {
            return false;
        }

        foreach ($values as $value) {
            $code = strtoupper($idToCode[$value] ?? $value);
            if ($code === $needle) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    protected function productCategoryIds(Product $product): array
    {
        if (! $product->relationLoaded('categories')) {
            $product->load('categories:id');
        }

        $ids = $product->categories->pluck('id')->map(fn ($id) => (string) $id)->all();
        if ($product->default_category_id) {
            $ids[] = (string) $product->default_category_id;
        }

        return array_values(array_unique($ids));
    }

    /** @return Collection<int, DiscountRule> */
    protected function catalogRules(): Collection
    {
        return $this->catalogRules ??= $this->loadLive('catalog');
    }

    /** @return Collection<int, DiscountRule> */
    protected function cartDiscountRules(): Collection
    {
        return $this->cartRules ??= $this->loadLive('cart');
    }

    /** @return Collection<int, DiscountRule> */
    protected function loadLive(string $applyAs): Collection
    {
        return DiscountRule::query()
            ->live()
            ->with('conditions')
            ->where('apply_as', $applyAs)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    /** @return array<string, string> */
    protected function countryCodeMap(): array
    {
        return $this->countryIdToCode ??= Country::query()
            ->get(['id', 'code'])
            ->mapWithKeys(fn (Country $country) => [(string) $country->id => strtoupper($country->code)])
            ->all();
    }

    /** @return array<string, string> */
    protected function regionCodeMap(): array
    {
        return $this->regionIdToCode ??= CountryRegion::query()
            ->get(['id', 'code'])
            ->mapWithKeys(fn (CountryRegion $region) => [(string) $region->id => strtoupper($region->code)])
            ->all();
    }

    /** @return array<string, string> */
    protected function currencyCodeMap(): array
    {
        return $this->currencyIdToCode ??= Currency::query()
            ->get(['id', 'code'])
            ->mapWithKeys(fn (Currency $currency) => [(string) $currency->id => strtoupper($currency->code)])
            ->all();
    }

    /** @return array<string, string> */
    protected function groupCodeMap(): array
    {
        return $this->groupIdToCode ??= CustomerGroup::query()
            ->get(['id', 'code'])
            ->mapWithKeys(fn (CustomerGroup $group) => [(string) $group->id => strtoupper((string) $group->code)])
            ->all();
    }
}
