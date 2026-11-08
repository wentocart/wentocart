<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\CatalogAttribute;
use App\Domain\Catalog\Models\AttributeValue;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\FeatureValue;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Catalog\Models\SupplierTranslation;
use App\Domain\Catalog\Services\AttributeService;
use App\Domain\Catalog\Services\BrandService;
use App\Domain\Catalog\Services\CategoryService;
use App\Domain\Catalog\Services\FeatureService;
use App\Domain\Catalog\Services\SupplierService;
use App\Domain\Catalog\Services\UrlRewriteService;
use App\Domain\Localization\Models\Language;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerAttribute;
use App\Domain\Marketplace\Models\SellerBrand;
use App\Domain\Marketplace\Models\SellerCategory;
use App\Domain\Marketplace\Models\SellerFeature;
use App\Domain\Marketplace\Models\SellerSupplier;
use App\Domain\Marketplace\Models\SellerWithdrawal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SellerCatalogService
{
    public function __construct(
        protected CategoryService $categories,
        protected BrandService $brands,
        protected FeatureService $features,
        protected AttributeService $attributes,
        protected SupplierService $suppliers,
        protected UrlRewriteService $urls,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $files
     */
    public function saveCategory(Seller $seller, array $data, ?SellerCategory $row = null, array $files = []): SellerCategory
    {
        return DB::transaction(function () use ($seller, $data, $row, $files) {
            $name = trim((string) $data['name']);
            $slug = $this->uniqueSellerSlug($seller, 'categories', $data['slug'] ?? $name, $row?->id);
            $parentId = ($data['parent_id'] ?? '') === '' || ($data['parent_id'] ?? null) === null
                ? null
                : (int) $data['parent_id'];
            if ($parentId) {
                $parent = SellerCategory::query()->where('seller_id', $seller->id)->whereKey($parentId)->first();
                if (! $parent) {
                    throw ValidationException::withMessages(['parent_id' => 'Choose a category that belongs to this seller.']);
                }
                if ($row && (int) $parent->id === (int) $row->id) {
                    throw ValidationException::withMessages(['parent_id' => 'A category cannot be its own parent.']);
                }
                if ($row && in_array($parentId, $this->sellerCategoryDescendantIds($row), true)) {
                    throw ValidationException::withMessages(['parent_id' => 'Cannot set a descendant category as parent.']);
                }
            }

            $payload = [
                'seller_id' => $seller->id,
                'parent_id' => $parentId,
                'name' => $name,
                'slug' => $slug,
                'description' => $data['description'] ?? null,
                'is_enabled' => (bool) ($data['is_enabled'] ?? true),
                'position' => (int) ($data['position'] ?? 0),
            ];

            if ($row) {
                $this->assertOwned($seller, $row->seller_id);
                $row->fill($payload)->save();
            } else {
                $row = SellerCategory::query()->create($payload);
            }

            $parentCoreId = $parentId
                ? SellerCategory::query()->whereKey($parentId)->value('category_id')
                : null;

            $core = $row->category_id ? Category::query()->find($row->category_id) : null;
            $corePayload = $this->coreCategoryPayload($seller, $row, $data, $parentCoreId);
            $saved = $this->categories->save($corePayload, $core, $files);
            $row->update(['category_id' => $saved->id]);

            return $row->fresh(['seller', 'category.translations']);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sellerCategoryTree(Seller $seller, ?string $locale = null): array
    {
        $locale ??= Language::defaultCode();
        $rows = SellerCategory::query()
            ->where('seller_id', $seller->id)
            ->with(['category' => fn ($query) => $query->with(['translations'])->withCount(['products', 'children'])])
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return $this->buildSellerCategoryTree(null, $rows, $locale);
    }

    /** @return array{all:int,active:int,inactive:int} */
    public function sellerCategoryCounts(Seller $seller): array
    {
        $base = SellerCategory::query()->where('seller_id', $seller->id);

        return [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('is_enabled', true)->count(),
            'inactive' => (clone $base)->where('is_enabled', false)->count(),
        ];
    }

    /** @return list<array{id:int,name:string,depth:int,parent_id:?int,is_enabled:bool}> */
    public function sellerCategoryParentOptions(Seller $seller, ?SellerCategory $exclude = null): array
    {
        $rows = SellerCategory::query()
            ->where('seller_id', $seller->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        $excludeIds = $exclude
            ? array_merge([(int) $exclude->id], $this->sellerCategoryDescendantIds($exclude))
            : [];

        return $this->buildSellerCategoryFlatTree(null, $rows, 0, $excludeIds);
    }

    /** @return array<string, mixed> */
    public function presentSellerCategoryForm(Seller $seller, SellerCategory $row, ?string $locale = null): array
    {
        $locale ??= Language::defaultCode();
        $row->loadMissing(['category.translations']);
        $core = $row->category;
        $presented = $core
            ? $this->categories->present($core, $locale)
            : [
                'name' => $row->name,
                'slug' => $row->slug,
                'description' => $row->description,
                'is_enabled' => (bool) $row->is_enabled,
                'position' => (int) $row->position,
                'translations' => [],
                'customer_group_ids' => [],
            ];

        $presented['id'] = $row->id;
        $presented['parent_id'] = $row->parent_id;
        $presented['seller_slug'] = $row->slug;
        if ($core && $presented['slug'] && str_starts_with((string) $presented['slug'], $seller->shop_slug.'-')) {
            $presented['slug'] = substr((string) $presented['slug'], strlen($seller->shop_slug) + 1);
        }
        if (! empty($presented['translations']) && is_array($presented['translations'])) {
            $presented['translations'] = array_map(function (array $translation) use ($seller) {
                if (! empty($translation['slug'])) {
                    $translation['slug'] = $this->stripSellerSlugPrefix($seller, (string) $translation['slug']);
                }

                return $translation;
            }, $presented['translations']);
        }

        return $presented;
    }

    public function toggleCategory(Seller $seller, SellerCategory $row): SellerCategory
    {
        $this->assertOwned($seller, $row->seller_id);
        $row->update(['is_enabled' => ! $row->is_enabled]);
        if ($row->category_id) {
            Category::query()->whereKey($row->category_id)->update(['is_enabled' => $row->is_enabled]);
        }

        return $row->fresh(['category']);
    }

    /** @param  list<int|string>  $orderedIds */
    public function reorderCategories(Seller $seller, array $orderedIds): void
    {
        $mapped = SellerCategory::query()
            ->where('seller_id', $seller->id)
            ->whereIn('id', $orderedIds)
            ->pluck('category_id', 'id');

        $coreIds = [];
        foreach ($orderedIds as $id) {
            $coreId = $mapped[(int) $id] ?? null;
            if ($coreId) {
                $coreIds[] = (int) $coreId;
            }
        }

        if ($coreIds !== []) {
            $this->categories->reorder($coreIds);
        }

        foreach ($orderedIds as $position => $id) {
            SellerCategory::query()
                ->where('seller_id', $seller->id)
                ->whereKey((int) $id)
                ->update(['position' => $position]);
        }
    }

    /** @param  list<int|string>  $ids */
    public function bulkCategoryStatus(Seller $seller, array $ids, bool $enabled): int
    {
        $rows = SellerCategory::query()
            ->where('seller_id', $seller->id)
            ->whereIn('id', $ids)
            ->get();
        $updated = 0;
        foreach ($rows as $row) {
            $row->update(['is_enabled' => $enabled]);
            if ($row->category_id) {
                Category::query()->whereKey($row->category_id)->update(['is_enabled' => $enabled]);
            }
            $updated++;
        }

        return $updated;
    }

    /** @param  list<int|string>  $ids */
    public function bulkDeleteCategories(Seller $seller, array $ids): int
    {
        $deleted = 0;
        foreach ($ids as $id) {
            $row = SellerCategory::query()->where('seller_id', $seller->id)->whereKey((int) $id)->first();
            if (! $row) {
                continue;
            }
            try {
                $this->deleteCategory($seller, $row);
                $deleted++;
            } catch (ValidationException) {
                // Skip categories with children or product assignments.
            }
        }

        return $deleted;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $files
     */
    protected function coreCategoryPayload(Seller $seller, SellerCategory $row, array $data, ?int $parentCoreId): array
    {
        $slug = $row->slug;
        if (isset($data['slug']) && trim((string) $data['slug']) !== '') {
            $slug = Str::slug((string) $data['slug']) ?: $slug;
        }

        $payload = [
            'seller_id' => $seller->id,
            'parent_id' => $parentCoreId,
            'is_enabled' => (bool) ($data['is_enabled'] ?? $row->is_enabled),
            'position' => (int) ($data['position'] ?? $row->position),
            'name' => trim((string) ($data['name'] ?? $row->name)),
            'slug' => $seller->shop_slug.'-'.$slug,
            'description' => $data['description'] ?? $row->description,
            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'meta_keywords' => $data['meta_keywords'] ?? null,
            'canonical_url' => $data['canonical_url'] ?? null,
            'customer_group_ids' => $data['customer_group_ids'] ?? null,
            'remove_image' => (bool) ($data['remove_image'] ?? false),
            'remove_thumbnail' => (bool) ($data['remove_thumbnail'] ?? false),
        ];

        if (! empty($data['translations']) && is_array($data['translations'])) {
            $payload['translations'] = $this->prefixSellerCategoryTranslations($seller, $data['translations'], $slug);
        }

        return $payload;
    }

    /**
     * @param  array<string|int, array<string, mixed>>  $translations
     * @return array<string, array<string, mixed>>
     */
    protected function prefixSellerCategoryTranslations(Seller $seller, array $translations, string $fallbackSlug): array
    {
        $prefixed = [];
        foreach ($translations as $language => $row) {
            if (! is_array($row)) {
                continue;
            }
            $languageCode = (string) ($row['language_code'] ?? $language);
            $localSlug = trim((string) ($row['slug'] ?? ''));
            if ($localSlug === '') {
                $localSlug = Str::slug(trim((string) ($row['name'] ?? ''))) ?: $fallbackSlug;
            } else {
                $localSlug = Str::slug($localSlug) ?: $fallbackSlug;
            }
            $prefixed[$languageCode] = [
                ...$row,
                'language_code' => $languageCode,
                'slug' => $seller->shop_slug.'-'.$localSlug,
            ];
        }

        return $prefixed;
    }

    protected function stripSellerSlugPrefix(Seller $seller, string $slug): string
    {
        $prefix = $seller->shop_slug.'-';
        if (str_starts_with($slug, $prefix)) {
            return substr($slug, strlen($prefix));
        }

        return $slug;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SellerCategory>  $rows
     * @return list<array<string, mixed>>
     */
    protected function buildSellerCategoryTree(?int $parentId, $rows, string $locale): array
    {
        $out = [];
        foreach ($rows->where('parent_id', $parentId)->sortBy([['position', 'asc'], ['id', 'asc']]) as $row) {
            $core = $row->category;
            $node = $core
                ? $this->categories->present($core, $locale)
                : [
                    'name' => $row->name,
                    'slug' => $row->slug,
                    'description' => $row->description,
                    'is_enabled' => (bool) $row->is_enabled,
                    'position' => (int) $row->position,
                    'products_count' => 0,
                    'children_count' => 0,
                    'image_url' => null,
                ];
            $node['id'] = $row->id;
            $node['parent_id'] = $row->parent_id;
            $node['seller_slug'] = $row->slug;
            $node['children'] = $this->buildSellerCategoryTree((int) $row->id, $rows, $locale);
            $out[] = $node;
        }

        return $out;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SellerCategory>  $rows
     * @param  list<int>  $excludeIds
     * @return list<array{id:int,name:string,depth:int,parent_id:?int,is_enabled:bool}>
     */
    protected function buildSellerCategoryFlatTree(?int $parentId, $rows, int $depth, array $excludeIds): array
    {
        $out = [];
        foreach ($rows->where('parent_id', $parentId)->sortBy([['position', 'asc'], ['id', 'asc']]) as $row) {
            if (in_array((int) $row->id, $excludeIds, true)) {
                continue;
            }
            $label = str_repeat('— ', $depth).$row->name;
            $out[] = [
                'id' => $row->id,
                'name' => $label,
                'depth' => $depth,
                'parent_id' => $row->parent_id,
                'is_enabled' => (bool) $row->is_enabled,
            ];
            $out = array_merge($out, $this->buildSellerCategoryFlatTree((int) $row->id, $rows, $depth + 1, $excludeIds));
        }

        return $out;
    }

    /** @return list<int> */
    protected function sellerCategoryDescendantIds(SellerCategory $row): array
    {
        $ids = [];
        $children = SellerCategory::query()->where('parent_id', $row->id)->get();
        foreach ($children as $child) {
            $ids[] = (int) $child->id;
            $ids = array_merge($ids, $this->sellerCategoryDescendantIds($child));
        }

        return $ids;
    }

    public function deleteCategory(Seller $seller, SellerCategory $row): void
    {
        $this->assertOwned($seller, $row->seller_id);
        if ($row->children()->exists()) {
            throw ValidationException::withMessages([
                'category' => 'Move or delete child categories before deleting this one.',
            ]);
        }

        DB::transaction(function () use ($row) {
            if ($row->category_id) {
                $core = Category::query()->find($row->category_id);
                if ($core && (int) $core->seller_id === (int) $row->seller_id) {
                    $this->categories->delete($core);
                }
            }
            $row->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveFeature(Seller $seller, array $data, ?SellerFeature $row = null): SellerFeature
    {
        return DB::transaction(function () use ($seller, $data, $row) {
            $name = trim((string) $data['name']);
            $code = trim((string) ($data['code'] ?? ''));
            if ($code === '') {
                $code = Str::slug($name) ?: 'feature';
            }
            $code = $this->uniqueSellerCode($seller, $code, $row?->id);

            $payload = [
                'seller_id' => $seller->id,
                'name' => $name,
                'code' => $code,
                'type' => $row?->type ?: ($data['type'] ?? 'select'),
                'is_filterable' => array_key_exists('is_filterable', $data)
                    ? (bool) $data['is_filterable']
                    : ($row?->is_filterable ?? true),
                'is_enabled' => (bool) ($data['is_enabled'] ?? true),
                'position' => (int) ($data['position'] ?? ($row?->position ?? 0)),
            ];

            if ($row) {
                $this->assertOwned($seller, $row->seller_id);
                $row->fill($payload)->save();
            } else {
                $row = SellerFeature::query()->create($payload);
            }

            $core = $row->feature_id ? Feature::query()->find($row->feature_id) : null;
            $saved = $this->features->saveFeature(
                $this->coreFeaturePayload($seller, $row, $data),
                $core,
            );
            $row->update(['feature_id' => $saved->id]);

            return $row->fresh(['seller', 'feature.translations']);
        });
    }

    /** @return list<array<string, mixed>> */
    public function sellerFeatureRows(Seller $seller, ?string $locale = null): array
    {
        $locale ??= Language::defaultCode();
        $rows = SellerFeature::query()
            ->where('seller_id', $seller->id)
            ->with(['feature' => fn ($query) => $query->with(['translations'])->withCount('values')])
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return $rows->map(function (SellerFeature $row) use ($locale) {
            $core = $row->feature;
            if ($core) {
                $presented = $this->features->presentFeature($core);

                return [
                    'id' => $row->id,
                    'name' => $presented['name'],
                    'type' => $presented['type'],
                    'type_label' => $presented['type_label'],
                    'values_count' => $presented['values_count'],
                    'products_count' => $presented['products_count'],
                    'is_enabled' => (bool) $row->is_enabled,
                    'position' => (int) $row->position,
                ];
            }

            return [
                'id' => $row->id,
                'name' => $row->name,
                'type' => $row->type ?: 'select',
                'type_label' => Feature::TYPES[$row->type] ?? 'Select',
                'values_count' => 0,
                'products_count' => 0,
                'is_enabled' => (bool) $row->is_enabled,
                'position' => (int) $row->position,
            ];
        })->values()->all();
    }

    /** @return array{all:int,active:int,inactive:int} */
    public function sellerFeatureCounts(Seller $seller): array
    {
        $base = SellerFeature::query()->where('seller_id', $seller->id);

        return [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('is_enabled', true)->count(),
            'inactive' => (clone $base)->where('is_enabled', false)->count(),
        ];
    }

    /** @return array<string, mixed> */
    public function presentSellerFeatureForm(Seller $seller, SellerFeature $row, ?string $locale = null): array
    {
        $locale ??= Language::defaultCode();
        $row->loadMissing(['feature.translations']);
        $core = $row->feature;

        if ($core) {
            $presented = $this->features->presentFeature($core);

            return [
                'id' => $row->id,
                'name' => $presented['name'],
                'description' => $presented['description'],
                'type' => $presented['type'],
                'type_label' => $presented['type_label'],
                'is_enabled' => (bool) $row->is_enabled,
                'is_filterable' => (bool) $row->is_filterable,
                'position' => (int) $row->position,
                'values_count' => $presented['values_count'],
                'products_count' => $presented['products_count'],
                'translations' => $presented['translations'],
            ];
        }

        return [
            'id' => $row->id,
            'name' => $row->name,
            'description' => '',
            'type' => $row->type ?: 'select',
            'type_label' => Feature::TYPES[$row->type] ?? 'Select',
            'is_enabled' => (bool) $row->is_enabled,
            'is_filterable' => (bool) $row->is_filterable,
            'position' => (int) $row->position,
            'values_count' => 0,
            'products_count' => 0,
            'translations' => [],
        ];
    }

    public function toggleFeature(Seller $seller, SellerFeature $row): SellerFeature
    {
        $this->assertOwned($seller, $row->seller_id);
        $row->update(['is_enabled' => ! $row->is_enabled]);
        if ($row->feature_id) {
            Feature::query()->whereKey($row->feature_id)->update(['is_enabled' => $row->is_enabled]);
        }

        return $row->fresh(['feature']);
    }

    /** @param  list<int|string>  $orderedIds */
    public function reorderFeatures(Seller $seller, array $orderedIds): void
    {
        $mapped = SellerFeature::query()
            ->where('seller_id', $seller->id)
            ->whereIn('id', $orderedIds)
            ->pluck('feature_id', 'id');

        $coreIds = [];
        foreach ($orderedIds as $id) {
            $coreId = $mapped[(int) $id] ?? null;
            if ($coreId) {
                $coreIds[] = (int) $coreId;
            }
        }

        if ($coreIds !== []) {
            $this->features->reorderFeatures($coreIds);
        }

        foreach ($orderedIds as $position => $id) {
            SellerFeature::query()
                ->where('seller_id', $seller->id)
                ->whereKey((int) $id)
                ->update(['position' => $position]);
        }
    }

    /** @param  list<int|string>  $ids */
    public function bulkFeatureStatus(Seller $seller, array $ids, bool $enabled): int
    {
        $rows = SellerFeature::query()
            ->where('seller_id', $seller->id)
            ->whereIn('id', $ids)
            ->get();
        $updated = 0;
        foreach ($rows as $row) {
            $row->update(['is_enabled' => $enabled]);
            if ($row->feature_id) {
                Feature::query()->whereKey($row->feature_id)->update(['is_enabled' => $enabled]);
            }
            $updated++;
        }

        return $updated;
    }

    /** @param  list<int|string>  $ids */
    public function bulkDeleteFeatures(Seller $seller, array $ids): int
    {
        $deleted = 0;
        foreach ($ids as $id) {
            $row = SellerFeature::query()->where('seller_id', $seller->id)->whereKey((int) $id)->first();
            if (! $row) {
                continue;
            }
            try {
                $this->deleteFeature($seller, $row);
                $deleted++;
            } catch (ValidationException) {
                // Skip features assigned to products.
            }
        }

        return $deleted;
    }

    public function deleteFeature(Seller $seller, SellerFeature $row): void
    {
        $this->assertOwned($seller, $row->seller_id);
        DB::transaction(function () use ($row) {
            if ($row->feature_id) {
                $core = Feature::query()->find($row->feature_id);
                if ($core && (int) $core->seller_id === (int) $row->seller_id) {
                    $this->features->deleteFeature($core);
                }
            }
            $row->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function coreFeaturePayload(Seller $seller, SellerFeature $row, array $data): array
    {
        $payload = [
            'seller_id' => $seller->id,
            'code' => 's'.$seller->id.'-'.$row->code,
            'type' => $row->type,
            'is_filterable' => (bool) $row->is_filterable,
            'is_enabled' => (bool) ($data['is_enabled'] ?? $row->is_enabled),
            'position' => (int) ($data['position'] ?? $row->position),
            'name' => trim((string) ($data['name'] ?? $row->name)),
            'description' => $data['description'] ?? null,
        ];

        if (! empty($data['translations']) && is_array($data['translations'])) {
            $payload['translations'] = $data['translations'];
        }

        return $payload;
    }

    /** @return list<int> */
    protected function sellerCoreFeatureIds(Seller $seller): array
    {
        return SellerFeature::query()
            ->where('seller_id', $seller->id)
            ->whereNotNull('feature_id')
            ->pluck('feature_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();
    }

    public function ownedSellerFeature(Seller $seller, int $sellerFeatureId): SellerFeature
    {
        return SellerFeature::query()
            ->where('seller_id', $seller->id)
            ->whereKey($sellerFeatureId)
            ->firstOrFail();
    }

    public function ownedFeatureValue(Seller $seller, FeatureValue $value): void
    {
        $value->loadMissing('feature');
        $feature = $value->feature;
        if (! $feature || (int) $feature->seller_id !== (int) $seller->id) {
            abort(404);
        }
    }

    /** @return list<array<string, mixed>> */
    public function sellerFeatureOptions(Seller $seller, ?int $includeSellerFeatureId = null): array
    {
        return SellerFeature::query()
            ->where('seller_id', $seller->id)
            ->with(['feature.translations'])
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(function (SellerFeature $row) use ($includeSellerFeatureId) {
                $name = $row->feature?->translation()?->name ?: $row->name;
                if (! $row->is_enabled && (int) $row->id !== (int) $includeSellerFeatureId) {
                    $name .= ' (Disabled)';
                }

                return [
                    'id' => $row->id,
                    'name' => $name,
                    'is_enabled' => (bool) $row->is_enabled,
                    'core_feature_id' => $row->feature_id,
                ];
            })
            ->values()
            ->all();
    }

    /** @return list<int> */
    protected function resolveCoreFeatureIdsForValues(Seller $seller, ?int $sellerFeatureId = null): array
    {
        if ($sellerFeatureId) {
            $row = SellerFeature::query()
                ->where('seller_id', $seller->id)
                ->whereKey($sellerFeatureId)
                ->first();

            return ($row && $row->feature_id) ? [(int) $row->feature_id] : [];
        }

        return $this->sellerCoreFeatureIds($seller);
    }

    /** @return array{all:int,active:int,inactive:int} */
    public function sellerFeatureValueCounts(Seller $seller, ?int $sellerFeatureId = null): array
    {
        $coreIds = $this->resolveCoreFeatureIdsForValues($seller, $sellerFeatureId);
        if ($coreIds === []) {
            return ['all' => 0, 'active' => 0, 'inactive' => 0];
        }

        $base = FeatureValue::query()->whereIn('feature_id', $coreIds);

        return [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('is_enabled', true)->count(),
            'inactive' => (clone $base)->where('is_enabled', false)->count(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function sellerFeatureValueRows(Seller $seller, ?int $sellerFeatureId = null): array
    {
        $coreIds = $this->resolveCoreFeatureIdsForValues($seller, $sellerFeatureId);
        if ($coreIds === []) {
            return [];
        }

        $sellerFeatureByCore = SellerFeature::query()
            ->where('seller_id', $seller->id)
            ->whereIn('feature_id', $coreIds)
            ->get()
            ->keyBy('feature_id');

        return FeatureValue::query()
            ->whereIn('feature_id', $coreIds)
            ->with(['translations', 'feature.translations'])
            ->withCount('products')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(function (FeatureValue $value) use ($sellerFeatureByCore) {
                $presented = $this->features->presentValue($value);
                $sellerFeature = $sellerFeatureByCore->get($value->feature_id);

                return [
                    ...$presented,
                    'seller_feature_id' => $sellerFeature?->id,
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<string, mixed>|null */
    public function presentSellerFeatureForValues(Seller $seller, int $sellerFeatureId): ?array
    {
        $row = SellerFeature::query()
            ->where('seller_id', $seller->id)
            ->whereKey($sellerFeatureId)
            ->with('feature.translations')
            ->first();

        if (! $row) {
            return null;
        }

        return [
            'id' => $row->id,
            'name' => $row->feature?->translation()?->name ?: $row->name,
            'core_feature_id' => $row->feature_id,
        ];
    }

    /** @return array<string, mixed> */
    public function presentSellerFeatureValueForm(Seller $seller, FeatureValue $value): array
    {
        $this->ownedFeatureValue($seller, $value);
        $value->loadMissing(['translations', 'feature.translations']);
        $presented = $this->features->presentValue($value);
        $sellerFeature = SellerFeature::query()
            ->where('seller_id', $seller->id)
            ->where('feature_id', $value->feature_id)
            ->first();
        $presented['seller_feature_id'] = $sellerFeature?->id;

        return $presented;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveFeatureValue(Seller $seller, array $data, ?FeatureValue $value = null): FeatureValue
    {
        $sellerFeature = $this->ownedSellerFeature($seller, (int) $data['feature_id']);
        if (! $sellerFeature->feature_id) {
            throw ValidationException::withMessages([
                'feature_id' => 'Feature is not synced to the catalog yet.',
            ]);
        }

        if ($value) {
            $this->ownedFeatureValue($seller, $value);
        }

        return $this->features->saveValue([
            'feature_id' => (int) $sellerFeature->feature_id,
            'value' => $data['value'] ?? '',
            'translations' => $data['translations'] ?? [],
            'is_enabled' => (bool) ($data['is_enabled'] ?? true),
            'position' => $data['position'] ?? null,
        ], $value);
    }

    public function toggleFeatureValue(Seller $seller, FeatureValue $value): FeatureValue
    {
        $this->ownedFeatureValue($seller, $value);
        $value->update(['is_enabled' => ! $value->is_enabled]);

        return $value->fresh(['translations', 'feature.translations']);
    }

    /** @param  list<int|string>  $orderedIds */
    public function reorderFeatureValues(Seller $seller, array $orderedIds): void
    {
        $coreIds = $this->sellerCoreFeatureIds($seller);
        $values = FeatureValue::query()->whereIn('id', $orderedIds)->get();
        foreach ($values as $value) {
            if (! in_array((int) $value->feature_id, $coreIds, true)) {
                abort(404);
            }
        }

        $this->features->reorderValues($orderedIds);
    }

    public function deleteFeatureValue(Seller $seller, FeatureValue $value): void
    {
        $this->ownedFeatureValue($seller, $value);
        $this->features->deleteValue($value);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveAttribute(Seller $seller, array $data, ?SellerAttribute $row = null): SellerAttribute
    {
        return DB::transaction(function () use ($seller, $data, $row) {
            $name = trim((string) $data['name']);
            $code = trim((string) ($data['code'] ?? ''));
            if ($code === '') {
                $code = Str::slug($name) ?: 'attribute';
            }
            $code = $this->uniqueSellerAttributeCode($seller, $code, $row?->id);

            $payload = [
                'seller_id' => $seller->id,
                'name' => $name,
                'code' => $code,
                'type' => array_key_exists('type', $data)
                    ? (string) $data['type']
                    : ($row?->type ?: 'select'),
                'supports_swatches' => array_key_exists('supports_swatches', $data)
                    ? (bool) $data['supports_swatches']
                    : ($row?->supports_swatches ?? false),
                'is_enabled' => (bool) ($data['is_enabled'] ?? true),
                'position' => (int) ($data['position'] ?? ($row?->position ?? 0)),
            ];

            if ($row) {
                $this->assertOwned($seller, $row->seller_id);
                $row->fill($payload)->save();
            } else {
                $row = SellerAttribute::query()->create($payload);
            }

            $core = $row->attribute_id ? CatalogAttribute::query()->find($row->attribute_id) : null;
            $saved = $this->attributes->saveAttribute(
                $this->coreAttributePayload($seller, $row, $data),
                $core,
            );
            $row->update(['attribute_id' => $saved->id]);

            return $row->fresh(['seller', 'attribute.translations']);
        });
    }

    /** @return list<array<string, mixed>> */
    public function sellerAttributeRows(Seller $seller, ?string $locale = null): array
    {
        $locale ??= Language::defaultCode();
        $rows = SellerAttribute::query()
            ->where('seller_id', $seller->id)
            ->with(['attribute' => fn ($query) => $query->with(['translations'])->withCount('values')])
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return $rows->map(function (SellerAttribute $row) use ($locale) {
            $core = $row->attribute;
            if ($core) {
                $presented = $this->attributes->presentAttribute($core);

                return [
                    'id' => $row->id,
                    'name' => $presented['name'],
                    'type' => $presented['type'],
                    'type_label' => $presented['type_label'],
                    'supports_swatches' => $presented['supports_swatches'],
                    'values_count' => $presented['values_count'],
                    'products_count' => $presented['products_count'],
                    'is_enabled' => (bool) $row->is_enabled,
                    'position' => (int) $row->position,
                ];
            }

            return [
                'id' => $row->id,
                'name' => $row->name,
                'type' => $row->type ?: 'select',
                'type_label' => CatalogAttribute::TYPES[$row->type] ?? 'Select',
                'supports_swatches' => (bool) $row->supports_swatches,
                'values_count' => 0,
                'products_count' => 0,
                'is_enabled' => (bool) $row->is_enabled,
                'position' => (int) $row->position,
            ];
        })->values()->all();
    }

    /** @return array{all:int,active:int,inactive:int} */
    public function sellerAttributeCounts(Seller $seller): array
    {
        $base = SellerAttribute::query()->where('seller_id', $seller->id);

        return [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('is_enabled', true)->count(),
            'inactive' => (clone $base)->where('is_enabled', false)->count(),
        ];
    }

    /** @return array<string, mixed> */
    public function presentSellerAttributeForm(Seller $seller, SellerAttribute $row, ?string $locale = null): array
    {
        $locale ??= Language::defaultCode();
        $row->loadMissing(['attribute.translations']);
        $core = $row->attribute;

        if ($core) {
            $presented = $this->attributes->presentAttribute($core);

            return [
                'id' => $row->id,
                'name' => $presented['name'],
                'description' => $presented['description'],
                'type' => $presented['type'],
                'type_label' => $presented['type_label'],
                'supports_swatches' => $presented['supports_swatches'],
                'is_enabled' => (bool) $row->is_enabled,
                'position' => (int) $row->position,
                'values_count' => $presented['values_count'],
                'products_count' => $presented['products_count'],
                'translations' => $presented['translations'],
            ];
        }

        return [
            'id' => $row->id,
            'name' => $row->name,
            'description' => '',
            'type' => $row->type ?: 'select',
            'type_label' => CatalogAttribute::TYPES[$row->type] ?? 'Select',
            'supports_swatches' => (bool) $row->supports_swatches,
            'is_enabled' => (bool) $row->is_enabled,
            'position' => (int) $row->position,
            'values_count' => 0,
            'products_count' => 0,
            'translations' => [],
        ];
    }

    public function toggleAttribute(Seller $seller, SellerAttribute $row): SellerAttribute
    {
        $this->assertOwned($seller, $row->seller_id);
        $row->update(['is_enabled' => ! $row->is_enabled]);
        if ($row->attribute_id) {
            CatalogAttribute::query()->whereKey($row->attribute_id)->update(['is_enabled' => $row->is_enabled]);
        }

        return $row->fresh(['attribute']);
    }

    /** @param  list<int|string>  $orderedIds */
    public function reorderAttributes(Seller $seller, array $orderedIds): void
    {
        $mapped = SellerAttribute::query()
            ->where('seller_id', $seller->id)
            ->whereIn('id', $orderedIds)
            ->pluck('attribute_id', 'id');

        $coreIds = [];
        foreach ($orderedIds as $id) {
            $coreId = $mapped[(int) $id] ?? null;
            if ($coreId) {
                $coreIds[] = (int) $coreId;
            }
        }

        if ($coreIds !== []) {
            $this->attributes->reorderAttributes($coreIds);
        }

        foreach ($orderedIds as $position => $id) {
            SellerAttribute::query()
                ->where('seller_id', $seller->id)
                ->whereKey((int) $id)
                ->update(['position' => $position]);
        }
    }

    /** @param  list<int|string>  $ids */
    public function bulkAttributeStatus(Seller $seller, array $ids, bool $enabled): int
    {
        $rows = SellerAttribute::query()
            ->where('seller_id', $seller->id)
            ->whereIn('id', $ids)
            ->get();
        $updated = 0;
        foreach ($rows as $row) {
            $row->update(['is_enabled' => $enabled]);
            if ($row->attribute_id) {
                CatalogAttribute::query()->whereKey($row->attribute_id)->update(['is_enabled' => $enabled]);
            }
            $updated++;
        }

        return $updated;
    }

    /** @param  list<int|string>  $ids */
    public function bulkDeleteAttributes(Seller $seller, array $ids): int
    {
        $deleted = 0;
        foreach ($ids as $id) {
            $row = SellerAttribute::query()->where('seller_id', $seller->id)->whereKey((int) $id)->first();
            if (! $row) {
                continue;
            }
            try {
                $this->deleteAttribute($seller, $row);
                $deleted++;
            } catch (ValidationException) {
                // Skip attributes assigned to products.
            }
        }

        return $deleted;
    }

    public function deleteAttribute(Seller $seller, SellerAttribute $row): void
    {
        $this->assertOwned($seller, $row->seller_id);
        DB::transaction(function () use ($row) {
            if ($row->attribute_id) {
                $core = CatalogAttribute::query()->find($row->attribute_id);
                if ($core && (int) $core->seller_id === (int) $row->seller_id) {
                    $this->attributes->deleteAttribute($core);
                }
            }
            $row->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function coreAttributePayload(Seller $seller, SellerAttribute $row, array $data): array
    {
        $payload = [
            'seller_id' => $seller->id,
            'code' => 's'.$seller->id.'-'.$row->code,
            'type' => $row->type,
            'supports_swatches' => (bool) $row->supports_swatches,
            'is_enabled' => (bool) ($data['is_enabled'] ?? $row->is_enabled),
            'position' => (int) ($data['position'] ?? $row->position),
            'name' => trim((string) ($data['name'] ?? $row->name)),
            'description' => $data['description'] ?? null,
        ];

        if (! empty($data['translations']) && is_array($data['translations'])) {
            $payload['translations'] = $data['translations'];
        }

        return $payload;
    }

    /** @return list<int> */
    protected function sellerCoreAttributeIds(Seller $seller): array
    {
        return SellerAttribute::query()
            ->where('seller_id', $seller->id)
            ->whereNotNull('attribute_id')
            ->pluck('attribute_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();
    }

    public function ownedSellerAttribute(Seller $seller, int $sellerAttributeId): SellerAttribute
    {
        return SellerAttribute::query()
            ->where('seller_id', $seller->id)
            ->whereKey($sellerAttributeId)
            ->firstOrFail();
    }

    public function ownedAttributeValue(Seller $seller, AttributeValue $value): void
    {
        $value->loadMissing('attribute');
        $attribute = $value->attribute;
        if (! $attribute || (int) $attribute->seller_id !== (int) $seller->id) {
            abort(404);
        }
    }

    /** @return list<array<string, mixed>> */
    public function sellerAttributeOptions(Seller $seller, ?int $includeSellerAttributeId = null): array
    {
        return SellerAttribute::query()
            ->where('seller_id', $seller->id)
            ->with(['attribute.translations'])
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(function (SellerAttribute $row) use ($includeSellerAttributeId) {
                $name = $row->attribute?->translation()?->name ?: $row->name;
                if (! $row->is_enabled && (int) $row->id !== (int) $includeSellerAttributeId) {
                    $name .= ' (Disabled)';
                }

                return [
                    'id' => $row->id,
                    'name' => $name,
                    'is_enabled' => (bool) $row->is_enabled,
                    'supports_swatches' => (bool) ($row->attribute?->supports_swatches ?? $row->supports_swatches),
                    'core_attribute_id' => $row->attribute_id,
                ];
            })
            ->values()
            ->all();
    }

    /** @return list<int> */
    protected function resolveCoreAttributeIdsForValues(Seller $seller, ?int $sellerAttributeId = null): array
    {
        if ($sellerAttributeId) {
            $row = SellerAttribute::query()
                ->where('seller_id', $seller->id)
                ->whereKey($sellerAttributeId)
                ->first();

            return ($row && $row->attribute_id) ? [(int) $row->attribute_id] : [];
        }

        return $this->sellerCoreAttributeIds($seller);
    }

    /** @return array{all:int,active:int,inactive:int} */
    public function sellerAttributeValueCounts(Seller $seller, ?int $sellerAttributeId = null): array
    {
        $coreIds = $this->resolveCoreAttributeIdsForValues($seller, $sellerAttributeId);
        if ($coreIds === []) {
            return ['all' => 0, 'active' => 0, 'inactive' => 0];
        }

        $base = AttributeValue::query()->whereIn('attribute_id', $coreIds);

        return [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('is_enabled', true)->count(),
            'inactive' => (clone $base)->where('is_enabled', false)->count(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function sellerAttributeValueRows(Seller $seller, ?int $sellerAttributeId = null): array
    {
        $coreIds = $this->resolveCoreAttributeIdsForValues($seller, $sellerAttributeId);
        if ($coreIds === []) {
            return [];
        }

        $sellerAttributeByCore = SellerAttribute::query()
            ->where('seller_id', $seller->id)
            ->whereIn('attribute_id', $coreIds)
            ->get()
            ->keyBy('attribute_id');

        return AttributeValue::query()
            ->whereIn('attribute_id', $coreIds)
            ->with(['translations', 'attribute.translations'])
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(function (AttributeValue $value) use ($sellerAttributeByCore) {
                $presented = $this->attributes->presentValue($value);
                $sellerAttribute = $sellerAttributeByCore->get($value->attribute_id);

                return [
                    ...$presented,
                    'seller_attribute_id' => $sellerAttribute?->id,
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<string, mixed>|null */
    public function presentSellerAttributeForValues(Seller $seller, int $sellerAttributeId): ?array
    {
        $row = SellerAttribute::query()
            ->where('seller_id', $seller->id)
            ->whereKey($sellerAttributeId)
            ->with('attribute.translations')
            ->first();

        if (! $row) {
            return null;
        }

        return [
            'id' => $row->id,
            'name' => $row->attribute?->translation()?->name ?: $row->name,
            'supports_swatches' => (bool) ($row->attribute?->supports_swatches ?? $row->supports_swatches),
            'core_attribute_id' => $row->attribute_id,
        ];
    }

    /** @return array<string, mixed> */
    public function presentSellerAttributeValueForm(Seller $seller, AttributeValue $value): array
    {
        $this->ownedAttributeValue($seller, $value);
        $value->loadMissing(['translations', 'attribute.translations']);
        $presented = $this->attributes->presentValue($value);
        $sellerAttribute = SellerAttribute::query()
            ->where('seller_id', $seller->id)
            ->where('attribute_id', $value->attribute_id)
            ->first();
        $presented['seller_attribute_id'] = $sellerAttribute?->id;

        return $presented;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveAttributeValue(Seller $seller, array $data, ?AttributeValue $value = null): AttributeValue
    {
        $sellerAttribute = $this->ownedSellerAttribute($seller, (int) $data['attribute_id']);
        if (! $sellerAttribute->attribute_id) {
            throw ValidationException::withMessages([
                'attribute_id' => 'Attribute is not synced to the catalog yet.',
            ]);
        }

        if ($value) {
            $this->ownedAttributeValue($seller, $value);
        }

        return $this->attributes->saveValue([
            'attribute_id' => (int) $sellerAttribute->attribute_id,
            'value' => $data['value'] ?? '',
            'translations' => $data['translations'] ?? [],
            'swatch_type' => $data['swatch_type'] ?? 'none',
            'color_code' => $data['color_code'] ?? null,
            'swatch_image' => $data['swatch_image'] ?? null,
            'is_enabled' => (bool) ($data['is_enabled'] ?? true),
            'position' => $data['position'] ?? null,
        ], $value);
    }

    public function toggleAttributeValue(Seller $seller, AttributeValue $value): AttributeValue
    {
        $this->ownedAttributeValue($seller, $value);
        $value->update(['is_enabled' => ! $value->is_enabled]);

        return $value->fresh(['translations', 'attribute.translations']);
    }

    /** @param  list<int|string>  $orderedIds */
    public function reorderAttributeValues(Seller $seller, array $orderedIds): void
    {
        $coreIds = $this->sellerCoreAttributeIds($seller);
        $values = AttributeValue::query()->whereIn('id', $orderedIds)->get();
        foreach ($values as $value) {
            if (! in_array((int) $value->attribute_id, $coreIds, true)) {
                abort(404);
            }
        }

        $this->attributes->reorderValues($orderedIds);
    }

    public function deleteAttributeValue(Seller $seller, AttributeValue $value): void
    {
        $this->ownedAttributeValue($seller, $value);
        $this->attributes->deleteValue($value);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $files
     */
    public function saveBrand(Seller $seller, array $data, ?SellerBrand $row = null, array $files = []): SellerBrand
    {
        return DB::transaction(function () use ($seller, $data, $row, $files) {
            $name = trim((string) $data['name']);
            $slug = $this->uniqueSellerSlug($seller, 'brands', $data['slug'] ?? $name, $row?->id);
            $code = trim((string) ($data['code'] ?? ''));

            $payload = [
                'seller_id' => $seller->id,
                'name' => $name,
                'slug' => $slug,
                'code' => $code !== '' ? $code : null,
                'description' => $data['description'] ?? null,
                'is_enabled' => (bool) ($data['is_enabled'] ?? true),
                'position' => (int) ($data['position'] ?? 0),
            ];

            if ($row) {
                $this->assertOwned($seller, $row->seller_id);
                $row->fill($payload)->save();
            } else {
                $row = SellerBrand::query()->create($payload);
            }

            $core = $row->brand_id ? Brand::query()->find($row->brand_id) : null;
            $saved = $this->brands->save(
                $this->coreBrandPayload($seller, $row, $data, $slug),
                $core,
                $files,
            );
            $row->update([
                'brand_id' => $saved->id,
                'code' => $row->code ?: ('s'.$seller->id.'-'.$slug),
            ]);

            return $row->fresh(['seller', 'brand.translations', 'brand.images']);
        });
    }

    /** @return list<array<string, mixed>> */
    public function sellerBrandRows(Seller $seller, ?string $locale = null): array
    {
        $locale ??= Language::defaultCode();
        $rows = SellerBrand::query()
            ->where('seller_id', $seller->id)
            ->with(['brand' => fn ($query) => $query->with(['translations', 'images'])->withCount('products')])
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return $rows->map(function (SellerBrand $row) use ($seller, $locale) {
            $core = $row->brand;
            if ($core) {
                $node = $this->brands->present($core, $locale);
                if (! empty($node['slug'])) {
                    $node['slug'] = $this->stripSellerSlugPrefix($seller, (string) $node['slug']);
                }
            } else {
                $node = [
                    'name' => $row->name,
                    'slug' => $row->slug,
                    'description' => $row->description,
                    'is_enabled' => (bool) $row->is_enabled,
                    'position' => (int) $row->position,
                    'products_count' => 0,
                    'logo_url' => null,
                ];
            }
            $node['id'] = $row->id;
            $node['seller_slug'] = $row->slug;

            return $node;
        })->values()->all();
    }

    /** @return array{all:int,active:int,inactive:int} */
    public function sellerBrandCounts(Seller $seller): array
    {
        $base = SellerBrand::query()->where('seller_id', $seller->id);

        return [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('is_enabled', true)->count(),
            'inactive' => (clone $base)->where('is_enabled', false)->count(),
        ];
    }

    /** @return array<string, mixed> */
    public function presentSellerBrandForm(Seller $seller, SellerBrand $row, ?string $locale = null): array
    {
        $locale ??= Language::defaultCode();
        $row->loadMissing(['brand.translations', 'brand.images']);
        $core = $row->brand;
        $presented = $core
            ? $this->brands->present($core, $locale)
            : [
                'name' => $row->name,
                'slug' => $row->slug,
                'description' => $row->description,
                'is_enabled' => (bool) $row->is_enabled,
                'position' => (int) $row->position,
                'translations' => [],
                'gallery' => [],
            ];

        $presented['id'] = $row->id;
        $presented['seller_slug'] = $row->slug;
        if ($core && ! empty($presented['slug'])) {
            $presented['slug'] = $this->stripSellerSlugPrefix($seller, (string) $presented['slug']);
        }
        if (! empty($presented['translations']) && is_array($presented['translations'])) {
            $presented['translations'] = array_map(function (array $translation) use ($seller) {
                if (! empty($translation['slug'])) {
                    $translation['slug'] = $this->stripSellerSlugPrefix($seller, (string) $translation['slug']);
                }

                return $translation;
            }, $presented['translations']);
        }

        return $presented;
    }

    public function toggleBrand(Seller $seller, SellerBrand $row): SellerBrand
    {
        $this->assertOwned($seller, $row->seller_id);
        $row->update(['is_enabled' => ! $row->is_enabled]);
        if ($row->brand_id) {
            Brand::query()->whereKey($row->brand_id)->update(['is_enabled' => $row->is_enabled]);
        }

        return $row->fresh(['brand']);
    }

    /** @param  list<int|string>  $orderedIds */
    public function reorderBrands(Seller $seller, array $orderedIds): void
    {
        $mapped = SellerBrand::query()
            ->where('seller_id', $seller->id)
            ->whereIn('id', $orderedIds)
            ->pluck('brand_id', 'id');

        $coreIds = [];
        foreach ($orderedIds as $id) {
            $coreId = $mapped[(int) $id] ?? null;
            if ($coreId) {
                $coreIds[] = (int) $coreId;
            }
        }

        if ($coreIds !== []) {
            $this->brands->reorder($coreIds);
        }

        foreach ($orderedIds as $position => $id) {
            SellerBrand::query()
                ->where('seller_id', $seller->id)
                ->whereKey((int) $id)
                ->update(['position' => $position]);
        }
    }

    /** @param  list<int|string>  $ids */
    public function bulkBrandStatus(Seller $seller, array $ids, bool $enabled): int
    {
        $rows = SellerBrand::query()
            ->where('seller_id', $seller->id)
            ->whereIn('id', $ids)
            ->get();
        $updated = 0;
        foreach ($rows as $row) {
            $row->update(['is_enabled' => $enabled]);
            if ($row->brand_id) {
                Brand::query()->whereKey($row->brand_id)->update(['is_enabled' => $enabled]);
            }
            $updated++;
        }

        return $updated;
    }

    /** @param  list<int|string>  $ids */
    public function bulkDeleteBrands(Seller $seller, array $ids): int
    {
        $deleted = 0;
        foreach ($ids as $id) {
            $row = SellerBrand::query()->where('seller_id', $seller->id)->whereKey((int) $id)->first();
            if (! $row) {
                continue;
            }
            try {
                $this->deleteBrand($seller, $row);
                $deleted++;
            } catch (ValidationException) {
                // Skip brands assigned to products.
            }
        }

        return $deleted;
    }

    public function deleteBrand(Seller $seller, SellerBrand $row): void
    {
        $this->assertOwned($seller, $row->seller_id);
        DB::transaction(function () use ($row) {
            if ($row->brand_id) {
                $core = Brand::query()->find($row->brand_id);
                if ($core && (int) $core->seller_id === (int) $row->seller_id) {
                    $this->brands->delete($core);
                }
            }
            $row->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function coreBrandPayload(Seller $seller, SellerBrand $row, array $data, string $slug): array
    {
        if (isset($data['slug']) && trim((string) $data['slug']) !== '') {
            $slug = Str::slug((string) $data['slug']) ?: $slug;
        }

        $payload = [
            'seller_id' => $seller->id,
            'code' => $row->code ?: ('s'.$seller->id.'-'.$slug),
            'is_enabled' => (bool) ($data['is_enabled'] ?? $row->is_enabled),
            'position' => (int) ($data['position'] ?? $row->position),
            'name' => trim((string) ($data['name'] ?? $row->name)),
            'slug' => $seller->shop_slug.'-'.$slug,
            'short_description' => $data['short_description'] ?? null,
            'tagline' => $data['tagline'] ?? null,
            'description' => $data['description'] ?? null,
            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'meta_keywords' => $data['meta_keywords'] ?? null,
            'website' => $data['website'] ?? null,
            'country_code' => $data['country_code'] ?? null,
            'established_year' => $data['established_year'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'facebook_url' => $data['facebook_url'] ?? null,
            'instagram_url' => $data['instagram_url'] ?? null,
            'youtube_url' => $data['youtube_url'] ?? null,
            'twitter_url' => $data['twitter_url'] ?? null,
            'linkedin_url' => $data['linkedin_url'] ?? null,
            'tiktok_url' => $data['tiktok_url'] ?? null,
            'remove_logo' => (bool) ($data['remove_logo'] ?? false),
            'remove_banner' => (bool) ($data['remove_banner'] ?? false),
            'gallery_keep' => $data['gallery_keep'] ?? [],
        ];

        if (! empty($data['translations']) && is_array($data['translations'])) {
            $payload['translations'] = $this->prefixSellerBrandTranslations($seller, $data['translations'], $slug);
        }

        return $payload;
    }

    /**
     * @param  array<string|int, array<string, mixed>>  $translations
     * @return array<string, array<string, mixed>>
     */
    protected function prefixSellerBrandTranslations(Seller $seller, array $translations, string $fallbackSlug): array
    {
        $prefixed = [];
        foreach ($translations as $language => $row) {
            if (! is_array($row)) {
                continue;
            }
            $languageCode = (string) ($row['language_code'] ?? $language);
            $localSlug = trim((string) ($row['slug'] ?? ''));
            if ($localSlug === '') {
                $localSlug = Str::slug(trim((string) ($row['name'] ?? ''))) ?: $fallbackSlug;
            } else {
                $localSlug = Str::slug($localSlug) ?: $fallbackSlug;
            }
            $prefixed[$languageCode] = [
                ...$row,
                'language_code' => $languageCode,
                'slug' => $seller->shop_slug.'-'.$localSlug,
            ];
        }

        return $prefixed;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveSupplier(Seller $seller, array $data, ?SellerSupplier $row = null): SellerSupplier
    {
        return DB::transaction(function () use ($seller, $data, $row) {
            $name = trim((string) $data['name']);
            $slug = $this->uniqueSellerSlug($seller, 'suppliers', $data['slug'] ?? $name, $row?->id);

            $payload = [
                'seller_id' => $seller->id,
                'name' => $name,
                'slug' => $slug,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'city' => $data['city'] ?? null,
                'country' => $data['country'] ?? null,
                'description' => $data['description'] ?? null,
                'is_enabled' => (bool) ($data['is_enabled'] ?? true),
                'position' => (int) ($data['position'] ?? 0),
            ];

            if ($row) {
                $this->assertOwned($seller, $row->seller_id);
                $row->fill($payload)->save();
            } else {
                $row = SellerSupplier::query()->create($payload);
            }

            $core = $row->supplier_id ? Supplier::query()->find($row->supplier_id) : null;
            $saved = $this->suppliers->save(
                $this->coreSupplierPayload($seller, $row, $data, $slug),
                $core,
            );
            $row->update(['supplier_id' => $saved->id]);

            return $row->fresh(['seller', 'supplier.translations']);
        });
    }

    /** @return list<array<string, mixed>> */
    public function sellerSupplierRows(Seller $seller, ?string $locale = null): array
    {
        $locale ??= Language::defaultCode();
        $rows = SellerSupplier::query()
            ->where('seller_id', $seller->id)
            ->with(['supplier' => fn ($query) => $query->with(['translations'])->withCount('products')])
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return $rows->map(function (SellerSupplier $row) use ($locale) {
            $core = $row->supplier;
            if ($core) {
                $core->setRelation('translations', $core->translations);
            }

            return [
                'id' => $row->id,
                'name' => $core ? $core->displayName() : $row->name,
                'email' => $core?->email ?? $row->email,
                'phone' => $core?->phone ?? $row->phone,
                'contact' => $core
                    ? $core->contactSummary()
                    : trim(implode(' · ', array_filter([(string) ($row->email ?? ''), (string) ($row->phone ?? '')]))),
                'products_count' => $core?->products_count ?? 0,
                'is_enabled' => (bool) $row->is_enabled,
                'position' => (int) $row->position,
                'seller_slug' => $row->slug,
            ];
        })->values()->all();
    }

    /** @return array{all:int,active:int,inactive:int} */
    public function sellerSupplierCounts(Seller $seller): array
    {
        $base = SellerSupplier::query()->where('seller_id', $seller->id);

        return [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('is_enabled', true)->count(),
            'inactive' => (clone $base)->where('is_enabled', false)->count(),
        ];
    }

    /** @return array<string, mixed> */
    public function presentSellerSupplierForm(Seller $seller, SellerSupplier $row, ?string $locale = null): array
    {
        $locale ??= Language::defaultCode();
        $row->loadMissing(['supplier.translations']);
        $core = $row->supplier;
        $translation = $core?->translation($locale);

        $presented = [
            'id' => $row->id,
            'name' => $translation?->name ?: $row->name,
            'description' => $translation?->description ?: $row->description,
            'email' => $core?->email ?? $row->email ?? '',
            'phone' => $core?->phone ?? $row->phone ?? '',
            'website' => $core?->website ?? '',
            'address' => $core?->address ?? '',
            'city' => $core?->city ?? $row->city ?? '',
            'postcode' => $core?->postcode ?? '',
            'country' => $core?->country ?? $row->country ?? '',
            'is_enabled' => (bool) $row->is_enabled,
            'position' => (int) $row->position,
            'products_count' => $core ? $core->products()->count() : 0,
            'seller_slug' => $row->slug,
            'translations' => $core
                ? $core->translations->map(fn (SupplierTranslation $t) => [
                    'language_code' => $t->language_code,
                    'name' => $t->name,
                    'description' => $t->description,
                ])->values()->all()
                : [],
        ];

        return $presented;
    }

    public function toggleSupplier(Seller $seller, SellerSupplier $row): SellerSupplier
    {
        $this->assertOwned($seller, $row->seller_id);
        $row->update(['is_enabled' => ! $row->is_enabled]);
        if ($row->supplier_id) {
            Supplier::query()->whereKey($row->supplier_id)->update(['is_enabled' => $row->is_enabled]);
        }

        return $row->fresh(['supplier']);
    }

    /** @param  list<int|string>  $orderedIds */
    public function reorderSuppliers(Seller $seller, array $orderedIds): void
    {
        $mapped = SellerSupplier::query()
            ->where('seller_id', $seller->id)
            ->whereIn('id', $orderedIds)
            ->pluck('supplier_id', 'id');

        $coreIds = [];
        foreach ($orderedIds as $id) {
            $coreId = $mapped[(int) $id] ?? null;
            if ($coreId) {
                $coreIds[] = (int) $coreId;
            }
        }

        if ($coreIds !== []) {
            \App\Domain\Catalog\Support\PositionReorder::among(Supplier::class, $coreIds);
        }

        foreach ($orderedIds as $position => $id) {
            SellerSupplier::query()
                ->where('seller_id', $seller->id)
                ->whereKey((int) $id)
                ->update(['position' => $position]);
        }
    }

    /** @param  list<int|string>  $ids */
    public function bulkSupplierStatus(Seller $seller, array $ids, bool $enabled): int
    {
        $rows = SellerSupplier::query()
            ->where('seller_id', $seller->id)
            ->whereIn('id', $ids)
            ->get();
        $updated = 0;
        foreach ($rows as $row) {
            $row->update(['is_enabled' => $enabled]);
            if ($row->supplier_id) {
                Supplier::query()->whereKey($row->supplier_id)->update(['is_enabled' => $enabled]);
            }
            $updated++;
        }

        return $updated;
    }

    /** @param  list<int|string>  $ids */
    public function bulkDeleteSuppliers(Seller $seller, array $ids): int
    {
        $deleted = 0;
        foreach ($ids as $id) {
            $row = SellerSupplier::query()->where('seller_id', $seller->id)->whereKey((int) $id)->first();
            if (! $row) {
                continue;
            }
            try {
                $this->deleteSupplier($seller, $row);
                $deleted++;
            } catch (ValidationException) {
                // Skip if delete fails.
            }
        }

        return $deleted;
    }

    public function deleteSupplier(Seller $seller, SellerSupplier $row): void
    {
        $this->assertOwned($seller, $row->seller_id);
        DB::transaction(function () use ($row) {
            if ($row->supplier_id) {
                $core = Supplier::query()->find($row->supplier_id);
                if ($core && (int) $core->seller_id === (int) $row->seller_id) {
                    $core->delete();
                }
            }
            $row->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function coreSupplierPayload(Seller $seller, SellerSupplier $row, array $data, string $slug): array
    {
        $payload = [
            'seller_id' => $seller->id,
            'email' => $this->nullableString($data['email'] ?? null),
            'phone' => $this->nullableString($data['phone'] ?? null),
            'website' => $this->nullableString($data['website'] ?? null),
            'address' => $this->nullableString($data['address'] ?? null),
            'city' => $this->nullableString($data['city'] ?? null),
            'postcode' => $this->nullableString($data['postcode'] ?? null),
            'country' => $this->nullableString($data['country'] ?? null),
            'is_enabled' => (bool) ($data['is_enabled'] ?? $row->is_enabled),
            'position' => (int) ($data['position'] ?? $row->position),
            'name' => trim((string) ($data['name'] ?? $row->name)),
            'description' => $data['description'] ?? null,
        ];

        if (! empty($data['translations']) && is_array($data['translations'])) {
            $payload['translations'] = $this->prefixSellerSupplierTranslations($seller, $data['translations'], $slug);
        }

        return $payload;
    }

    /**
     * @param  array<string|int, array<string, mixed>>  $translations
     * @return array<string, array<string, mixed>>
     */
    protected function prefixSellerSupplierTranslations(Seller $seller, array $translations, string $fallbackSlug): array
    {
        $prefixed = [];
        foreach ($translations as $language => $row) {
            if (! is_array($row)) {
                continue;
            }
            $languageCode = (string) ($row['language_code'] ?? $language);
            $localSlug = trim((string) ($row['slug'] ?? ''));
            if ($localSlug === '') {
                $localSlug = Str::slug(trim((string) ($row['name'] ?? ''))) ?: $fallbackSlug;
            } else {
                $localSlug = Str::slug($localSlug) ?: $fallbackSlug;
            }
            $prefixed[$languageCode] = [
                ...$row,
                'language_code' => $languageCode,
                'slug' => $seller->shop_slug.'-'.$localSlug,
            ];
        }

        return $prefixed;
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === '' || $value === null) ? null : (string) $value;
    }

    public function availablePayoutAmount(Seller $seller): float
    {
        return app(SellerPayoutService::class)->availablePayoutAmount($seller);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function requestWithdrawal(Seller $seller, array $data): SellerWithdrawal
    {
        return app(SellerPayoutService::class)->request($seller, $data);
    }

    public function processWithdrawal(SellerWithdrawal $row, string $action, ?int $adminId = null, ?string $note = null): SellerWithdrawal
    {
        return app(SellerPayoutService::class)->process($row, $action, $adminId, $note);
    }

    protected function uniqueSellerSlug(Seller $seller, string $kind, string $value, ?int $ignoreId): string
    {
        $base = Str::slug($value) ?: $kind;
        $slug = $base;
        $i = 1;
        $query = match ($kind) {
            'categories' => SellerCategory::query(),
            'brands' => SellerBrand::query(),
            default => SellerSupplier::query(),
        };

        while ($query->clone()
            ->where('seller_id', $seller->id)
            ->where('slug', $slug)
            ->when($ignoreId, fn ($inner) => $inner->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }

    protected function uniqueSellerAttributeCode(Seller $seller, string $code, ?int $ignoreId): string
    {
        $base = Str::slug($code) ?: 'attribute';
        $value = $base;
        $i = 1;
        while (SellerAttribute::query()
            ->where('seller_id', $seller->id)
            ->where('code', $value)
            ->when($ignoreId, fn ($inner) => $inner->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $value = $base.'-'.$i;
            $i++;
        }

        return $value;
    }

    protected function uniqueSellerCode(Seller $seller, string $code, ?int $ignoreId): string
    {
        $base = Str::slug($code) ?: 'feature';
        $value = $base;
        $i = 1;
        while (SellerFeature::query()
            ->where('seller_id', $seller->id)
            ->where('code', $value)
            ->when($ignoreId, fn ($inner) => $inner->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $value = $base.'-'.$i;
            $i++;
        }

        return $value;
    }

    protected function uniqueSupplierSlug(string $slug, int $supplierId, string $locale): string
    {
        $base = Str::slug($slug) ?: 'supplier-'.$supplierId;
        $value = $base;
        $i = 1;
        while (SupplierTranslation::query()
            ->where('language_code', $locale)
            ->where('slug', $value)
            ->where('supplier_id', '!=', $supplierId)
            ->exists()
        ) {
            $value = $base.'-'.$i;
            $i++;
        }

        return $value;
    }

    protected function assertOwned(Seller $seller, int $sellerId): void
    {
        if ((int) $sellerId !== (int) $seller->id) {
            abort(403, 'This record does not belong to this seller.');
        }
    }
}
