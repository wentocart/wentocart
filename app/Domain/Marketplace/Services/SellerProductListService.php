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
use Illuminate\Support\Collection;

class SellerProductListService
{
    public const SORT_COLUMNS = ['id', 'name', 'sku', 'price', 'stock_qty', 'is_enabled', 'created_at'];

    /** @return array<string, mixed> */
    public function filtersFromRequest(Request $request): array
    {
        $status = trim($request->string('status')->toString() ?: 'all');
        $search = trim($request->string('q')->toString() ?: $request->string('search')->toString());

        return [
            'status' => $status === '' ? 'all' : $status,
            'q' => $search,
            'name' => trim($request->string('name')->toString()),
            'sku' => trim($request->string('sku')->toString()),
            'category' => trim($request->string('category')->toString()),
            'id_min' => $request->input('id_min'),
            'id_max' => $request->input('id_max'),
            'price_min' => $request->input('price_min'),
            'price_max' => $request->input('price_max'),
            'qty_min' => $request->input('qty_min'),
            'qty_max' => $request->input('qty_max'),
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
            ->where('seller_id', $seller->id)
            ->with([
                'product:id,type,cover_path,seller_id',
                'category.translations',
            ]);

        $search = (string) ($filters['q'] ?? '');
        if ($search !== '') {
            $query->where(function (Builder $inner) use ($search) {
                $inner->where('seller_products.name', 'like', '%'.$search.'%')
                    ->orWhere('seller_products.sku', 'like', '%'.$search.'%');
            });
        }

        $name = (string) ($filters['name'] ?? '');
        if ($name !== '') {
            $query->where('seller_products.name', 'like', '%'.$name.'%');
        }

        $sku = (string) ($filters['sku'] ?? '');
        if ($sku !== '') {
            $query->where('seller_products.sku', 'like', '%'.$sku.'%');
        }

        $category = (string) ($filters['category'] ?? '');
        if ($category !== '') {
            $query->whereHas('category.translations', fn (Builder $inner) => $inner->where('name', 'like', '%'.$category.'%'));
        }

        $idMin = $filters['id_min'] ?? null;
        $idMax = $filters['id_max'] ?? null;
        if ($idMin !== null && $idMin !== '') {
            $query->where('seller_products.product_id', '>=', (int) $idMin);
        }
        if ($idMax !== null && $idMax !== '') {
            $query->where('seller_products.product_id', '<=', (int) $idMax);
        }

        $priceMin = $filters['price_min'] ?? null;
        $priceMax = $filters['price_max'] ?? null;
        if ($priceMin !== null && $priceMin !== '') {
            $query->where('seller_products.price', '>=', (float) $priceMin);
        }
        if ($priceMax !== null && $priceMax !== '') {
            $query->where('seller_products.price', '<=', (float) $priceMax);
        }

        $qtyMin = $filters['qty_min'] ?? null;
        $qtyMax = $filters['qty_max'] ?? null;
        if ($qtyMin !== null && $qtyMin !== '') {
            $query->where('seller_products.stock_qty', '>=', (int) $qtyMin);
        }
        if ($qtyMax !== null && $qtyMax !== '') {
            $query->where('seller_products.stock_qty', '<=', (int) $qtyMax);
        }

        $status = (string) ($filters['status'] ?? 'all');
        if ($status === 'active') {
            $query->where('seller_products.is_enabled', true);
        } elseif ($status === 'inactive') {
            $query->where('seller_products.is_enabled', false);
        } elseif ($status === 'out_of_stock') {
            $query->where('seller_products.stock_qty', '<=', 0);
        } elseif (in_array($status, SellerProduct::STATUSES, true)) {
            $query->where('seller_products.approval_status', $status);
        }

        $sort = $this->normalizeSort((string) ($filters['sort'] ?? 'id'));
        $dir = ($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        if ($sort === 'id') {
            $query->orderBy('seller_products.product_id', $dir);
        } else {
            $query->orderBy('seller_products.'.$sort, $dir);
        }
        $query->orderBy('seller_products.product_id');

        return $query;
    }

    /** @param  array<string, mixed>  $filters */
    public function paginate(Seller $seller, array $filters): LengthAwarePaginator
    {
        $locale = Language::defaultCode();

        return $this->query($seller, $filters)
            ->paginate((int) ($filters['per_page'] ?? 20), ['*'], 'page', (int) ($filters['page'] ?? 1))
            ->withQueryString()
            ->through(fn (SellerProduct $row) => $this->presentRow($row, $locale));
    }

    /** @param  array<string, mixed>  $filters */
    public function exportRows(Seller $seller, array $filters): Collection
    {
        $locale = Language::defaultCode();

        return $this->query($seller, $filters)
            ->get()
            ->map(fn (SellerProduct $row) => $this->presentRow($row, $locale));
    }

    /** @return array<string, mixed> */
    public function presentRow(SellerProduct $row, ?string $locale = null): array
    {
        $locale = $locale ?: Language::defaultCode();
        $category = $row->category;
        $categoryName = $category?->translation($locale)?->name
            ?? $category?->translation()?->name;

        return [
            'id' => $row->id,
            'product_id' => $row->product_id,
            'sku' => $row->sku,
            'name' => $row->name,
            'slug' => $row->slug,
            'type' => $row->product?->type,
            'cover_path' => $row->product?->cover_path,
            'cover_url' => CatalogMediaUrl::url($row->product?->cover_path, DefaultImage::SM),
            'category_name' => $categoryName,
            'price' => (float) $row->price,
            'stock_qty' => (int) $row->stock_qty,
            'is_enabled' => (bool) $row->is_enabled,
            'approval_status' => $row->approval_status,
            'approval_note' => $row->approval_note,
            'reviewed_at' => $row->reviewed_at?->toIso8601String(),
            'status_label' => $this->statusLabel($row),
        ];
    }

    public function statusLabel(SellerProduct $row): string
    {
        if ($row->approval_status === SellerProduct::STATUS_PENDING) {
            return 'pending';
        }
        if ($row->approval_status === SellerProduct::STATUS_REJECTED) {
            return 'rejected';
        }
        if ($row->is_enabled) {
            return 'active';
        }

        return 'inactive';
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
