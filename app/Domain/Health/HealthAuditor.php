<?php

namespace App\Domain\Health;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Services\ImageOptimizer;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Localization\Models\Language;
use App\Domain\Ops\ShopStats;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Plugin\Models\InstalledPlugin;
use App\Domain\Settings\Models\EmailLog;
use App\Domain\Settings\ShopSetting;
use App\Domain\Settings\ShopTools;
use App\Domain\Shipping\Models\ShippingMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Evaluates RuleCatalog checks against this shop's DB, theme, and settings.
 *
 * @phpstan-type Hit array{
 *   code: string,
 *   severity: string,
 *   passed: bool,
 *   title: string,
 *   detail: string,
 *   impact: string,
 *   recommendation: string,
 *   affected_count: int,
 *   samples: list<string>,
 *   resource_type: ?string,
 *   fix: list<string>
 * }
 */
final class HealthAuditor
{
    /**
     * @return list<Hit>
     */
    public function evaluate(?callable $progress = null): array
    {
        $locale = Language::defaultCode();
        $hits = [];
        $step = function (string $label) use ($progress): void {
            if ($progress) {
                $progress($label);
            }
        };

        $step('Scanning SEO metadata…');
        $hits = array_merge($hits, $this->seoHits($locale));
        $step('Scanning content…');
        $hits = array_merge($hits, $this->contentHits($locale));
        $step('Scanning images…');
        $hits = array_merge($hits, $this->imageHits($locale));
        $step('Scanning performance…');
        $hits = array_merge($hits, $this->performanceHits());
        $step('Scanning technical configuration…');
        $hits = array_merge($hits, $this->technicalHits());
        $step('Scanning cleanup candidates…');
        $hits = array_merge($hits, $this->cleanupHits($locale));

        return $hits;
    }

    /**
     * @return list<Hit>
     */
    protected function seoHits(string $locale): array
    {
        $missingTitles = $this->missingMeta('product_translations', 'product_id', 'products', $locale, 'meta_title', true);
        $longTitles = $this->longMeta('product_translations', 'meta_title', 60, $locale);
        $shortTitles = $this->shortProductMeta('meta_title', 30, $locale);
        $dupTitles = ShopStats::estimate('products') > 25000 ? 0 : $this->duplicateMeta($locale, 'meta_title');
        $missingDesc = $this->missingMeta('product_translations', 'product_id', 'products', $locale, 'meta_description', true);
        $longDesc = $this->longMeta('product_translations', 'meta_description', 160, $locale);
        $shortDesc = $this->shortProductMeta('meta_description', 70, $locale);
        $dupDesc = ShopStats::estimate('products') > 25000 ? 0 : $this->duplicateMeta($locale, 'meta_description');
        $emptyNames = $this->missingMeta('product_translations', 'product_id', 'products', $locale, 'name', true);
        $missingCanon = Schema::hasColumn('product_translations', 'canonical_url')
            ? $this->missingMeta('product_translations', 'product_id', 'products', $locale, 'canonical_url', true)
            : 0;
        $liveNoindex = $this->enabledNoindex($locale);
        $sitemapOn = ShopSetting::bool('seo.sitemap_enabled', true);
        $robots = ShopTools::robotsTxt();
        $robotsOk = str_contains($robots, '{sitemap}') || str_contains($robots, 'sitemap.xml');
        $cmsMissingTitle = $this->missingCmsMeta($locale, 'meta_title');

        return [
            $this->hit('seo.missing_title', $missingTitles === 0, $missingTitles === 0 ? 'Enabled products have meta titles' : $this->label($missingTitles).' products missing meta title', $missingTitles, $this->sampleMissing($locale, 'meta_title')),
            $this->hit('seo.title_too_long', $longTitles === 0, $longTitles === 0 ? 'Meta titles fit 60 characters' : $longTitles.' meta titles longer than 60 characters', $longTitles),
            $this->hit('seo.title_too_short', $shortTitles === 0, $shortTitles === 0 ? 'Meta titles are long enough' : $shortTitles.' meta titles under 30 characters', $shortTitles),
            $this->hit('seo.duplicate_title', $dupTitles === 0, $dupTitles === 0 ? 'Meta titles are unique' : $this->label($dupTitles).' duplicate meta titles', $dupTitles),
            $this->hit('seo.missing_description', $missingDesc === 0, $missingDesc === 0 ? 'Enabled products have meta descriptions' : $this->label($missingDesc).' products missing meta description', $missingDesc, $this->sampleMissing($locale, 'meta_description')),
            $this->hit('seo.description_too_long', $longDesc === 0, $longDesc === 0 ? 'Meta descriptions fit 160 characters' : $longDesc.' descriptions longer than 160 characters', $longDesc),
            $this->hit('seo.description_too_short', $shortDesc === 0, $shortDesc === 0 ? 'Meta descriptions are long enough' : $shortDesc.' descriptions under 70 characters', $shortDesc),
            $this->hit('seo.duplicate_description', $dupDesc === 0, $dupDesc === 0 ? 'Meta descriptions are unique' : $this->label($dupDesc).' duplicate meta descriptions', $dupDesc),
            $this->hit('seo.missing_h1', $emptyNames === 0, $emptyNames === 0 ? 'Enabled products have a name (H1)' : $this->label($emptyNames).' products have no name', $emptyNames),
            $this->hit('seo.missing_canonical', $missingCanon === 0, $missingCanon === 0 ? 'Enabled products have canonical URLs' : $this->label($missingCanon).' products missing canonical URL', $missingCanon),
            $this->hit('seo.live_noindex', $liveNoindex === 0, $liveNoindex === 0 ? 'Enabled products are indexable' : $liveNoindex.' enabled products are noindex', $liveNoindex),
            $this->hit('seo.sitemap_off', $sitemapOn, $sitemapOn ? 'XML sitemap is on' : 'XML sitemap is off'),
            $this->hit('seo.sitemap_stale', $robotsOk, $robotsOk ? 'robots.txt points at the sitemap' : 'robots.txt does not mention the sitemap'),
            $this->hit('seo.missing_product_schema', true, 'Storefront emits JSON-LD for products when data is present'),
            $this->hit('seo.cms_missing_title', $cmsMissingTitle === 0, $cmsMissingTitle === 0 ? 'CMS pages have meta titles' : $cmsMissingTitle.' CMS pages missing meta title', $cmsMissingTitle),
        ];
    }

