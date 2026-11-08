<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Support\CatalogMediaUrl;
use App\Domain\Catalog\Support\DefaultImage;
use App\Domain\Localization\Models\Language;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerProduct;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class SellerInventoryListService
{
    public const SORT_COLUMNS = ['id', 'name', 'sku', 'supplier', 'stock_qty', 'reserved_qty', 'available'];

    /** @return array<string, mixed> */
    public function filtersFromRequest(Request $request): array
    {
        return [
            'name' => trim($request->string('name')->toString()),
            'sku' => trim($request->string('sku')->toString()),
            'supplier' => trim($request->string('supplier')->toString()),
            'id_min' => $request->input('id_min'),
            'id_max' => $request->input('id_max'),
            'sort' => $this->normalizeSort($request->string('sort')->toString() ?: 'id'),
            'dir' => $request->string('dir')->toString() === 'asc' ? 'asc' : 'desc',
            'page' => max(1, (int) $request->input('page', 1)),
            'per_page' => min(100, max(1, (int) $request->input('per_page', 20))),
        ];
    }

    public function normalizeSort(string $sort): string
    {
        return in_array($sort, self::SORT_COLUMNS, true) ? $sort : 'id';
    }

    /** @param  array<string, mixed>  $filters */
    public function query(Seller $seller, array $filters): Builder
    {
        $query = SellerProduct::query()
            ->where('seller_products.seller_id', $seller->id)
            ->join('products', function ($join) use ($seller) {
                $join->on('products.id', '=', 'seller_products.product_id')
                    ->where('products.seller_id', '=', $seller->id);
            })
            ->select('seller_products.*')
            ->with([
                'product:id,cover_path,stock_qty,reserved_qty,manage_stock,stock_status,supplier_id,seller_id',
                'product.supplier.translations',
            ]);

        $name = (string) ($filters['name'] ?? '');
        if ($name !== '') {
            $query->where('seller_products.name', 'like', '%'.$name.'%');
        }

        $sku = (string) ($filters['sku'] ?? '');
        if ($sku !== '') {
            $query->where('seller_products.sku', 'like', '%'.$sku.'%');
        }

        $supplier = (string) ($filters['supplier'] ?? '');
        if ($supplier !== '') {
            $query->whereHas('product.supplier.translations', fn (Builder $inner) => $inner->where('name', 'like', '%'.$supplier.'%'));
        }

        $idMin = $filters['id_min'] ?? null;
        $idMax = $filters['id_max'] ?? null;
        if ($idMin !== null && $idMin !== '') {
            $query->where('seller_products.product_id', '>=', (int) $idMin);
        }
        if ($idMax !== null && $idMax !== '') {
            $query->where('seller_products.product_id', '<=', (int) $idMax);
        }

        $sort = $this->normalizeSort((string) ($filters['sort'] ?? 'id'));
        $dir = ($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        match ($sort) {
            'id' => $query->orderBy('seller_products.product_id', $dir),
            'name' => $query->orderBy('seller_products.name', $dir),
            'sku' => $query->orderBy('seller_products.sku', $dir),
            'supplier' => (function () use ($query, $dir) {
                $locale = Language::defaultCode();
                $query->leftJoin('supplier_translations as inv_supplier_sort', function ($join) use ($locale) {
                    $join->on('inv_supplier_sort.supplier_id', '=', 'products.supplier_id')
                        ->where('inv_supplier_sort.locale', '=', $locale);
                });
                $query->orderBy('inv_supplier_sort.name', $dir);
            })(),
            'stock_qty' => $query->orderBy('products.stock_qty', $dir),
            'reserved_qty' => $query->orderBy('products.reserved_qty', $dir),
            'available' => $query->orderByRaw(
                '(products.stock_qty - COALESCE(products.reserved_qty, 0)) '.($dir === 'asc' ? 'asc' : 'desc'),
            ),
            default => $query->orderBy('seller_products.product_id', $dir),
        };

        return $query->orderBy('seller_products.product_id');
    }

    /** @param  array<string, mixed>  $filters */
    public function paginate(Seller $seller, array $filters): LengthAwarePaginator
    {
        $locale = Language::defaultCode();

        return $this->query($seller, $filters)
            ->paginate((int) ($filters['per_page'] ?? 20), ['seller_products.*'], 'page', (int) ($filters['page'] ?? 1))
            ->withQueryString()
            ->through(fn (SellerProduct $row) => $this->presentRow($row, $locale));
    }

    /** @return array<string, mixed> */
    public function presentRow(SellerProduct $row, ?string $locale = null): array
    {
        $product = $row->product;
        $stockQty = (int) ($product?->stock_qty ?? 0);
        $reservedQty = (int) ($product?->reserved_qty ?? 0);

        return [
            'product_id' => (int) $row->product_id,
            'sku' => $row->sku ?: $product?->sku,
            'name' => $row->name ?: ('Product #'.$row->product_id),
            'cover_path' => $product?->cover_path,
            'cover_url' => CatalogMediaUrl::url($product?->cover_path, DefaultImage::SM),
            'supplier_name' => $product?->supplier?->translation($locale)?->name
                ?? $product?->supplier?->translation()?->name,
            'stock_qty' => $stockQty,
            'reserved_qty' => $reservedQty,
            'available' => $product ? $product->availableStock() : max(0, $stockQty - $reservedQty),
            'manage_stock' => (bool) ($product?->manage_stock ?? true),
            'stock_status' => (string) ($product?->stock_status ?? ''),
            'is_enabled' => (bool) $row->is_enabled,
        ];
    }

    /** @param  array<int>  $productIds */
    public function ownedProductIds(Seller $seller, array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));

        return Product::query()
            ->where('seller_id', $seller->id)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
