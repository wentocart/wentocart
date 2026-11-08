<?php

namespace App\Domain\Seo;

use App\Domain\Catalog\Models\UrlRewrite;
use App\Domain\Localization\Models\Language;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\StoreContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class SitemapBuilder
{
    public const CHUNK = 5000;

    public const INDEX_AFTER = 10000;

    /**
     * @return array{xml: string, content_type: string}
     */
    public function document(?string $type = null, int $page = 1): array
    {
        if (! ShopSetting::bool('seo.sitemap_enabled', true)) {
            abort(404);
        }

        $root = rtrim($this->shopRoot(), '/');
        $counts = $this->counts();
        $total = array_sum($counts);

        if ($type === null && $total > self::INDEX_AFTER) {
            return [
                'xml' => $this->index($root, $counts),
                'content_type' => 'application/xml; charset=UTF-8',
            ];
        }

        if ($type === null) {
            return [
                'xml' => $this->urlset($this->allLocs($root)),
                'content_type' => 'application/xml; charset=UTF-8',
            ];
        }

        return [
            'xml' => $this->urlset($this->locsFor($type, $root, $page)),
            'content_type' => 'application/xml; charset=UTF-8',
        ];
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $home = 1;
        if ($this->localePathMode()) {
            $home = max(1, count($this->enabledLanguageCodes()));
        }

        return [
            'home' => $home,
            'product' => ShopSetting::bool('seo.sitemap_include_products', true) ? $this->enabledRewriteCount('product') : 0,
            'category' => ShopSetting::bool('seo.sitemap_include_categories', true) ? $this->enabledRewriteCount('category') : 0,
            'cms' => ShopSetting::bool('seo.sitemap_include_cms', true) ? $this->enabledRewriteCount('cms') : 0,
            'brand' => ShopSetting::bool('seo.sitemap_include_brands', true) ? $this->enabledRewriteCount('brand') : 0,
        ];
    }

    /**
     * @param  array<string, int>  $counts
     */
    protected function index(string $root, array $counts): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        $xml .= '  <sitemap><loc>'.e($root.'/sitemap/pages.xml').'</loc></sitemap>'."\n";
        foreach (['product', 'category', 'cms', 'brand'] as $type) {
            $n = $counts[$type] ?? 0;
            if ($n < 1) {
                continue;
            }
            $pages = (int) ceil($n / self::CHUNK);
            for ($page = 1; $page <= $pages; $page++) {
                $xml .= '  <sitemap><loc>'.e($root.'/sitemap/'.$type.'-'.$page.'.xml').'</loc></sitemap>'."\n";
            }
        }
        $xml .= '</sitemapindex>';

        return $xml;
    }

    /**
     * @param  list<array{loc: string, lastmod?: string, changefreq?: string, priority?: string, alternates?: list<array{lang: string, href: string}>}>  $rows
     */
    protected function urlset(array $rows): string
    {
        $hasAlternates = collect($rows)->contains(fn (array $row) => ! empty($row['alternates']));
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= $hasAlternates
            ? '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n"
            : '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($rows as $row) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>'.e($row['loc']).'</loc>'."\n";
            if (! empty($row['lastmod'])) {
                $xml .= '    <lastmod>'.e($row['lastmod']).'</lastmod>'."\n";
            }
            if (! empty($row['changefreq'])) {
                $xml .= '    <changefreq>'.e($row['changefreq']).'</changefreq>'."\n";
            }
            if (! empty($row['priority'])) {
                $xml .= '    <priority>'.e($row['priority']).'</priority>'."\n";
            }
            foreach ($row['alternates'] ?? [] as $alt) {
                $xml .= '    <xhtml:link rel="alternate" hreflang="'.e($alt['lang']).'" href="'.e($alt['href']).'"/>'."\n";
            }
            $xml .= "  </url>\n";
        }
        $xml .= '</urlset>';

        return $xml;
    }

    /**
     * @return list<array{loc: string, lastmod?: string, changefreq?: string, priority?: string, alternates?: list<array{lang: string, href: string}>}>
     */
    protected function allLocs(string $root): array
    {
        $rows = $this->locsFor('pages', $root, 1);
        foreach (['product', 'category', 'cms', 'brand'] as $type) {
            $page = 1;
            do {
                $chunk = $this->locsFor($type, $root, $page);
                $rows = array_merge($rows, $chunk);
                $page++;
            } while (count($chunk) === self::CHUNK);
        }

        return $rows;
    }

    /**
     * @return list<array{loc: string, lastmod?: string, changefreq?: string, priority?: string, alternates?: list<array{lang: string, href: string}>}>
     */
    protected function locsFor(string $type, string $root, int $page): array
    {
        $page = max(1, $page);
        $languages = $this->enabledLanguageCodes();
        $localeMode = $this->localePathMode();

        if ($type === 'pages') {
            if ($localeMode) {
                $homeAlts = collect($languages)->map(fn (string $code) => [
                    'lang' => $code,
                    'href' => $root.'/'.$code,
                ])->values()->all();
                if (count($homeAlts) > 1) {
                    $default = $this->defaultLanguageCode();
                    $defaultHref = collect($homeAlts)->firstWhere('lang', $default)['href']
                        ?? ($homeAlts[0]['href'] ?? $root.'/');
                    $homeAlts[] = ['lang' => 'x-default', 'href' => $defaultHref];
                }

                return collect($languages)->map(fn (string $code) => [
                    'loc' => $root.'/'.$code,
                    'changefreq' => 'daily',
                    'priority' => '1.0',
                    'lastmod' => now()->toDateString(),
                    'alternates' => count($languages) > 1 ? $homeAlts : [],
                ])->values()->all();
            }

            return [[
                'loc' => $root.'/',
                'changefreq' => 'daily',
                'priority' => '1.0',
                'lastmod' => now()->toDateString(),
            ]];
        }

        $include = match ($type) {
            'product' => ShopSetting::bool('seo.sitemap_include_products', true),
            'category' => ShopSetting::bool('seo.sitemap_include_categories', true),
            'cms' => ShopSetting::bool('seo.sitemap_include_cms', true),
            'brand' => ShopSetting::bool('seo.sitemap_include_brands', true),
            default => false,
        };
        if (! $include || ! Schema::hasTable('url_rewrites') || $languages === []) {
            return [];
        }

        $owner = $this->ownerTable($type);
        if ($owner === null || ! Schema::hasTable($owner)) {
            return [];
        }

        $select = [
            'url_rewrites.id',
            'url_rewrites.request_path',
            'url_rewrites.language_code',
            'url_rewrites.entity_id',
        ];
        $hasUpdated = Schema::hasColumn($owner, 'updated_at');
        if ($hasUpdated) {
            $select[] = $owner.'.updated_at';
        }
        $query = UrlRewrite::query()
            ->from('url_rewrites')
            ->where('url_rewrites.entity_type', $type)
            ->whereIn('url_rewrites.language_code', $languages)
            ->join($owner, $owner.'.id', '=', 'url_rewrites.entity_id')
            ->where($owner.'.is_enabled', true)
            ->orderBy('url_rewrites.id')
            ->select($select);

        if ($type === 'cms') {
            if (Schema::hasColumn('cms_pages', 'is_visible')) {
                $query->where('cms_pages.is_visible', true);
            }
            if (Schema::hasColumn('cms_pages', 'status')) {
                $query->where('cms_pages.status', 'published');
            }
        }

        $rows = $query->forPage($page, self::CHUNK)->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $siblingsByEntity = $this->siblingLocsByEntity($type, $rows->pluck('entity_id')->unique()->all(), $root, $languages, $localeMode);

        return $rows
            ->map(function ($row) use ($root, $type, $localeMode, $siblingsByEntity) {
                $lang = (string) $row->language_code;
                $loc = $this->locForRewrite($root, (string) $row->request_path, $lang, $localeMode);
                $lastmod = $row->updated_at ? substr((string) $row->updated_at, 0, 10) : null;
                $alternates = $siblingsByEntity[(int) $row->entity_id] ?? [];

                return [
                    'loc' => $loc,
                    'lastmod' => $lastmod,
                    'changefreq' => $type === 'product' ? 'weekly' : 'monthly',
                    'priority' => $type === 'product' ? '0.8' : '0.6',
                    'alternates' => count($alternates) > 1 ? $alternates : [],
                ];
            })
            ->unique('loc')
            ->values()
            ->all();
    }

    /**
     * @param  list<int|string>  $entityIds
     * @param  list<string>  $languages
     * @return array<int, list<array{lang: string, href: string}>>
     */
    protected function siblingLocsByEntity(string $type, array $entityIds, string $root, array $languages, bool $localeMode): array
    {
        if ($entityIds === [] || count($languages) < 2) {
            return [];
        }

        $siblings = UrlRewrite::query()
            ->where('entity_type', $type)
            ->whereIn('entity_id', $entityIds)
            ->whereIn('language_code', $languages)
            ->get(['entity_id', 'language_code', 'request_path']);

        $default = $this->defaultLanguageCode();
        /** @var Collection<int, Collection<int, UrlRewrite>> $grouped */
        $grouped = $siblings->groupBy(fn ($row) => (int) $row->entity_id);
        $out = [];
        foreach ($grouped as $entityId => $rows) {
            $alts = $rows->map(fn ($row) => [
                'lang' => (string) $row->language_code,
                'href' => $this->locForRewrite($root, (string) $row->request_path, (string) $row->language_code, $localeMode),
            ])->unique('lang')->values()->all();
            if (count($alts) < 2) {
                continue;
            }
            $defaultHref = collect($alts)->firstWhere('lang', $default)['href'] ?? $alts[0]['href'];
            $alts[] = ['lang' => 'x-default', 'href' => $defaultHref];
            $out[(int) $entityId] = $alts;
        }

        return $out;
    }

    protected function locForRewrite(string $root, string $requestPath, string $languageCode, bool $localeMode): string
    {
        $path = ltrim($requestPath, '/');
        if ($localeMode) {
            $parts = $path === '' ? [] : explode('/', $path);
            if (($parts[0] ?? '') === $languageCode) {
                $path = implode('/', array_slice($parts, 1));
            }

            return $path === ''
                ? $root.'/'.$languageCode
                : $root.'/'.$languageCode.'/'.$path;
        }

        return $root.'/'.$path;
    }

    protected function enabledRewriteCount(string $type): int
    {
        if (! Schema::hasTable('url_rewrites')) {
            return 0;
        }
        $owner = $this->ownerTable($type);
        if ($owner === null || ! Schema::hasTable($owner)) {
            return 0;
        }

        $languages = $this->enabledLanguageCodes();
        $query = UrlRewrite::query()
            ->where('url_rewrites.entity_type', $type)
            ->whereIn('url_rewrites.language_code', $languages)
            ->join($owner, $owner.'.id', '=', 'url_rewrites.entity_id')
            ->where($owner.'.is_enabled', true);

        if ($type === 'cms') {
            if (Schema::hasColumn('cms_pages', 'is_visible')) {
                $query->where('cms_pages.is_visible', true);
            }
            if (Schema::hasColumn('cms_pages', 'status')) {
                $query->where('cms_pages.status', 'published');
            }
        }

        return (int) $query->count('url_rewrites.id');
    }

    protected function localePathMode(): bool
    {
        try {
            return (string) ShopSetting::get('seo.locale_url_mode', config('wento.locale_url_mode', 'cookie')) === 'path';
        } catch (\Throwable) {
            return (string) config('wento.locale_url_mode', 'cookie') === 'path';
        }
    }

    /**
     * @return list<string>
     */
    protected function enabledLanguageCodes(): array
    {
        try {
            return Language::query()
                ->enabled()
                ->orderBy('position')
                ->pluck('code')
                ->map(fn ($code) => (string) $code)
                ->all();
        } catch (\Throwable) {
            return [(string) config('wento.default_language', 'en')];
        }
    }

    protected function defaultLanguageCode(): string
    {
        try {
            return (string) Language::defaultCode();
        } catch (\Throwable) {
            return (string) config('wento.default_language', 'en');
        }
    }

    protected function ownerTable(string $type): ?string
    {
        return match ($type) {
            'product' => 'products',
            'category' => 'categories',
            'cms' => Schema::hasTable('cms_pages') ? 'cms_pages' : null,
            'brand' => Schema::hasTable('brands') ? 'brands' : null,
            default => null,
        };
    }

    protected function shopRoot(): string
    {
        $url = app(StoreContext::class)->store()?->primaryUrl()?->root();

        return is_string($url) && $url !== '' ? $url : (string) config('app.url');
    }
}