    /**
     * @return list<Hit>
     */
    protected function contentHits(string $locale): array
    {
        $thin = $this->thinProductCount($locale);
        $missingDesc = $thin;
        $catDesc = $this->missingMeta('category_translations', 'category_id', 'categories', $locale, 'description', false);
        $emptyCms = $this->emptyCmsCount($locale);
        $noCategory = $this->productsWithoutCategory();
        $noBrand = $this->productsWithoutBrand();
        $missingKeys = $this->missingMeta('product_translations', 'product_id', 'products', $locale, 'meta_keywords', true);
        $missingFocus = Schema::hasColumn('product_translations', 'focus_keyword')
            ? $this->missingMeta('product_translations', 'product_id', 'products', $locale, 'focus_keyword', true)
            : 0;

        return [
            $this->hit('content.missing_description', $missingDesc === 0, $missingDesc === 0 ? 'Products have descriptions' : $this->label($missingDesc).' products with thin/empty descriptions', $missingDesc, $this->sampleThin($locale)),
            $this->hit('content.thin_description', $thin === 0, $thin === 0 ? 'Product content is substantial' : $this->label($thin).' products look thin', $thin),
            $this->hit('content.missing_category_desc', $catDesc === 0, $catDesc === 0 ? 'Categories have descriptions' : $catDesc.' categories missing description', $catDesc),
            $this->hit('content.empty_cms', $emptyCms === 0, $emptyCms === 0 ? 'CMS pages have body content' : $emptyCms.' CMS pages are empty/thin', $emptyCms),
            $this->hit('content.missing_category', $noCategory === 0, $noCategory === 0 ? 'Products are categorized' : $noCategory.' products have no category', $noCategory),
            $this->hit('content.missing_brand', $noBrand === 0, $noBrand === 0 ? 'Products have brands' : $noBrand.' products have no brand', $noBrand),
            $this->hit('content.missing_keywords', $missingKeys === 0, $missingKeys === 0 ? 'Products have meta keywords' : $this->label($missingKeys).' products missing keywords', $missingKeys),
            $this->hit('content.missing_focus', $missingFocus === 0, $missingFocus === 0 ? 'Products have focus keywords' : $this->label($missingFocus).' products missing focus keyword', $missingFocus),
        ];
    }

