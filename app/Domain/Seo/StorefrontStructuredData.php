<?php

namespace App\Domain\Seo;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Support\CatalogMediaUrl;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Localization\Money;
use App\Domain\Settings\ProductSettings;
use App\Domain\Settings\ShopSetting;

/**
 * JSON-LD + OG helpers shared by SSR (StorefrontController) and API presenters.
 */
final class StorefrontStructuredData
{
    /**
     * Absolute URL without locale prefix (media, assets, already-absolute links).
     */
    public static function absoluteUrl(string $pathOrUrl): string
    {
        if ($pathOrUrl === '') {
            return StorefrontHreflang::shopRoot().'/';
        }
        if (str_starts_with($pathOrUrl, 'http://') || str_starts_with($pathOrUrl, 'https://')) {
            return $pathOrUrl;
        }

        return StorefrontHreflang::shopRoot().'/'.ltrim($pathOrUrl, '/');
    }

    /**
     * Absolute storefront catalog URL. When locale_url_mode=path, prefixes /{lang}.
     */
    public static function publicUrl(string $pathOrUrl, ?string $locale = null): string
    {
        if ($pathOrUrl !== '' && (str_starts_with($pathOrUrl, 'http://') || str_starts_with($pathOrUrl, 'https://'))) {
            return $pathOrUrl;
        }

        $root = StorefrontHreflang::shopRoot();
        $path = trim($pathOrUrl, '/');
        $locale = $locale ?: app()->getLocale();

        if ($path === '') {
            if (StorefrontHreflang::localePathMode()) {
                return $root.'/'.$locale;
            }

            return $root.'/';
        }

        // Media / theme assets must not receive a locale prefix.
        if (str_starts_with($path, 'storage/') || str_starts_with($path, 'themes/') || str_starts_with($path, 'build/')) {
            return $root.'/'.$path;
        }

        return $root.'/'.StorefrontHreflang::withLocalePrefix($path, $locale);
    }

    public static function absoluteMedia(?string $path, string $size = 'large'): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $url = CatalogMediaUrl::urlForType($path, $size);

