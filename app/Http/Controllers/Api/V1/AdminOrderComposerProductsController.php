<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Support\CatalogMediaUrl;
use App\Domain\Catalog\Support\DefaultImage;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 */
class AdminOrderComposerProductsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }

        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min(50, (int) $request->input('per_page', 30)));
        $locale = (string) ($request->input('locale') ?: app()->getLocale());
        $sort = (string) $request->input('sort', 'id');
        if (! in_array($sort, ['id', 'sku', 'created_at', 'updated_at'], true)) {
            $sort = 'id';
        }
        $order = strtolower((string) $request->input('order', 'desc')) === 'asc' ? 'asc' : 'desc';

        $query = Product::query()->with(['translations', 'images']);

        if ($request->filled('sku')) {
            $query->where('sku', (string) $request->input('sku'));
        }
        if ($request->filled('query')) {
            $term = trim((string) $request->input('query'));
            if ($term !== '') {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
                $query->where(function ($q) use ($like) {
                    $q->where('sku', 'like', $like)
                        ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', $like));
                });
            }
        }
        if ($request->filled('type')) {
            $rawType = strtolower(trim((string) $request->input('type')));
            if ($rawType === 'booking') {
                $query->whereIn('type', ['service', 'rental']);
            } else {
                $type = $this->normalizeType($rawType);
                if ($type !== null) {
                    $query->where('type', $type);
                }
            }
        }
        if ($request->has('status') && $request->input('status') !== '') {
            $query->where('is_enabled', (int) $request->input('status') === 1);
        }
        if ($request->filled('categoryId')) {
            $categoryId = (int) $request->input('categoryId');
            $query->where(function ($q) use ($categoryId) {
                $q->where('default_category_id', $categoryId)
                    ->orWhereHas('categories', fn ($c) => $c->where('categories.id', $categoryId));
            });
        }

        $paginator = $query->orderBy($sort, $order)->paginate($perPage, ['*'], 'page', $page);

        $data = $paginator->getCollection()->map(function (Product $p) use ($locale) {
            $name = $p->translation($locale)?->name;
            $price = $p->price !== null ? (float) $p->price : null;
            $saleable = (bool) $p->is_enabled
                && (! $p->manage_stock || (float) ($p->stock_qty ?? 0) > 0 || (bool) $p->allow_backorder);

            return [
                'id' => (int) $p->id,
                'sku' => (string) $p->sku,
                'type' => $this->productTypeKey((string) $p->type),
                'name' => $name,
                'status' => $p->is_enabled === null ? null : ((bool) $p->is_enabled ? 1 : 0),
                'price' => $price,
                'formattedPrice' => $price !== null ? number_format($price, 2, '.', '') : null,
                'baseImageUrl' => $this->imageUrl($p),
                'isSaleable' => $saleable && ! $p->isService() && ! $p->isRental(),
            ];
        })->values()->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    protected function normalizeType(string $type): ?string
    {
        $type = strtolower(trim($type));

        return match ($type) {
            'simple', 'virtual', 'downloadable' => 'simple',
            'configurable', 'combination' => 'combination',
            'bundle' => 'bundle',
            'grouped', 'group' => 'group',
            'booking', 'service', 'rental' => $type === 'rental' ? 'rental' : 'service',
            default => $type !== '' ? $type : null,
        };
    }

    protected function productTypeKey(string $type): string
    {
        return match ($type) {
            'combination' => 'configurable',
            'group' => 'grouped',
            'service', 'rental' => 'booking',
            default => $type,
        };
    }

    protected function imageUrl(Product $product): string
    {
        $path = $product->cover_path;
        if (! $path) {
            $first = $product->images->sortBy('position')->first();
            $path = $first?->path ?? null;
        }
        if (is_string($path) && $path !== '') {
            if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                return $path;
            }
            return CatalogMediaUrl::url($path, DefaultImage::MD);
        }

        return url(DefaultImage::path(DefaultImage::MD));
    }
}
