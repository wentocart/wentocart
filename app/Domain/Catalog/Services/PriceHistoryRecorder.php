<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\PriceHistorySchema;
use App\Domain\Settings\OmnibusSettings;
use App\Domain\Settings\PricingSettings;
use App\Domain\Store\StoreContext;
use Illuminate\Support\Facades\DB;

final class PriceHistoryRecorder
{
    public function record(Product $product, ?int $storeId = null): void
    {
        if (! OmnibusSettings::enabled()) {
            return;
        }
        PriceHistorySchema::ensure();

        $storeId = $this->normalizeStoreId($storeId);
        $price = PricingSettings::roundMoney($product->catalogSellingPrice());
        $recordedOn = now()->toDateString();
        $now = now();

        $existing = DB::table('product_price_history')
            ->where('product_id', $product->id)
            ->where('recorded_on', $recordedOn)
            ->where(function ($query) use ($storeId) {
                if ($storeId === null) {
                    $query->whereNull('store_id');
                } else {
                    $query->where('store_id', $storeId);
                }
            })
            ->first();

        if ($existing !== null) {
            DB::table('product_price_history')->where('id', $existing->id)->update([
                'price' => min((float) $existing->price, $price),
                'recorded_at' => $now,
            ]);

            return;
        }

        DB::table('product_price_history')->insert([
            'product_id' => $product->id,
            'store_id' => $storeId,
            'price' => $price,
            'recorded_on' => $recordedOn,
            'recorded_at' => $now,
        ]);
    }

    /**
     * Lowest recorded catalog price in the sliding window. Pass $above to ignore
     * prices at or below a threshold (e.g. current sale price for EU Omnibus).
     */
    public function lowestPriceLastDays(int $productId, int $days = 30, ?float $above = null): ?float
    {
        PriceHistorySchema::ensure();

        $days = max(1, $days);
        $since = now()->subDays($days)->startOfDay();

        $query = DB::table('product_price_history')
            ->where('product_id', $productId)
            ->where('recorded_at', '>=', $since);

        if ($above !== null) {
            $query->where('price', '>', PricingSettings::roundMoney($above));
        }

        $min = $query->min('price');

        return $min !== null ? (float) $min : null;
    }

    public function windowDays(): int
    {
        return OmnibusSettings::windowDays();
    }

    private function normalizeStoreId(?int $storeId): ?int
    {
        if ($storeId !== null) {
            return $storeId > 0 ? $storeId : null;
        }

        if (! app()->bound(StoreContext::class)) {
            return null;
        }

        $contextId = app(StoreContext::class)->id();

        return $contextId > 0 ? $contextId : null;
    }
}
