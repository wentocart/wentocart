<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\MarketplaceCommissionRule;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerCommission;

/**
 * Configurable marketplace commission engine (Phase 09).
 *
 * Precedence (exactly one rule wins per line):
 *   Product → Seller rule → Plan commission → Seller rate → Category → Global
 *
 * Types: percent | fixed
 *
 * Commissionable base (explicit):
 * - Gross = item line totals minus allocated discount shares
 * - Tax is excluded from commissionable gross / seller net
 * - Shipping is excluded from commissionable base (Phase 14 allocates seller shipping_total separately)
 *
 * Historical orders use snapshotted type/value/source on SellerOrder / SellerEarning —
 * never re-resolve live rules for past sales.
 */
class CommissionService
{
    public const SOURCE_PRODUCT = 'product';

    public const SOURCE_SELLER = 'seller';

    public const SOURCE_PLAN = 'plan';

    public const SOURCE_CATEGORY = 'category';

    public const SOURCE_GLOBAL = 'global';

    public const TYPE_PERCENT = MarketplaceCommissionRule::TYPE_PERCENT;

    public const TYPE_FIXED = MarketplaceCommissionRule::TYPE_FIXED;

    /**
     * @return array{
     *   type: string,
     *   value: float,
     *   source: string,
     *   rule_id: int|null,
     *   rate: float
     * }
     */
    public function resolveForSeller(Seller $seller, ?Product $product = null): array
    {
        return $this->resolveRule($seller, $product);
    }

    /**
     * @return array{
     *   type: string,
     *   value: float,
     *   source: string,
     *   rule_id: int|null,
     *   rate: float
     * }
     */
    public function resolveRule(Seller $seller, ?Product $product = null): array
    {
        if ($product) {
            $product->loadMissing('categories');
            $productRule = MarketplaceCommissionRule::query()
                ->where('is_enabled', true)
                ->where('scope', MarketplaceCommissionRule::SCOPE_PRODUCT)
                ->where('product_id', $product->id)
                ->orderByDesc('id')
                ->first();
            if ($productRule) {
                return $this->presentRule($productRule, self::SOURCE_PRODUCT);
            }
        }

        $sellerRule = MarketplaceCommissionRule::query()
            ->where('is_enabled', true)
            ->where('scope', MarketplaceCommissionRule::SCOPE_SELLER)
            ->where('seller_id', $seller->id)
            ->orderByDesc('id')
            ->first();
        if ($sellerRule) {
            return $this->presentRule($sellerRule, self::SOURCE_SELLER);
        }
        if (MarketplaceSettings::subscriptionPlansEnabled()) {
            $plan = app(SellerSubscriptionGate::class)->activePlan($seller);
            if ($plan && $plan->commission_value !== null && $plan->commission_value !== '') {
                $rate = $this->clampRate((float) $plan->commission_value);

                return [
                    'type' => self::TYPE_PERCENT,
                    'value' => $rate,
                    'source' => self::SOURCE_PLAN,
                    'rule_id' => null,
                    'rate' => $rate,
                ];
            }
        }

        // Live seller percent override (existing admin/seller commission UI).
        if ($seller->commission_rate !== null && $seller->commission_rate !== '') {
            $rate = $this->clampRate((float) $seller->commission_rate);

            return [
                'type' => self::TYPE_PERCENT,
                'value' => $rate,
                'source' => self::SOURCE_SELLER,
                'rule_id' => null,
                'rate' => $rate,
            ];
        }

        $defaultSellerCommission = SellerCommission::query()
            ->where('seller_id', $seller->id)
            ->where('is_enabled', true)
            ->where('is_default', true)
            ->orderByDesc('id')
            ->first();
        if ($defaultSellerCommission) {
            $type = ($defaultSellerCommission->type ?: self::TYPE_PERCENT) === self::TYPE_FIXED
                ? self::TYPE_FIXED
                : self::TYPE_PERCENT;
            $value = $type === self::TYPE_PERCENT
                ? $this->clampRate((float) $defaultSellerCommission->rate)
                : max(0, round((float) $defaultSellerCommission->rate, 2));

            return [
                'type' => $type,
                'value' => $value,
                'source' => self::SOURCE_SELLER,
                'rule_id' => null,
                'rate' => $type === self::TYPE_PERCENT ? $value : 0.0,
            ];
        }

        if ($product) {
            $categoryIds = collect([(int) ($product->default_category_id ?: 0)])
                ->merge($product->categories->pluck('id')->map(fn ($id) => (int) $id))
                ->filter()
                ->unique()
                ->values()
                ->all();
            if ($categoryIds !== []) {
                $categoryRule = MarketplaceCommissionRule::query()
                    ->where('is_enabled', true)
                    ->where('scope', MarketplaceCommissionRule::SCOPE_CATEGORY)
                    ->whereIn('category_id', $categoryIds)
                    ->orderByDesc('id')
                    ->first();
                if ($categoryRule) {
                    return $this->presentRule($categoryRule, self::SOURCE_CATEGORY);
                }
            }
        }

        $globalRule = MarketplaceCommissionRule::query()
            ->where('is_enabled', true)
            ->where('scope', MarketplaceCommissionRule::SCOPE_GLOBAL)
            ->orderByDesc('id')
            ->first();
        if ($globalRule) {
            return $this->presentRule($globalRule, self::SOURCE_GLOBAL);
        }

        $rate = $this->clampRate(MarketplaceSettings::defaultCommission());

        return [
            'type' => self::TYPE_PERCENT,
            'value' => $rate,
            'source' => self::SOURCE_GLOBAL,
            'rule_id' => null,
            'rate' => $rate,
        ];
    }

