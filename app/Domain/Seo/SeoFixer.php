<?php

namespace App\Domain\Seo;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Services\ImageOptimizer;
use App\Domain\Catalog\Services\UrlRewriteService;
use App\Domain\Localization\Models\Language;
use App\Domain\Settings\ShopSetting;
use App\Domain\Settings\ShopTools;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SeoFixer
{
    protected mixed $onProgress = null;

    protected string $currentId = '';

    protected int $expected = 0;

    /**
     * @return array{fixed: int, message: string}
     */
    public function apply(string $id, bool $flush = true, ?callable $onProgress = null, int $expected = 0): array
    {
        $this->onProgress = $onProgress ?? $this->onProgress;
        $this->currentId = $id;
        $this->expected = max(0, $expected);
        $journal = app(SeoFixJournal::class);
        $journal->start($id);
        $this->emit('step_start', [
            'total' => $this->expected,
            'message' => 'Started.',
        ]);
        $result = ['fixed' => 0, 'message' => 'Nothing changed.'];
        try {
            $result = match ($id) {
                'enable_sitemap' => $this->toggle('seo.sitemap_enabled', true, 'XML sitemap is on.'),
                'write_robots_sitemap' => $this->robotsSitemap(),
                'set_default_description' => $this->defaultDescription(),
                'enable_webp' => $this->toggle('images.webp', true, 'WebP output is on. Regenerate thumbnails on Configure Images if you already have files.'),
                'set_og_image' => $this->setOgImage(),
                'fill_product_titles' => $this->fillProductColumn('meta_title', fn (object $row) => $this->titleFrom($row->name)),
                'fill_product_descriptions' => $this->fillProductColumn('meta_description', fn (object $row) => $this->descriptionFrom($row), 70),
                'fill_product_keywords' => $this->fillProductColumn('meta_keywords', fn (object $row) => $this->keywordsFrom($row->name)),
                'fill_product_focus' => $this->fillProductColumn('focus_keyword', fn (object $row) => $this->focusFrom($row->name)),
                'trim_product_titles' => $this->trimProductTitles(),
                'unique_product_titles' => $this->uniqueProductTitles(),
                'expand_short_titles' => $this->expandShortTitles(),
                'place_focus_in_title' => $this->placeFocusInTitle(),
                'fill_product_canonicals' => $this->fillProductCanonicals(),
                'fill_thin_content' => $this->fillThinContent(),
                'assign_product_covers' => $this->assignProductCovers(),
                'optimize_images' => $this->optimizeImages(),
                'fill_cms_meta' => $this->fillCmsMeta(),
                'fill_category_meta' => $this->fillCategoryMeta(),
                'noindex_disabled_products' => $this->noindexDisabled(),
                'fill_image_alts' => $this->fillImageAlts(),
                default => throw new InvalidArgumentException('Unknown fix.'),
            };
        } finally {
            $count = (int) ($result['fixed'] ?? 0);
            $journal->finish($result['message'] ?? ('Store health: '.$id.' · '.$count.' records'));
        }
        if ($flush) {
            ShopAudit::flush();
        }
        $this->emit('step_done', [
            'fixed' => $result['fixed'],
            'done' => $result['fixed'],
            'total' => max($this->expected, (int) $result['fixed']),
            'message' => $result['message'],
        ]);

        return $result;
    }

    /**
     * @param  list<array{id?: string, title?: string, count?: int}>  $jobs
     * @return array{fixed: int, message: string}
     */
    public function applyAll(?callable $onProgress = null, array $jobs = []): array
    {
        $this->onProgress = $onProgress ?? $this->onProgress;
        $journal = app(SeoFixJournal::class);
        $journal->start('all');
        $ids = [];
        $expected = [];
        $fixed = 0;
        $parts = [];
        try {
            foreach ($jobs as $job) {
                $fix = trim((string) ($job['id'] ?? ''));
                if ($fix === '') {
                    continue;
                }
                $ids[$fix] = true;
                $expected[$fix] = max(0, (int) ($job['count'] ?? 0));
            }
            if ($ids === []) {
                foreach (app(ShopAudit::class)->report()['issues'] as $issue) {
                    if (! empty($issue['fix'])) {
                        $ids[$issue['fix']] = true;
                        $expected[$issue['fix']] = max($expected[$issue['fix']] ?? 0, (int) ($issue['count'] ?? 0));
                    }
                }
            }
            foreach (array_keys($ids) as $id) {
                $result = $this->apply($id, false, $this->onProgress, $expected[$id] ?? 0);
                $fixed += $result['fixed'];
                $parts[] = $result['message'];
            }
        } finally {
            $message = $fixed === 0 ? 'Nothing left to auto-fix.' : implode(' ', $parts);
            $journal->finish($fixed === 0 ? $message : 'Store health Fix all · '.$fixed.' records. '.$message);
        }
        ShopAudit::flush();

        return [
            'fixed' => $fixed,
            'message' => $fixed === 0 ? 'Nothing left to auto-fix.' : implode(' ', $parts),
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function emit(string $type, array $extra = []): void
    {
        if (! is_callable($this->onProgress)) {
            return;
        }
        ($this->onProgress)(array_merge([
            'type' => $type,
            'id' => $this->currentId,
        ], $extra));
    }

    /**
     * @return array{fixed: int, message: string}
     */
    protected function toggle(string $key, bool $value, string $message): array
    {
        $this->writeSetting($key, $value);

        return ['fixed' => 1, 'message' => $message];
    }

    /**
     * @return array{fixed: int, message: string}
     */
    protected function robotsSitemap(): array
    {
        $body = trim(ShopTools::robotsTxt());
        if (! str_contains($body, '{sitemap}') && ! str_contains(strtolower($body), 'sitemap.xml')) {
            $body .= ($body === '' ? '' : "\n").'Sitemap: {sitemap}';
        }
        $this->writeSetting('seo.robots_txt', $body."\n");

        return ['fixed' => 1, 'message' => 'robots.txt now includes the sitemap URL.'];
    }

    /**
     * @return array{fixed: int, message: string}
     */
    protected function defaultDescription(): array
    {
        $name = (string) (config('wento.name') ?: config('app.name') ?: 'Shop');
        $this->writeSetting('seo.default_description', 'Shop '.$name.' for quality products, fast checkout, and order tracking.');

        return ['fixed' => 1, 'message' => 'Default meta description saved.'];
    }

    /**
     * @param  callable(object): string  $maker
     * @return array{fixed: int, message: string}
     */
    protected function fillProductColumn(string $column, callable $maker, int $minLength = 0): array
    {
        if (! Schema::hasColumn('product_translations', $column)) {
            return ['fixed' => 0, 'message' => 'This shop schema has no '.$column.' column.'];
        }
        $locale = Language::defaultCode();
        $cap = 5000;
        $batch = 50;
        $length = $minLength > 0 ? $this->lengthExpr('product_translations.'.$column) : null;
        $query = DB::table('product_translations')
            ->join('products', 'products.id', '=', 'product_translations.product_id')
            ->where('product_translations.language_code', $locale)
            ->where('products.is_enabled', true)
            ->where(function ($q) use ($column, $minLength, $length) {
                $q->whereNull('product_translations.'.$column)->orWhere('product_translations.'.$column, '');
                if ($minLength > 0 && $length) {
                    $q->orWhereRaw($length.' < ?', [$minLength]);
                }
            })
            ->orderBy('product_translations.id');

        $total = $this->expected > 0 ? min($cap, $this->expected) : min($cap, (int) (clone $query)->count());
        $this->emit('progress', [
            'done' => 0,
            'total' => $total,
            'message' => 'Preparing product updates…',
        ]);

        $fixed = 0;
        $lastId = 0;
        while ($fixed < $cap) {
            $rows = (clone $query)
                ->where('product_translations.id', '>', $lastId)
                ->limit($batch)
                ->get([
                    'product_translations.id',
                    'product_translations.name',
                    'product_translations.short_description',
                    'product_translations.description',
                    'product_translations.meta_description',
                ]);
            if ($rows->isEmpty()) {
                break;
            }
            foreach ($rows as $row) {
                $lastId = (int) $row->id;
                $value = trim($maker($row));
                if ($value === '') {
                    continue;
                }
                if (! $this->writeFields('product_translations', (int) $row->id, (string) ($row->name ?? '#'.$row->id), [$column => $value], 'product_translation')) {
                    continue;
                }
                $fixed++;
            }
            $this->emit('progress', [
                'done' => $fixed,
                'total' => max($total, $fixed),
                'message' => 'Updated '.$fixed.($total ? ' of '.$total : '').' products…',
            ]);
        }

        return ['fixed' => $fixed, 'message' => $this->updatedMessage($fixed, 'product', $cap)];
    }

    /**
     * @return array{fixed: int, message: string}
     */
    protected function fillCmsMeta(): array
    {
        if (! Schema::hasTable('cms_page_translations')) {
            return ['fixed' => 0, 'message' => 'No CMS pages to update.'];
        }
        $locale = Language::defaultCode();
        $rows = DB::table('cms_page_translations')
            ->where('language_code', $locale)
            ->orderBy('id')
            ->limit(2000)
            ->get();
        $fixed = 0;
        foreach ($rows as $row) {
            $update = [];
            if (trim((string) $row->meta_title) === '') {
                $update['meta_title'] = Str::limit(trim((string) $row->title), 60, '');
            }
            if (Schema::hasColumn('cms_page_translations', 'meta_description') && trim((string) ($row->meta_description ?? '')) === '') {
                $source = trim((string) ($row->short_description ?: strip_tags((string) ($row->body ?? ''))));
                $update['meta_description'] = Str::limit($source !== '' ? $source : (string) $row->title, 160, '');
            }
            if (Schema::hasColumn('cms_page_translations', 'meta_keywords') && trim((string) ($row->meta_keywords ?? '')) === '') {
                $update['meta_keywords'] = $this->keywordsFrom((string) $row->title);
            }
            if ($update === []) {
                continue;
            }
            if (! $this->writeFields('cms_page_translations', (int) $row->id, (string) ($row->title ?? '#'.$row->id), $update, 'cms_page_translation')) {
                continue;
            }
            $fixed++;
            if ($fixed % 20 === 0) {
                $this->emit('progress', [
                    'done' => $fixed,
                    'total' => max($this->expected, $rows->count()),
                    'message' => 'Updated '.$fixed.' CMS pages…',
                ]);
            }
        }

        return ['fixed' => $fixed, 'message' => $this->updatedMessage($fixed, 'CMS page', 2000)];
    }

    /**
     * @return array{fixed: int, message: string}
     */
    protected function fillCategoryMeta(): array
    {
        $locale = Language::defaultCode();
        $rows = DB::table('category_translations')
            ->where('language_code', $locale)
            ->orderBy('id')
            ->limit(2000)
            ->get();
        $fixed = 0;
        foreach ($rows as $row) {
            $update = [];
            if (trim((string) ($row->meta_title ?? '')) === '') {
                $update['meta_title'] = Str::limit(trim((string) $row->name), 60, '');
            }
            if (Schema::hasColumn('category_translations', 'meta_description') && trim((string) ($row->meta_description ?? '')) === '') {
                $source = trim(strip_tags((string) ($row->description ?? '')));
                $update['meta_description'] = Str::limit($source !== '' ? $source : (string) $row->name, 160, '');
            }
            if (Schema::hasColumn('category_translations', 'meta_keywords') && trim((string) ($row->meta_keywords ?? '')) === '') {
                $update['meta_keywords'] = $this->keywordsFrom((string) $row->name);
            }
            if ($update === []) {
                continue;
            }
            if (! $this->writeFields('category_translations', (int) $row->id, (string) ($row->name ?? '#'.$row->id), $update, 'category_translation')) {
                continue;
            }
            $fixed++;
            if ($fixed % 20 === 0) {
                $this->emit('progress', [
                    'done' => $fixed,
                    'total' => max($this->expected, $rows->count()),
                    'message' => 'Updated '.$fixed.' categories…',
                ]);
            }
        }

        return ['fixed' => $fixed, 'message' => $this->updatedMessage($fixed, 'category', 2000)];
    }

    /**
     * @return array{fixed: int, message: string}
     */
    protected function noindexDisabled(): array
    {
        if (! Schema::hasColumn('product_translations', 'robots')) {
            return ['fixed' => 0, 'message' => 'Product translations have no robots column.'];
        }
        $ids = Product::query()->where('is_enabled', false)->pluck('id');
        $fixed = 0;
        $total = max($this->expected, $ids->count());
        foreach ($ids->chunk(100) as $chunk) {
            $rows = DB::table('product_translations')
                ->whereIn('product_id', $chunk->all())
                ->where(function ($q) {
                    $q->whereNull('robots')->orWhere('robots', '')->orWhere('robots', 'like', 'index%');
                })
                ->get(['id', 'name', 'robots']);
            foreach ($rows as $row) {
                if ($this->writeFields('product_translations', (int) $row->id, (string) ($row->name ?? '#'.$row->id), ['robots' => 'noindex,follow'], 'product_translation')) {
                    $fixed++;
                }
            }
            $this->emit('progress', [
                'done' => $fixed,
                'total' => max($total, $fixed),
                'message' => 'Set noindex on '.$fixed.' translations…',
            ]);
        }

        return ['fixed' => $fixed, 'message' => $fixed === 1 ? '1 translation set to noindex.' : $fixed.' translations set to noindex.'];
    }

    /**
     * @return array{fixed: int, message: string}
     */
    protected function fillImageAlts(): array
    {
        $locale = Language::defaultCode();
        $rows = DB::table('product_images')
            ->leftJoin('product_translations', function ($join) use ($locale) {
                $join->on('product_translations.product_id', '=', 'product_images.product_id')
                    ->where('product_translations.language_code', '=', $locale);
            })
            ->where(function ($q) {
                $q->whereNull('product_images.alt')->orWhere('product_images.alt', '');
            })
            ->orderBy('product_images.id')
            ->limit(5000)
            ->get(['product_images.id', 'product_translations.name']);
        $fixed = 0;
        foreach ($rows as $row) {
            $alt = trim((string) $row->name);
            if ($alt === '') {
                continue;
            }
            if (! $this->writeFields('product_images', (int) $row->id, $alt, ['alt' => Str::limit($alt, 120, '')], 'product_image')) {
                continue;
            }
            $fixed++;
            if ($fixed % 50 === 0) {
                $this->emit('progress', [
                    'done' => $fixed,
                    'total' => max($this->expected, $rows->count()),
                    'message' => 'Updated '.$fixed.' image alts…',
                ]);
            }
        }

        return ['fixed' => $fixed, 'message' => $this->updatedMessage($fixed, 'image alt', 5000)];
    }

    /**
     * @return array{fixed: int, message: string}
     */
    protected function setOgImage(): array
    {
        $path = '';
        try {
            if (Schema::hasColumn('products', 'cover_path')) {
                $path = (string) Product::query()
                    ->where('is_enabled', true)
                    ->whereNotNull('cover_path')
                    ->where('cover_path', '!=', '')
                    ->value('cover_path');
            }
        } catch (\Throwable) {
            $path = '';
        }
        if ($path === '' && Schema::hasTable('product_images')) {
            $path = (string) ProductImage::query()->whereNotNull('path')->where('path', '!=', '')->orderBy('id')->value('path');
        }
        if ($path === '') {
            return ['fixed' => 0, 'message' => 'No product image is available to use as the Open Graph fallback.'];
        }
        $this->writeSetting('seo.og_image', $path);

        return ['fixed' => 1, 'message' => 'Open Graph fallback image set from a product photo.'];
    }

    /**
     * @return array{fixed: int, message: string}
     */
    protected function trimProductTitles(): array
    {
        $locale = Language::defaultCode();
        $length = $this->lengthExpr('meta_title');
        $rows = DB::table('product_translations')
            ->where('language_code', $locale)
            ->whereNotNull('meta_title')
            ->where('meta_title', '!=', '')
            ->whereRaw($length.' > ?', [60])
            ->orderBy('id')
            ->limit(5000)
            ->get(['id', 'name', 'meta_title']);
        $fixed = 0;
        foreach ($rows as $row) {
            $next = Str::limit(trim((string) $row->meta_title), 60, '');
            if ($this->writeFields('product_translations', (int) $row->id, (string) ($row->name ?? '#'.$row->id), ['meta_title' => $next], 'product_translation')) {
                $fixed++;
            }
        }

        return ['fixed' => $fixed, 'message' => $this->updatedMessage($fixed, 'product title', 5000)];
    }

    /**
     * @return array{fixed: int, message: string}
     */
    protected function uniqueProductTitles(): array
    {
        $locale = Language::defaultCode();
        $rows = DB::table('product_translations')
            ->join('products', 'products.id', '=', 'product_translations.product_id')
            ->where('product_translations.language_code', $locale)
            ->whereNotNull('product_translations.meta_title')
            ->where('product_translations.meta_title', '!=', '')
            ->orderBy('product_translations.id')
            ->limit(8000)
            ->get([
                'product_translations.id',
                'product_translations.name',
                'product_translations.meta_title',
                'products.sku',
            ]);
        $seen = [];
        $fixed = 0;
        foreach ($rows as $row) {
            $key = mb_strtolower(trim((string) $row->meta_title));
            if ($key === '') {
                continue;
            }
            if (! isset($seen[$key])) {
                $seen[$key] = true;

                continue;
            }
            $sku = trim((string) ($row->sku ?? ''));
            $next = Str::limit(trim((string) $row->meta_title).($sku !== '' ? ' · '.$sku : ' · #'.$row->id), 60, '');
            if ($this->writeFields('product_translations', (int) $row->id, (string) ($row->name ?? '#'.$row->id), ['meta_title' => $next], 'product_translation')) {
                $fixed++;
                $seen[mb_strtolower($next)] = true;
            }
        }

        return ['fixed' => $fixed, 'message' => $this->updatedMessage($fixed, 'duplicate title', 5000)];
    }

    /**
     * @return array{fixed: int, message: string}
     */
    protected function fillThinContent(): array
    {
        $locale = Language::defaultCode();
        $rows = DB::table('product_translations')
            ->join('products', 'products.id', '=', 'product_translations.product_id')
            ->where('products.is_enabled', true)
            ->where('product_translations.language_code', $locale)
            ->orderBy('product_translations.id')
            ->limit(5000)
            ->get([
                'product_translations.id',
                'product_translations.name',
                'product_translations.short_description',
                'product_translations.description',
            ]);
        $fixed = 0;
        foreach ($rows as $row) {
            $short = trim(strip_tags((string) ($row->short_description ?? '')));
            $long = trim(strip_tags((string) ($row->description ?? '')));
            if (mb_strlen($short) >= 40 && mb_strlen($long) >= 80) {
                continue;
            }
            $name = trim((string) ($row->name ?? 'Product'));
            $shop = (string) (config('wento.name') ?: config('app.name') ?: 'Shop');
            $body = $long !== '' ? $long : ($short !== '' ? $short : $name);
            if (mb_strlen($body) < 80) {
                $body = trim($body.' Shop '.$name.' at '.$shop.'. See specifications, shipping, and returns on the product page.');
            }
            $teaser = $short !== '' ? $short : Str::limit(strip_tags($body), 140, '');
            if (mb_strlen($teaser) < 40) {
                $teaser = Str::limit($name.' from '.$shop.'. Ready to order with tracked shipping.', 140, '');
            }
            $fields = [];
            if (mb_strlen($short) < 40) {
                $fields['short_description'] = $teaser;
            }
            if (mb_strlen($long) < 80) {
                $fields['description'] = $body;
            }
            if ($fields !== [] && $this->writeFields('product_translations', (int) $row->id, $name, $fields, 'product_translation')) {
                $fixed++;
            }
        }

        return ['fixed' => $fixed, 'message' => $this->updatedMessage($fixed, 'product', 5000)];
    }

    /**
     * @return array{fixed: int, message: string}
     */
    protected function assignProductCovers(): array
    {
        if (! Schema::hasColumn('products', 'cover_path') || ! Schema::hasTable('product_images')) {
            return ['fixed' => 0, 'message' => 'This shop schema cannot store product covers.'];
        }
        $rows = Product::query()
            ->where('is_enabled', true)
            ->where(function ($q) {
                $q->whereNull('cover_path')->orWhere('cover_path', '');
            })
            ->orderBy('id')
            ->limit(5000)
            ->get(['id', 'sku', 'cover_path']);
        $fixed = 0;
        foreach ($rows as $product) {
            $path = (string) ProductImage::query()
                ->where('product_id', $product->id)
                ->orderBy('position')
                ->orderBy('id')
                ->value('path');
            if ($path === '') {
                continue;
            }
            if ($this->writeFields('products', (int) $product->id, (string) $product->sku, ['cover_path' => $path], 'product')) {
                $fixed++;
            }
        }

        return ['fixed' => $fixed, 'message' => $this->updatedMessage($fixed, 'product cover', 5000)];
    }

    /**
     * @return array{fixed: int, message: string}
     */
    protected function expandShortTitles(): array
    {
        $locale = Language::defaultCode();
        $length = $this->lengthExpr('meta_title');
        $rows = DB::table('product_translations')
            ->where('language_code', $locale)
            ->whereNotNull('meta_title')
            ->where('meta_title', '!=', '')
            ->whereRaw($length.' < ?', [30])
            ->orderBy('id')
            ->limit(5000)
            ->get(['id', 'name', 'meta_title']);
        $fixed = 0;
        foreach ($rows as $row) {
            $next = $this->titleFrom((string) ($row->name ?: $row->meta_title));
            if (mb_strlen($next) < 30) {
                $shop = (string) (config('wento.name') ?: config('app.name') ?: 'Shop');
                $next = Str::limit(trim($next.' | '.$shop), 60, '');
            }
            if ($next === '' || $next === (string) $row->meta_title) {
                continue;
            }
            if ($this->writeFields('product_translations', (int) $row->id, (string) ($row->name ?? '#'.$row->id), ['meta_title' => $next], 'product_translation')) {
                $fixed++;
            }
        }

        return ['fixed' => $fixed, 'message' => $this->updatedMessage($fixed, 'short title', 5000)];
    }

    /**
     * @return array{fixed: int, message: string}
     */
    protected function placeFocusInTitle(): array
    {
        $locale = Language::defaultCode();
        $rows = DB::table('product_translations')
            ->join('products', 'products.id', '=', 'product_translations.product_id')
            ->where('products.is_enabled', true)
            ->where('product_translations.language_code', $locale)
            ->whereNotNull('product_translations.focus_keyword')
            ->where('product_translations.focus_keyword', '!=', '')
            ->whereNotNull('product_translations.meta_title')
            ->where('product_translations.meta_title', '!=', '')
            ->orderBy('product_translations.id')
            ->limit(5000)
            ->get([
                'product_translations.id',
                'product_translations.name',
                'product_translations.meta_title',
                'product_translations.focus_keyword',
            ]);
        $fixed = 0;
        foreach ($rows as $row) {
            $focus = trim((string) $row->focus_keyword);
            $title = trim((string) $row->meta_title);
            if ($focus === '' || mb_stripos($title, $focus) !== false) {
                continue;
            }
            $next = Str::limit($focus.' | '.$title, 60, '');
            if ($this->writeFields('product_translations', (int) $row->id, (string) ($row->name ?? '#'.$row->id), ['meta_title' => $next], 'product_translation')) {
                $fixed++;
            }
        }

        return ['fixed' => $fixed, 'message' => $this->updatedMessage($fixed, 'title', 5000)];
    }

    /**
     * @return array{fixed: int, message: string}
     */
    protected function fillProductCanonicals(): array
    {
        if (! Schema::hasColumn('product_translations', 'canonical_url')) {
            return ['fixed' => 0, 'message' => 'Product translations have no canonical_url column.'];
        }
        $locale = Language::defaultCode();
        $rewrites = app(UrlRewriteService::class);
        $rows = DB::table('product_translations')
            ->join('products', 'products.id', '=', 'product_translations.product_id')
            ->where('products.is_enabled', true)
            ->where('product_translations.language_code', $locale)
            ->where(function ($q) {
                $q->whereNull('product_translations.canonical_url')
                    ->orWhere('product_translations.canonical_url', '');
            })
            ->orderBy('product_translations.id')
            ->limit(5000)
            ->get(['product_translations.id', 'product_translations.name', 'product_translations.product_id']);
        $fixed = 0;
        $base = rtrim((string) config('app.url'), '/');
        foreach ($rows as $row) {
            $path = $rewrites->pathFor('product', (int) $row->product_id, $locale);
            if (! is_string($path) || $path === '') {
                continue;
            }
            $canonical = $base.'/'.ltrim($path, '/');
            if ($this->writeFields('product_translations', (int) $row->id, (string) ($row->name ?? '#'.$row->id), ['canonical_url' => $canonical], 'product_translation')) {
                $fixed++;
            }
        }

        return ['fixed' => $fixed, 'message' => $this->updatedMessage($fixed, 'canonical URL', 5000)];
    }

    /**
     * @return array{fixed: int, message: string}
     */
    protected function optimizeImages(): array
    {
        $optimizer = app(ImageOptimizer::class);
        if (! $optimizer->gdAvailable()) {
            return ['fixed' => 0, 'message' => 'PHP GD is not available, so image files cannot be resized here.'];
        }
        $images = ProductImage::query()->orderBy('id')->limit(200)->get(['id', 'path', 'alt']);
        $fixed = 0;
        $files = 0;
        foreach ($images as $image) {
            $created = $optimizer->generateMissing((string) $image->path);
            if ($created === []) {
                continue;
            }
            app(SeoFixJournal::class)->record(
                'storage',
                (int) $image->id,
                (string) ($image->alt ?: $image->path),
                ['created' => []],
                ['created' => $created],
                'product_image'
            );
            $fixed++;
            $files += count($created);
            if ($fixed % 10 === 0) {
                $this->emit('progress', [
                    'done' => $fixed,
                    'total' => max($this->expected, $images->count()),
                    'message' => 'Optimized '.$fixed.' images…',
                ]);
            }
        }

        $message = $fixed === 0
            ? 'No missing image sizes were found.'
            : 'Wrote '.$files.' sized files for '.$fixed.' images.';
        if ($fixed === 200) {
            $message .= ' Run Fix again for the rest.';
        }

        return ['fixed' => $fixed, 'message' => $message];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function writeFields(string $table, int $id, string $label, array $fields, string $entityType): bool
    {
        $row = DB::table($table)->where('id', $id)->first();
        if (! $row) {
            return false;
        }
        $before = [];
        $after = [];
        foreach ($fields as $column => $value) {
            $old = $row->{$column} ?? null;
            if ((string) $old === (string) $value) {
                continue;
            }
            $before[$column] = $old;
            $after[$column] = $value;
        }
        if ($after === []) {
            return false;
        }
        DB::table($table)->where('id', $id)->update($after);
        app(SeoFixJournal::class)->record($table, $id, $label, $before, $after, $entityType);

        return true;
    }

    protected function writeSetting(string $key, mixed $value): bool
    {
        $old = ShopSetting::get($key, null);
        if ((string) $old === (string) $value) {
            return false;
        }
        ShopSetting::put($key, $value);
        app(SeoFixJournal::class)->record(
            'settings',
            0,
            $key,
            ['key' => $key, 'value' => $old],
            ['key' => $key, 'value' => $value],
            'setting'
        );

        return true;
    }

    protected function titleFrom(string $name): string
    {
        $pattern = trim((string) ShopSetting::get('seo.product_title_pattern', '')) ?: ShopTools::titlePattern();
        $shop = (string) (config('wento.name') ?: config('app.name') ?: 'Shop');
        $title = str_replace(['{name}', '{shop}'], [trim($name), $shop], $pattern);

        return Str::limit(trim($title), 60, '');
    }

    protected function descriptionFrom(object $row): string
    {
        $name = trim((string) ($row->name ?? ''));
        $existing = trim(strip_tags((string) ($row->meta_description ?? '')));
        $short = trim(strip_tags((string) ($row->short_description ?? '')));
        $long = trim(strip_tags((string) ($row->description ?? '')));
        $shop = (string) (config('wento.name') ?: config('app.name') ?: 'Shop');

        $parts = array_values(array_unique(array_filter([$short, $long, $existing, $name], fn ($part) => $part !== '')));
        $text = trim(implode(' ', $parts));
        if ($text === '') {
            $text = $name !== '' ? $name : $shop;
        }
        if (mb_strlen($text) < 70) {
            $text = trim($text.' Shop '.($name !== '' ? $name : 'this product').' at '.$shop.'. Quality products, fast checkout, and order tracking.');
        }

        return Str::limit($text, 160, '');
    }

    protected function lengthExpr(string $column): string
    {
        $wrapped = DB::connection()->getQueryGrammar()->wrap($column);
        $fn = DB::getDriverName() === 'mysql' ? 'CHAR_LENGTH' : 'LENGTH';

        return $fn.'('.$wrapped.')';
    }

    protected function keywordsFrom(string $name): string
    {
        $parts = preg_split('/[\s,\/\-]+/', mb_strtolower(trim($name))) ?: [];
        $parts = array_values(array_unique(array_filter($parts, fn ($part) => mb_strlen($part) > 2)));

        return implode(', ', array_slice($parts, 0, 8));
    }

    protected function focusFrom(string $name): string
    {
        return Str::limit(trim($name), 80, '');
    }

    protected function updatedMessage(int $fixed, string $noun, int $cap): string
    {
        $plural = $noun === 'category' ? 'categories' : $noun.'s';
        $message = $fixed === 1 ? 'Updated 1 '.$noun.'.' : 'Updated '.$fixed.' '.$plural.'.';
        if ($fixed === $cap) {
            $message .= ' Run Fix again for the rest.';
        }

        return $message;
    }
}
