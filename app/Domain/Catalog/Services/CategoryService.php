<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\CategoryTranslation;
use App\Domain\Catalog\Models\UrlRewrite;
use App\Domain\Catalog\Support\CatalogCsvImport;
use App\Domain\Catalog\Support\CatalogMediaUrl;
use App\Domain\Catalog\Support\CategoryCsvSchema;
use App\Domain\Catalog\Support\DefaultImage;
use App\Domain\Catalog\Support\PositionReorder;
use App\Domain\Localization\Models\Language;
use App\Domain\Security\CsvSafe;
use App\Domain\Security\RasterImageUpload;
use App\Domain\Security\SafeUrl;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CategoryService
{
    public function __construct(
        protected UrlRewriteService $urls,
    ) {}

    public function present(Category $category, ?string $locale = null): array
    {
        $translation = $category->translation($locale);
        $parent = $category->relationLoaded('parent') ? $category->parent : null;

        return [
            'id' => $category->id,
            'parent_id' => $category->parent_id,
            'parent_name' => $parent?->translation($locale)?->name,
            'name' => $translation?->name ?: 'Untitled category',
            'slug' => $translation?->slug,
            'description' => $translation?->description,
            'meta_title' => $translation?->meta_title,
            'meta_description' => $translation?->meta_description,
            'meta_keywords' => $translation?->meta_keywords,
            'canonical_url' => $translation?->canonical_url,
            'translations' => $category->relationLoaded('translations')
                ? $category->translations->map(fn (CategoryTranslation $row) => [
                    'language_code' => $row->language_code,
                    'name' => $row->name,
                    'slug' => $row->slug,
                    'description' => $row->description,
                    'meta_title' => $row->meta_title,
                    'meta_description' => $row->meta_description,
                    'meta_keywords' => $row->meta_keywords,
                    'canonical_url' => $row->canonical_url,
                ])->values()->all()
                : [],
            'image_path' => $category->image_path,
            'thumbnail_path' => $category->thumbnail_path,
            'banner_path' => $category->banner_path,
            'cover_path' => $category->cover_path ?: $category->banner_path ?: $category->image_path,
            'image_url' => CatalogMediaUrl::url($category->cover_path ?: $category->image_path, DefaultImage::MD),
            'thumbnail_url' => CatalogMediaUrl::url($category->thumbnail_path ?: $category->cover_path ?: $category->image_path, DefaultImage::SM),
            'customer_group_ids' => Category::normalizeCustomerGroupIds($category->customer_group_ids),
            'is_enabled' => (bool) $category->is_enabled,
            'position' => (int) $category->position,
            'products_count' => (int) ($category->products_count ?? $category->products()->count()),
            'children_count' => (int) ($category->children_count ?? $category->children()->count()),
        ];
    }

    /** @return list<array{id:int,name:string,depth:int,parent_id:?int,is_enabled:bool}> */
    public function parentOptions(?Category $exclude = null, ?string $locale = null): array
    {
        $locale ??= Language::defaultCode();
        $categories = Category::query()->with('translations')->orderBy('position')->orderBy('id')->get();
        $excludeIds = $exclude ? array_merge([(int) $exclude->id], $this->descendantIds((int) $exclude->id)) : [];

        return $this->buildFlatTree(null, $categories, 0, $excludeIds, $locale);
    }

    /**
     * Nested category tree for the admin list.
     *
     * @return list<array<string, mixed>>
     */
    public function adminTree(?string $locale = null): array
    {
        $locale ??= Language::defaultCode();
        $categories = Category::query()
            ->with(['translations', 'parent.translations'])
            ->withCount(['products', 'children'])
            ->orderByDesc('id')
            ->get();

        return $this->buildNestedTree(null, $categories, $locale);
    }

    public function save(array $data, ?Category $category = null, array $files = []): Category
    {
        return DB::transaction(function () use ($data, $category, $files) {
            $category ??= new Category;
            $default = Language::defaultCode();
            $parentId = ($data['parent_id'] ?? '') === '' || $data['parent_id'] === null
                ? null
                : (int) $data['parent_id'];

            $this->validateParent($category, $parentId);

            $category->fill([
                'seller_id' => ($data['seller_id'] ?? '') === '' || ($data['seller_id'] ?? null) === null
                    ? ($category->seller_id ?? null)
                    : (int) $data['seller_id'],
                'parent_id' => $parentId,
                'is_enabled' => (bool) ($data['is_enabled'] ?? true),
                'position' => PositionReorder::resolve(
                    $data['position'] ?? null,
                    $category,
                    'parent_id',
                    $parentId,
                ),
                'customer_group_ids' => Category::normalizeCustomerGroupIds($data['customer_group_ids'] ?? null),
            ]);
            $category->save();

            $this->syncTranslations($category, $data, $default);
            $this->syncMedia($category, $data, $files);

            return $category->fresh(['translations', 'parent.translations']);
        });
    }

    public function duplicate(Category $category): Category
    {
        $category->loadMissing('translations');

        return DB::transaction(function () use ($category) {
            $copy = $category->replicate(['image_path', 'thumbnail_path', 'banner_path', 'cover_path', 'icon_path']);
            $copy->is_enabled = false;
            $copy->image_path = null;
            $copy->thumbnail_path = null;
            $copy->banner_path = null;
            $copy->cover_path = null;
            $copy->icon_path = null;
            $copy->position = PositionReorder::next(
                Category::class,
                'parent_id',
                $category->parent_id,
            );
            $copy->save();

            foreach ($category->translations as $translation) {
                $name = $translation->name.' (copy)';
                $slug = $this->uniqueSlug(
                    ($translation->slug ?: Str::slug($translation->name)).'-copy',
                    $name,
                    $translation->language_code,
                    $copy->id,
                );
                $copy->translations()->create([
                    'language_code' => $translation->language_code,
                    'name' => $name,
                    'slug' => $slug,
                    'description' => $translation->description,
                    'meta_title' => $translation->meta_title,
                    'meta_description' => $translation->meta_description,
                    'meta_keywords' => $translation->meta_keywords,
                    'canonical_url' => $translation->canonical_url,
                ]);
                $this->urls->sync('category', $copy->id, $translation->language_code, $slug, 'category');
            }

            foreach (['image_path', 'thumbnail_path', 'banner_path', 'cover_path', 'icon_path'] as $field) {
                $copy->{$field} = $this->copyPublicFile($category->{$field}, $copy->id, str_replace('_path', '', $field));
            }
            $copy->save();

            return $copy->fresh(['translations', 'parent.translations']);
        });
    }

    public function delete(Category $category): void
    {
        $children = Category::query()->withoutGlobalScopes()->where('parent_id', $category->id)->count();
        if ($children > 0) {
            throw ValidationException::withMessages([
                'category' => 'This category contains child categories. Move or delete them before deleting this category.',
            ]);
        }

        $count = $category->products()->withoutGlobalScopes()->count();
        if ($count > 0) {
            throw ValidationException::withMessages([
                'category' => 'This category is assigned to '.$count.' product'.($count === 1 ? '' : 's').' and cannot be deleted.',
            ]);
        }

        foreach (['image_path', 'thumbnail_path', 'banner_path', 'cover_path', 'icon_path'] as $field) {
            $this->deletePublicFile($category->{$field});
        }

        UrlRewrite::query()
            ->where('entity_type', 'category')
            ->where('entity_id', $category->id)
            ->delete();

        $category->delete();
    }

    /** @param  list<int|string>  $orderedIds */
    public function reorder(array $orderedIds): void
    {
        PositionReorder::among(Category::class, $orderedIds, 'parent_id');
    }

    public function csvColumns(): array
    {
        return CategoryCsvSchema::columns();
    }

    public function sampleCsv(
        string $delimiter = CategoryCsvSchema::DEFAULT_DELIMITER,
        string $multiSeparator = CategoryCsvSchema::DEFAULT_MULTI_SEPARATOR,
    ): string {
        return CategoryCsvSchema::sampleCsv($delimiter, $multiSeparator);
    }

    public function importCsv(
        string $contents,
        string $locale = 'en',
        string $delimiter = CategoryCsvSchema::DEFAULT_DELIMITER,
        string $multiSeparator = CategoryCsvSchema::DEFAULT_MULTI_SEPARATOR,
    ): int {
        $delimiter = $this->normalizeCsvDelimiter($delimiter);
        $multiSeparator = $this->normalizeCsvMultiSeparator($multiSeparator);

        $parsed = CatalogCsvImport::parse($contents, $delimiter);
        CatalogCsvImport::assertHeaders($parsed['header'], CategoryCsvSchema::columns(), 'Category');
        CatalogCsvImport::assertHasDataRows($parsed['rows'], 'Category');

        $errors = [];
        $prepared = [];
        foreach ($parsed['rows'] as $index => $row) {
            $rowNumber = CatalogCsvImport::rowNumber($index);
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                $errors[] = "Row {$rowNumber}: Category name is required.";

                continue;
            }
            $mapped = CategoryCsvSchema::mapRow($row, $locale, $multiSeparator);
            $prepared[] = [
                'row' => $row,
                'mapped' => $mapped,
            ];
        }

        CatalogCsvImport::throwRowErrors($errors);
        if ($prepared === []) {
            throw ValidationException::withMessages([
                'file' => 'Invalid CSV file. No valid Category rows to import.',
            ]);
        }

        return DB::transaction(function () use ($prepared, $locale, $multiSeparator) {
            $count = 0;

            foreach ($prepared as $item) {
                $mapped = $item['mapped'];
                $existing = $this->findBySlug($mapped['slug'], $locale);
                $parentId = $this->resolveParentId($mapped['parent_slug'], $locale, (int) ($existing?->id ?? 0));
                $payload = $this->csvSavePayload($mapped, $parentId);
                $category = $this->save($payload, $existing);
                $this->importRemoteMedia($category, $mapped);
                $count++;
            }

            foreach ($prepared as $item) {
                $mapped = CategoryCsvSchema::mapRow($item['row'], $locale, $multiSeparator);
                $category = $this->findBySlug($mapped['slug'], $locale);
                if (! $category) {
                    continue;
                }

                $parentId = $this->resolveParentId($mapped['parent_slug'], $locale, (int) $category->id);
                if ((int) ($category->parent_id ?? 0) === (int) ($parentId ?? 0)) {
                    continue;
                }

                $this->save([
                    ...$this->csvSavePayload($mapped, $parentId),
                ], $category);
            }

            return $count;
        });
    }

    public function exportCsv(
        string $locale = 'en',
        ?Request $request = null,
        string $delimiter = CategoryCsvSchema::DEFAULT_DELIMITER,
        string $multiSeparator = CategoryCsvSchema::DEFAULT_MULTI_SEPARATOR,
    ): string {
        $delimiter = $this->normalizeCsvDelimiter($delimiter);
        $multiSeparator = $this->normalizeCsvMultiSeparator($multiSeparator);

        $out = fopen('php://temp', 'r+');
        CsvSafe::put($out, $this->csvColumns(), $delimiter);

        $status = $request?->string('status')->toString() ?: 'all';
        $name = trim((string) ($request?->string('name')->toString() ?: $request?->string('q')->toString() ?: ''));
        $slug = trim((string) ($request?->string('slug')->toString() ?: ''));
        $idMin = $request?->input('id_min');
        $idMax = $request?->input('id_max');
        $positionMin = $request?->input('position_min');
        $positionMax = $request?->input('position_max');

        $query = Category::query()
            ->with(['translations', 'parent.translations'])
            ->when($name !== '', function ($query) use ($name) {
                $query->whereHas('translations', fn ($t) => $t->where('name', 'like', "%{$name}%"));
            })
            ->when($slug !== '', function ($query) use ($slug) {
                $query->whereHas('translations', fn ($t) => $t->where('slug', 'like', "%{$slug}%"));
            })
            ->when($idMin !== null && $idMin !== '', fn ($query) => $query->where('categories.id', '>=', (int) $idMin))
            ->when($idMax !== null && $idMax !== '', fn ($query) => $query->where('categories.id', '<=', (int) $idMax))
            ->when($positionMin !== null && $positionMin !== '', fn ($query) => $query->where('categories.position', '>=', (int) $positionMin))
            ->when($positionMax !== null && $positionMax !== '', fn ($query) => $query->where('categories.position', '<=', (int) $positionMax))
            ->when($status === 'active', fn ($q) => $q->where('categories.is_enabled', true))
            ->when($status === 'inactive', fn ($q) => $q->where('categories.is_enabled', false))
            ->orderBy('categories.position')
            ->orderBy('categories.id');

        $query->chunk(100, function ($categories) use ($out, $locale, $delimiter, $multiSeparator) {
            foreach ($categories as $category) {
                CsvSafe::put($out, CategoryCsvSchema::exportValues($category, $locale, $multiSeparator), $delimiter);
            }
        });

        rewind($out);

        return stream_get_contents($out) ?: '';
    }

    /**
     * @param  array<string, mixed>  $mapped
     * @return array<string, mixed>
     */
    protected function csvSavePayload(array $mapped, ?int $parentId): array
    {
        return [
            'name' => $mapped['name'],
            'slug' => $mapped['slug'],
            'description' => $mapped['description'],
            'meta_title' => $mapped['meta_title'],
            'meta_description' => $mapped['meta_description'],
            'meta_keywords' => $mapped['meta_keywords'],
            'canonical_url' => $mapped['canonical_url'],
            'is_enabled' => $mapped['is_enabled'],
            'position' => $mapped['position'],
            'customer_group_ids' => $mapped['customer_group_ids'],
            'parent_id' => $parentId,
            'language_code' => $mapped['language_code'],
        ];
    }

    protected function findBySlug(string $slug, string $locale): ?Category
    {
        if ($slug === '') {
            return null;
        }

        return Category::query()
            ->whereHas('translations', fn ($q) => $q->where('language_code', $locale)->where('slug', $slug))
            ->first();
    }

    protected function resolveParentId(?string $parentSlug, string $locale, int $excludeId): ?int
    {
        if ($parentSlug === null || $parentSlug === '') {
            return null;
        }

        $parent = $this->findBySlug($parentSlug, $locale);
        if (! $parent || (int) $parent->id === $excludeId) {
            return null;
        }

        return (int) $parent->id;
    }

    /**
     * @param  array<string, mixed>  $mapped
     */
    protected function importRemoteMedia(Category $category, array $mapped): void
    {
        foreach (['image_url' => 'image', 'thumbnail_url' => 'thumbnail'] as $mediaKey => $fileKey) {
            $url = $mapped[$mediaKey] ?? null;
            if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }

            try {
                $response = SafeUrl::get($url, 8, false);
                if (! $response->successful() || $response->body() === '' || strlen($response->body()) > 5_000_000) {
                    continue;
                }
                $tmp = tempnam(sys_get_temp_dir(), 'wento-cat-img-');
                if ($tmp === false) {
                    continue;
                }
                file_put_contents($tmp, $response->body());
                $name = basename(parse_url($url, PHP_URL_PATH) ?: ($fileKey.'.jpg')) ?: ($fileKey.'.jpg');
                $file = new UploadedFile($tmp, $name, $response->header('Content-Type') ?: null, null, true);
                $pathKey = $fileKey === 'image' ? 'image_path' : 'thumbnail_path';
                $this->deletePublicFile($category->{$pathKey});
                $category->{$pathKey} = $this->storePublicFile($file, (int) $category->id, $fileKey);
                $category->save();
                @unlink($tmp);
            } catch (\Throwable) {
                // Skip unreachable image URLs.
            }
        }
    }

    protected function normalizeCsvDelimiter(string $delimiter): string
    {
        $delimiter = $delimiter === '' ? CategoryCsvSchema::DEFAULT_DELIMITER : $delimiter;

        return strlen($delimiter) === 1 ? $delimiter : CategoryCsvSchema::DEFAULT_DELIMITER;
    }

    protected function normalizeCsvMultiSeparator(string $separator): string
    {
        return $separator === '' ? CategoryCsvSchema::DEFAULT_MULTI_SEPARATOR : $separator;
    }

    /** @return list<int> */
    public function descendantIds(int $categoryId): array
    {
        $ids = [];
        $queue = Category::query()->where('parent_id', $categoryId)->pluck('id')->map(fn ($id) => (int) $id)->all();

        while ($queue !== []) {
            $id = array_shift($queue);
            $ids[] = $id;
            $queue = array_merge(
                $queue,
                Category::query()->where('parent_id', $id)->pluck('id')->map(fn ($childId) => (int) $childId)->all()
            );
        }

        return $ids;
    }

    protected function validateParent(Category $category, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        if ($category->exists && $parentId === (int) $category->id) {
            throw ValidationException::withMessages(['parent_id' => 'A category cannot be its own parent.']);
        }

        if (! Category::query()->whereKey($parentId)->exists()) {
            throw ValidationException::withMessages(['parent_id' => 'Selected parent category does not exist.']);
        }

        if ($category->exists && in_array($parentId, $this->descendantIds((int) $category->id), true)) {
            throw ValidationException::withMessages(['parent_id' => 'A category cannot be moved under one of its descendants.']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function syncTranslations(Category $category, array $data, string $default): void
    {
        $rows = $data['translations'] ?? [];
        if ($rows === []) {
            $language = (string) ($data['language_code'] ?? $default);
            $rows = [$language => [
                'language_code' => $language,
                'name' => $data['name'] ?? '',
                'slug' => $data['slug'] ?? '',
                'description' => $data['description'] ?? null,
                'meta_title' => $data['meta_title'] ?? null,
                'meta_description' => $data['meta_description'] ?? null,
                'meta_keywords' => $data['meta_keywords'] ?? null,
                'canonical_url' => $data['canonical_url'] ?? null,
            ]];
        }

        foreach ($rows as $key => $row) {
            $row = (array) $row;
            $language = (string) ($row['language_code'] ?? $key);
            if ($language === '') {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            if ($language !== $default && $this->translationRowBlank($row)) {
                UrlRewrite::query()
                    ->where('entity_type', 'category')
                    ->where('entity_id', $category->id)
                    ->where('language_code', $language)
                    ->delete();
                CategoryTranslation::query()
                    ->where('category_id', $category->id)
                    ->where('language_code', $language)
                    ->delete();

                continue;
            }

            $slug = $this->uniqueSlug(
                (string) ($row['slug'] ?? ''),
                $name,
                $language,
                $category->id,
            );

            $category->translations()->updateOrCreate(
                ['language_code' => $language],
                [
                    'name' => $name,
                    'slug' => $slug,
                    'description' => $row['description'] ?? null,
                    'meta_title' => $this->nullableString($row['meta_title'] ?? null),
                    'meta_description' => $this->nullableString($row['meta_description'] ?? null),
                    'meta_keywords' => $this->nullableString($row['meta_keywords'] ?? null),
                    'canonical_url' => $this->nullableString($row['canonical_url'] ?? null),
                ]
            );

            $category->loadMissing('parent.translations');
            $this->urls->sync('category', $category->id, $language, $slug, null, [
                'id' => $category->id,
                'id_category' => $category->id,
                'category' => $slug,
                'id_parent' => $category->parent_id,
                'parent' => $category->parent?->translation($language)?->slug,
            ]);
        }
    }

    protected function translationRowBlank(array $row): bool
    {
        foreach ([
            'name', 'slug', 'description', 'meta_title', 'meta_description',
            'meta_keywords', 'canonical_url',
        ] as $field) {
            $value = $row[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return false;
            }
            if (! is_string($value) && $value !== null) {
                return false;
            }
        }

        return true;
    }

    protected function uniqueSlug(string $slug, string $name, string $locale, ?int $ignoreCategoryId): string
    {
        $base = Str::slug($slug !== '' ? $slug : $name) ?: 'category';
        $candidate = $base;
        $i = 2;

        while (CategoryTranslation::query()
            ->where('language_code', $locale)
            ->where('slug', $candidate)
            ->when($ignoreCategoryId, fn ($query) => $query->where('category_id', '!=', $ignoreCategoryId))
            ->exists()) {
            $candidate = "{$base}-{$i}";
            $i++;
        }

        return $candidate;
    }

    protected function syncMedia(Category $category, array $data, array $files): void
    {
        $map = [
            'image' => 'image_path',
            'thumbnail' => 'thumbnail_path',
        ];

        foreach ($map as $fileKey => $pathKey) {
            $removeKey = 'remove_'.$fileKey;
            if (! empty($data[$removeKey])) {
                $this->deletePublicFile($category->{$pathKey});
                $category->{$pathKey} = null;
            }
            if (($files[$fileKey] ?? null) instanceof UploadedFile) {
                $this->deletePublicFile($category->{$pathKey});
                $category->{$pathKey} = $this->storePublicFile($files[$fileKey], $category->id, $fileKey);
            }
        }

        $category->save();
    }

    protected function storePublicFile(UploadedFile $file, int $categoryId, string $kind): string
    {
        // Raster-only, finfo-verified, server-named: never trust the client
        // extension for files landing on the public disk.
        return RasterImageUpload::storePublic(
            $file,
            "categories/{$categoryId}",
            (int) config('wento.upload.max_image_kb', 5120)
        );
    }

    protected function copyPublicFile(?string $path, int $categoryId, string $kind): ?string
    {
        $disk = \App\Domain\Settings\ObjectStorageSettings::disk();
        if (! $path || ! $disk->exists($path)) {
            return null;
        }

        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $dest = "categories/{$categoryId}/{$kind}-".Str::lower(Str::random(8)).($ext ? '.'.$ext : '');
        $disk->copy($path, $dest);

        return $dest;
    }

    protected function deletePublicFile(?string $path): void
    {
        $disk = \App\Domain\Settings\ObjectStorageSettings::disk();
        if ($path && $disk->exists($path)) {
            $disk->delete($path);
        }
    }

    protected function publicUrl(?string $path): ?string
    {
        return $path ? \App\Domain\Settings\ObjectStorageSettings::disk()->url($path) : null;
    }

    protected function nullableString(?string $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @param  list<int>  $excludeIds
     * @return list<array{id:int,name:string,depth:int,parent_id:?int,is_enabled:bool}>
     */
    protected function buildFlatTree(
        ?int $parentId,
        Collection $categories,
        int $depth,
        array $excludeIds,
        string $locale,
        string $path = '',
    ): array {
        $rows = [];
        foreach ($categories->where('parent_id', $parentId)->sortBy([['position', 'asc'], ['id', 'asc']]) as $category) {
            if (in_array((int) $category->id, $excludeIds, true)) {
                continue;
            }
            $name = $category->translation($locale)?->name ?: 'Untitled category';
            $label = $path === '' ? $name : $path.' › '.$name;
            $rows[] = [
                'id' => (int) $category->id,
                'name' => $label,
                'short_name' => $name,
                'depth' => $depth,
                'parent_id' => $category->parent_id ? (int) $category->parent_id : null,
                'is_enabled' => (bool) $category->is_enabled,
            ];
            $rows = array_merge(
                $rows,
                $this->buildFlatTree((int) $category->id, $categories, $depth + 1, $excludeIds, $locale, $label)
            );
        }

        return $rows;
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @return list<array<string, mixed>>
     */
    protected function buildNestedTree(?int $parentId, Collection $categories, string $locale): array
    {
        $rows = [];
        foreach ($categories->where('parent_id', $parentId)->sortBy([['position', 'asc'], ['id', 'asc']]) as $category) {
            $row = $this->present($category, $locale);
            $row['children'] = $this->buildNestedTree((int) $category->id, $categories, $locale);
            $rows[] = $row;
        }

        return $rows;
    }
}