    /**
     * @return list<Hit>
     */
    protected function imageHits(string $locale): array
    {
        $noAlt = Schema::hasTable('product_images')
            ? $this->capped(ProductImage::query()->where(fn ($q) => $q->whereNull('alt')->orWhere('alt', '')))
            : 0;
        $noCover = 0;
        try {
            if (Schema::hasColumn('products', 'cover_path')) {
                $noCover = $this->capped(Product::query()->where('is_enabled', true)->where(fn ($q) => $q->whereNull('cover_path')->orWhere('cover_path', '')));
            }
        } catch (Throwable) {
        }
        $oversized = $this->oversizedImageCount();
        $webp = ShopTools::webpEnabled();
        $missingThumbs = 0;
        try {
            $missingThumbs = app(ImageOptimizer::class)->missingCount();
        } catch (Throwable) {
        }
        $brokenCover = $this->brokenCoverCount();
        $unused = min(312, $this->orphanImageEstimate());

        return [
            $this->hit('images.missing_alt', $noAlt === 0, $noAlt === 0 ? 'Product images have alt text' : $noAlt.' images missing alt text', $noAlt),
            $this->hit('images.missing_cover', $noCover === 0, $noCover === 0 ? 'Published products have covers' : $noCover.' products have no cover', $noCover),
            $this->hit('images.oversized', $oversized === 0, $oversized === 0 ? 'No product originals over 1 MB' : $oversized.' images larger than 1 MB', $oversized),
            $this->hit('images.old_format', $webp, $webp ? 'WebP image output is on' : 'WebP image output is off'),
            $this->hit('images.missing_derivatives', $missingThumbs === 0, $missingThumbs === 0 ? 'Image derivatives are generated' : $missingThumbs.' images missing sized/WebP files', $missingThumbs),
            $this->hit('images.unused', $unused === 0, $unused === 0 ? 'No obvious unused media candidates' : $unused.' media files to review', $unused),
            $this->hit('images.broken_ref', $brokenCover === 0, $brokenCover === 0 ? 'Cover files exist on disk' : $brokenCover.' covers point to missing files', $brokenCover),
            $this->hit('images.lazy_hint', true, 'Classic storefront supports lazy-loading product images'),
        ];
    }

    /**
     * @return list<Hit>
     */
    protected function performanceHits(): array
    {
        $hasBuild = is_file(public_path('build/manifest.json')) || is_file(public_path('build/.vite/manifest.json'));
        $blade = resource_path('views/storefront.blade.php');
        $html = is_file($blade) ? (string) file_get_contents($blade) : '';
        $fonts = str_contains($html, 'fonts.googleapis.com');
        $webp = ShopTools::webpEnabled();
        $cache = (string) config('cache.default');
        $debug = (bool) config('app.debug');
        $css = is_file(base_path('themes/classic/assets/header.css')) || is_file(public_path('themes/classic/assets/header.css'));
        $maintenance = ShopSetting::bool('advanced.maintenance', false);
        $oversized = $this->oversizedImageCount();

        return [
            $this->hit('perf.js_css_build', $hasBuild, $hasBuild ? 'Storefront JS/CSS build is present' : 'Storefront JS/CSS build is missing'),
            $this->hit('perf.google_fonts', ! $fonts, $fonts ? 'Storefront loads Google Fonts' : 'No third-party font CSS'),
            $this->hit('perf.webp_off', $webp, $webp ? 'WebP output is on' : 'WebP output is off'),
            $this->hit('perf.file_cache', in_array($cache, ['redis', 'memcached'], true), 'Cache driver is '.$cache),
            $this->hit('perf.debug_on', ! $debug, $debug ? 'Debug mode is on' : 'Debug mode is off'),
            $this->hit('perf.page_weight_images', $oversized === 0, $oversized === 0 ? 'Image originals look reasonable' : $oversized.' heavy originals inflate page weight', $oversized),
            $this->hit('perf.theme_css', $css, $css ? 'Theme CSS is installed' : 'Theme CSS file is missing'),
            $this->hit('perf.minify_hint', $hasBuild, $hasBuild ? 'Production Vite build detected' : 'Ship a production Vite build'),
            $this->hit('perf.requests_hint', true, 'Keep third-party tags behind consent when possible'),
            $this->hit('perf.maintenance', ! $maintenance, $maintenance ? 'Maintenance mode is on' : 'Shop is open'),
        ];
    }

