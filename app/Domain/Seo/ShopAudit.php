<?php

namespace App\Domain\Seo;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Services\ImageOptimizer;
use App\Domain\Localization\Models\Language;
use App\Domain\Ops\ShopStats;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Plugin\Models\InstalledPlugin;
use App\Domain\Settings\InventorySettings;
use App\Domain\Settings\Models\EmailLog;
use App\Domain\Settings\ShopSetting;
use App\Domain\Settings\ShopTools;
use App\Domain\Shipping\Models\ShippingMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ShopAudit
{
    public const CACHE_KEY = 'seo.shop_audit';

    public const CACHE_TTL = 60;

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => $this->build());
        } catch (Throwable $e) {
            try {
                report($e);
            } catch (Throwable) {
            }
            Cache::forget(self::CACHE_KEY);

            return $this->failedReport($e->getMessage());
        }
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    protected function build(): array
    {
        $locale = Language::defaultCode();
        $issues = [];

        $productCount = ShopStats::estimate('products');
        $enabledProducts = $productCount;
        $categoryCount = ShopStats::estimate('categories');
        $cmsCount = ShopStats::estimate('cms_pages');
        $sitemapUrls = ShopStats::estimate('url_rewrites');

        $missingTitles = $this->missingMetaCount('product_translations', 'product_id', 'products', $locale, 'meta_title', true);
        $missingDescriptions = $this->missingMetaCount('product_translations', 'product_id', 'products', $locale, 'meta_description', true);
        $missingKeywords = $this->missingMetaCount('product_translations', 'product_id', 'products', $locale, 'meta_keywords', true);
        $missingFocus = $this->missingMetaCount('product_translations', 'product_id', 'products', $locale, 'focus_keyword', true);
        $longTitles = $this->longMetaCount('product_translations', 'meta_title', 60, $locale);
        $shortDescriptions = $this->shortMetaCount('product_translations', 'meta_description', 70, $locale);
        $noCover = 0;
        try {
            if (Schema::hasColumn('products', 'cover_path')) {
                $noCover = $this->cappedCount(Product::query()->where('is_enabled', true)->where(function ($q) {
                    $q->whereNull('cover_path')->orWhere('cover_path', '');
                }));
            }
        } catch (Throwable) {
            $noCover = 0;
        }
        $noAlt = Schema::hasTable('product_images')
            ? $this->cappedCount(ProductImage::query()->where(function ($q) {
                $q->whereNull('alt')->orWhere('alt', '');
            }))
            : 0;
        $noCategory = $this->productsWithoutCategory();
        $disabledIndexed = $this->disabledStillIndexed($locale);
        $cmsMissingTitle = $this->missingCmsMeta($locale, 'meta_title');
        $cmsMissingDesc = $this->missingCmsMeta($locale, 'meta_description');
        $cmsMissingKeys = $this->missingCmsMeta($locale, 'meta_keywords');
        $catMissingTitle = $this->missingMetaCount('category_translations', 'category_id', 'categories', $locale, 'meta_title', false);
        $catMissingDesc = $this->missingMetaCount('category_translations', 'category_id', 'categories', $locale, 'meta_description', false);
        $catMissingKeys = $this->missingMetaCount('category_translations', 'category_id', 'categories', $locale, 'meta_keywords', false);
        $thinContent = $this->thinProductCount($locale);
        $dupTitles = $productCount > 25000 ? 0 : $this->duplicateMeta($locale);
        $shortTitles = $this->shortMetaCount('product_translations', 'meta_title', 30, $locale);
        $missingCanonicals = $this->missingCanonicalCount($locale);
        $titlesMissingFocus = $this->titlesMissingFocus($locale);
        $missingThumbs = 0;
        try {
            $missingThumbs = app(ImageOptimizer::class)->missingCount();
        } catch (Throwable) {
            $missingThumbs = 0;
        }

        $sitemapOn = ShopSetting::bool('seo.sitemap_enabled', true);
        $robots = ShopTools::robotsTxt();
        $og = trim((string) ShopSetting::get('seo.og_image', ''));
        $defaultDesc = trim((string) ShopSetting::get('seo.default_description', ''));
        $titlePattern = trim(ShopTools::titlePattern());
        $https = str_starts_with((string) config('app.url'), 'https://');
        $debug = (bool) config('app.debug');
        $cache = (string) config('cache.default');
        $webp = ShopTools::webpEnabled();
        $ga4 = trim((string) ShopSetting::get('privacy.ga4_id', ''));
        $gtm = trim((string) ShopSetting::get('privacy.gtm_id', ''));
        $pixel = trim((string) ShopSetting::get('privacy.meta_pixel_id', ''));
        $consent = ShopSetting::bool('privacy.cookie_consent', true);
        $mailer = (string) ShopSetting::get('mail.mailer', (string) config('mail.default', 'log'));
        $smtpHost = trim((string) ShopSetting::get('mail.host', ''));
        $payments = 0;
        $shipping = 0;
        $outOfStock = 0;
        try {
            $payments = (int) PaymentMethod::query()->where('is_enabled', true)->count();
        } catch (Throwable) {
        }
        try {
            $shipping = (int) ShippingMethod::query()->where('is_enabled', true)->count();
        } catch (Throwable) {
        }
        try {
            if (Schema::hasColumn('products', 'stock_qty')) {
                $outOfStock = $this->cappedCount(Product::query()->where('is_enabled', true)->where('stock_qty', '<=', 0));
            }
        } catch (Throwable) {
            $outOfStock = 0;
        }
        $languages = (int) Language::query()->where('is_enabled', true)->count();
        $localeUrlMode = 'cookie';
        try {
            $localeUrlMode = (string) ShopSetting::get('seo.locale_url_mode', config('wento.locale_url_mode', 'cookie'));
        } catch (Throwable) {
            $localeUrlMode = (string) config('wento.locale_url_mode', 'cookie');
        }
        $maintenance = ShopSetting::bool('advanced.maintenance', false);

        $issues[] = $this->issue('sitemap.enabled', 'indexing', $sitemapOn ? 'ok' : 'error', $sitemapOn ? 'XML sitemap is on' : 'XML sitemap is off', 'Search engines need /sitemap.xml to discover products, categories, and pages.', $sitemapOn ? null : 'enable_sitemap', '/admin/seo?tab=sitemap');
        $issues[] = $this->issue('robots.sitemap', 'indexing', str_contains($robots, '{sitemap}') || str_contains($robots, 'sitemap.xml') ? 'ok' : 'warning', 'robots.txt points at the sitemap', 'Add Sitemap: {sitemap} so crawlers find the map.', str_contains($robots, 'sitemap') ? null : 'write_robots_sitemap', '/admin/seo?tab=settings');
        $issues[] = $this->issue('seo.title_pattern', 'seo', $titlePattern !== '' ? 'ok' : 'error', $titlePattern !== '' ? 'Title pattern is set' : 'Title pattern is empty', 'Used when a page has no meta title.', $titlePattern !== '' ? null : null, '/admin/seo?tab=settings');
        $issues[] = $this->issue('seo.default_description', 'seo', $defaultDesc !== '' ? 'ok' : 'warning', $defaultDesc !== '' ? 'Default meta description is set' : 'No shop-wide meta description', 'Fallback for pages without their own description.', $defaultDesc !== '' ? null : 'set_default_description', '/admin/seo?tab=settings');
        $issues[] = $this->issue('seo.og_image', 'seo', $og !== '' ? 'ok' : 'warning', $og !== '' ? 'Open Graph image is set' : 'No Open Graph fallback image', 'Used when a page is shared without its own image. Fix copies a product photo. Twitter cards use the same image.', $og !== '' ? null : 'set_og_image', '/admin/seo?tab=settings');
        $issues[] = $this->issue('seo.https', 'store', $https ? 'ok' : 'warning', $https ? 'Shop URL uses HTTPS' : 'APP_URL is not HTTPS', 'Set APP_URL to https:// in .env after TLS is on this host. That cannot be guessed from admin.', null, '/admin/store');
        $issues[] = $this->issue('store.debug', 'store', $debug ? 'warning' : 'ok', $debug ? 'Debug mode is on' : 'Debug mode is off', 'Turn APP_DEBUG off in .env on a live shop.', null, '/admin/advanced?tab=info');
        $issues[] = $this->issue('store.cache', 'store', in_array($cache, ['redis', 'memcached'], true) ? 'ok' : 'warning', 'Cache driver is '.$cache, 'File cache is fine for a first shop. Redis is an .env change, not an automatic catalog fix.', null, '/admin/performance');
        $issues[] = $this->issue('store.webp', 'store', $webp ? 'ok' : 'warning', $webp ? 'WebP image output is on' : 'WebP image output is off', 'Modern formats shrink pages for Core Web Vitals. Fix turns WebP on; then run image optimizer for files.', $webp ? null : 'enable_webp', '/admin/images');
        $issues[] = $this->issue('store.image_derivatives', 'store', $missingThumbs === 0 ? 'ok' : 'warning', $missingThumbs === 0 ? 'Product image sizes are generated' : $missingThumbs.' product images are missing sized/WebP files', 'Fix writes cart/home/medium/large derivatives from the original photo. Roll back deletes only the files this run created.', $missingThumbs > 0 ? 'optimize_images' : null, '/admin/images', $missingThumbs);
        $issues[] = $this->issue('seo.analytics', 'seo', ($ga4 !== '' || $gtm !== '') ? 'ok' : 'warning', ($ga4 !== '' || $gtm !== '') ? 'Analytics ID is set' : 'No Google Analytics or Tag Manager ID', 'Paste a real GA4 or GTM ID on SEO → Settings. The shop cannot invent a tracking account.', null, '/admin/seo?tab=settings');
        if ($pixel !== '' && ! $consent) {
            $issues[] = $this->issue('seo.consent', 'seo', 'warning', 'Meta Pixel is on without cookie consent', 'Ask before loading tracking pixels.', null, '/admin/seo?tab=settings');
        }
        if ($languages <= 1) {
            $issues[] = $this->issue('seo.hreflang', 'seo', 'ok', 'Single language shop', 'Hreflang is not needed until you enable a second language.', null, '/admin/localization');
        } elseif ($localeUrlMode === 'path') {
            $issues[] = $this->issue('seo.hreflang', 'seo', 'ok', $languages.' languages with path-locale URLs', 'Storefront emits /{lang}/… URLs, hreflang + x-default, and sitemap xhtml alternates.', null, '/admin/seo?tab=settings');
        } else {
            $issues[] = $this->issue(
                'seo.hreflang',
                'seo',
                'warning',
                $languages.' languages on cookie locale mode',
                'Entity hreflang uses per-language rewrite paths. Home shares one URL. Switch Language URL mode to Path for full multilingual SEO (/en, /fr).',
                null,
                '/admin/seo?tab=settings'
            );
        }
        $issues[] = $this->issue(
            'seo.merchant_feed',
            'seo',
            ShopSetting::bool('seo.merchant_feed', true) ? 'ok' : 'warning',
            ShopSetting::bool('seo.merchant_feed', true) ? 'Google Merchant feed is published' : 'Google Merchant feed is off',
            'Feed XML is ready at /feeds/google-merchant.xml. Approval still requires Google Merchant Center validation (identifiers, policy, shipping).',
            null,
            '/admin/seo?tab=settings'
        );

        $issues[] = $this->issue('content.product_titles', 'content', $missingTitles === 0 ? 'ok' : 'error', $missingTitles === 0 ? 'Enabled products have meta titles' : $this->countLabel($missingTitles).' products missing meta title', 'Aim for 50-60 characters. Fix fills from the product name and title pattern.', $missingTitles > 0 ? 'fill_product_titles' : null, '/admin/seo?tab=pages&type=product&filter=missing_title', $missingTitles);
        $issues[] = $this->issue('content.product_descriptions', 'content', $missingDescriptions === 0 ? 'ok' : 'warning', $missingDescriptions === 0 ? 'Enabled products have meta descriptions' : $this->countLabel($missingDescriptions).' products missing meta description', 'Aim for 150-160 characters. Fix copies the short description.', $missingDescriptions > 0 ? 'fill_product_descriptions' : null, '/admin/seo?tab=pages&type=product&filter=missing_description', $missingDescriptions);
        $issues[] = $this->issue('content.product_keywords', 'content', $missingKeywords === 0 ? 'ok' : 'warning', $missingKeywords === 0 ? 'Enabled products have meta keywords' : $this->countLabel($missingKeywords).' products missing meta keywords', 'Keywords help internal search and some engines. Fix uses the product name.', $missingKeywords > 0 ? 'fill_product_keywords' : null, '/admin/seo?tab=pages&type=product&filter=missing_keywords', $missingKeywords);
        $issues[] = $this->issue('content.product_focus', 'content', $missingFocus === 0 ? 'ok' : 'warning', $missingFocus === 0 ? 'Enabled products have a focus keyword' : $this->countLabel($missingFocus).' products missing focus keyword', 'Used in the product SERP preview. Fix uses the product name.', $missingFocus > 0 ? 'fill_product_focus' : null, '/admin/seo?tab=pages&type=product&filter=missing_focus', $missingFocus);
        $issues[] = $this->issue('content.product_title_length', 'content', $longTitles === 0 ? 'ok' : 'warning', $longTitles === 0 ? 'Meta titles fit 60 characters' : $longTitles.' meta titles are longer than 60 characters', 'Google typically shows about 50–60 characters. Fix trims titles to 60.', $longTitles > 0 ? 'trim_product_titles' : null, '/admin/seo?tab=pages&type=product', $longTitles);
        $issues[] = $this->issue('content.product_title_short', 'content', $shortTitles === 0 ? 'ok' : 'warning', $shortTitles === 0 ? 'Meta titles are long enough' : $shortTitles.' meta titles are under 30 characters', 'Short titles waste SERP space. Fix expands them from the product name and title pattern.', $shortTitles > 0 ? 'expand_short_titles' : null, '/admin/seo?tab=pages&type=product', $shortTitles);
        $issues[] = $this->issue('content.focus_in_title', 'content', $titlesMissingFocus === 0 ? 'ok' : 'warning', $titlesMissingFocus === 0 ? 'Focus keywords appear in meta titles' : $titlesMissingFocus.' titles omit their focus keyword', 'Search engines expect the target phrase in the title. Fix inserts the focus keyword.', $titlesMissingFocus > 0 ? 'place_focus_in_title' : null, '/admin/seo?tab=pages&type=product', $titlesMissingFocus);
        $issues[] = $this->issue('content.canonicals', 'seo', $missingCanonicals === 0 ? 'ok' : 'warning', $missingCanonicals === 0 ? 'Enabled products have canonical URLs' : $this->countLabel($missingCanonicals).' products missing canonical URL', 'Canonicals stop duplicate-URL dilution. Fix writes the public rewrite path.', $missingCanonicals > 0 ? 'fill_product_canonicals' : null, '/admin/seo?tab=pages&type=product', $missingCanonicals);
        $issues[] = $this->issue('content.product_desc_length', 'content', $shortDescriptions === 0 ? 'ok' : 'warning', $shortDescriptions === 0 ? 'Meta descriptions are long enough' : $shortDescriptions.' meta descriptions are under 70 characters', 'Thin snippets get rewritten by search engines.', $shortDescriptions > 0 ? 'fill_product_descriptions' : null, '/admin/seo?tab=pages&type=product&filter=missing_description', $shortDescriptions);
        $issues[] = $this->issue('content.covers', 'content', $noCover === 0 ? 'ok' : 'warning', $noCover === 0 ? 'Published products have cover images' : $noCover.' published products have no cover', 'Fix uses the first gallery image when one exists.', $noCover > 0 ? 'assign_product_covers' : null, '/admin/products', $noCover);
        $issues[] = $this->issue('content.alt', 'content', $noAlt === 0 ? 'ok' : 'warning', $noAlt === 0 ? 'Product images have alt text' : $noAlt.' product images missing alt text', 'Alt text is required for accessibility and image search. Fix copies the product name.', $noAlt > 0 ? 'fill_image_alts' : null, '/admin/products', $noAlt);
        $issues[] = $this->issue('content.category', 'content', $noCategory === 0 ? 'ok' : 'warning', $noCategory === 0 ? 'Enabled products are in a category' : $noCategory.' enabled products have no category', 'Category paths help crawl depth and breadcrumbs.', null, '/admin/products', $noCategory);
        $issues[] = $this->issue('content.thin', 'content', $thinContent === 0 ? 'ok' : 'warning', $thinContent === 0 ? 'Enabled products have descriptions' : $thinContent.' enabled products have thin or empty descriptions', 'Fix writes a real short description and body from the product name when copy is missing.', $thinContent > 0 ? 'fill_thin_content' : null, '/admin/products', $thinContent);
        $issues[] = $this->issue('content.duplicates', 'content', $dupTitles === 0 ? 'ok' : 'warning', $dupTitles === 0 ? ($productCount > 25000 ? 'Duplicate title scan skipped on large catalogs' : 'Meta titles are unique') : $this->countLabel($dupTitles).' duplicate meta titles', $productCount > 25000 ? 'Open Pages and filter Duplicate titles to review a page at a time.' : 'Fix keeps the first title and appends the SKU to the rest.', $dupTitles > 0 && $productCount <= 25000 ? 'unique_product_titles' : null, '/admin/seo?tab=pages&type=product&filter=duplicate', $dupTitles);
        $issues[] = $this->issue('indexing.disabled', 'indexing', $disabledIndexed === 0 ? 'ok' : 'warning', $disabledIndexed === 0 ? 'Disabled products are noindex' : $disabledIndexed.' disabled products are still indexable', 'Fix sets robots to noindex,follow on disabled products.', $disabledIndexed > 0 ? 'noindex_disabled_products' : null, '/admin/products?status=inactive', $disabledIndexed);

        $issues[] = $this->issue('content.cms_titles', 'content', $cmsMissingTitle === 0 ? 'ok' : 'warning', $cmsMissingTitle === 0 ? 'CMS pages have meta titles' : $cmsMissingTitle.' CMS pages missing meta title', 'Fix copies the page title.', $cmsMissingTitle > 0 ? 'fill_cms_meta' : null, '/admin/seo?tab=pages&type=cms&filter=missing_title', $cmsMissingTitle);
        $issues[] = $this->issue('content.cms_descriptions', 'content', $cmsMissingDesc === 0 ? 'ok' : 'warning', $cmsMissingDesc === 0 ? 'CMS pages have meta descriptions' : $cmsMissingDesc.' CMS pages missing meta description', 'Fix copies the short description or title.', $cmsMissingDesc > 0 ? 'fill_cms_meta' : null, '/admin/seo?tab=pages&type=cms&filter=missing_description', $cmsMissingDesc);
        $issues[] = $this->issue('content.cms_keywords', 'content', $cmsMissingKeys === 0 ? 'ok' : 'warning', $cmsMissingKeys === 0 ? 'CMS pages have meta keywords' : $cmsMissingKeys.' CMS pages missing meta keywords', 'Fix uses words from the page title.', $cmsMissingKeys > 0 ? 'fill_cms_meta' : null, '/admin/seo?tab=pages&type=cms&filter=missing_keywords', $cmsMissingKeys);
        $issues[] = $this->issue('content.category_titles', 'content', $catMissingTitle === 0 ? 'ok' : 'warning', $catMissingTitle === 0 ? 'Categories have meta titles' : $catMissingTitle.' categories missing meta title', 'Fix copies the category name.', $catMissingTitle > 0 ? 'fill_category_meta' : null, '/admin/seo?tab=pages&type=category&filter=missing_title', $catMissingTitle);
        $issues[] = $this->issue('content.category_descriptions', 'content', $catMissingDesc === 0 ? 'ok' : 'warning', $catMissingDesc === 0 ? 'Categories have meta descriptions' : $catMissingDesc.' categories missing meta description', 'Fix copies the category description or name.', $catMissingDesc > 0 ? 'fill_category_meta' : null, '/admin/seo?tab=pages&type=category', $catMissingDesc);
        $issues[] = $this->issue('content.category_keywords', 'content', $catMissingKeys === 0 ? 'ok' : 'warning', $catMissingKeys === 0 ? 'Categories have meta keywords' : $catMissingKeys.' categories missing meta keywords', 'Fix uses words from the category name.', $catMissingKeys > 0 ? 'fill_category_meta' : null, '/admin/seo?tab=pages&type=category', $catMissingKeys);

        $smtpOk = $mailer !== 'smtp' || $smtpHost !== '';
        $issues[] = $this->issue('store.mailer', 'store', $smtpOk ? 'ok' : 'error', $mailer === 'smtp' ? 'SMTP mailer' : 'Mailer is '.$mailer, $smtpOk ? 'Outgoing mail is configured.' : 'SMTP is selected but the host is empty.', null, '/admin/email');
        $failedMail = 0;
        try {
            if (Schema::hasTable((new EmailLog)->getTable())) {
                $failedMail = (int) EmailLog::query()->where('status', 'failed')->where('created_at', '>=', now()->subDays(7))->count();
            }
        } catch (Throwable) {
            $failedMail = 0;
        }
        $issues[] = $this->issue('store.mail_failures', 'store', $failedMail > 0 ? 'warning' : 'ok', $failedMail === 0 ? 'No failed emails in the last 7 days' : $failedMail.' emails failed in the last 7 days', 'Check Email logs and SMTP credentials.', null, '/admin/email?tab=logs', $failedMail);
        $issues[] = $this->issue('store.payments', 'store', $payments > 0 ? 'ok' : 'error', $payments > 0 ? $payments.' payment methods enabled' : 'No payment methods enabled', 'Checkout cannot complete without a payment method.', null, '/admin/payments');
        $issues[] = $this->issue('store.shipping', 'store', $shipping > 0 ? 'ok' : 'warning', $shipping > 0 ? $shipping.' shipping methods enabled' : 'No shipping methods enabled', 'Add a method or digital-only shops can ignore this.', null, '/admin/shipping');
        $issues[] = $this->issue('store.stock', 'store', $outOfStock === 0 || ! InventorySettings::enabled() ? 'ok' : 'warning', $outOfStock === 0 ? 'No published products are out of stock' : $outOfStock.' published products are out of stock', InventorySettings::enabled() ? 'Inventory tracking is on.' : 'Inventory tracking is off.', null, '/admin/inventory', $outOfStock);
        $issues[] = $this->issue('store.maintenance', 'store', $maintenance ? 'warning' : 'ok', $maintenance ? 'Maintenance mode is on' : 'Shop is open', 'Shoppers see the maintenance page while this is on.', null, '/admin/advanced?tab=maintenance');

        $brokenApps = 0;
        try {
            if (class_exists(InstalledPlugin::class) && Schema::hasTable('plugins') && Schema::hasColumn('plugins', 'health_status')) {
                $brokenApps = (int) InstalledPlugin::query()->where('health_status', 'error')->count();
            }
        } catch (Throwable) {
            $brokenApps = 0;
        }
        $issues[] = $this->issue('store.apps', 'store', $brokenApps > 0 ? 'error' : 'ok', $brokenApps === 0 ? 'Installed extensions are healthy' : $brokenApps.' extensions reported an error', 'Open Extensions for details.', null, '/admin/extensions', $brokenApps);

        $emptyNames = $this->emptyProductNames($locale);
        $noBrand = $this->productsWithoutBrand();
        $liveNoindex = $this->enabledNoindex($locale);
        $issues[] = $this->issue('content.product_h1', 'content', $emptyNames === 0 ? 'ok' : 'error', $emptyNames === 0 ? 'Enabled products have a name (H1)' : $this->countLabel($emptyNames).' products have no name', 'The product name is the on-page H1. Empty names hide the page from shoppers and search.', null, '/admin/products', $emptyNames);
        $issues[] = $this->issue('content.product_brand', 'content', $noBrand === 0 ? 'ok' : 'warning', $noBrand === 0 ? 'Enabled products have a brand' : $noBrand.' enabled products have no brand', 'Brand pages and breadcrumbs help crawl paths. Assign a brand on the product form.', null, '/admin/products', $noBrand);
        $issues[] = $this->issue('indexing.live_noindex', 'indexing', $liveNoindex === 0 ? 'ok' : 'warning', $liveNoindex === 0 ? 'Enabled products are indexable' : $liveNoindex.' enabled products are noindex', 'A live product with robots noindex will not appear in Google. Open the product SEO tab and set index,follow.', null, '/admin/seo?tab=pages&type=product', $liveNoindex);

        foreach ($this->storefrontAssetIssues() as $row) {
            $issues[] = $row;
        }

        $openOrders = 0;
        try {
            $openOrders = (int) Order::query()->whereIn('status', ['pending', 'processing', 'on_hold'])->count();
        } catch (Throwable) {
            $openOrders = 0;
        }
        $issues[] = $this->issue('store.open_orders', 'store', 'ok', $openOrders.' open orders', 'Pending, processing, and on-hold.', null, '/admin/orders', $openOrders);

        $this->attachSamples($issues, $locale);
        $scores = $this->scores($issues);
        $groups = $this->groupSummaries($issues);

        return [
            'scores' => $scores,
            'groups' => $groups,
            'counts' => [
                'products' => $productCount,
                'enabled_products' => $enabledProducts,
                'categories' => $categoryCount,
                'cms' => $cmsCount,
                'sitemap_urls' => $sitemapUrls,
                'issues' => count(array_filter($issues, fn ($row) => $row['severity'] !== 'ok')),
                'fixes' => count(array_filter($issues, fn ($row) => ! empty($row['fix']))),
            ],
            'sitemap' => [
                'enabled' => $sitemapOn,
                'url' => url('/sitemap.xml'),
                'robots_url' => url('/robots.txt'),
                'feed_url' => url('/feeds/google-merchant.xml'),
                'include_products' => ShopSetting::bool('seo.sitemap_include_products', true),
                'include_categories' => ShopSetting::bool('seo.sitemap_include_categories', true),
                'include_cms' => ShopSetting::bool('seo.sitemap_include_cms', true),
                'include_brands' => ShopSetting::bool('seo.sitemap_include_brands', true),
                'urls' => $sitemapUrls,
            ],
            'issues' => $issues,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function failedReport(string $detail = ''): array
    {
        $message = trim($detail);
        if ($message === '') {
            $message = 'A catalog or SEO query failed. See storage/logs/laravel.log. A missing column such as products.cover_path will do this until migrate has run.';
        }

        $issues = [
            $this->issue(
                'audit.error',
                'store',
                'error',
                'Store health could not finish its checks',
                $message,
                null,
                '/admin/health'
            ),
        ];

        return [
            'scores' => $this->scores($issues),
            'groups' => $this->groupSummaries($issues),
            'counts' => [
                'products' => 0,
                'enabled_products' => 0,
                'categories' => 0,
                'cms' => 0,
                'sitemap_urls' => 0,
                'issues' => 1,
                'fixes' => 0,
            ],
            'sitemap' => [
                'enabled' => false,
                'url' => url('/sitemap.xml'),
                'robots_url' => url('/robots.txt'),
                'feed_url' => url('/feeds/google-merchant.xml'),
                'include_products' => true,
                'include_categories' => true,
                'include_cms' => true,
                'include_brands' => true,
                'urls' => 0,
            ],
            'issues' => $issues,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return array{overall: int, seo: int, content: int, indexing: int, store: int}
     */
    protected function scores(array $issues): array
    {
        $groups = ['seo' => [], 'content' => [], 'indexing' => [], 'store' => []];
        foreach ($issues as $issue) {
            $area = $issue['area'] === 'seo' || isset($groups[$issue['area']]) ? $issue['area'] : 'store';
            $groups[$area][] = $issue['severity'];
        }
        $out = [];
        foreach ($groups as $area => $severities) {
            $out[$area] = $this->scoreFor($severities);
        }
        $out['overall'] = (int) round(array_sum($out) / max(1, count($out)));

        return $out;
    }

    /**
     * @param  list<string>  $severities
     */
    protected function scoreFor(array $severities): int
    {
        if ($severities === []) {
            return 100;
        }
        $points = 0;
        foreach ($severities as $severity) {
            $points += match ($severity) {
                'ok' => 100,
                'warning' => 55,
                default => 15,
            };
        }

        return (int) round($points / count($severities));
    }

    /**
     * @return array<string, mixed>
     */
    protected function issue(string $id, string $area, string $severity, string $title, string $detail, ?string $fix, string $href, int $count = 0): array
    {
        return [
            'id' => $id,
            'area' => $area,
            'group' => $this->groupFor($id, $area),
            'severity' => $severity,
            'title' => $title,
            'detail' => $detail,
            'why' => $detail,
            'how' => $fix
                ? 'Click Fix to write this shop’s catalog or settings. Every write lands in History and can be rolled back.'
                : 'Open the linked admin screen and enter the real value. Store health cannot invent tracking IDs, TLS certificates, or SMTP passwords.',
            'impact' => $this->impactFor($severity, $count),
            'samples' => [],
            'fix' => $fix,
            'href' => $href,
            'count' => $count,
        ];
    }

    protected function groupFor(string $id, string $area): string
    {
        if (str_contains($id, 'og_image') || $id === 'seo.analytics' || $id === 'seo.consent' || $id === 'seo.twitter') {
            return 'social';
        }
        if (str_contains($id, 'webp') || str_contains($id, 'image') || str_contains($id, 'alt') || str_contains($id, 'cover') || str_contains($id, 'favicon') || str_contains($id, 'logo')) {
            return 'images';
        }
        if ($area === 'indexing' || str_starts_with($id, 'tech.') || str_starts_with($id, 'sitemap') || str_starts_with($id, 'robots') || $id === 'seo.https' || $id === 'seo.hreflang' || $id === 'store.debug' || $id === 'store.cache') {
            return 'technical';
        }
        if ($area === 'store') {
            return 'store';
        }

        return 'onpage';
    }

    protected function impactFor(string $severity, int $count): string
    {
        if ($severity === 'ok') {
            return 'Passing.';
        }
        $rows = $count > 0 ? $this->countLabel($count).' records. ' : '';
        if ($severity === 'error') {
            return $rows.'This blocks indexing or checkout until it is fixed.';
        }

        return $rows.'This weakens rankings or social snippets until it is fixed.';
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return list<array<string, mixed>>
     */
    protected function groupSummaries(array $issues): array
    {
        $catalog = [
            'onpage' => ['title' => 'On-page SEO', 'hint' => 'Meta titles, descriptions, keywords, headings, canonicals, and product copy.'],
            'images' => ['title' => 'Images', 'hint' => 'Cover photos, alt text, WebP, sized derivatives, logo, and favicon.'],
            'technical' => ['title' => 'Technical', 'hint' => 'Sitemap, robots, HTTPS, JS/CSS build, viewport, language, and cache.'],
            'social' => ['title' => 'Social & tracking', 'hint' => 'Open Graph, Twitter cards, Analytics, and cookie consent.'],
            'store' => ['title' => 'Store ops', 'hint' => 'Payments, shipping, mail, stock, extensions, and maintenance.'],
        ];
        $out = [];
        foreach ($catalog as $id => $meta) {
            $rows = array_values(array_filter($issues, fn ($row) => ($row['group'] ?? '') === $id));
            $open = array_values(array_filter($rows, fn ($row) => ($row['severity'] ?? 'ok') !== 'ok'));
            $out[] = [
                'id' => $id,
                'title' => $meta['title'],
                'hint' => $meta['hint'],
                'open' => count($open),
                'checks' => count($rows),
                'score' => $this->scoreFor(array_map(fn ($row) => (string) ($row['severity'] ?? 'ok'), $rows)),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     */
    protected function attachSamples(array &$issues, string $locale): void
    {
        foreach ($issues as &$issue) {
            if (($issue['severity'] ?? 'ok') === 'ok' || (int) ($issue['count'] ?? 0) === 0) {
                continue;
            }
            $issue['samples'] = match ($issue['id'] ?? '') {
                'content.product_titles' => $this->sampleMissingMeta($locale, 'meta_title'),
                'content.product_descriptions' => $this->sampleMissingMeta($locale, 'meta_description'),
                'content.product_keywords' => $this->sampleMissingMeta($locale, 'meta_keywords'),
                'content.product_focus' => $this->sampleMissingMeta($locale, 'focus_keyword'),
                'content.product_h1' => $this->sampleMissingMeta($locale, 'name'),
                'content.thin' => $this->sampleThinProducts($locale),
                'content.covers' => $this->sampleNoCover(),
                'content.alt' => $this->sampleMissingAlts(),
                'content.category' => $this->sampleNoCategory(),
                default => [],
            };
        }
        unset($issue);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function storefrontAssetIssues(): array
    {
        $issues = [];
        $hasBuild = is_file(public_path('build/manifest.json')) || is_file(public_path('build/.vite/manifest.json'));
        $issues[] = $this->issue(
            'tech.js_css',
            'indexing',
            $hasBuild ? 'ok' : 'error',
            $hasBuild ? 'Storefront JS and CSS build is present' : 'Storefront JS/CSS build is missing',
            'The shop serves Vite assets from public/build. Run npm run build if the storefront is unstyled.',
            null,
            '/admin/performance'
        );

        $blade = resource_path('views/storefront.blade.php');
        $html = is_file($blade) ? (string) file_get_contents($blade) : '';
        $viewport = str_contains($html, 'name="viewport"');
        $issues[] = $this->issue('tech.viewport', 'indexing', $viewport ? 'ok' : 'error', $viewport ? 'Viewport meta tag is present' : 'Viewport meta tag is missing', 'Mobile SERP and Core Web Vitals need width=device-width.', null, '/admin/themes');
        $charset = str_contains($html, 'charset="utf-8"') || str_contains($html, "charset='utf-8'");
        $issues[] = $this->issue('tech.charset', 'indexing', $charset ? 'ok' : 'warning', $charset ? 'HTML charset is UTF-8' : 'HTML charset is not UTF-8', 'Search engines expect UTF-8 on every storefront document.', null, '/admin/themes');
        $lang = str_contains($html, 'lang=');
        $issues[] = $this->issue('tech.html_lang', 'indexing', $lang ? 'ok' : 'warning', $lang ? 'HTML lang attribute is set' : 'HTML lang attribute is missing', 'Language tags help hreflang and accessibility.', null, '/admin/localization');
        $twitter = str_contains($html, 'twitter:card');
        $issues[] = $this->issue('seo.twitter', 'seo', $twitter ? 'ok' : 'warning', $twitter ? 'Twitter Card tags are emitted' : 'Twitter Card tags are missing', 'Storefront shares og:image as twitter:image when a fallback image is set.', null, '/admin/seo?tab=settings');
        $jsonLd = str_contains($html, 'application/ld+json');
        $issues[] = $this->issue('tech.jsonld', 'seo', $jsonLd ? 'ok' : 'warning', $jsonLd ? 'JSON-LD structured data is emitted' : 'JSON-LD structured data is missing', 'Product and shop schema help rich results. StorefrontController fills this from catalog rows.', null, '/admin/seo');

        $favicon = is_file(public_path('favicon.ico')) || is_file(public_path('favicon.svg')) || str_contains($html, 'rel="icon"');
        $issues[] = $this->issue('tech.favicon', 'seo', $favicon ? 'ok' : 'warning', $favicon ? 'Favicon is present' : 'No favicon.ico in public/', 'Browsers and Google SERP show a favicon. Add public/favicon.ico or a theme icon link.', null, '/admin/themes');

        $logo = '';
        try {
            $logo = trim((string) ShopSetting::get('theme.logo_url', ''));
        } catch (Throwable) {
        }
        if ($logo === '') {
            $logo = '/themes/classic/assets/merchant/ui/logo.png';
        }
        $logoFile = public_path(ltrim(parse_url($logo, PHP_URL_PATH) ?: $logo, '/'));
        $logoOk = str_starts_with($logo, 'http')
            || is_file($logoFile)
            || is_file(base_path('themes/classic/assets/merchant/ui/logo.png'))
            || is_file(base_path('themes/classic/assets/merchant/ui/logo.svg'));
        $issues[] = $this->issue('tech.logo', 'seo', $logoOk ? 'ok' : 'warning', $logoOk ? 'Theme logo file is present' : 'Theme logo file is missing', 'Header branding uses the Classic theme logo. Replace it under Design → Themes.', null, '/admin/themes');

        $css = is_file(base_path('themes/classic/assets/header.css')) || is_file(public_path('themes/classic/assets/header.css'));
        $issues[] = $this->issue('tech.theme_css', 'indexing', $css ? 'ok' : 'warning', $css ? 'Theme CSS is installed' : 'Theme CSS file is missing', 'Classic ships assets/header.css. A missing file means the storefront header has no extra styles.', null, '/admin/themes');

        $fonts = str_contains($html, 'fonts.googleapis.com');
        $issues[] = $this->issue('tech.google_fonts', 'indexing', $fonts ? 'warning' : 'ok', $fonts ? 'Storefront loads Google Fonts' : 'No third-party font CSS', 'External font CSS can delay first paint. Classic uses Google Fonts by default; a child theme can self-host.', null, '/admin/themes');

        return $issues;
    }

    protected function emptyProductNames(string $locale): int
    {
        return $this->missingMetaCount('product_translations', 'product_id', 'products', $locale, 'name', true);
    }

    protected function productsWithoutBrand(): int
    {
        try {
            if (! Schema::hasColumn('products', 'brand_id')) {
                return 0;
            }

            return $this->cappedCount(Product::query()->where('is_enabled', true)->where(function ($q) {
                $q->whereNull('brand_id')->orWhere('brand_id', 0);
            }));
        } catch (Throwable) {
            return 0;
        }
    }

    protected function enabledNoindex(string $locale): int
    {
        try {
            if (! Schema::hasColumn('product_translations', 'robots')) {
                return 0;
            }

            return $this->cappedCount(
                DB::table('products')
                    ->join('product_translations', 'product_translations.product_id', '=', 'products.id')
                    ->where('products.is_enabled', true)
                    ->where('product_translations.language_code', $locale)
                    ->where('product_translations.robots', 'like', '%noindex%')
            );
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return list<string>
     */
    protected function sampleMissingMeta(string $locale, string $column): array
    {
        try {
            if (! Schema::hasColumn('product_translations', $column)) {
                return [];
            }
            return DB::table('product_translations')
                ->join('products', 'products.id', '=', 'product_translations.product_id')
                ->where('products.is_enabled', true)
                ->where('product_translations.language_code', $locale)
                ->where(function ($q) use ($column) {
                    $q->whereNull('product_translations.'.$column)->orWhere('product_translations.'.$column, '');
                })
                ->limit(8)
                ->pluck('product_translations.name')
                ->map(fn ($name) => trim((string) $name) !== '' ? (string) $name : 'Untitled')
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    protected function sampleThinProducts(string $locale): array
    {
        try {
            return DB::table('products')
                ->join('product_translations', 'product_translations.product_id', '=', 'products.id')
                ->where('products.is_enabled', true)
                ->where('product_translations.language_code', $locale)
                ->where(function ($q) {
                    $q->whereNull('product_translations.description')
                        ->orWhere('product_translations.description', '')
                        ->orWhereRaw($this->lengthExpr('product_translations.description').' < 80');
                })
                ->limit(8)
                ->pluck('product_translations.name')
                ->map(fn ($name) => (string) $name)
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    protected function sampleNoCover(): array
    {
        try {
            if (! Schema::hasColumn('products', 'cover_path')) {
                return [];
            }

            return Product::query()
                ->where('is_enabled', true)
                ->where(function ($q) {
                    $q->whereNull('cover_path')->orWhere('cover_path', '');
                })
                ->limit(8)
                ->get()
                ->map(fn (Product $p) => (string) ($p->translation()?->name ?: $p->sku))
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    protected function sampleMissingAlts(): array
    {
        try {
            if (! Schema::hasTable('product_images')) {
                return [];
            }

            return ProductImage::query()
                ->where(function ($q) {
                    $q->whereNull('alt')->orWhere('alt', '');
                })
                ->limit(8)
                ->pluck('path')
                ->map(fn ($path) => basename((string) $path))
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    protected function sampleNoCategory(): array
    {
        try {
            return Product::query()
                ->where('is_enabled', true)
                ->whereDoesntHave('categories')
                ->limit(8)
                ->get()
                ->map(fn (Product $p) => (string) ($p->translation()?->name ?: $p->sku))
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    protected function missingMetaCount(string $translationTable, string $fk, string $ownerTable, string $locale, string $column, bool $enabledOnly): int
    {
        if (! Schema::hasTable($translationTable) || ! Schema::hasColumn($translationTable, $column)) {
            return 0;
        }
        $query = DB::table($translationTable)
            ->join($ownerTable, $ownerTable.'.id', '=', $translationTable.'.'.$fk)
            ->where($translationTable.'.language_code', $locale)
            ->where(function ($q) use ($translationTable, $column) {
                $q->whereNull($translationTable.'.'.$column)->orWhere($translationTable.'.'.$column, '');
            });
        if ($enabledOnly && Schema::hasColumn($ownerTable, 'is_enabled')) {
            $query->where($ownerTable.'.is_enabled', true);
        }

        return $this->cappedCount($query);
    }

    protected function longMetaCount(string $table, string $column, int $max, string $locale): int
    {
        try {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                return 0;
            }

            $query = DB::table($table)
                ->where('language_code', $locale)
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->whereRaw($this->lengthExpr($table.'.'.$column).' > ?', [$max]);

            return $this->cappedCount($query);
        } catch (Throwable) {
            return 0;
        }
    }

    protected function shortMetaCount(string $table, string $column, int $min, string $locale): int
    {
        try {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                return 0;
            }

            $query = DB::table($table)
                ->join('products', 'products.id', '=', $table.'.product_id')
                ->where('products.is_enabled', true)
                ->where($table.'.language_code', $locale)
                ->whereNotNull($table.'.'.$column)
                ->where($table.'.'.$column, '!=', '')
                ->whereRaw($this->lengthExpr($table.'.'.$column).' < ?', [$min]);

            return $this->cappedCount($query);
        } catch (Throwable) {
            return 0;
        }
    }

    protected function productsWithoutCategory(): int
    {
        if (! Schema::hasTable('category_product')) {
            return 0;
        }

        return $this->cappedCount(
            Product::query()
                ->where('is_enabled', true)
                ->whereDoesntHave('categories')
        );
    }

    protected function disabledStillIndexed(string $locale): int
    {
        if (! Schema::hasColumn('product_translations', 'robots')) {
            return $this->cappedCount(Product::query()->where('is_enabled', false));
        }

        return $this->cappedCount(
            DB::table('products')
                ->join('product_translations', 'product_translations.product_id', '=', 'products.id')
                ->where('products.is_enabled', false)
                ->where('product_translations.language_code', $locale)
                ->where(function ($q) {
                    $q->whereNull('product_translations.robots')
                        ->orWhere('product_translations.robots', '')
                        ->orWhere('product_translations.robots', 'like', 'index%');
                })
        );
    }

    protected function missingCmsMeta(string $locale, string $column): int
    {
        if (! Schema::hasTable('cms_page_translations') || ! Schema::hasColumn('cms_page_translations', $column)) {
            return 0;
        }

        return $this->cappedCount(
            DB::table('cms_pages')
                ->join('cms_page_translations', 'cms_page_translations.cms_page_id', '=', 'cms_pages.id')
                ->where('cms_page_translations.language_code', $locale)
                ->where('cms_pages.is_enabled', true)
                ->where(function ($q) use ($column) {
                    $q->whereNull('cms_page_translations.'.$column)->orWhere('cms_page_translations.'.$column, '');
                })
        );
    }

    protected function thinProductCount(string $locale): int
    {
        try {
            if (! Schema::hasTable('product_translations')) {
                return 0;
            }

            return $this->cappedCount(
                DB::table('products')
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
                    })
            );
        } catch (Throwable) {
            return 0;
        }
    }

    protected function missingCanonicalCount(string $locale): int
    {
        try {
            if (! Schema::hasColumn('product_translations', 'canonical_url')) {
                return 0;
            }

            return $this->cappedCount(
                DB::table('product_translations')
                    ->join('products', 'products.id', '=', 'product_translations.product_id')
                    ->where('products.is_enabled', true)
                    ->where('product_translations.language_code', $locale)
                    ->where(function ($q) {
                        $q->whereNull('product_translations.canonical_url')
                            ->orWhere('product_translations.canonical_url', '');
                    })
            );
        } catch (Throwable) {
            return 0;
        }
    }

    protected function titlesMissingFocus(string $locale): int
    {
        try {
            if (! Schema::hasColumn('product_translations', 'focus_keyword') || ! Schema::hasColumn('product_translations', 'meta_title')) {
                return 0;
            }
            $like = DB::getDriverName() === 'sqlite'
                ? "LOWER(product_translations.meta_title) NOT LIKE '%' || LOWER(product_translations.focus_keyword) || '%'"
                : "LOWER(product_translations.meta_title) NOT LIKE CONCAT('%', LOWER(product_translations.focus_keyword), '%')";

            return $this->cappedCount(
                DB::table('product_translations')
                    ->join('products', 'products.id', '=', 'product_translations.product_id')
                    ->where('products.is_enabled', true)
                    ->where('product_translations.language_code', $locale)
                    ->whereNotNull('product_translations.focus_keyword')
                    ->where('product_translations.focus_keyword', '!=', '')
                    ->whereNotNull('product_translations.meta_title')
                    ->where('product_translations.meta_title', '!=', '')
                    ->whereRaw($like)
            );
        } catch (Throwable) {
            return 0;
        }
    }

    protected function duplicateMeta(string $locale): int
    {
        if (! Schema::hasTable('product_translations')) {
            return 0;
        }
        $row = DB::table('product_translations')
            ->selectRaw('COUNT(*) as extra')
            ->where('language_code', $locale)
            ->whereNotNull('meta_title')
            ->where('meta_title', '!=', '')
            ->groupBy('meta_title')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        return (int) $row->sum(fn ($item) => ((int) $item->extra) - 1);
    }

    protected function cappedCount($query, int $cap = 5001): int
    {
        $base = $query instanceof Builder ? $query->toBase() : $query;

        return (int) DB::query()
            ->fromSub($base->clone()->selectRaw('1')->limit($cap), 'seo_cap')
            ->count();
    }

    protected function countLabel(int $count): string
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
