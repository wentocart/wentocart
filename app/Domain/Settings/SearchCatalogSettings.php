<?php

namespace App\Domain\Settings;

final class SearchCatalogSettings
{
    /**
     * Canonical catalog sort keys shared by admin Default sorting and the storefront Sort by dropdown.
     *
     * @var list<string>
     */
    public const SORT_OPTIONS = [
        'relevance',
        'popularity',
        'newest',
        'price_asc',
        'price_desc',
        'rating',
        'discount',
    ];

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function sortOptionDefinitions(): array
    {
        return [
            ['value' => 'relevance', 'label' => 'Relevance'],
            ['value' => 'popularity', 'label' => 'Popularity'],
            ['value' => 'newest', 'label' => 'Newest'],
            ['value' => 'price_asc', 'label' => 'Price: Low to High'],
            ['value' => 'price_desc', 'label' => 'Price: High to Low'],
            ['value' => 'rating', 'label' => 'Rating'],
            ['value' => 'discount', 'label' => 'Biggest Discount'],
        ];
    }

    public static function defaultSort(): string
    {
        $raw = trim((string) ShopSetting::get('catalog.search.default_sort', 'popularity'));

        return self::normalizeSort($raw);
    }

    /**
     * Map aliases / invalid keys onto a supported option (falls back to popularity).
     */
    public static function normalizeSort(?string $sort): string
    {
        $raw = strtolower(trim((string) $sort));
        $aliases = [
            'price-low' => 'price_asc',
            'price_low' => 'price_asc',
            'price-high' => 'price_desc',
            'price_high' => 'price_desc',
            'best_sellers' => 'popularity',
            'best-sellers' => 'popularity',
            'bestseller' => 'popularity',
        ];
        if (isset($aliases[$raw])) {
            $raw = $aliases[$raw];
        }

        return in_array($raw, self::SORT_OPTIONS, true) ? $raw : 'popularity';
    }

    public static function perPage(): int
    {
        return ProductSettings::storefrontPerPage();
    }

    public static function suggestions(): bool
    {
        return ShopSetting::bool('catalog.search.suggestions', true);
    }

    public static function typoTolerance(): bool
    {
        return ShopSetting::bool('catalog.search.typo_tolerance', false);
    }

    public static function recentlyViewed(): bool
    {
        return ShopSetting::bool('catalog.search.recently_viewed', true);
    }

    public static function wishlistEnabled(): bool
    {
        return ShopSetting::bool('catalog.wishlist_enabled', true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        return [
            'default_sort' => self::defaultSort(),
            'sort_options' => self::sortOptionDefinitions(),
            'per_page' => self::perPage(),
            'suggestions' => self::suggestions(),
            'typo_tolerance' => self::typoTolerance(),
            'recently_viewed' => self::recentlyViewed(),
            'wishlist_enabled' => self::wishlistEnabled(),
            'engine' => app(\App\Domain\Search\SearchEngineManager::class)->preferred(),
            'engines' => app(\App\Domain\Search\SearchEngineManager::class)->describe(),
            'meilisearch' => CommunityFeatures::meilisearch(),
            'meilisearch_host' => (string) ShopSetting::get('search.meilisearch_host', env('MEILISEARCH_HOST', '')),
            'meilisearch_key' => (string) ShopSetting::get('search.meilisearch_key', env('MEILISEARCH_KEY', '')),
            'meilisearch_index' => (string) ShopSetting::get('search.meilisearch_index', 'products') ?: 'products',
            'elasticsearch' => ShopSetting::bool('search.elasticsearch', false),
            'elasticsearch_host' => (string) ShopSetting::get('search.elasticsearch_host', env('ELASTICSEARCH_HOST', '')),
            'elasticsearch_user' => (string) ShopSetting::get('search.elasticsearch_user', env('ELASTICSEARCH_USER', '')),
            'elasticsearch_pass' => (string) ShopSetting::get('search.elasticsearch_pass', env('ELASTICSEARCH_PASS', '')),
            'elasticsearch_index' => (string) ShopSetting::get('search.elasticsearch_index', 'products') ?: 'products',
            'compare_enabled' => ProductSettings::compareEnabled(),
            'out_of_stock_behavior' => ProductSettings::outOfStockBehavior(),
            'synonyms' => \Illuminate\Support\Facades\Schema::hasTable('search_synonyms')
                ? \App\Domain\Search\Models\SearchSynonym::query()
                    ->orderBy('term')
                    ->get(['id', 'term', 'aliases', 'is_enabled'])
                    ->all()
                : [],
            'pins' => \Illuminate\Support\Facades\Schema::hasTable('search_pins')
                ? \App\Domain\Search\Models\SearchPin::query()
                    ->with(['product.translations'])
                    ->orderBy('query')
                    ->orderBy('position')
                    ->get()
                    ->map(fn ($pin) => [
                        'id' => $pin->id,
                        'query' => $pin->query,
                        'product_id' => $pin->product_id,
                        'product_name' => $pin->product?->translation()?->name ?: $pin->product?->sku,
                        'position' => (int) $pin->position,
                    ])
                    ->all()
                : [],
            'redirects' => \Illuminate\Support\Facades\Schema::hasTable('search_redirects')
                ? \App\Domain\Search\Models\SearchRedirect::query()
                    ->orderBy('query')
                    ->get(['id', 'query', 'target_path', 'is_enabled'])
                    ->all()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function storefrontPayload(): array
    {
        return [
            'default_sort' => self::defaultSort(),
            'sort_options' => self::sortOptionDefinitions(),
            'per_page' => self::perPage(),
            'suggestions' => self::suggestions(),
            'typo_tolerance' => self::typoTolerance(),
            'recently_viewed' => self::recentlyViewed(),
            'wishlist_enabled' => self::wishlistEnabled(),
        ];
    }
}
