<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Catalog\Models\UrlRedirect;
use App\Domain\Catalog\Models\UrlRewrite;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Settings\ShopTools;
use App\Domain\Store\StoreContext;
use Illuminate\Support\Str;

class UrlRewriteService
{
    /** @var array<string, string|null> */
    protected static array $pathMemo = [];

    /**
     * @param  array<string, mixed>  $tokens
     */
    public function sync(string $entityType, int $entityId, string $languageCode, string $slug, ?string $prefix = null, array $tokens = []): UrlRewrite
    {
        $storeId = $this->storeId();
        $path = $this->compile($entityType, $entityId, $slug, $prefix, $tokens);
        $path = $this->uniquePath($languageCode, $path, $entityType, $entityId, $storeId);

        $existing = UrlRewrite::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('language_code', $languageCode)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId), fn ($q) => $q->whereNull('store_id'))
            ->first();

        if ($existing && $existing->request_path !== $path) {
            $this->rememberRedirect($existing->request_path, $path, $languageCode, $storeId);
            $existing->update(['request_path' => $path]);
            $row = $existing->fresh();
        } elseif ($existing) {
            $row = $existing;
        } else {
            $row = UrlRewrite::query()->create([
                'store_id' => $storeId,
                'language_code' => $languageCode,
                'request_path' => $path,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
            ]);
        }

        $this->alignSiblingPaths($entityType, $entityId, $languageCode, $path, (int) $row->id);

        return $row;
    }

    public function find(string $path, ?string $language = null): ?UrlRewrite
    {
        return UrlRewrite::locate($path, $language);
    }

    public function pathFor(string $entityType, int $entityId, ?string $language = null): ?string
    {
        $language ??= app()->getLocale();
        $storeId = $this->storeId();
        $memoKey = $entityType.'|'.$entityId.'|'.$language.'|'.($storeId ?? '0');
        if (array_key_exists($memoKey, self::$pathMemo)) {
            return self::$pathMemo[$memoKey];
        }

        $query = UrlRewrite::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('language_code', $language);
        if ($storeId) {
            $query->where(function ($q) use ($storeId) {
                $q->where('store_id', $storeId)->orWhereNull('store_id');
            });
            $query->orderByRaw('case when store_id = ? then 0 else 1 end', [$storeId]);
        }

        $path = $query->value('request_path');
        $resolved = is_string($path) && $path !== '' ? $path : null;
        self::$pathMemo[$memoKey] = $resolved;

        return $resolved;
    }

    /**
     * Prefetch rewrite paths for a batch of entities (avoids N+1 on home/listings).
     * Pass a list of language codes (e.g. all enabled locales) to warm hreflang lookups in one query.
     *
     * @param  list<int|string>  $entityIds
     * @param  string|list<string>|null  $language
     */
    public function warmPaths(string $entityType, array $entityIds, string|array|null $language = null): void
    {
        $languages = is_array($language)
            ? array_values(array_unique(array_filter(array_map('strval', $language))))
            : [ (string) ($language ?? app()->getLocale()) ];
        if ($languages === []) {
            $languages = [ (string) app()->getLocale() ];
        }

        $storeId = $this->storeId();
        $ids = array_values(array_unique(array_filter(array_map('intval', $entityIds))));
        if ($ids === []) {
            return;
        }

        /** @var array<string, array{id: int, language: string}> $pending */
        $pending = [];
        foreach ($ids as $id) {
            foreach ($languages as $code) {
                $memoKey = $entityType.'|'.$id.'|'.$code.'|'.($storeId ?? '0');
                if (! array_key_exists($memoKey, self::$pathMemo)) {
                    $pending[$memoKey] = ['id' => $id, 'language' => $code];
                    self::$pathMemo[$memoKey] = null;
                }
            }
        }
        if ($pending === []) {
            return;
        }

        $query = UrlRewrite::query()
            ->where('entity_type', $entityType)
            ->whereIn('entity_id', $ids)
            ->whereIn('language_code', $languages)
            ->get(['entity_id', 'language_code', 'request_path', 'store_id']);

        /** @var array<string, string> $best */
        $best = [];
        foreach ($query as $row) {
            $id = (int) $row->entity_id;
            $code = (string) $row->language_code;
            $path = is_string($row->request_path) && $row->request_path !== '' ? $row->request_path : null;
            if ($path === null) {
                continue;
            }
            $memoKey = $entityType.'|'.$id.'|'.$code.'|'.($storeId ?? '0');
            $isStore = $storeId && (int) $row->store_id === (int) $storeId;
            if (! isset($best[$memoKey]) || $isStore) {
                $best[$memoKey] = $path;
            }
        }
        foreach ($pending as $memoKey => $_meta) {
            self::$pathMemo[$memoKey] = $best[$memoKey] ?? null;
        }
    }

    /**
     * Rebuild public paths for the current shop. Returns how many rows were written.
     */
    public function regenerate(?string $type = null): int
    {
        $types = $type ? [$type] : ShopTools::URL_TYPES;
        $count = 0;

        foreach ($types as $entityType) {
            $count += match ($entityType) {
                'product' => $this->regenerateProducts(),
                'category' => $this->regenerateCategories(),
                'cms' => $this->regenerateCms(),
                'brand' => $this->regenerateBrands(),
                'supplier' => $this->regenerateSuppliers(),
                default => 0,
            };
        }

        StorefrontCatalogCache::bump();

        return $count;
    }

    /**
     * @param  array<string, mixed>  $tokens
     */
    public function compile(string $entityType, int $entityId, string $slug, ?string $prefix = null, array $tokens = []): string
    {
        $rewrite = Str::slug($slug) ?: 'item';
        $schema = ShopTools::urlSchema($entityType);
        if (trim($schema) === '') {
            $schema = ShopTools::URL_SCHEMAS[$entityType] ?? '{rewrite}';
        }

        $map = [
            '{id}' => (string) ($tokens['id'] ?? $entityId),
            '{rewrite}' => $rewrite,
            '{id_category}' => (string) ($tokens['id_category'] ?? ''),
            '{category}' => Str::slug((string) ($tokens['category'] ?? '')) ?: '',
            '{id_parent}' => (string) ($tokens['id_parent'] ?? ''),
            '{parent}' => Str::slug((string) ($tokens['parent'] ?? '')) ?: '',
            '{brand}' => Str::slug((string) ($tokens['brand'] ?? '')) ?: '',
            '{supplier}' => Str::slug((string) ($tokens['supplier'] ?? '')) ?: '',
            '{ipa}' => (string) ($tokens['ipa'] ?? $tokens['combination'] ?? ''),
            '{combination}' => (string) ($tokens['ipa'] ?? $tokens['combination'] ?? ''),
        ];

        $path = strtr($schema, $map);
        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;
        $path = preg_replace('#-{2,}#', '-', $path) ?? $path;
        $path = trim($path, '/');
        $path = trim($path, '-');

        if ($path === '' && $prefix) {
            $path = trim($prefix.'/'.$rewrite, '/');
        }
        if ($path === '') {
            $path = $rewrite;
        }

        $suffix = ShopTools::urlSuffix();
        if ($suffix !== '' && ! str_ends_with($path, $suffix)) {
            $path .= $suffix;
        }

        return $path;
    }

    /**
     * Convert a schema into a regex that captures {id} when present.
     */
    public function schemaRegex(string $entityType): ?string
    {
        $schema = ShopTools::urlSchema($entityType);
        if (! str_contains($schema, '{id}')) {
            return null;
        }

        $quoted = preg_quote($schema, '#');
        $quoted = str_replace('\\{id\\}', '(?P<id>\\d+)', $quoted);
        $quoted = str_replace('\\{id_category\\}', '(?P<id_category>\\d+)', $quoted);
        $quoted = str_replace('\\{id_parent\\}', '(?P<id_parent>\\d+)', $quoted);
        $quoted = str_replace('\\{rewrite\\}', '(?P<rewrite>[^/]+)', $quoted);
        $quoted = str_replace('\\{category\\}', '(?P<category>[^/]+)', $quoted);
        $quoted = str_replace('\\{parent\\}', '(?P<parent>[^/]+)', $quoted);
        $quoted = str_replace('\\{brand\\}', '(?P<brand>[^/]+)', $quoted);
        $quoted = str_replace('\\{supplier\\}', '(?P<supplier>[^/]+)', $quoted);
        $quoted = str_replace('\\{ipa\\}', '(?P<ipa>\\d+)', $quoted);
        $quoted = str_replace('\\{combination\\}', '(?P<combination>\\d+)', $quoted);
        $suffix = preg_quote(ShopTools::urlSuffix(), '#');

        return '#^'.$quoted.$suffix.'$#';
    }

    /**
     * @return array<string, mixed>
     */
    public function productTokens(Product $product, string $locale, string $slug): array
    {
        $product->loadMissing(['categories.translations']);
        $default = $product->categories->first(fn (Category $category) => (bool) ($category->pivot->is_default ?? false))
            ?: $product->categories->first();
        $catSlug = $default?->translation($locale)?->slug;

        return [
            'id' => $product->id,
            'id_category' => $default?->id,
            'category' => $catSlug,
            'id_parent' => $default?->parent_id,
            'rewrite' => $slug,
            'brand' => $product->brand?->translation($locale)?->slug,
            'supplier' => $product->supplier?->translation($locale)?->slug
                ?? $product->suppliers?->first()?->translation($locale)?->slug,
        ];
    }

    protected function regenerateProducts(): int
    {
        $count = 0;
        Product::query()->with(['translations', 'categories.translations', 'brand.translations', 'supplier.translations', 'suppliers.translations'])->chunkById(100, function ($products) use (&$count) {
            foreach ($products as $product) {
                foreach ($product->translations as $translation) {
                    $this->sync('product', $product->id, $translation->language_code, (string) $translation->slug, null, $this->productTokens($product, $translation->language_code, (string) $translation->slug));
                    $count++;
                }
            }
        });

        return $count;
    }

    protected function regenerateCategories(): int
    {
        $count = 0;
        Category::query()->with(['translations', 'parent.translations'])->chunkById(100, function ($rows) use (&$count) {
            foreach ($rows as $category) {
                foreach ($category->translations as $translation) {
                    $locale = $translation->language_code;
                    $this->sync('category', $category->id, $locale, (string) $translation->slug, null, [
                        'id' => $category->id,
                        'id_category' => $category->id,
                        'category' => (string) $translation->slug,
                        'id_parent' => $category->parent_id,
                        'parent' => $category->parent?->translation($locale)?->slug,
                    ]);
                    $count++;
                }
            }
        });

        return $count;
    }

    protected function regenerateCms(): int
    {
        $count = 0;
        CmsPage::query()->with('translations')->chunkById(100, function ($rows) use (&$count) {
            foreach ($rows as $page) {
                foreach ($page->translations as $translation) {
                    $this->sync('cms', $page->id, $translation->language_code, (string) $translation->slug);
                    $count++;
                }
            }
        });

        return $count;
    }

    protected function regenerateBrands(): int
    {
        $count = 0;
        Brand::query()->with('translations')->chunkById(100, function ($rows) use (&$count) {
            foreach ($rows as $brand) {
                foreach ($brand->translations as $translation) {
                    $this->sync('brand', $brand->id, $translation->language_code, (string) $translation->slug, 'brand', [
                        'id' => $brand->id,
                        'brand' => (string) $translation->slug,
                        'rewrite' => (string) $translation->slug,
                    ]);
                    $count++;
                }
            }
        });

        return $count;
    }

    protected function regenerateSuppliers(): int
    {
        $count = 0;
        Supplier::query()->with('translations')->chunkById(100, function ($rows) use (&$count) {
            foreach ($rows as $supplier) {
                foreach ($supplier->translations as $translation) {
                    $this->sync('supplier', $supplier->id, $translation->language_code, (string) $translation->slug, 'supplier', [
                        'id' => $supplier->id,
                        'supplier' => (string) $translation->slug,
                        'rewrite' => (string) $translation->slug,
                    ]);
                    $count++;
                }
            }
        });

        return $count;
    }

    protected function alignSiblingPaths(string $entityType, int $entityId, string $languageCode, string $path, int $exceptId): void
    {
        $siblings = UrlRewrite::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('language_code', $languageCode)
            ->where('id', '!=', $exceptId)
            ->get();

        foreach ($siblings as $sibling) {
            $siblingStore = $sibling->store_id ? (int) $sibling->store_id : null;
            $candidate = $this->uniquePath($languageCode, $path, $entityType, $entityId, $siblingStore);
            if ($sibling->request_path === $candidate) {
                continue;
            }
            $this->rememberRedirect((string) $sibling->request_path, $candidate, $languageCode, $siblingStore);
            $sibling->update(['request_path' => $candidate]);
        }
    }

    protected function rememberRedirect(string $from, string $to, string $language, ?int $storeId): void
    {
        $from = trim($from, '/');
        $to = trim($to, '/');
        if ($from === '' || $from === $to) {
            return;
        }

        UrlRedirect::query()
            ->where('language_code', $language)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId), fn ($q) => $q->whereNull('store_id'))
            ->where('from_path', $to)
            ->delete();

        UrlRedirect::query()
            ->where('language_code', $language)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId), fn ($q) => $q->whereNull('store_id'))
            ->where('to_path', $from)
            ->update(['to_path' => $to]);

        $query = UrlRedirect::query()
            ->where('language_code', $language)
            ->where('from_path', $from)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId), fn ($q) => $q->whereNull('store_id'));
        $row = $query->first() ?? new UrlRedirect;
        $row->fill([
            'store_id' => $storeId,
            'language_code' => $language,
            'from_path' => $from,
            'to_path' => $to,
        ]);
        $row->save();
    }

    protected function uniquePath(string $language, string $path, string $type, int $id, ?int $storeId): string
    {
        $suffix = ShopTools::urlSuffix();
        $base = $suffix !== '' && str_ends_with($path, $suffix)
            ? substr($path, 0, -strlen($suffix))
            : $path;
        $candidate = $path;
        $i = 1;

        while (
            UrlRewrite::query()
                ->where('language_code', $language)
                ->where('request_path', $candidate)
                ->when($storeId, fn ($q) => $q->where('store_id', $storeId), fn ($q) => $q->whereNull('store_id'))
                ->where(function ($q) use ($type, $id) {
                    $q->where('entity_type', '!=', $type)->orWhere('entity_id', '!=', $id);
                })
                ->exists()
        ) {
            $candidate = $base.'-'.$i.$suffix;
            $i++;
        }

        return $candidate;
    }

    protected function storeId(): ?int
    {
        $id = app(StoreContext::class)->id();

        return $id > 0 ? $id : null;
    }
}
