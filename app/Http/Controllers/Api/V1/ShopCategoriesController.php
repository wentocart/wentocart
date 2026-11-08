<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\FeatureValue;
use App\Domain\Catalog\Models\Product;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shop REST categories + category-trees REST.
 */
class ShopCategoriesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 15)));
        $page = max(1, (int) $request->query('page', 1));
        $parentId = $request->query('parent_id', $request->query('parentId'));
        $locale = app()->getLocale();

        $q = Category::query()
            ->where('is_enabled', true)
            ->with(['translations', 'children' => fn ($c) => $c->where('is_enabled', true)->orderBy('position')])
            ->orderBy('position')
            ->orderBy('id');
        if ($parentId !== null && $parentId !== '') {
            $q->where('parent_id', (int) $parentId);
        }
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->map(fn (Category $c) => $this->flatRow($c, $locale))
            ->values()
            ->all();

        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function show(int $id): JsonResponse
    {
        $locale = app()->getLocale();
        $category = Category::query()
            ->where('is_enabled', true)
            ->with(['translations', 'children' => fn ($c) => $c->where('is_enabled', true)->orderBy('position')])
            ->find($id);
        if (! $category) {
            return response()->json(['message' => 'Category not found.', 'success' => false], 404);
        }

        return response()->json($this->flatRow($category, $locale));
    }

    public function tree(Request $request): JsonResponse
    {
        $parentId = $request->query('parentId', $request->query('parent_id'));
        if ($parentId === null || $parentId === '') {
            return response()->json([]);
        }
        $depth = max(1, min(10, (int) $request->query('depth', 4)));
        $locale = app()->getLocale();

        $roots = Category::query()
            ->where('is_enabled', true)
            ->where('parent_id', (int) $parentId)
            ->with('translations')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $data = $roots->map(fn (Category $c) => $this->treeNode($c, $locale, $depth, 1))->values()->all();

        return response()->json($data);
    }

    /**
     * Returns highest enabled product price in category (or catalog-wide when id omitted).
     */
    public function maxPrice(?int $id = null): JsonResponse
    {
        $q = Product::query()->enabled();
        if ($id !== null && $id > 0) {
            $category = Category::query()->where('is_enabled', true)->find($id);
            if (! $category) {
                return response()->json(['message' => 'Category not found.', 'success' => false], 404);
            }
            $q->where(function ($inner) use ($id) {
                $inner->where('default_category_id', $id)
                    ->orWhereHas('categories', fn ($c) => $c->where('categories.id', $id));
            });
        }
        $max = (float) ($q->max('price') ?? 0);

        return response()->json([
            'data' => [
                'max_price' => $max,
            ],
        ]);
    }

    /**
     */
    public function filterableAttributes(Request $request): JsonResponse
    {
        $locale = app()->getLocale();
        $categoryId = (int) ($request->query('category_id') ?? $request->query('categoryId') ?? 0);
        if ($categoryId > 0 && ! Category::query()->where('is_enabled', true)->whereKey($categoryId)->exists()) {
            return response()->json(['message' => 'Category not found.', 'success' => false], 404);
        }

        $rows = Feature::query()
            ->where('is_enabled', true)
            ->where('is_filterable', true)
            ->with(['translations', 'values' => fn ($v) => $v->where('is_enabled', true)->with('translations')->orderBy('position')])
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(function (Feature $f) use ($locale) {
                $name = (string) ($f->translation($locale)?->name ?? $f->code);

                return [
                    'id' => (int) $f->id,
                    'code' => (string) $f->code,
                    'type' => (string) ($f->type ?? 'select'),
                    'adminName' => $name,
                    'name' => $name,
                    'isFilterable' => 1,
                    'options' => $f->values->map(function (FeatureValue $v) use ($locale) {
                        $label = (string) ($v->translation($locale)?->value ?? $v->code);

                        return [
                            'id' => (int) $v->id,
                            'adminName' => $label,
                            'label' => $label,
                            'swatchValue' => null,
                        ];
                    })->values()->all(),
                ];
            })
            ->values()
            ->all();

        return response()->json($rows);
    }

    /**
     */
    public function filterableAttributeOptions(Request $request, int $attributeId): JsonResponse
    {
        $locale = app()->getLocale();
        $feature = Feature::query()->where('is_enabled', true)->find($attributeId);
        if (! $feature) {
            return response()->json(['message' => 'Attribute not found.', 'success' => false], 404);
        }

        $q = FeatureValue::query()
            ->where('feature_id', $feature->id)
            ->where('is_enabled', true)
            ->with('translations')
            ->orderBy('position')
            ->orderBy('id');
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $q->where(function ($inner) use ($like, $locale) {
                $inner->where('code', 'like', $like)
                    ->orWhereHas('translations', fn ($t) => $t->where('value', 'like', $like));
            });
        }
        $perPage = min(50, max(1, (int) $request->query('per_page', 15)));
        $page = max(1, (int) $request->query('page', 1));
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->map(function (FeatureValue $v) use ($locale) {
                $label = (string) ($v->translation($locale)?->value ?? $v->code);

                return [
                    'id' => (int) $v->id,
                    'adminName' => $label,
                    'label' => $label,
                    'swatchValue' => null,
                    'sortOrder' => (int) ($v->position ?? 0),
                ];
            })
            ->values()
            ->all();

        return response()->json($rows)->withHeaders([
            'X-Total-Count' => (string) $total,
            'X-Page' => (string) $page,
            'X-Per-Page' => (string) $perPage,
        ]);
    }

    /**
     */
    public function descendants(Request $request): JsonResponse
    {
        $parentId = $request->query('parent_id', $request->query('parentId'));
        if ($parentId === null || $parentId === '') {
            return response()->json([]);
        }
        $locale = app()->getLocale();
        $children = Category::query()
            ->where('is_enabled', true)
            ->where('parent_id', (int) $parentId)
            ->with(['translations', 'children' => fn ($c) => $c->where('is_enabled', true)->orderBy('position')])
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (Category $c) => $this->flatRow($c, $locale))
            ->values()
            ->all();

        return response()->json($children);
    }

    /**
     * {id} is the category id referenced from categories.translations[].
     */
    public function translations(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->query('per_page', 10)));
        $page = max(1, (int) $request->query('page', 1));
        $locale = app()->getLocale();

        $q = Category::query()->where('is_enabled', true)->with('translations')->orderBy('id');
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->map(fn (Category $c) => $this->translationRow($c, $locale))
            ->values()
            ->all();

        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function showTranslation(int $id): JsonResponse
    {
        $locale = app()->getLocale();
        $category = Category::query()->where('is_enabled', true)->with('translations')->find($id);
        if (! $category) {
            return response()->json(['message' => 'Category translation not found.', 'success' => false], 404);
        }

        return response()->json($this->translationRow($category, $locale));
    }

    /**
     * @return array<string, mixed>
     */
    private function translationRow(Category $c, string $locale): array
    {
        $t = $c->translation($locale);
        $name = (string) ($t?->name ?? '');
        $slug = (string) ($t?->slug ?? '');

        return [
            'id' => (int) ($t?->id ?? $c->id),
            'categoryId' => (int) $c->id,
            'locale' => $locale,
            'name' => $name,
            'slug' => $slug,
            'urlPath' => $slug,
            'description' => (string) ($t?->description ?? ''),
            'metaTitle' => (string) ($t?->meta_title ?? $name),
            'metaDescription' => (string) ($t?->meta_description ?? ''),
            'metaKeywords' => (string) ($t?->meta_keywords ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function flatRow(Category $c, string $locale): array
    {
        $t = $c->translation($locale);
        $slug = (string) ($t?->slug ?? '');
        $name = (string) ($t?->name ?? '');
        $row = [
            'id' => (int) $c->id,
            'position' => (int) ($c->position ?? 0),
            'status' => 1,
            'displayMode' => 'products_and_description',
            'url' => $slug !== '' ? '/catalog/category/'.$slug : '/catalog',
            'minPrice' => 0,
            'maxPrice' => 0,
            '_lft' => (int) $c->id,
            '_rgt' => (int) $c->id,
            'createdAt' => $c->created_at?->toIso8601String(),
            'updatedAt' => $c->updated_at?->toIso8601String(),
            'translation' => [
                'id' => (int) ($t?->id ?? $c->id),
                'categoryId' => (int) $c->id,
                'locale' => $locale,
                'name' => $name,
                'slug' => $slug,
                'urlPath' => $slug,
                'description' => (string) ($t?->description ?? ''),
                'metaTitle' => (string) ($t?->meta_title ?? $name),
                'metaDescription' => (string) ($t?->meta_description ?? ''),
                'metaKeywords' => (string) ($t?->meta_keywords ?? ''),
            ],
            'translations' => [
                '/api/v1/store/category_translations/'.$c->id,
            ],
            'children' => $c->relationLoaded('children')
                ? $c->children->map(fn (Category $child) => '/api/v1/store/categories/'.$child->id)->values()->all()
                : [],
            'filterableAttributes' => [],
        ];
        if ($c->parent_id) {
            $row['parent'] = '/api/v1/store/categories/'.$c->parent_id;
        }
        if ($c->image_path) {
            $row['logoPath'] = (string) $c->image_path;
            $row['logoUrl'] = url('storage/'.$c->image_path);
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function treeNode(Category $c, string $locale, int $maxDepth, int $level): array
    {
        $t = $c->translation($locale);
        $slug = (string) ($t?->slug ?? '');
        $name = (string) ($t?->name ?? '');
        $children = [];
        if ($level < $maxDepth) {
            $kids = Category::query()
                ->where('is_enabled', true)
                ->where('parent_id', $c->id)
                ->with('translations')
                ->orderBy('position')
                ->orderBy('id')
                ->get();
            $children = $kids->map(fn (Category $child) => $this->treeNode($child, $locale, $maxDepth, $level + 1))->values()->all();
        }

        return [
            'id' => (int) $c->id,
            'position' => (int) ($c->position ?? 0),
            'status' => 1,
            'displayMode' => 'products_and_description',
            'url' => $slug !== '' ? '/catalog/category/'.$slug : '/catalog',
            '_lft' => (int) $c->id,
            '_rgt' => (int) $c->id,
            'createdAt' => $c->created_at?->toIso8601String(),
            'updatedAt' => $c->updated_at?->toIso8601String(),
            'translation' => [
                'id' => (int) ($t?->id ?? $c->id),
                'categoryId' => (int) $c->id,
                'locale' => $locale,
                'name' => $name,
                'slug' => $slug,
                'urlPath' => $slug,
                'description' => (string) ($t?->description ?? ''),
                'metaTitle' => (string) ($t?->meta_title ?? $name),
                'metaDescription' => (string) ($t?->meta_description ?? ''),
                'metaKeywords' => (string) ($t?->meta_keywords ?? ''),
            ],
            'children' => $children,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $data
     */
    private function paginated(array $data, int $total, int $page, int $perPage): JsonResponse
    {
        $pages = max(1, (int) ceil($total / max(1, $perPage)));

        return response()->json($data)->withHeaders([
            'X-Total-Count' => (string) $total,
            'X-Page' => (string) $page,
            'X-Per-Page' => (string) $perPage,
            'X-Total-Pages' => (string) $pages,
        ]);
    }
}
