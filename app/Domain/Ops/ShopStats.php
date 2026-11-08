<?php

namespace App\Domain\Ops;

use App\Domain\Store\StoreContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ShopStats
{
    public static function estimate(string $table): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $driver = DB::getDriverName();
        $full = Schema::getConnection()->getTablePrefix().$table;
        if ($driver === 'mysql') {
            try {
                $row = DB::selectOne('SHOW TABLE STATUS LIKE ?', [$full]);
                if ($row && isset($row->Rows)) {
                    return (int) $row->Rows;
                }
            } catch (\Throwable) {
            }
        }

        return (int) DB::table($table)->count();
    }

    /**
     * @return array{all: int, active: int, inactive: int, out_of_stock: int}
     */
    public static function productTabs(): array
    {
        return Cache::remember('admin.products.tab_counts.'.self::productCacheSuffix(), 60, function () {
            $row = self::productsQuery()
                ->selectRaw(
                    'COUNT(*) as total,
                    SUM(CASE WHEN is_enabled = 1 THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN is_enabled = 0 THEN 1 ELSE 0 END) as inactive,
                    SUM(CASE WHEN stock_qty <= 0 THEN 1 ELSE 0 END) as out_of_stock'
                )
                ->first();

            return [
                'all' => (int) ($row->total ?? 0),
                'active' => (int) ($row->active ?? 0),
                'inactive' => (int) ($row->inactive ?? 0),
                'out_of_stock' => (int) ($row->out_of_stock ?? 0),
            ];
        });
    }

    /**
     * KPI cards above the products list.
     *
     * @return array<string, mixed>
     */
    public static function productStats(): array
    {
        return Cache::remember('admin.products.stats.'.self::productCacheSuffix(), 60, function () {
            $prevStart = now()->subDays(60);
            $prevEnd = now()->subDays(30);

            $row = self::productsQuery()
                ->selectRaw(
                    'COUNT(*) as total,
                    SUM(CASE WHEN is_enabled = 1 THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN stock_qty <= 0 THEN 1 ELSE 0 END) as out_of_stock,
                    SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as new_count,
                    SUM(CASE WHEN created_at < ? THEN 1 ELSE 0 END) as total_prev,
                    SUM(CASE WHEN is_enabled = 1 AND created_at < ? THEN 1 ELSE 0 END) as active_prev,
                    SUM(CASE WHEN stock_qty <= 0 AND created_at < ? THEN 1 ELSE 0 END) as out_prev,
                    SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) as new_prev,
                    AVG(price) as avg_price,
                    AVG(CASE WHEN created_at < ? THEN price ELSE NULL END) as avg_price_prev',
                    [$prevEnd, $prevEnd, $prevEnd, $prevEnd, $prevStart, $prevEnd, $prevEnd]
                )
                ->first();

            $total = (int) ($row->total ?? 0);
            $totalPrev = (int) ($row->total_prev ?? 0);
            $new = (int) ($row->new_count ?? 0);
            $newPrev = (int) ($row->new_prev ?? 0);
            $active = (int) ($row->active ?? 0);
            $activePrev = (int) ($row->active_prev ?? 0);
            $outOfStock = (int) ($row->out_of_stock ?? 0);
            $outPrev = (int) ($row->out_prev ?? 0);
            $avgPrice = (float) ($row->avg_price ?? 0);
            $avgPricePrev = (float) ($row->avg_price_prev ?? 0);

            return [
                'total' => $total,
                'total_trend' => self::statTrend($total, $totalPrev),
                'new' => $new,
                'new_trend' => self::statTrend($new, $newPrev),
                'active' => $active,
                'active_trend' => self::statTrend($active, $activePrev),
                'out_of_stock' => $outOfStock,
                'out_of_stock_trend' => self::statTrend($outOfStock, $outPrev),
                'avg_price' => round($avgPrice, 2),
                'avg_price_trend' => self::statTrend($avgPrice, $avgPricePrev),
            ];
        });
    }

    private static function statTrend(float|int $current, float|int $previous): float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    public static function flushProducts(): void
    {
        Cache::forever('admin.products.cache_v', (int) Cache::get('admin.products.cache_v', 1) + 1);
    }

    private static function productCacheSuffix(): string
    {
        $version = (int) Cache::get('admin.products.cache_v', 1);
        $context = app(StoreContext::class);
        if (! $context->filters()) {
            return $version.'.all';
        }

        return $version.'.'.implode('-', $context->catalogStoreIds());
    }

    private static function productsQuery(): Builder
    {
        $query = DB::table('products');
        $context = app(StoreContext::class);
        if (! $context->filters()) {
            return $query;
        }

        $ids = array_values(array_filter($context->catalogStoreIds()));
        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereExists(function ($sub) use ($ids) {
            $sub->selectRaw('1')
                ->from('product_store')
                ->whereColumn('product_store.product_id', 'products.id')
                ->whereIn('product_store.store_id', $ids)
                ->where('product_store.is_visible', true);
        });
    }

    public static function flushOrders(): void
    {
        Cache::forever('admin.orders.cache_v', (int) Cache::get('admin.orders.cache_v', 1) + 1);
    }

    public static function orderCacheSuffix(): string
    {
        $version = (int) Cache::get('admin.orders.cache_v', 1);
        $context = app(StoreContext::class);
        if (! $context->filters()) {
            return $version.'.all';
        }

        return $version.'.'.implode('-', $context->idsFor(\App\Domain\Store\Sharing::FAMILY_ORDERS));
    }

    public static function flushCustomers(): void
    {
        Cache::forever('admin.customers.cache_v', (int) Cache::get('admin.customers.cache_v', 1) + 1);
    }

    public static function flushAll(): void
    {
        self::flushProducts();
        self::flushOrders();
        self::flushCustomers();
        Cache::forget('sf.category.facets');
    }
}
