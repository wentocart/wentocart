<?php

namespace App\Domain\Seo;

use App\Domain\Localization\Models\Language;
use App\Domain\Search\PrefixQuery;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SeoPages
{
    /**
     * @return array{type: string, filter: string, q: string, rows: Paginator}
     */
    public function list(string $type, string $filter, string $q): array
    {
        $type = in_array($type, ['product', 'category', 'cms'], true) ? $type : 'product';
        $filter = in_array($filter, ['all', 'missing_title', 'missing_description', 'missing_keywords', 'missing_focus', 'duplicate'], true)
            ? $filter
            : 'all';
        if ($type !== 'product' && $filter === 'missing_focus') {
            $filter = 'all';
        }

        $rows = match ($type) {
            'category' => $this->categories($filter, $q),
            'cms' => $this->cms($filter, $q),
            default => $this->products($filter, $q),
        };

        return compact('type', 'filter', 'q', 'rows');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(string $type, int $id, array $data): void
    {
        $locale = Language::defaultCode();
        $payload = [
            'meta_title' => trim((string) ($data['meta_title'] ?? '')),
            'meta_description' => trim((string) ($data['meta_description'] ?? '')),
            'meta_keywords' => trim((string) ($data['meta_keywords'] ?? '')),
        ];
        if (array_key_exists('focus_keyword', $data)) {
            $payload['focus_keyword'] = trim((string) $data['focus_keyword']);
        }
        if (array_key_exists('robots', $data)) {
            $payload['robots'] = (string) $data['robots'];
        }

        match ($type) {
            'product' => $this->saveProduct($id, $locale, $payload),
            'category' => $this->saveCategory($id, $locale, $payload),
            'cms' => $this->saveCms($id, $locale, $payload, $data),
            default => throw ValidationException::withMessages(['type' => 'Unknown page type.']),
        };

        ShopAudit::flush();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function saveProduct(int $id, string $locale, array $payload): void
    {
        $query = DB::table('product_translations')->where('product_id', $id)->where('language_code', $locale);
        if (! $query->exists()) {
            throw ValidationException::withMessages(['id' => 'Product translation not found.']);
        }
        $update = array_intersect_key($payload, array_flip($this->existingColumns('product_translations', array_keys($payload))));
        if ($update !== []) {
            $query->update($update);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function saveCategory(int $id, string $locale, array $payload): void
    {
        unset($payload['focus_keyword'], $payload['robots']);
        $query = DB::table('category_translations')->where('category_id', $id)->where('language_code', $locale);
        if (! $query->exists()) {
            throw ValidationException::withMessages(['id' => 'Category translation not found.']);
        }
        $update = array_intersect_key($payload, array_flip($this->existingColumns('category_translations', array_keys($payload))));
        if ($update !== []) {
            $query->update($update);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $data
     */
    protected function saveCms(int $id, string $locale, array $payload, array $data): void
    {
        unset($payload['focus_keyword']);
        $robots = $payload['robots'] ?? null;
        unset($payload['robots']);
        $query = DB::table('cms_page_translations')->where('cms_page_id', $id)->where('language_code', $locale);
        if (! Schema::hasTable('cms_page_translations') || ! $query->exists()) {
            throw ValidationException::withMessages(['id' => 'CMS translation not found.']);
        }
        $update = array_intersect_key($payload, array_flip($this->existingColumns('cms_page_translations', array_keys($payload))));
        if ($update !== []) {
            $query->update($update);
        }
        if (is_string($robots) && $robots !== '' && Schema::hasColumn('cms_pages', 'robots')) {
            DB::table('cms_pages')->where('id', $id)->update(['robots' => $robots]);
        }
        if (array_key_exists('canonical_url', $data) && Schema::hasColumn('cms_pages', 'canonical_url')) {
            DB::table('cms_pages')->where('id', $id)->update(['canonical_url' => trim((string) $data['canonical_url'])]);
        }
    }

    protected function products(string $filter, string $q): Paginator
    {
        $locale = Language::defaultCode();
        $query = DB::table('products')
            ->join('product_translations', 'product_translations.product_id', '=', 'products.id')
            ->where('product_translations.language_code', $locale)
            ->select([
                'products.id',
                'products.sku',
                'products.is_enabled',
                'product_translations.name',
                'product_translations.meta_title',
                'product_translations.meta_description',
                'product_translations.meta_keywords',
                'product_translations.focus_keyword',
                'product_translations.robots',
            ])
            ->orderBy('products.id');
        $this->applyBlank($query, 'product_translations', $filter);
        if ($filter === 'duplicate') {
            $this->applyDuplicate($query, 'product_translations', $locale);
        }
        if ($filter === 'missing_focus' && Schema::hasColumn('product_translations', 'focus_keyword')) {
            $query->where(function ($inner) {
                $inner->whereNull('product_translations.focus_keyword')->orWhere('product_translations.focus_keyword', '');
            });
        }
        if ($q !== '') {
            $query->where(function ($inner) use ($q) {
                $inner->where('product_translations.name', 'like', PrefixQuery::like($q))
                    ->orWhere('products.sku', $q)
                    ->orWhere('products.sku', 'like', PrefixQuery::like($q));
            });
        }

        return $query->paginate(\App\Support\AdminPagination::perPage(request(), 10))->withQueryString();
    }

    protected function categories(string $filter, string $q): Paginator
    {
        $locale = Language::defaultCode();
        $query = DB::table('categories')
            ->join('category_translations', 'category_translations.category_id', '=', 'categories.id')
            ->where('category_translations.language_code', $locale)
            ->select([
                'categories.id',
                'categories.is_enabled',
                'category_translations.name',
                'category_translations.meta_title',
                'category_translations.meta_description',
                'category_translations.meta_keywords',
            ])
            ->orderBy('categories.id');
        $this->applyBlank($query, 'category_translations', $filter);
        if ($filter === 'duplicate') {
            $this->applyDuplicate($query, 'category_translations', $locale);
        }
        if ($q !== '') {
            $query->where('category_translations.name', 'like', PrefixQuery::like($q));
        }

        return $query->paginate(\App\Support\AdminPagination::perPage(request(), 10))->withQueryString();
    }

    protected function cms(string $filter, string $q): Paginator
    {
        if (! Schema::hasTable('cms_pages') || ! Schema::hasTable('cms_page_translations')) {
            return DB::table('cms_pages')->whereRaw('0 = 1')->paginate(\App\Support\AdminPagination::perPage(request(), 10));
        }
        $locale = Language::defaultCode();
        $query = DB::table('cms_pages')
            ->join('cms_page_translations', 'cms_page_translations.cms_page_id', '=', 'cms_pages.id')
            ->where('cms_page_translations.language_code', $locale)
            ->select([
                'cms_pages.id',
                'cms_pages.is_enabled',
                'cms_pages.robots',
                'cms_page_translations.title as name',
                'cms_page_translations.meta_title',
                'cms_page_translations.meta_description',
                'cms_page_translations.meta_keywords',
            ])
            ->orderBy('cms_pages.id');
        $this->applyBlank($query, 'cms_page_translations', $filter);
        if ($filter === 'duplicate') {
            $this->applyDuplicate($query, 'cms_page_translations', $locale);
        }
        if ($q !== '') {
            $query->where('cms_page_translations.title', 'like', PrefixQuery::like($q));
        }

        return $query->paginate(\App\Support\AdminPagination::perPage(request(), 10))->withQueryString();
    }

    protected function applyBlank($query, string $table, string $filter): void
    {
        $column = match ($filter) {
            'missing_title' => 'meta_title',
            'missing_description' => 'meta_description',
            'missing_keywords' => 'meta_keywords',
            default => null,
        };
        if ($column === null || ! Schema::hasColumn($table, $column)) {
            return;
        }
        $query->where(function ($inner) use ($table, $column) {
            $inner->whereNull($table.'.'.$column)->orWhere($table.'.'.$column, '');
        });
    }

    protected function applyDuplicate($query, string $table, string $locale): void
    {
        if (! Schema::hasColumn($table, 'meta_title')) {
            return;
        }
        $dupes = DB::table($table)
            ->select('meta_title')
            ->where('language_code', $locale)
            ->whereNotNull('meta_title')
            ->where('meta_title', '!=', '')
            ->groupBy('meta_title')
            ->havingRaw('COUNT(*) > 1')
            ->limit(500)
            ->pluck('meta_title');
        if ($dupes->isEmpty()) {
            $query->whereRaw('0 = 1');

            return;
        }
        $query->whereIn($table.'.meta_title', $dupes->all());
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    protected function existingColumns(string $table, array $columns): array
    {
        return array_values(array_filter($columns, fn ($column) => Schema::hasColumn($table, $column)));
    }
}