        return self::absoluteUrl($url);
    }

    public static function offerCurrency(): string
    {
        try {
            return Money::currentCode();
        } catch (\Throwable) {
            return (string) config('wento.default_currency', 'USD');
        }
    }

    /**
     * @param  list<array{name?: string|null, url?: string|null}>  $trail
     * @param  list<array{author?: string, rating?: int|float, body?: string, date?: string|null}>  $reviews
     * @return array<string, mixed>
     */
    public static function productGraph(
        Product $product,
        string $name,
        string $description,
        string $canonicalUrl,
        ?string $ogImage,
        array $trail = [],
        ?float $ratingAverage = null,
        int $ratingCount = 0,
        array $reviews = [],
    ): array {
        $productNode = [
            '@type' => 'Product',
            'name' => $name,
            'description' => $description,
            'sku' => $product->sku,
            'url' => $canonicalUrl,
        ];
        if ($ogImage) {
            $productNode['image'] = $ogImage;
        }
        $brandName = $product->relationLoaded('brand')
            ? $product->brand?->translation(app()->getLocale())?->name
            : null;
        if (is_string($brandName) && $brandName !== '') {
            $productNode['brand'] = ['@type' => 'Brand', 'name' => $brandName];
        }
        $gtin = trim((string) ($product->gtin ?: $product->upc ?: $product->barcode ?: ''));
        if ($gtin !== '') {
            $productNode['gtin'] = $gtin;
        }
        $mpn = trim((string) ($product->mpn ?: ''));
        if ($mpn !== '') {
            $productNode['mpn'] = $mpn;
        }
        $isbn = trim((string) ($product->isbn ?: ''));
        if ($isbn !== '') {
            $productNode['isbn'] = $isbn;
        }
        if ($ratingCount > 0 && $ratingAverage !== null) {
            $productNode['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => round($ratingAverage, 1),
                'reviewCount' => $ratingCount,
                'bestRating' => 5,
                'worstRating' => 1,
            ];
        }
        $reviewNodes = [];
        foreach (array_slice($reviews, 0, 5) as $review) {
            $author = trim((string) ($review['author'] ?? ''));
            $rating = (float) ($review['rating'] ?? 0);
            if ($author === '' || $rating < 1) {
                continue;
            }
            $node = [
                '@type' => 'Review',
                'author' => ['@type' => 'Person', 'name' => $author],
                'reviewRating' => [
                    '@type' => 'Rating',
                    'ratingValue' => $rating,
                    'bestRating' => 5,
                    'worstRating' => 1,
                ],
            ];
            $body = trim((string) ($review['body'] ?? ''));
            if ($body !== '') {
                $node['reviewBody'] = mb_substr($body, 0, 500);
            }
            if (! empty($review['date'])) {
                $node['datePublished'] = (string) $review['date'];
            }
            $reviewNodes[] = $node;
        }
        if ($reviewNodes !== []) {
            $productNode['review'] = $reviewNodes;
        }
        if (($product->show_price ?? true) && (! ProductSettings::catalogMode() || ProductSettings::showPrice())) {
            $productNode['offers'] = [
                '@type' => 'Offer',
                'url' => $canonicalUrl,
                'price' => $product->sellingPrice(),
                'priceCurrency' => self::offerCurrency(),
                'availability' => $product->stock_status === 'in_stock'
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                $productNode,
                [
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => self::breadcrumbItems($trail, $name, $canonicalUrl),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function websiteGraph(string $shopName, ?string $ogImage = null): array
    {
        $url = self::publicUrl('/');
        $website = [
            '@type' => 'WebSite',
            'name' => $shopName,
            'url' => $url,
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => self::publicUrl('/search').'?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
        $org = [
            '@type' => 'Organization',
            'name' => $shopName,
            'url' => $url,
        ];
        if ($ogImage) {
            $org['logo'] = $ogImage;
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => [$website, $org],
        ];
    }

    /**
     * @param  list<array{q: string, a: string}>  $faqs
     * @return array<string, mixed>|null
     */
    public static function faqPage(array $faqs): ?array
    {
        $entities = [];
        foreach ($faqs as $row) {
            $q = trim((string) ($row['q'] ?? ''));
            $a = trim(strip_tags((string) ($row['a'] ?? '')));
            if ($q === '' || $a === '') {
                continue;
            }
            $entities[] = [
                '@type' => 'Question',
                'name' => $q,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => mb_substr($a, 0, 2000),
                ],
            ];
        }
        if ($entities === []) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $entities,
        ];
    }

    /**
     * @param  list<array{name?: string|null, url?: string|null}>  $trail
     * @return list<array{('@type'): string, position: int, name: string, item?: string}>
     */
    public static function breadcrumbItems(array $trail, string $leafName, string $leafUrl): array
    {
        $items = [];
        $position = 1;
        $home = self::publicUrl('/');
        $items[] = [
            '@type' => 'ListItem',
            'position' => $position++,
            'name' => (string) (config('app.name') ?: 'Home'),
            'item' => $home,
        ];
        foreach ($trail as $row) {
            $label = trim((string) ($row['name'] ?? ''));
            if ($label === '' || strcasecmp($label, 'Home') === 0) {
                continue;
            }
            $item = [
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => $label,
            ];
            $url = $row['url'] ?? null;
            if (is_string($url) && $url !== '') {
                $item['item'] = self::publicUrl($url);
            }
            $items[] = $item;
        }
        $items[] = [
            '@type' => 'ListItem',
            'position' => $position,
            'name' => $leafName,
            'item' => $leafUrl,
        ];

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public static function collectionPage(string $name, string $url, ?string $description = null): array
    {
        $row = [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $name,
            'url' => $url,
        ];
        if (is_string($description) && $description !== '') {
            $row['description'] = $description;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public static function brandNode(string $name, ?string $url = null, ?string $logo = null): array
    {
        $row = [
            '@context' => 'https://schema.org',
            '@type' => 'Brand',
            'name' => $name,
        ];
        if ($url) {
            $row['url'] = $url;
        }
        if ($logo) {
            $row['logo'] = $logo;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public static function webPage(string $name, string $url, ?string $description = null): array
    {
        $row = [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => $name,
            'url' => $url,
        ];
        if (is_string($description) && $description !== '') {
            $row['description'] = $description;
        }

        return $row;
    }

    public static function defaultOgImage(): ?string
    {
        try {
            $path = (string) ShopSetting::get('seo.og_image', '');
        } catch (\Throwable) {
            $path = '';
        }

        return self::absoluteMedia($path !== '' ? $path : null);
    }

    /**
     * Category trail for a product (deepest primary category first → root last flipped by caller).
     *
     * @return list<array{name: string|null, url: string|null}>
     */
    public static function categoryTrail(Product $product, string $locale, callable $urlForCategory): array
    {
        if (! $product->relationLoaded('categories')) {
            return [];
        }
        $primary = $product->categories->first();
        if (! $primary instanceof Category) {
            return [];
        }

        $nodes = [];
        $cursor = $primary;
        $guard = 0;
        while ($cursor && $guard < 8) {
            $cursor->loadMissing('translations');
            $nodes[] = [
                'name' => $cursor->translation($locale)?->name,
                'url' => $urlForCategory($cursor),
            ];
            $cursor->loadMissing('parent.translations');
            $cursor = $cursor->parent;
            $guard++;
        }

        return array_reverse($nodes);
    }

    public static function brandOgImage(Brand $brand): ?string
    {
        return self::absoluteMedia($brand->banner_path ?: $brand->logo_path, 'large')
            ?: self::defaultOgImage();
    }

    public static function cmsOgImage(CmsPage $page): ?string
    {
        return self::defaultOgImage();
    }
}