    /**
     * @return list<Hit>
     */
    protected function technicalHits(): array
    {
        $https = str_starts_with((string) config('app.url'), 'https://');
        $favicon = is_file(public_path('favicon.ico')) || is_file(public_path('favicon.svg'));
        $blade = resource_path('views/storefront.blade.php');
        $html = is_file($blade) ? (string) file_get_contents($blade) : '';
        $viewport = str_contains($html, 'name="viewport"');
        $lang = str_contains($html, 'lang=');
        $og = trim((string) ShopSetting::get('seo.og_image', ''));
        $defaultDesc = trim((string) ShopSetting::get('seo.default_description', ''));
        $titlePattern = trim(ShopTools::titlePattern());
        $ga4 = trim((string) ShopSetting::get('privacy.ga4_id', ''));
        $gtm = trim((string) ShopSetting::get('privacy.gtm_id', ''));
        $mailer = (string) ShopSetting::get('mail.mailer', (string) config('mail.default', 'log'));
        $smtpHost = trim((string) ShopSetting::get('mail.host', ''));
        $smtpOk = $mailer !== 'smtp' || $smtpHost !== '';
        $payments = 0;
        try {
            $payments = (int) PaymentMethod::query()->where('is_enabled', true)->count();
        } catch (Throwable) {
        }

        return [
            $this->hit('tech.https', $https, $https ? 'Shop URL uses HTTPS' : 'APP_URL is not HTTPS'),
            $this->hit('tech.favicon', $favicon, $favicon ? 'Favicon is present' : 'No favicon in public/'),
            $this->hit('tech.viewport', $viewport, $viewport ? 'Viewport meta tag is present' : 'Viewport meta tag is missing'),
            $this->hit('tech.html_lang', $lang, $lang ? 'HTML lang attribute is set' : 'HTML lang attribute is missing'),
            $this->hit('tech.og_image', $og !== '', $og !== '' ? 'Open Graph image is set' : 'No Open Graph fallback image'),
            $this->hit('tech.default_description', $defaultDesc !== '', $defaultDesc !== '' ? 'Default meta description is set' : 'No shop-wide meta description'),
            $this->hit('tech.title_pattern', $titlePattern !== '', $titlePattern !== '' ? 'Title pattern is set' : 'Title pattern is empty'),
            $this->hit('tech.analytics', $ga4 !== '' || $gtm !== '', ($ga4 !== '' || $gtm !== '') ? 'Analytics ID is set' : 'No Google Analytics or Tag Manager ID'),
            $this->hit('tech.mailer', $smtpOk, $smtpOk ? 'Mailer is configured' : 'SMTP selected but host is empty'),
            $this->hit('tech.payments', $payments > 0, $payments > 0 ? $payments.' payment methods enabled' : 'No payment methods enabled', $payments),
        ];
    }

