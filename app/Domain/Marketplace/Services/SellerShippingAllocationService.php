<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Order\Models\Order;
use App\Domain\Shipping\Models\ShippingMethod;

/**
 * Phase 14 — allocate parent Order shipping onto SellerOrder slices.
 *
 * Strategies (explicit, testable):
 * - seller_owned_method: selected ShippingMethod.seller_id is set and that seller
 *   is on the order → charge 100% of parent shipping_total to that seller.
 * - proportional_merchandise: otherwise split by merchandise line totals
 *   (item.total). Platform (null seller_id) lines get a platform bucket that is
 *   NOT stored on any SellerOrder.
 *
 * Rounding: every bucket except the largest (tie-break higher seller_id;
 * platform loses ties to sellers) is floored via round(share, 2); the remainder
 * lands on the largest bucket so sum(sellers)+platform == parent shipping_total.
 *
 * Commissionable gross continues to exclude shipping (see SellerService).
 */
class SellerShippingAllocationService
{
    public const STRATEGY_SELLER_OWNED = 'seller_owned_method';

    public const STRATEGY_PROPORTIONAL = 'proportional_merchandise';

    /**
     * @return array{
     *     strategy: string,
     *     parent_shipping: float,
     *     sellers: array<int, float>,
     *     platform: float,
     *     shipping_method_id: int|null
     * }
     */
    public function allocate(Order $order): array
    {
        $order->loadMissing(['items.product', 'shippingMethod']);

        $parentShipping = round((float) $order->shipping_total, 2);
        $methodId = $order->shipping_method_id ? (int) $order->shipping_method_id : null;

        /** @var array<int, float> $weights */
        $weights = [];
        $platformWeight = 0.0;

        foreach ($order->items as $item) {
            $line = round((float) $item->total, 2);
            $sellerId = (int) ($item->product?->seller_id ?? 0);
            if ($sellerId > 0) {
                $weights[$sellerId] = round(($weights[$sellerId] ?? 0) + $line, 2);
            } else {
                $platformWeight = round($platformWeight + $line, 2);
            }
        }

        ksort($weights);

        $emptySellers = [];
        foreach (array_keys($weights) as $sellerId) {
            $emptySellers[$sellerId] = 0.0;
        }

        if ($parentShipping <= 0.0) {
            return [
                'strategy' => self::STRATEGY_PROPORTIONAL,
                'parent_shipping' => 0.0,
                'sellers' => $emptySellers,
                'platform' => 0.0,
                'shipping_method_id' => $methodId,
            ];
        }

        $method = $order->shippingMethod
            ?? ($methodId ? ShippingMethod::query()->find($methodId) : null);

        if ($method && $method->seller_id) {
            $ownerId = (int) $method->seller_id;
            if (array_key_exists($ownerId, $weights)) {
                $sellers = $emptySellers;
                $sellers[$ownerId] = $parentShipping;

                return [
                    'strategy' => self::STRATEGY_SELLER_OWNED,
                    'parent_shipping' => $parentShipping,
                    'sellers' => $sellers,
                    'platform' => 0.0,
                    'shipping_method_id' => $methodId,
                ];
            }
        }

        return $this->proportional($weights, $platformWeight, $parentShipping, $methodId);
    }

    /**
     * @param  array<int, float>  $weights
     * @return array{
     *     strategy: string,
     *     parent_shipping: float,
     *     sellers: array<int, float>,
     *     platform: float,
     *     shipping_method_id: int|null
     * }
     */
    protected function proportional(
        array $weights,
        float $platformWeight,
        float $parentShipping,
        ?int $methodId,
    ): array {
        $totalWeight = round(array_sum($weights) + $platformWeight, 2);
        $sellers = [];
        foreach (array_keys($weights) as $sellerId) {
            $sellers[$sellerId] = 0.0;
        }

        if ($totalWeight <= 0.0) {
            return [
                'strategy' => self::STRATEGY_PROPORTIONAL,
                'parent_shipping' => $parentShipping,
                'sellers' => $sellers,
                'platform' => $parentShipping,
                'shipping_method_id' => $methodId,
            ];
        }

        /** @var list<array{key: string, seller_id: int|null, weight: float}> $buckets */
        $buckets = [];
        foreach ($weights as $sellerId => $weight) {
            $buckets[] = [
                'key' => 'seller:'.$sellerId,
                'seller_id' => $sellerId,
                'weight' => $weight,
            ];
        }
        if ($platformWeight > 0.0) {
            $buckets[] = [
                'key' => 'platform',
                'seller_id' => null,
                'weight' => $platformWeight,
            ];
        }

        // Remainder goes to the largest bucket (weight desc, then seller_id desc; platform last on ties).
        usort($buckets, static function (array $a, array $b): int {
            if ($a['weight'] !== $b['weight']) {
                return $a['weight'] <=> $b['weight'];
            }
            $aId = $a['seller_id'] ?? -1;
            $bId = $b['seller_id'] ?? -1;

            return $aId <=> $bId;
        });

        $allocated = 0.0;
        $shares = [];
        $lastIndex = count($buckets) - 1;

        foreach ($buckets as $index => $bucket) {
            if ($index === $lastIndex) {
                $share = round($parentShipping - $allocated, 2);
            } else {
                $share = round(($bucket['weight'] / $totalWeight) * $parentShipping, 2);
                $allocated = round($allocated + $share, 2);
            }
            $shares[$bucket['key']] = $share;
        }

        $platform = (float) ($shares['platform'] ?? 0.0);
        foreach ($weights as $sellerId => $_) {
            $sellers[$sellerId] = (float) ($shares['seller:'.$sellerId] ?? 0.0);
        }

        return [
            'strategy' => self::STRATEGY_PROPORTIONAL,
            'parent_shipping' => $parentShipping,
            'sellers' => $sellers,
            'platform' => $platform,
            'shipping_method_id' => $methodId,
        ];
    }

    /**
     * Assert sum(sellers)+platform equals parent shipping (within 0.01).
     *
     * @param  array{parent_shipping: float, sellers: array<int, float>, platform: float}  $allocation
     */
    public function assertReconciles(array $allocation): bool
    {
        $sum = round(array_sum($allocation['sellers']) + (float) $allocation['platform'], 2);

        return abs($sum - (float) $allocation['parent_shipping']) < 0.015;
    }
}
