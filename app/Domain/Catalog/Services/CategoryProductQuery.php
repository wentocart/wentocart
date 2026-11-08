<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Services\StorefrontCatalogCache;
use App\Domain\Settings\ProductSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CategoryProductQuery
{
    /**
     * List products via category_product (PK on category_id, product_id) instead of nested whereHas.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Domain\Catalog\Models\Product>  $query
     * @param  list<int>  $categoryIds
     */
    public static function constrain($query, array $categoryIds): void
    {
        $ids = array_values(array_filter(array_map('intval', $categoryIds)));
        if ($ids === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $table = $query->getModel()->getTable();
        $query->whereIn($table.'.id', function ($sub) use ($ids) {
            $sub->select('product_id')
                ->from('category_product')
                ->whereIn('category_id', $ids);
        });
    }

    /**
     * @param  list<int>  $categoryIds
     * @return array<int, int>
     */
    public static function counts(array $categoryIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $categoryIds)));
        if ($ids === []) {
            return [];
        }

        $key = StorefrontCatalogCache::key('cat.counts.'.md5(implode(',', $ids)));

        return Cache::remember($key, 120, function () use ($ids) {
            $prefix = DB::connection()->getTablePrefix();
            $pivot = $prefix.'category_product';
            $products = $prefix.'products';

            $rows = DB::table('category_product')
                ->join('products', 'products.id', '=', 'category_product.product_id')
                ->whereIn('category_product.category_id', $ids)
                ->where('products.is_enabled', true);
            $shopHide = ProductSettings::hideOutOfStock();
            $rows->where(function ($inner) use ($products, $shopHide) {
                $inner->where('products.manage_stock', false)
                    ->orWhereRaw("(COALESCE({$products}.stock_qty, 0) - COALESCE({$products}.reserved_qty, 0)) > 0")
                    ->orWhereIn('products.backorder_status', ['allow', 'allow_notify']);
                if (! $shopHide) {
                    $inner->orWhere(function ($visible) {
                        $visible->whereNull('products.backorder_status')
                            ->orWhere('products.backorder_status', '!=', 'hide');
                    });
                }
            });
            $rows = $rows
                ->groupBy('category_product.category_id')
                ->selectRaw("{$pivot}.category_id as category_id, COUNT(DISTINCT {$pivot}.product_id) as c")
                ->get();
            $out = [];
            foreach ($rows as $row) {
                $out[(int) $row->category_id] = (int) $row->c;
            }

            return $out;
        });
    }
}