    /**
     * @return array{
     *   type: string,
     *   value: float,
     *   source: string,
     *   rule_id: int|null,
     *   rate: float
     * }
     */
    protected function presentRule(MarketplaceCommissionRule $rule, string $source): array
    {
        $type = $rule->type === self::TYPE_FIXED ? self::TYPE_FIXED : self::TYPE_PERCENT;
        $value = $type === self::TYPE_PERCENT
            ? $this->clampRate((float) $rule->value)
            : max(0, round((float) $rule->value, 2));

        return [
            'type' => $type,
            'value' => $value,
            'source' => $source,
            'rule_id' => (int) $rule->id,
            'rate' => $type === self::TYPE_PERCENT ? $value : 0.0,
        ];
    }

    public function clampRate(float $rate): float
    {
        return round(max(0, min(100, $rate)), 2);
    }

    public function toMinor(float|string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    public function fromMinor(int $minor): float
    {
        return round($minor / 100, 2);
    }

    /**
     * @param  array{type?: string, value?: float, rate?: float}  $rule
     * @return array{
     *   gross: float,
     *   commission_rate: float,
     *   commission_amount: float,
     *   seller_net: float,
     *   commission_type: string,
     *   commission_value: float,
     *   gross_minor: int,
     *   commission_minor: int,
     *   net_minor: int
     * }
     */
    public function calculate(float $gross, float|array $rateOrRule = 0): array
    {
        if (is_array($rateOrRule)) {
            $type = ($rateOrRule['type'] ?? self::TYPE_PERCENT) === self::TYPE_FIXED
                ? self::TYPE_FIXED
                : self::TYPE_PERCENT;
            $value = (float) ($rateOrRule['value'] ?? $rateOrRule['rate'] ?? 0);
        } else {
            $type = self::TYPE_PERCENT;
            $value = (float) $rateOrRule;
        }

        $grossMinor = max(0, $this->toMinor($gross));

        if ($type === self::TYPE_FIXED) {
            $fixedMinor = max(0, $this->toMinor($value));
            $commissionMinor = min($fixedMinor, $grossMinor);
            $value = $this->fromMinor($fixedMinor);
            $rate = $grossMinor > 0
                ? round(($commissionMinor / $grossMinor) * 100, 2)
                : 0.0;
        } else {
            $rate = $this->clampRate($value);
            $value = $rate;
            $commissionMinor = (int) round($grossMinor * ($rate / 100));
            if ($commissionMinor > $grossMinor) {
                $commissionMinor = $grossMinor;
            }
        }

        $netMinor = $grossMinor - $commissionMinor;

        return [
            'gross' => $this->fromMinor($grossMinor),
            'commission_rate' => $rate,
            'commission_amount' => $this->fromMinor($commissionMinor),
            'seller_net' => $this->fromMinor($netMinor),
            'commission_type' => $type,
            'commission_value' => $value,
            'gross_minor' => $grossMinor,
            'commission_minor' => $commissionMinor,
            'net_minor' => $netMinor,
        ];
    }

    /**
     * Resolve + calculate each line independently (supports mixed product/category rules).
     *
     * @param  list<array{order_item_id: int, product_id: int|null, gross: float, product?: Product|null}>  $lines
     * @return array{
     *   lines: list<array<string, mixed>>,
     *   commission_total: float,
     *   earning_total: float,
     *   commission_rate: float|null,
     *   commission_type: string,
     *   commission_value: float|null,
     *   commission_source: string,
     *   commission_rule_id: int|null
     * }
     */
    public function calculateLines(Seller $seller, array $lines): array
    {
        $out = [];
        $commissionTotal = 0.0;
        $earningTotal = 0.0;
        $sources = [];
        $types = [];
        $values = [];
        $ruleIds = [];

        $productIds = collect($lines)
            ->pluck('product_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $productsById = $productIds === []
            ? collect()
            : Product::query()->with('categories')->whereIn('id', $productIds)->get()->keyBy('id');

        foreach ($lines as $line) {
            $product = $line['product'] ?? null;
            if (! $product && ! empty($line['product_id'])) {
                $product = $productsById->get((int) $line['product_id']);
            }
            $rule = $this->resolveRule($seller, $product instanceof Product ? $product : null);
            $calc = $this->calculate((float) $line['gross'], $rule);
            $commissionTotal = round($commissionTotal + $calc['commission_amount'], 2);
            $earningTotal = round($earningTotal + $calc['seller_net'], 2);
            $sources[] = $rule['source'];
            $types[] = $rule['type'];
            $values[] = $rule['value'];
            $ruleIds[] = $rule['rule_id'];

            $out[] = [
                'order_item_id' => (int) $line['order_item_id'],
                'product_id' => $line['product_id'] !== null ? (int) $line['product_id'] : null,
                'gross' => $calc['gross'],
                'commission_amount' => $calc['commission_amount'],
                'net_amount' => $calc['seller_net'],
                'commission_rate' => $calc['commission_rate'],
                'commission_type' => $calc['commission_type'],
                'commission_value' => $calc['commission_value'],
                'commission_source' => $rule['source'],
                'commission_rule_id' => $rule['rule_id'],
            ];
        }

        $uniformSource = count(array_unique($sources)) === 1 ? ($sources[0] ?? self::SOURCE_GLOBAL) : 'mixed';
        $uniformType = count(array_unique($types)) === 1 ? ($types[0] ?? self::TYPE_PERCENT) : 'mixed';
        $uniformValue = count(array_unique(array_map(fn ($v) => (string) $v, $values))) === 1
            ? ($values[0] ?? null)
            : null;
        $uniformRule = count(array_unique(array_map(fn ($id) => (string) ($id ?? 'null'), $ruleIds))) === 1
            ? ($ruleIds[0] ?? null)
            : null;
        $uniformRate = null;
        if ($uniformType === self::TYPE_PERCENT && $uniformValue !== null) {
            $uniformRate = (float) $uniformValue;
        } elseif ($commissionTotal > 0 && ($gross = array_sum(array_column($out, 'gross'))) > 0) {
            $uniformRate = round(($commissionTotal / $gross) * 100, 2);
        }

        return [
            'lines' => $out,
            'commission_total' => $commissionTotal,
            'earning_total' => $earningTotal,
            'commission_rate' => $uniformRate,
            'commission_type' => $uniformType,
            'commission_value' => $uniformValue !== null ? (float) $uniformValue : null,
            'commission_source' => $uniformSource,
            'commission_rule_id' => $uniformRule,
        ];
    }

    /**
     * @deprecated Prefer calculateLines() for Phase 09 precedence.
     *
     * @param  list<array{order_item_id: int, product_id: int|null, gross: float}>  $lines
     * @return list<array{order_item_id: int, product_id: int|null, gross: float, commission_amount: float, net_amount: float, commission_rate: float}>
     */
    public function allocateLines(array $lines, float $ratePercent, float $sellerCommissionTotal, float $sellerNetTotal): array
    {
        $rate = $this->clampRate($ratePercent);
        $count = count($lines);
        if ($count === 0) {
            return [];
        }

        $allocatedCommission = 0.0;
        $allocatedNet = 0.0;
        $out = [];

        foreach ($lines as $i => $line) {
            $isLast = ($i === $count - 1);
            $calc = $this->calculate((float) $line['gross'], $rate);
            $commission = $calc['commission_amount'];
            $net = $calc['seller_net'];

            if ($isLast) {
                $commission = round($sellerCommissionTotal - $allocatedCommission, 2);
                $net = round($sellerNetTotal - $allocatedNet, 2);
            } else {
                $allocatedCommission = round($allocatedCommission + $commission, 2);
                $allocatedNet = round($allocatedNet + $net, 2);
            }

            $out[] = [
                'order_item_id' => (int) $line['order_item_id'],
                'product_id' => $line['product_id'] !== null ? (int) $line['product_id'] : null,
                'gross' => $calc['gross'],
                'commission_amount' => $commission,
                'net_amount' => $net,
                'commission_rate' => $rate,
            ];
        }

        return $out;
    }
}