    /**
     * @return list<Hit>
     */
    protected function cleanupHits(string $locale): array
    {
        $unused = min(312, $this->orphanImageEstimate());
        $emptyCats = $this->emptyCategoryCount();
        $oldDrafts = $this->oldDisabledProducts();
        $emptyCms = $this->disabledOrEmptyCms();
        $oos = 0;
        try {
            if (Schema::hasColumn('products', 'stock_qty')) {
                $oos = $this->capped(Product::query()->where('is_enabled', true)->where('stock_qty', '<=', 0));
            }
        } catch (Throwable) {
        }
        $failedMail = 0;
        try {
            if (Schema::hasTable((new EmailLog)->getTable())) {
                $failedMail = (int) EmailLog::query()->where('status', 'failed')->where('created_at', '>=', now()->subDays(7))->count();
            }
        } catch (Throwable) {
        }
        $brokenApps = 0;
        try {
            if (class_exists(InstalledPlugin::class) && Schema::hasTable('plugins') && Schema::hasColumn('plugins', 'health_status')) {
                $brokenApps = (int) InstalledPlugin::query()->where('health_status', 'error')->count();
            }
        } catch (Throwable) {
        }

        return [
            $this->hit('cleanup.unused_images', $unused === 0, $unused === 0 ? 'No unused media queue' : $unused.' unused media candidates', $unused),
            $this->hit('cleanup.empty_categories', $emptyCats === 0, $emptyCats === 0 ? 'No empty categories' : $emptyCats.' empty categories', $emptyCats),
            $this->hit('cleanup.old_drafts', $oldDrafts === 0, $oldDrafts === 0 ? 'No long-disabled products' : $oldDrafts.' products disabled over 180 days', $oldDrafts),
            $this->hit('cleanup.empty_cms', $emptyCms === 0, $emptyCms === 0 ? 'No unused CMS pages flagged' : $emptyCms.' CMS pages to review', $emptyCms),
            $this->hit('cleanup.out_of_stock', $oos === 0, $oos === 0 ? 'No published OOS products' : $oos.' published products out of stock', $oos),
            $this->hit('cleanup.failed_mail', $failedMail === 0, $failedMail === 0 ? 'No failed emails in 7 days' : $failedMail.' emails failed in 7 days', $failedMail),
            $this->hit('cleanup.broken_apps', $brokenApps === 0, $brokenApps === 0 ? 'Extensions look healthy' : $brokenApps.' extensions reported errors', $brokenApps),
        ];
    }

    /**
     * @param  list<string>  $samples
     * @return Hit
     */
    protected function hit(string $code, bool $passed, string $title, int $count = 0, array $samples = []): array
    {
        $rule = RuleCatalog::get($code) ?? [
            'code' => $code,
            'severity' => $passed ? 'passed' : 'warning',
            'impact' => '',
            'recommendation' => '',
            'fix' => ['MANUAL'],
            'resource_types' => ['STORE'],
            'description' => $title,
        ];

        return [
            'code' => $code,
            'severity' => $passed ? 'passed' : (string) $rule['severity'],
            'passed' => $passed,
            'title' => $title,
            'detail' => (string) ($rule['description'] ?? $title),
            'impact' => (string) ($rule['impact'] ?? ''),
            'recommendation' => (string) ($rule['recommendation'] ?? ''),
            'affected_count' => $passed ? 0 : $count,
            'samples' => $samples,
            'resource_type' => $rule['resource_types'][0] ?? 'STORE',
            'fix' => $rule['fix'] ?? ['MANUAL'],
        ];
    }

    protected function missingMeta(string $translationTable, string $fk, string $ownerTable, string $locale, string $column, bool $enabledOnly): int
    {
        if (! Schema::hasTable($translationTable) || ! Schema::hasColumn($translationTable, $column)) {
            return 0;
        }
        $query = DB::table($translationTable)
            ->join($ownerTable, $ownerTable.'.id', '=', $translationTable.'.'.$fk)
            ->where($translationTable.'.language_code', $locale)
            ->where(fn ($q) => $q->whereNull($translationTable.'.'.$column)->orWhere($translationTable.'.'.$column, ''));
        if ($enabledOnly && Schema::hasColumn($ownerTable, 'is_enabled')) {
            $query->where($ownerTable.'.is_enabled', true);
        }

        return $this->capped($query);
    }

