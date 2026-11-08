<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Support\CatalogMediaUrl;
use App\Domain\Catalog\Support\DefaultImage;
use App\Domain\Localization\Models\Language;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class CatalogInventoryListService
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
            'stock_min' => $this->unsigned($request->input('stock_min')),
            'stock_max' => $this->unsigned($request->input('stock_max')),
            'reserved_min' => $this->unsigned($request->input('reserved_min')),
            'reserved_max' => $this->unsigned($request->input('reserved_max')),
            'available_min' => $this->unsigned($request->input('available_min')),
            'available_max' => $this->unsigned($request->input('available_max')),
            'sort' => $this->normalizeSort($request->string('sort')->toString() ?: 'id'),
            'dir' => $request->string('dir')->toString() === 'asc' ? 'asc' : 'desc',
            'page' => max(1, (int) $request->input('page', 1)),
            'per_page' => \App\Support\AdminPagination::perPage($request, 10),
        ];
    }

    public function normalizeSort(string $sort): string
    {
        return in_array($sort, self::SORT_COLUMNS, true) ? $sort : 'id';
    }

    /** @param  array<string, mixed>  $filters */
    public function query(array $filters): Builder
    {
        $query = Product::query()
            ->select('products.*')
            ->with(['translations', 'supplier.translations']);

        $name = (string) ($filters['name'] ?? '');
        if ($name !== '') {
            $query->whereHas('translations', fn (Builder $inner) => $inner->where('name', 'like', '%'.$name.'%'));
        }

        $sku = (string) ($filters['sku'] ?? '');
        if ($sku !== '') {
            $query->where('products.sku', 'like', '%'.$sku.'%');
        }

        $supplier = (string) ($filters['supplier'] ?? '');
        if ($supplier !== '') {
            $query->whereHas('supplier.translations', fn (Builder $inner) => $inner->where('name', 'like', '%'.$supplier.'%'));
        }

        $idMin = $filters['id_min'] ?? null;
        $idMax = $filters['id_max'] ?? null;
        if ($idMin !== null && $idMin !== '') {
            $query->where('products.id', '>=', (int) $idMin);
        }
        if ($idMax !== null && $idMax !== '') {
            $query->where('products.id', '<=', (int) $idMax);
        }

        $this->applyQtyRange($query, 'products.stock_qty', $filters['stock_min'] ?? null, $filters['stock_max'] ?? null);
        $this->applyQtyRange($query, 'products.reserved_qty', $filters['reserved_min'] ?? null, $filters['reserved_max'] ?? null);
        $grammar = $query->getGrammar();
        $stockCol = $grammar->wrap($query->getModel()->qualifyColumn('stock_qty'));
        $reservedCol = $grammar->wrap($query->getModel()->qualifyColumn('reserved_qty'));
        $availableExpr = "(CAST({$stockCol} - COALESCE({$reservedCol}, 0) AS DECIMAL(12, 3)))";
        $availableMin = $filters['available_min'] ?? null;
        $availableMax = $filters['available_max'] ?? null;
        if ($availableMin !== null && $availableMin !== '') {
            $query->whereRaw($availableExpr.' >= ?', [(float) $availableMin]);
        }
        if ($availableMax !== null && $availableMax !== '') {
            $query->whereRaw($availableExpr.' <= ?', [(float) $availableMax]);
        }

        $sort = $this->normalizeSort((string) ($filters['sort'] ?? 'id'));
        $dir = ($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        match ($sort) {
            'id' => $query->orderBy('products.id', $dir),
            'name' => $query->orderBy(
                \App\Domain\Catalog\Models\ProductTranslation::query()
                    ->select('name')
                    ->whereColumn('product_id', 'products.id')
                    ->limit(1),
                $dir
            ),
            'sku' => $query->orderBy('products.sku', $dir),
            'supplier' => $query->orderBy(
                \App\Domain\Catalog\Models\SupplierTranslation::query()
                    ->select('name')
                    ->whereColumn('supplier_id', 'products.supplier_id')
                    ->where('language_code', Language::defaultCode())
                    ->limit(1),
                $dir
            ),
            'stock_qty' => $query->orderBy('products.stock_qty', $dir),
            'reserved_qty' => $query->orderBy('products.reserved_qty', $dir),
            'available' => $query->orderByRaw("{$availableExpr} ".($dir === 'asc' ? 'asc' : 'desc')),
            default => $query->orderBy('products.id', $dir),
        };

        return $query->orderBy('products.id');
    }

    /** @param  array<string, mixed>  $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $locale = Language::defaultCode();

        return $this->query($filters)
            ->paginate((int) ($filters['per_page'] ?? 20), ['products.*'], 'page', (int) ($filters['page'] ?? 1))
            ->withQueryString()
            ->through(fn (Product $product) => $this->presentRow($product, $locale));
    }

    /** @return array<string, mixed> */
    public function presentRow(Product $product, ?string $locale = null): array
    {
        $stockQty = (int) $product->stock_qty;
        $reservedQty = (int) ($product->reserved_qty ?? 0);

        return [
            'id' => (int) $product->id,
            'sku' => $product->sku,
            'name' => $product->translation($locale)?->name
                ?? $product->translation()?->name
                ?? ('Product #'.$product->id),
            'cover_path' => $product->cover_path,
            'cover_url' => CatalogMediaUrl::url($product->cover_path, DefaultImage::SM),
            'supplier_name' => $product->supplier?->translation($locale)?->name
                ?? $product->supplier?->translation()?->name,
            'stock_qty' => $stockQty,
            'reserved_qty' => $reservedQty,
            'available' => $product->availableStock(),
            'manage_stock' => (bool) $product->manage_stock,
            'stock_status' => (string) $product->stock_status,
        ];
    }

    /** @param  array<int>  $productIds */
    public function existingProductIds(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));

        return Product::query()
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    protected function unsigned(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }
        if (! is_numeric($value) || (float) $value < 0) {
            return 0;
        }

        return $value;
    }

    protected function applyQtyRange(Builder $query, string $column, mixed $min, mixed $max): void
    {
        if ($min !== null && $min !== '') {
            $query->where($column, '>=', (float) $min);
        }
        if ($max !== null && $max !== '') {
            $query->where($column, '<=', (float) $max);
        }
    }
}
