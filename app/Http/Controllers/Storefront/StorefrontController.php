<?php

namespace App\Http\Controllers\Storefront;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\UrlRewrite;
use App\Domain\Catalog\Services\ImageOptimizer;
use App\Domain\Catalog\Services\UrlResolver;
use App\Domain\Catalog\Services\UrlRewriteService;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Localization\Models\Language;
use App\Domain\Localization\Money;
use App\Domain\Localization\UiI18n;
use App\Domain\Seo\StorefrontHreflang;
use App\Domain\Seo\StorefrontSeoPolicy;
use App\Domain\Seo\StorefrontStructuredData;
use App\Domain\Settings\ProductSettings;
use App\Domain\Settings\ShopSetting;
use App\Domain\Settings\ShopTools;
use App\Domain\Store\StoreContext;
use App\Domain\Theme\ThemeResolver;
use App\Http\Controllers\Controller;
use App\Support\WebBase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Throwable;

class StorefrontController extends Controller
{
    public function __invoke(Request $request, UrlResolver $resolver): View|RedirectResponse|Response
    {
        $path = trim($request->path(), '/');
        // Path-locale mode: first segment may already have been stripped by SetLocale into attributes.
        if ($request->attributes->has('wento_path_locale_stripped')) {
            $path = trim((string) $request->attributes->get('wento_catalog_path', $path), '/');
        } elseif ($this->localePathMode() && $request->isMethod('GET')) {
            // Force /{locale}/... URLs so crawlers and React basename stay aligned.
            $locale = app()->getLocale();
            $target = $path === '' ? $locale : $locale.'/'.$path;
            $qs = $request->getQueryString();

            return redirect('/'.$target.($qs ? '?'.$qs : ''), 301);
        }

        $locale = app()->getLocale();
        $shopName = $this->shopName();
        $seo = $this->baseSeo($request, $shopName);
        $status = 200;
        $rewrite = null;

        if ($path === '') {
            $seo['hreflang'] = StorefrontHreflang::forHome();
        } elseif (StorefrontSeoPolicy::isUtilityPath($path)) {
            $seo = $this->utilitySeo($request, $shopName, $path);
        } else {
            try {
                $resolved = $resolver->resolve($path);
                if (! empty($resolved['redirect_to']) && $resolved['redirect_to'] !== $path) {
                    $target = ltrim((string) $resolved['redirect_to'], '/');
                    if ($this->localePathMode()) {
                        $target = $this->withLocalePrefix($target, $locale);
                    }

                    return redirect('/'.$target, 301);
                }
                $rewrite = $resolved['rewrite'] ?? null;
                if ($rewrite && StorefrontSeoPolicy::isReservedShellPath($path)) {
                    $rewrite = null;
                }
                if ($rewrite) {
                    $entitySeo = $this->seoFor($rewrite, $this->canonicalUrl($request, $path));
                    if ($entitySeo === null) {
                        $status = 404;
                        $seo = $this->notFoundSeo($request, $shopName);
                    } elseif (! empty($entitySeo['_gone'])) {
                        unset($entitySeo['_gone']);
                        $status = 410;
                        $seo = $entitySeo;
                        $seo['robots'] = 'noindex,nofollow';
                    } else {
                        $seo = $entitySeo;
                        if (StorefrontSeoPolicy::hasFacetOrPagingQuery($request)) {
                            $seo['robots'] = 'noindex,follow';
                            $seo['canonical'] = $this->canonicalUrl($request, $path);
                        }
                    }
                } elseif (! StorefrontSeoPolicy::isShellPath($path)) {
                    $status = 404;
                    $seo = $this->notFoundSeo($request, $shopName);
                } else {
                    $seo = $this->shellSeo($request, $shopName, $path, $seo);
                }
            } catch (Throwable) {
                // Prefer a hard 404 for unknown paths over a soft-200 indexable shell.
                if ($path !== '' && ! StorefrontSeoPolicy::isShellPath($path) && ! StorefrontSeoPolicy::isUtilityPath($path)) {
                    $status = 404;
                    $seo = $this->notFoundSeo($request, $shopName);
                }
            }
        }

        $i18n = [
            'locale' => $locale,
            'languages' => [],
            'messages' => [],
        ];
        $i18nScriptUrl = null;
        try {
            $i18n = [
                'locale' => $locale,
                'current' => $locale,
                'default' => Language::defaultCode(),
                'languages' => Language::enabledList()->map(fn (Language $language) => [
                    'code' => $language->code,
                    'name' => $language->name,
                    'is_default' => $language->is_default,
                ])->values()->all(),
                // Messages load via a cacheable public script (see $i18nScriptUrl) so HTML stays small.
                'messages' => [],
            ];
            $i18nScriptUrl = UiI18n::messagesScriptUrl($locale, 'storefront');
        } catch (Throwable) {
            // UI strings table may not exist during early install.
        }

        $theme = [];
        try {
            $theme = app(ThemeResolver::class)->bootPayload(app(StoreContext::class)->store());
        } catch (Throwable) {
            $theme = [
                'name' => 'classic',
                'engine' => 'react-vite',
                'settings' => [],
                'css' => [],
            ];
        }

        $currency = (string) config('wento.default_currency');
        try {
            $currency = Money::currentCode();
        } catch (Throwable) {
            $currency = (string) config('wento.default_currency');
        }

        $view = ($theme['engine'] ?? 'react-vite') === 'spa-dist' ? 'storefront-theme' : 'storefront';
        $localePrefix = $this->localePathMode() ? '/'.$locale : '';

        return response()->view($view, [
            'seo' => $seo,
            'theme' => $theme,
            'httpStatus' => $status,
            'i18nScriptUrl' => $i18nScriptUrl,
            'boot' => [
                'locale' => $locale,
                'default_locale' => $i18n['default'] ?? $locale,
                'locale_url_mode' => $this->localePathMode() ? 'path' : 'cookie',
                'locale_prefix' => $localePrefix,
                'base_path' => WebBase::path(),
                'asset_base' => WebBase::installPath(),
                'currency' => $currency,
                'shop' => $shopName,
                'path' => '/'.$path,
                'not_found' => in_array($status, [404, 410], true),
                'hreflang' => $seo['hreflang'] ?? [],
                'seo' => [
                    'title' => $seo['title'] ?? $shopName,
                    'description' => $seo['description'] ?? '',
                    'canonical' => $seo['canonical'] ?? $request->url(),
                    'robots' => $seo['robots'] ?? ShopTools::defaultRobots(),
                    'og_image' => $seo['og_image'] ?? null,
                    'og_type' => $seo['og_type'] ?? 'website',
                    'json_ld' => $seo['json_ld'] ?? null,
                ],
                'i18n' => $i18n,
                'theme' => $theme,
            ],
        ], $status);
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseSeo(Request $request, string $shopName): array
    {
        $description = '';
        $ogPath = '';
        try {
            $description = (string) ShopSetting::get('seo.default_description', '');
            $ogPath = (string) ShopSetting::get('seo.og_image', '');
        } catch (Throwable) {
            // Settings table / DB may be offline.
        }
        $og = $this->absoluteMedia($ogPath, $request);

        return [
            'title' => $shopName,
            'description' => $description !== '' ? $description : $shopName,
            'canonical' => $request->url(),
            'robots' => ShopTools::defaultRobots(),
            'og_image' => $og,
            'og_type' => 'website',
            'twitter_card' => $og ? 'summary_large_image' : 'summary',
            'keywords' => '',
            'hreflang' => [],
            'crawl_html' => '',
            'json_ld' => StorefrontStructuredData::websiteGraph($shopName, $og),
        ];
    }

    /**
     * React shell routes without a rewrite: try CMS aliases, otherwise noindex thin pages.
     *
     * @param  array<string, mixed>  $seo
     * @return array<string, mixed>
     */
    protected function shellSeo(Request $request, string $shopName, string $path, array $seo): array
    {
        $fromCms = false;
        if (StorefrontSeoPolicy::isCmsAliasPath($path)) {
            $locale = app()->getLocale();
            $page = CmsPage::query()->public()
                ->whereHas('translations', fn ($t) => $t->where('slug', $path)->where('language_code', $locale))
                ->with('translations')
                ->first()
                ?: CmsPage::query()->public()
                    ->whereHas('translations', fn ($t) => $t->where('slug', $path))
                    ->with('translations')
                    ->first();
            if ($page) {
                $cms = $this->cmsSeo((int) $page->id, $locale, $this->canonicalUrl($request, $path));
                if ($cms) {
                    $seo = $cms;
                    $fromCms = true;
                }
            }
        }

        $root = StorefrontSeoPolicy::root($path);
        if (in_array($root, ['help', 'faq'], true)) {
            $faqLd = StorefrontStructuredData::faqPage($this->defaultHelpFaqs());
            if ($faqLd) {
                if ($fromCms && is_array($seo['json_ld'] ?? null)) {
                    $seo['json_ld'] = [
                        '@context' => 'https://schema.org',
                        '@graph' => [
                            array_diff_key($seo['json_ld'], ['@context' => true]),
                            array_diff_key($faqLd, ['@context' => true]),
                        ],
                    ];
                } else {
                    $seo['json_ld'] = $faqLd;
                }
            }
        }

        if (! $fromCms && (StorefrontSeoPolicy::isThinShellPath($path) || StorefrontSeoPolicy::isCmsAliasPath($path))) {
            $seo['robots'] = 'noindex,follow';
            $seo['title'] = ucwords(str_replace('-', ' ', StorefrontSeoPolicy::root($path))).' | '.$shopName;
            $seo['crawl_html'] = $seo['crawl_html'] ?? '';
        }

        if (StorefrontSeoPolicy::hasFacetOrPagingQuery($request)) {
            $seo['robots'] = 'noindex,follow';
            $seo['canonical'] = $this->canonicalUrl($request, $path);
        }

        return $seo;
    }

    /**
     * @return array<string, mixed>
     */
    protected function utilitySeo(Request $request, string $shopName, string $path): array
    {
        $label = StorefrontSeoPolicy::root($path) ?: 'page';

        return [
            'title' => ucwords(str_replace('-', ' ', $label)).' | '.$shopName,
            'description' => $shopName,
            'canonical' => $request->url(),
            'robots' => 'noindex,nofollow',
            'og_image' => null,
            'og_type' => 'website',
            'twitter_card' => 'summary',
            'keywords' => '',
            'hreflang' => [],
            'crawl_html' => '',
            'json_ld' => [
                '@context' => 'https://schema.org',
                '@type' => 'WebPage',
                'name' => $label,
                'url' => $request->url(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function notFoundSeo(Request $request, string $shopName): array
    {
        return [
            'title' => 'Page not found | '.$shopName,
            'description' => 'The requested page could not be found.',
            'canonical' => $request->url(),
            'robots' => 'noindex,nofollow',
            'og_image' => null,
            'og_type' => 'website',
            'twitter_card' => 'summary',
            'keywords' => '',
            'hreflang' => [],
            'crawl_html' => '<h1>Page not found</h1><p>The requested page could not be found.</p>',
            'json_ld' => [
                '@context' => 'https://schema.org',
                '@type' => 'WebPage',
                'name' => 'Page not found',
            ],
        ];
    }

    protected function seoFor(UrlRewrite $rewrite, string $url): ?array
    {
        $locale = $rewrite->language_code;

        $row = match ($rewrite->entity_type) {
            'product' => $this->productSeo($rewrite->entity_id, $locale, $url),
            'category' => $this->categorySeo($rewrite->entity_id, $locale, $url),
            'brand' => $this->brandSeo($rewrite->entity_id, $locale, $url),
            'cms' => $this->cmsSeo($rewrite->entity_id, $locale, $url),
            default => null,
        };

        if ($row && empty($row['_gone'])) {
            $row['hreflang'] = StorefrontHreflang::forEntity($rewrite->entity_type, (int) $rewrite->entity_id);
        }

        return $row;
    }

    protected function seoTranslation(object $model, string $locale): mixed
    {
        return $model->translation($locale)
            ?: $model->translation(Language::defaultCode())
            ?: $model->translations->first();
    }

    protected function productSeo(int $id, string $locale, string $url): ?array
    {
        $product = Product::query()->with(['translations', 'images'])->find($id);
        $t = $product ? $this->seoTranslation($product, $locale) : null;
        if (! $t) {
            return null;
        }

        if (! $product->is_enabled || ProductSettings::hiddenFromStorefront($product)) {
            return [
                '_gone' => true,
                'title' => $this->productTitle((string) $t->name, $t->meta_title),
                'description' => (string) ($t->meta_description ?: $t->short_description ?: ''),
                'canonical' => $t->canonical_url ?: $url,
                'robots' => 'noindex,nofollow',
                'og_image' => null,
                'og_type' => 'product',
                'twitter_card' => 'summary',
                'keywords' => '',
                'hreflang' => [],
                'crawl_html' => '',
                'json_ld' => ['@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => $t->name],
            ];
        }

        $description = (string) ($t->meta_description ?: '');
        if ($description === '' && ShopSetting::bool('seo.use_short_description_fallback', true)) {
            $description = (string) ($t->short_description ?: '');
        }
        if ($description === '') {
            $description = (string) ShopSetting::get('seo.default_description', '');
        }
        $og = $this->absoluteMedia(
            $product->cover_path ?: (string) ShopSetting::get('seo.og_image', ''),
            request(),
            'large'
        );

        $product->loadMissing([
            'brand.translations',
            'categories.translations',
            'categories.parent.translations',
            'reviews' => fn ($q) => $q->where('is_approved', true)->with('customer')->latest()->limit(5),
        ]);
        $canonicalPath = filled($t->canonical_url) ? (string) $t->canonical_url : (string) (parse_url($url, PHP_URL_PATH) ?: $url);
        if (str_starts_with($canonicalPath, 'http://') || str_starts_with($canonicalPath, 'https://')) {
            $canonical = $canonicalPath;
        } else {
            $canonical = StorefrontStructuredData::publicUrl($canonicalPath, $locale);
        }
        $urls = app(UrlRewriteService::class);
        $trail = StorefrontStructuredData::categoryTrail(
            $product,
            $locale,
            fn (Category $category) => '/'.ltrim((string) ($urls->pathFor('category', $category->id, $locale) ?: $category->translation($locale)?->slug), '/')
        );

        $ratingAvg = null;
        $ratingCount = 0;
        try {
            if (\App\Domain\Settings\ReviewSettings::enabled()) {
                $ratingAvg = $product->reviews()->where('is_approved', true)->avg('rating');
                $ratingCount = (int) $product->reviews()->where('is_approved', true)->count();
                $ratingAvg = $ratingAvg !== null ? (float) $ratingAvg : null;
            }
        } catch (Throwable) {
            $ratingAvg = null;
            $ratingCount = 0;
        }
        $reviewRows = $product->relationLoaded('reviews')
            ? $product->reviews->map(fn ($review) => [
                'author' => $review->customer?->displayName() ?: 'Customer',
                'rating' => (int) $review->rating,
                'body' => (string) ($review->body ?: ''),
                'date' => $review->created_at?->toDateString(),
            ])->values()->all()
            : [];

        $body = strip_tags((string) ($t->description ?: $t->short_description ?: $description));

        return [
            'title' => $this->productTitle((string) $t->name, $t->meta_title),
            'description' => $description,
            'canonical' => $canonical,
            'robots' => $t->robots ?: ShopTools::defaultRobots(),
            'og_image' => $og,
            'og_type' => 'product',
            'twitter_card' => $og ? 'summary_large_image' : 'summary',
            'keywords' => (string) ($t->meta_keywords ?: ''),
            'crawl_html' => $this->productCrawlBlock(
                $product,
                (string) $t->name,
                $description,
                $body,
                $canonical,
                $og,
                $trail,
                $ratingAvg,
                $ratingCount
            ),
            'json_ld' => StorefrontStructuredData::productGraph(
                $product,
                (string) $t->name,
                $description,
                $canonical,
                $og,
                $trail,
                $ratingCount > 0 ? $ratingAvg : null,
                $ratingCount,
                $reviewRows,
            ),
        ];
    }

    protected function categorySeo(int $id, string $locale, string $url): ?array
    {
        $category = Category::query()->with('translations')->find($id);
        $t = $category ? $this->seoTranslation($category, $locale) : null;
        if (! $t) {
            return null;
        }
        if ($category && ! $category->is_enabled) {
            return null;
        }

        $description = $t->meta_description ?: $t->description ?: (string) ShopSetting::get('seo.default_description', '');
        $canonical = filled($t->canonical_url) ? (string) $t->canonical_url : $url;
        $body = strip_tags((string) ($t->description ?: $description));
        $og = $this->absoluteMedia(
            $category->cover_path ?: $category->banner_path ?: (string) ShopSetting::get('seo.og_image', ''),
            request()
        );

        return [
            'title' => $this->title((string) $t->name, $t->meta_title),
            'description' => $description,
            'canonical' => $canonical,
            'robots' => ShopTools::defaultRobots(),
            'og_image' => $og,
            'og_type' => 'website',
            'twitter_card' => $og ? 'summary_large_image' : 'summary',
            'keywords' => (string) ($t->meta_keywords ?: ''),
            'crawl_html' => $this->categoryCrawlBlock($category, (string) $t->name, (string) $description, $body, $canonical, $locale),
            'json_ld' => StorefrontStructuredData::collectionPage((string) $t->name, $canonical, (string) $description),
        ];
    }

    protected function brandSeo(int $id, string $locale, string $url): ?array
    {
        $brand = Brand::query()->with('translations')->find($id);
        $t = $brand ? $this->seoTranslation($brand, $locale) : null;
        if (! $t) {
            return null;
        }
        if ($brand && ! $brand->is_enabled) {
            return null;
        }

        $description = $t->meta_description ?: $t->description ?: (string) ShopSetting::get('seo.default_description', '');
        $body = strip_tags((string) ($t->description ?: $description));
        $og = StorefrontStructuredData::brandOgImage($brand);

        return [
            'title' => $this->title((string) $t->name, $t->meta_title),
            'description' => $description,
            'canonical' => $url,
            'robots' => ShopTools::defaultRobots(),
            'og_image' => $og,
            'og_type' => 'website',
            'twitter_card' => $og ? 'summary_large_image' : 'summary',
            'keywords' => (string) ($t->meta_keywords ?: ''),
            'crawl_html' => $this->brandCrawlBlock($brand, (string) $t->name, (string) $description, $body, $url, $locale),
            'json_ld' => StorefrontStructuredData::brandNode((string) $t->name, $url, $og),
        ];
    }

    protected function cmsSeo(int $id, string $locale, string $url): ?array
    {
        $page = CmsPage::query()->public()->with('translations')->find($id);
        $t = $page ? $this->seoTranslation($page, $locale) : null;
        if (! $t) {
            return null;
        }

        $description = $t->meta_description ?: $t->short_description ?: (string) ShopSetting::get('seo.default_description', '');
        $body = strip_tags((string) ($t->body ?: $t->short_description ?: $description));
        $canonical = $page->canonical_url ?: $url;
        $og = StorefrontStructuredData::cmsOgImage($page);

        return [
            'title' => $this->title((string) $t->title, $t->meta_title),
            'description' => $description,
            'canonical' => $canonical,
            'robots' => $page->robots ?: ShopTools::defaultRobots(),
            'og_image' => $og,
            'og_type' => 'article',
            'twitter_card' => $og ? 'summary_large_image' : 'summary',
            'keywords' => (string) ($t->meta_keywords ?: ''),
            'crawl_html' => $this->crawlBlock((string) $t->title, (string) $description, $body, $canonical),
            'json_ld' => StorefrontStructuredData::webPage((string) $t->title, $canonical, (string) $description),
        ];
    }

    protected function crawlBlock(string $heading, string $summary, string $body, string $url): string
    {
        $text = $body !== '' ? $body : $summary;
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if (mb_strlen($text) > 4000) {
            $text = mb_substr($text, 0, 4000).'…';
        }

        return '<article>'
            .'<h1>'.e($heading).'</h1>'
            .($summary !== '' ? '<p>'.e(mb_substr(strip_tags($summary), 0, 500)).'</p>' : '')
            .($text !== '' && $text !== strip_tags($summary) ? '<div>'.e($text).'</div>' : '')
            .'<p><a href="'.e($url).'">'.e($heading).'</a></p>'
            .'</article>';
    }

    protected function productCrawlBlock(
        Product $product,
        string $heading,
        string $summary,
        string $body,
        string $url,
        ?string $imageUrl = null,
        array $trail = [],
        ?float $ratingAvg = null,
        int $ratingCount = 0
    ): string {
        $html = '<article class="wento-product-crawl" itemscope itemtype="https://schema.org/Product">';

        if (! empty($trail)) {
            $html .= '<nav aria-label="Breadcrumb" class="wento-breadcrumbs"><ol>';
            foreach ($trail as $crumb) {
                $html .= '<li><a href="'.e($crumb['url']).'">'.e($crumb['name']).'</a> &gt; </li>';
            }
            $html .= '<li><span aria-current="page">'.e($heading).'</span></li>';
            $html .= '</ol></nav>';
        }

        $html .= '<h1><span itemprop="name">'.e($heading).'</span></h1>';

        if ($imageUrl) {
            $imageAlt = $heading.($product->sku ? ' - '.$product->sku : '');
            $html .= '<div class="product-image"><img src="'.e($imageUrl).'" alt="'.e($imageAlt).'" itemprop="image" loading="lazy" /></div>';
        }

        $currency = (string) (config('wento.default_currency') ?: 'USD');
        $html .= '<div itemprop="offers" itemscope itemtype="https://schema.org/Offer" class="product-offer">';
        if ($this->publicPricesVisible() && $product->price !== null) {
            $price = number_format((float) $product->price, 2, '.', '');
            $html .= '<span itemprop="priceCurrency" content="'.e($currency).'">'.e($currency).'</span> ';
            $html .= '<span itemprop="price" content="'.e($price).'" class="price">'.e($price).'</span> ';
        }
        $inStock = $product->stock_status === 'in_stock' || (int) ($product->stock_qty ?? 0) > 0 || (bool) ($product->allow_backorder ?? false);
        $availabilityUrl = $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock';
        $availabilityLabel = $inStock ? 'In Stock' : 'Out of Stock';
        $html .= '<link itemprop="availability" href="'.$availabilityUrl.'"/><span class="availability">'.e($availabilityLabel).'</span>';
        $html .= '</div>';

        $metaParts = [];
        if (filled($product->sku)) {
            $metaParts[] = 'SKU: <span itemprop="sku">'.e($product->sku).'</span>';
        }
        if ($product->brand) {
            $brandName = $product->brand->name ?? $product->brand->translation(app()->getLocale())?->name;
            if (filled($brandName)) {
                $metaParts[] = 'Brand: <span itemprop="brand">'.e($brandName).'</span>';
            }
        }
        if (! empty($metaParts)) {
            $html .= '<div class="product-meta">'.implode(' &middot; ', $metaParts).'</div>';
        }

        if ($ratingCount > 0 && $ratingAvg !== null) {
            $html .= '<div itemprop="aggregateRating" itemscope itemtype="https://schema.org/AggregateRating" class="product-rating">';
            $html .= '<span itemprop="ratingValue">'.number_format($ratingAvg, 1).'</span> / 5 based on ';
            $html .= '<span itemprop="reviewCount">'.e((string) $ratingCount).'</span> reviews';
            $html .= '</div>';
        }

        if ($summary !== '') {
            $html .= '<p itemprop="description" class="summary">'.e(mb_substr(strip_tags($summary), 0, 500)).'</p>';
        }

        $text = $body !== '' ? $body : $summary;
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if (mb_strlen($text) > 4000) {
            $text = mb_substr($text, 0, 4000).'…';
        }
        if ($text !== '' && $text !== strip_tags($summary)) {
            $html .= '<div class="description">'.e($text).'</div>';
        }

        $html .= '<p><a href="'.e($url).'">'.e($heading).'</a></p>';
        $html .= '</article>';

        return $html;
    }

    protected function categoryCrawlBlock(Category $category, string $heading, string $summary, string $body, string $url, string $locale): string
    {
        $urls = app(UrlRewriteService::class);
        $products = $category->products()
            ->where('is_enabled', true)
            ->with(['translations'])
            ->limit(24)
            ->get();

        $html = '<article class="wento-category-crawl">';
        $html .= '<h1>'.e($heading).'</h1>';
        if ($summary !== '') {
            $html .= '<p class="summary">'.e(mb_substr(strip_tags($summary), 0, 500)).'</p>';
        }
        $text = $body !== '' ? $body : $summary;
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if (mb_strlen($text) > 2000) {
            $text = mb_substr($text, 0, 2000).'…';
        }
        if ($text !== '' && $text !== strip_tags($summary)) {
            $html .= '<div class="description">'.e($text).'</div>';
        }

        if ($products->isNotEmpty()) {
            $html .= '<section class="category-products"><h2>Products in '.e($heading).'</h2><ul>';
            $currency = (string) (config('wento.default_currency') ?: 'USD');
            foreach ($products as $prod) {
                $pTrans = $prod->translation($locale);
                $pName = $pTrans?->name ?? 'Product';
                $pSlug = $urls->pathFor('product', (int) $prod->id, $locale) ?: $pTrans?->slug;
                $pUrl = $pSlug ? url($pSlug) : url('/product/'.$prod->id);
                $priceStr = $this->publicPricesVisible() && $prod->price !== null ? number_format((float) $prod->price, 2) : '';
                $html .= '<li><a href="'.e($pUrl).'">'.e($pName).'</a>';
                if ($priceStr !== '') {
                    $html .= ' <span>('.e($currency).' '.e($priceStr).')</span>';
                }
                $html .= '</li>';
            }
            $html .= '</ul></section>';
        }

        $html .= '<p><a href="'.e($url).'">'.e($heading).'</a></p>';
        $html .= '</article>';

        return $html;
    }

    protected function brandCrawlBlock(Brand $brand, string $heading, string $summary, string $body, string $url, string $locale): string
    {
        $urls = app(UrlRewriteService::class);
        $products = $brand->products()
            ->where('is_enabled', true)
            ->with(['translations'])
            ->limit(24)
            ->get();

        $html = '<article class="wento-brand-crawl">';
        $html .= '<h1>'.e($heading).'</h1>';
        if ($summary !== '') {
            $html .= '<p class="summary">'.e(mb_substr(strip_tags($summary), 0, 500)).'</p>';
        }
        $text = $body !== '' ? $body : $summary;
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if (mb_strlen($text) > 2000) {
            $text = mb_substr($text, 0, 2000).'…';
        }
        if ($text !== '' && $text !== strip_tags($summary)) {
            $html .= '<div class="description">'.e($text).'</div>';
        }

        if ($products->isNotEmpty()) {
            $html .= '<section class="brand-products"><h2>Products by '.e($heading).'</h2><ul>';
            $currency = (string) (config('wento.default_currency') ?: 'USD');
            foreach ($products as $prod) {
                $pTrans = $prod->translation($locale);
                $pName = $pTrans?->name ?? 'Product';
                $pSlug = $urls->pathFor('product', (int) $prod->id, $locale) ?: $pTrans?->slug;
                $pUrl = $pSlug ? url($pSlug) : url('/product/'.$prod->id);
                $priceStr = $this->publicPricesVisible() && $prod->price !== null ? number_format((float) $prod->price, 2) : '';
                $html .= '<li><a href="'.e($pUrl).'">'.e($pName).'</a>';
                if ($priceStr !== '') {
                    $html .= ' <span>('.e($currency).' '.e($priceStr).')</span>';
                }
                $html .= '</li>';
            }
            $html .= '</ul></section>';
        }

        $html .= '<p><a href="'.e($url).'">'.e($heading).'</a></p>';
        $html .= '</article>';

        return $html;
    }

    protected function title(string $name, ?string $meta): string
    {
        if (filled($meta)) {
            return (string) $meta;
        }

        return strtr(ShopTools::titlePattern(), [
            '{name}' => $name,
            '{shop}' => $this->shopName(),
        ]);
    }

    protected function productTitle(string $name, ?string $meta): string
    {
        if (filled($meta)) {
            return (string) $meta;
        }

        $pattern = trim((string) ShopSetting::get('seo.product_title_pattern', ''));
        if ($pattern === '') {
            $pattern = ShopTools::titlePattern();
        }

        return strtr($pattern, [
            '{name}' => $name,
            '{shop}' => $this->shopName(),
        ]);
    }

    /**
     * @return list<array{lang: string, url: string}>
     */
    protected function hreflang(UrlRewrite $rewrite): array
    {
        try {
            $languages = Language::enabledList();
        } catch (Throwable) {
            return [];
        }
        if ($languages->count() < 2) {
            return [];
        }

        $root = rtrim((string) (app(StoreContext::class)->store()?->primaryUrl()?->root() ?: config('app.url')), '/');
        $urls = app(UrlRewriteService::class);
        $out = [];
        $defaultCode = Language::defaultCode();
        $defaultUrl = null;
        foreach ($languages as $language) {
            $path = $urls->pathFor($rewrite->entity_type, (int) $rewrite->entity_id, $language->code);
            if (! $path) {
                continue;
            }
            $href = $root.'/'.$this->withLocalePrefix($path, $language->code);
            $out[] = [
                'lang' => $language->code,
                'url' => $href,
            ];
            if ($language->code === $defaultCode || ($defaultUrl === null && $language->is_default)) {
                $defaultUrl = $href;
            }
        }
        if ($defaultUrl) {
            $out[] = ['lang' => 'x-default', 'url' => $defaultUrl];
        }

        return $out;
    }

    /**
     * @return list<array{lang: string, url: string}>
     */
    protected function homeHreflang(): array
    {
        // Cookie mode shares one home URL across languages — do not emit duplicate hreflang.
        if (! $this->localePathMode()) {
            return [];
        }

        try {
            $languages = Language::enabledList();
        } catch (Throwable) {
            return [];
        }
        if ($languages->count() < 2) {
            return [];
        }
        $root = rtrim((string) (app(StoreContext::class)->store()?->primaryUrl()?->root() ?: config('app.url')), '/');
        $out = [];
        $defaultUrl = $root.'/';
        foreach ($languages as $language) {
            $href = $root.'/'.$language->code;
            $out[] = ['lang' => $language->code, 'url' => $href];
            if ($language->is_default) {
                $defaultUrl = $href;
            }
        }
        $out[] = ['lang' => 'x-default', 'url' => $defaultUrl];

        return $out;
    }

    protected function canonicalUrl(Request $request, string $path): string
    {
        $root = rtrim($request->getSchemeAndHttpHost(), '/');
        $prefix = '';
        if ($this->localePathMode()) {
            $prefix = '/'.app()->getLocale();
        }
        $suffix = $path === '' ? '' : '/'.ltrim($path, '/');

        return $root.$prefix.$suffix;
    }

    protected function localePathMode(): bool
    {
        try {
            $mode = (string) ShopSetting::get('seo.locale_url_mode', config('wento.locale_url_mode', 'cookie'));
        } catch (Throwable) {
            $mode = (string) config('wento.locale_url_mode', 'cookie');
        }

        return $mode === 'path';
    }

    protected function withLocalePrefix(string $path, string $locale): string
    {
        $path = trim($path, '/');
        if (! $this->localePathMode()) {
            return $path;
        }
        if ($path === '') {
            return $locale;
        }
        $first = explode('/', $path)[0];
        try {
            $codes = Language::enabledList()->pluck('code')->all();
        } catch (Throwable) {
            $codes = [$locale];
        }
        if (in_array($first, $codes, true)) {
            return $path;
        }

        return $locale.'/'.$path;
    }

    protected function absoluteMedia(?string $path, Request $request, string $type = 'large'): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        $relative = app(ImageOptimizer::class)->publicPath($path, $type) ?: $path;

        return $request->getSchemeAndHttpHost().WebBase::url('storage/'.ltrim($relative, '/'));
    }

    protected function shopName(): string
    {
        try {
            $store = app(StoreContext::class)->store();
            $named = \App\Domain\Store\ShopIdentity::forStorefront($store)['name'] ?? '';

            return $named !== '' ? $named : (string) ($store?->name ?: config('app.name'));
        } catch (Throwable) {
            return (string) config('app.name');
        }
    }

    protected function publicPricesVisible(): bool
    {
        if (ProductSettings::catalogMode() && ! ProductSettings::showPrice()) {
            return false;
        }
        if (ProductSettings::hidePricesGuests() && ! auth('customer')->user()) {
            return false;
        }
        if (\App\Domain\Settings\CustomerSettings::customerGroup(auth('customer')->user())?->hide_prices) {
            return false;
        }

        return true;
    }

    /**
     * @return list<array{q: string, a: string}>
     */
    protected function defaultHelpFaqs(): array
    {
        return [
            [
                'q' => 'How do I track an order?',
                'a' => 'Use Track Your Order in the header with your order number and email, or sign in and open My orders.',
            ],
            [
                'q' => 'How do returns work?',
                'a' => 'Most unused items can be returned within 7 days. Start from the order page or email the shop.',
            ],
            [
                'q' => 'How do I contact the shop?',
                'a' => 'Open the Contact page from the footer, or email the address shown on Help and About.',
            ],
        ];
    }
}