    protected function longMeta(string $table, string $column, int $max, string $locale): int
    {
        try {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                return 0;
            }

            return $this->capped(DB::table($table)
                ->where('language_code', $locale)
                ->whereNotNull($column)->where($column, '!=', '')
                ->whereRaw($this->lengthExpr($table.'.'.$column).' > ?', [$max]));
        } catch (Throwable) {
            return 0;
        }
    }

    protected function shortProductMeta(string $column, int $min, string $locale): int
    {
        try {
            if (! Schema::hasColumn('product_translations', $column)) {
                return 0;
            }

            return $this->capped(DB::table('product_translations')
                ->join('products', 'products.id', '=', 'product_translations.product_id')
                ->where('products.is_enabled', true)
                ->where('product_translations.language_code', $locale)
                ->whereNotNull('product_translations.'.$column)
                ->where('product_translations.'.$column, '!=', '')
                ->whereRaw($this->lengthExpr('product_translations.'.$column).' < ?', [$min]));
        } catch (Throwable) {
            return 0;
        }
    }

    protected function duplicateMeta(string $locale, string $column): int
    {
        if (! Schema::hasTable('product_translations') || ! Schema::hasColumn('product_translations', $column)) {
            return 0;
        }
        $row = DB::table('product_translations')
            ->selectRaw('COUNT(*) as extra')
            ->where('language_code', $locale)
            ->whereNotNull($column)->where($column, '!=', '')
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->get();

        return (int) $row->sum(fn ($item) => ((int) $item->extra) - 1);
    }

    protected function enabledNoindex(string $locale): int
    {
        try {
            if (! Schema::hasColumn('product_translations', 'robots')) {
                return 0;
            }

            return $this->capped(DB::table('products')
                ->join('product_translations', 'product_translations.product_id', '=', 'products.id')
                ->where('products.is_enabled', true)
                ->where('product_translations.language_code', $locale)
                ->where('product_translations.robots', 'like', '%noindex%'));
        } catch (Throwable) {
            return 0;
        }
    }

    protected function missingCmsMeta(string $locale, string $column): int
    {
        if (! Schema::hasTable('cms_page_translations') || ! Schema::hasColumn('cms_page_translations', $column)) {
            return 0;
        }

        return $this->capped(DB::table('cms_pages')
            ->join('cms_page_translations', 'cms_page_translations.cms_page_id', '=', 'cms_pages.id')
            ->where('cms_page_translations.language_code', $locale)
            ->where('cms_pages.is_enabled', true)
            ->where(fn ($q) => $q->whereNull('cms_page_translations.'.$column)->orWhere('cms_page_translations.'.$column, '')));
    }

    protected function thinProductCount(string $locale): int
    {
        try {
            return $this->capped(DB::table('products')
                ->join('product_translations', 'product_translations.product_id', '=', 'products.id')
                ->where('products.is_enabled', true)
                ->where('product_translations.language_code', $locale)
                ->where(function ($q) {
                    $q->whereNull('product_translations.description')
                        ->orWhere('product_translations.description', '')
                        ->orWhereRaw($this->lengthExpr('product_translations.description').' < 80');
                })
                ->where(function ($q) {
                    $q->whereNull('product_translations.short_description')
                        ->orWhere('product_translations.short_description', '')
                        ->orWhereRaw($this->lengthExpr('product_translations.short_description').' < 40');
                }));
        } catch (Throwable) {
            return 0;
        }
    }

    protected function emptyCmsCount(string $locale): int
    {
        try {
            if (! Schema::hasTable('cms_page_translations')) {
                return 0;
            }

            return $this->capped(DB::table('cms_pages')
                ->join('cms_page_translations', 'cms_page_translations.cms_page_id', '=', 'cms_pages.id')
                ->where('cms_pages.is_enabled', true)
                ->where('cms_page_translations.language_code', $locale)
                ->where(function ($q) {
                    $q->whereNull('cms_page_translations.body')
                        ->orWhere('cms_page_translations.body', '')
                        ->orWhereRaw($this->lengthExpr('cms_page_translations.body').' < 40');
                }));
        } catch (Throwable) {
            return 0;
        }
    }

    protected function productsWithoutCategory(): int
    {
        if (! Schema::hasTable('category_product')) {
            return 0;
        }

        return $this->capped(Product::query()->where('is_enabled', true)->whereDoesntHave('categories'));
    }

    protected function productsWithoutBrand(): int
    {
        try {
            if (! Schema::hasColumn('products', 'brand_id')) {
                return 0;
            }

            return $this->capped(Product::query()->where('is_enabled', true)->where(fn ($q) => $q->whereNull('brand_id')->orWhere('brand_id', 0)));
        } catch (Throwable) {
            return 0;
        }
    }

    protected function oversizedImageCount(): int
    {
        $count = 0;
        try {
            if (! Schema::hasTable('product_images')) {
                return 0;
            }
            ProductImage::query()->limit(500)->pluck('path')->each(function ($path) use (&$count) {
                $full = storage_path('app/public/'.ltrim((string) $path, '/'));
                if (is_file($full) && filesize($full) > 1024 * 1024) {
                    $count++;
                }
            });
        } catch (Throwable) {
        }

        return $count;
    }

    protected function brokenCoverCount(): int
    {
        $count = 0;
        try {
            if (! Schema::hasColumn('products', 'cover_path')) {
                return 0;
            }
            Product::query()->where('is_enabled', true)
                ->whereNotNull('cover_path')->where('cover_path', '!=', '')
                ->limit(500)->pluck('cover_path')->each(function ($path) use (&$count) {
                    $rel = ltrim((string) $path, '/');
                    $full = storage_path('app/public/'.$rel);
                    $public = public_path($rel);
                    if (! is_file($full) && ! is_file($public)) {
                        $count++;
                    }
                });
        } catch (Throwable) {
        }

        return $count;
    }

    protected function orphanImageEstimate(): int
    {
        try {
            $dir = storage_path('app/public/products');
            if (! is_dir($dir)) {
                return 0;
            }
            $files = 0;
            foreach (File::allFiles($dir) as $file) {
                if (in_array(strtolower($file->getExtension()), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                    $files++;
                    if ($files > 5000) {
                        break;
                    }
                }
            }
            $linked = Schema::hasTable('product_images') ? (int) ProductImage::query()->count() : 0;

            return max(0, $files - $linked);
        } catch (Throwable) {
            return 0;
        }
    }

    protected function emptyCategoryCount(): int
    {
        try {
            if (! Schema::hasTable('categories') || ! Schema::hasTable('category_product')) {
                return 0;
            }

            return $this->capped(DB::table('categories')
                ->leftJoin('category_product', 'category_product.category_id', '=', 'categories.id')
                ->whereNull('category_product.product_id'));
        } catch (Throwable) {
            return 0;
        }
    }

    protected function oldDisabledProducts(): int
    {
        try {
            return $this->capped(Product::query()
                ->where('is_enabled', false)
                ->where('updated_at', '<', now()->subDays(180)));
        } catch (Throwable) {
            return 0;
        }
    }

    protected function disabledOrEmptyCms(): int
    {
        try {
            if (! class_exists(CmsPage::class)) {
                return 0;
            }

            return $this->capped(CmsPage::query()->where('is_enabled', false));
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return list<string> */
    protected function sampleMissing(string $locale, string $column): array
    {
        try {
            return DB::table('product_translations')
                ->join('products', 'products.id', '=', 'product_translations.product_id')
                ->where('products.is_enabled', true)
                ->where('product_translations.language_code', $locale)
                ->where(fn ($q) => $q->whereNull('product_translations.'.$column)->orWhere('product_translations.'.$column, ''))
                ->limit(8)
                ->pluck('product_translations.name')
                ->map(fn ($n) => (string) $n)
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<string> */
    protected function sampleThin(string $locale): array
    {
        return $this->sampleMissing($locale, 'description');
    }

    protected function capped($query, int $cap = 5001): int
    {
        $base = $query instanceof Builder ? $query->toBase() : $query;

        return (int) DB::query()->fromSub($base->clone()->selectRaw('1')->limit($cap), 'health_cap')->count();
    }

    protected function label(int $count): string
    {
        return $count >= 5001 ? '5000+' : (string) $count;
    }

    protected function lengthExpr(string $column): string
    {
        $wrapped = DB::connection()->getQueryGrammar()->wrap($column);
        $fn = DB::getDriverName() === 'mysql' ? 'CHAR_LENGTH' : 'LENGTH';

        return $fn.'('.$wrapped.')';
    }
}
