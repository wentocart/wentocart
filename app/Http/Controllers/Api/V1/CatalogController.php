<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Support\CatalogMediaUrl;
use App\Domain\Catalog\Support\DefaultImage;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Catalog\Services\CatalogPresenter;
use App\Domain\Catalog\Services\CategoryProductQuery;
use App\Domain\Catalog\Services\CustomerStockAlertService;
use App\Domain\Catalog\Services\PreorderService;
use App\Domain\Catalog\Services\UrlResolver;
use App\Domain\Catalog\Services\UrlRewriteService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Cms\Models\CmsBlock;
use App\Domain\Cms\CmsPageLayout;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Customer\Models\Review;
use App\Domain\Catalog\Services\StorefrontCatalogCache;
use App\Domain\Localization\Money;
use App\Domain\Ops\Models\CommerceEvent;
use App\Domain\Plugin\Support\ModuleBuses;
use App\Domain\Search\CatalogSearch;
use App\Domain\Security\HtmlSanitizer;
use App\Domain\Seo\StorefrontHreflang;
use App\Domain\Seo\StorefrontStructuredData;
use App\Domain\Settings\ProductSettings;
use App\Domain\Settings\SearchCatalogSettings;
use App\Domain\Store\StoreContext;
use App\Domain\Theme\ThemeResolver;
use App\Domain\Theme\ThemeSettingsCatalog;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class CatalogController extends Controller
{
    public function __construct(
        protected CatalogPresenter $present,
        protected ThemeResolver $themes,
    ) {}

    public function home(): JsonResponse
    {
        $locale = app()->getLocale();
        $currency = Money::currentCode();
        // Guest home is identical for a locale/currency; skip cache for signed-in price rules.
        if (auth('customer')->check()) {
            return response()->json($this->homePayload($locale));
        }

        $payload = StorefrontCatalogCache::remember(
            "home.{$locale}.{$currency}",
            300,
            fn () => $this->homePayload($locale)
        );

        return response()->json($payload);
    }

    /**
     * The corner deal banner only needs a name. Do not build the homepage for it.
     */
    public function deal(): JsonResponse
    {
        $locale = app()->getLocale();
        $payload = StorefrontCatalogCache::remember(
            "deal.{$locale}",
            300,
            function () use ($locale) {
                $product = Product::query()
                    ->with('translations')
                    ->enabled()
                    ->visibleIn('catalog')
                    ->where('is_on_sale', true)
                    ->latest('id')
                    ->first();
                if (! $product) {
                    $product = Product::query()
                        ->with('translations')
                        ->enabled()
                        ->visibleIn('catalog')
                        ->latest('id')
                        ->first();
                }

                return [
                    'deal' => $product ? ['name' => $product->translation($locale)?->name] : null,
                    'deal_ends_at' => now()->endOfDay()->toIso8601String(),
                ];
            }
        );

        return response()->json($payload);
    }

    /**
     * @return array<string, mixed>
     */
    protected function homePayload(string $locale): array
    {
        $store = app(StoreContext::class)->store();
        $home = $this->themes->settings(null, $store)['home'] ?? [];
        // Home cards only need listing fields + ratings. Skipping featureValues here
        // cuts a large join tree on every cache miss (was multi-second on demo data).
        $listing = fn ($q) => $this->present->withRatings(
            ProductSettings::applyListingStockFilter(
                $q->selectListing()
                    // Avoid per-card loadMissing during discount/price + hreflang category checks.
                    ->with(['qtyPrices', 'categories:id', 'translations', 'brand.translations', 'images'])
                    ->enabled()
                    ->visibleIn('catalog')
            )
        );

        $featuredLimit = max(2, min(24, (int) ($home['featured']['limit'] ?? 12)));
        $featuredCategoryId = (int) ($home['featured']['category_id'] ?? 0);
        $featuredQuery = $listing(Product::query());
        if ($featuredCategoryId > 0) {
            CategoryProductQuery::constrain($featuredQuery, [$featuredCategoryId]);
        }
        $featured = $featuredQuery->latest()->limit($featuredLimit)->get();

        $newLimit = max(2, min(24, (int) ($home['new_arrivals']['limit'] ?? 12)));
        $newest = $listing(Product::query())->where('is_new', true)->latest()->limit($newLimit)->get();
        if ($newest->isEmpty()) {
            $newest = $featured;
        }

        $bestLimit = max(2, min(24, (int) ($home['bestsellers']['limit'] ?? 12)));
        $bestsellers = $listing(Product::query())->where('is_best_seller', true)->latest()->limit($bestLimit)->get();
        $sale = $listing(Product::query())->where('is_on_sale', true)->latest()->limit(12)->get();
        $deal = $sale->first() ?: $featured->first();

        $shopCatIds = array_values(array_filter(array_map('intval', $home['shop_categories']['category_ids'] ?? [])));
        $shopCatLimit = max(3, min(20, (int) ($home['shop_categories']['limit'] ?? 20)));
        $allEnabledCategories = Category::query()
            ->where('is_enabled', true)
            ->with('translations')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        // Prefetch rewrite paths for every enabled locale before categoryForest/product cards
        // (hreflang calls pathFor once per language).
        $urls = app(UrlRewriteService::class);
        $localeCodes = \App\Domain\Localization\Models\Language::enabledList()->pluck('code')->all() ?: [$locale];
        $urls->warmPaths('category', $allEnabledCategories->pluck('id')->all(), $localeCodes);
        $urls->warmPaths(
            'product',
            $featured->concat($newest)->concat($bestsellers)->concat($sale)->pluck('id')->all(),
            $localeCodes
        );

        $categoryForest = collect($this->present->categoryForest(
            $allEnabledCategories,
            $locale,
            $shopCatIds !== [] ? $shopCatIds : null,
        ));
        if ($shopCatIds === []) {
            $categoryForest = $categoryForest->take($shopCatLimit)->values();
        }
        $countMap = CategoryProductQuery::counts($categoryForest->pluck('id')->all());
        $categories = $categoryForest->map(fn (array $category) => $category + [
            'products_count' => $countMap[$category['id']] ?? 0,
        ]);

        $shelfIds = array_values(array_filter(array_map('intval', $home['category_shelves']['category_ids'] ?? [])));
        $shelfProductLimit = ThemeSettingsCatalog::CATEGORY_SHELF_PRODUCT_LIMIT;
        $shelfQuery = Category::query()
            ->where('is_enabled', true)
            ->with(['translations', 'children.translations']);
        if ($shelfIds !== []) {
            $shelfQuery->whereIn('id', $shelfIds);
        } else {
            $shelfQuery->whereNull('parent_id')->orderBy('position')->limit(3);
        }
        $shelfCategories = $shelfQuery->get();
        if ($shelfIds !== []) {
            $shelfCategories = $shelfCategories->sortBy(fn (Category $c) => array_search($c->id, $shelfIds, true))->values();
        }

        $shelves = $shelfCategories->map(function (Category $category) use ($listing, $locale, $localeCodes, $shelfProductLimit, $urls) {
            $ids = $category->children->filter(fn ($child) => $child->is_enabled)->pluck('id')->push($category->id)->all();
            $products = $listing(Product::query());
            CategoryProductQuery::constrain($products, $ids);
            $products = $products
                ->latest()
                ->limit($shelfProductLimit)
                ->get();
            $urls->warmPaths('product', $products->pluck('id')->all(), $localeCodes);

            // Same tree the FO category page lists, so "View All" can compare total vs shelf limit.
            $totalQuery = ProductSettings::applyListingStockFilter(
                Product::query()->enabled()->visibleIn('catalog')
            );
            CategoryProductQuery::constrain($totalQuery, $this->descendantIds($category)->all());

            return [
                'category' => $this->present->category($category, $locale) + [
                    'products_count' => (int) $totalQuery->count(),
                ],
                'products' => $products->map(fn (Product $p) => $this->present->product($p, $locale))->values()->all(),
            ];
        })->values();

        $brandCfg = is_array($home['brands'] ?? null) ? $home['brands'] : [];
        $brandIds = array_values(array_filter(array_map('intval', $brandCfg['brand_ids'] ?? [])));
        $brandLimit = max(2, min(16, (int) ($brandCfg['limit'] ?? 16)));
        $brandQuery = Brand::query()->where('is_enabled', true)->with('translations');
        if ($brandIds !== []) {
            $brandQuery->whereIn('id', $brandIds);
        } else {
            $brandQuery->orderBy('position')->limit($brandLimit);
        }
        $brandModels = $brandQuery->get();
        if ($brandIds !== []) {
            $brandModels = $brandModels->sortBy(fn (Brand $b) => array_search($b->id, $brandIds, true))->values();
        }
        $urls->warmPaths(
            'brand',
            $featured->concat($newest)->concat($bestsellers)->concat($sale)->pluck('brand_id')
                ->merge($brandModels->pluck('id'))
                ->all(),
            $localeCodes
        );

        $brands = $brandModels->map(fn (Brand $brand) => [
            'id' => $brand->id,
            'name' => $brand->translation($locale)?->name,
            'slug' => $brand->translation($locale)?->slug,
            'url' => '/'.ltrim((string) ($urls->pathFor('brand', (int) $brand->id, $locale) ?: ('brand/'.($brand->translation($locale)?->slug ?: $brand->id))), '/'),
            'logo' => CatalogMediaUrl::url($brand->logo_path, DefaultImage::SM),
            'banner' => $brand->banner_path ? CatalogMediaUrl::url($brand->banner_path, DefaultImage::LG) : null,
            'tagline' => $brand->translation($locale)?->tagline
                ?: $brand->translation($locale)?->short_description,
        ]);

        return [
            'blocks' => CmsBlock::query()
                ->where('is_enabled', true)
                ->where('location', 'home')
                ->with('translations')
                ->orderBy('position')
                ->get()
                ->map(fn (CmsBlock $block) => CmsController::presentBlock($block))
                ->values()
                ->all(),
            'menu' => null,
            'featured' => $featured->map(fn (Product $p) => $this->present->product($p, $locale))->values()->all(),
            'new_arrivals' => $newest->map(fn (Product $p) => $this->present->product($p, $locale))->values()->all(),
            'bestsellers' => $bestsellers->map(fn (Product $p) => $this->present->product($p, $locale))->values()->all(),
            'on_sale' => $sale->map(fn (Product $p) => $this->present->product($p, $locale))->values()->all(),
            'deal' => $deal ? $this->present->product($deal, $locale) : null,
            'deal_ends_at' => now()->endOfDay()->toIso8601String(),
            'categories' => $categories->values()->all(),
            'category_shelves' => $shelves->all(),
            'brands' => $brands->values()->all(),
            'modules' => [
                'body' => ModuleBuses::collectSlots('storefront.home.body'),
            ],
        ];
    }

    public function categories(): JsonResponse
    {
        $locale = app()->getLocale();
        $allEnabledCategories = Category::query()
            ->where('is_enabled', true)
            ->with('translations')
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        $forest = collect($this->present->categoryForest($allEnabledCategories, $locale));
        $countMap = CategoryProductQuery::counts($forest->pluck('id')->all());
        $rows = $forest->map(fn (array $category) => $category + [
            'products_count' => $countMap[$category['id']] ?? 0,
        ]);

        return response()->json(['categories' => $rows->values()]);
    }

    public function category(Request $request, string $slug): JsonResponse
    {
        $locale = app()->getLocale();
        $rewrite = app(UrlResolver::class)->locate($slug);
        $with = $this->categoryListingRelations();
        $category = null;
        if ($rewrite?->entity_type === 'category') {
            $category = Category::query()->with($with)->find($rewrite->entity_id);
        }
        if (! $category) {
            $category = Category::query()
                ->whereHas('translations', fn ($t) => $t->where('slug', $slug)->where('language_code', $locale))
                ->with($with)
                ->first();
        }

        // /catalog is a fixed storefront route; shops without a "catalog" category still need a listing.
        if (! $category && $this->isStorefrontCatalogSlug($slug)) {
            return $this->catalogRootListing($request, $locale, $with, $slug);
        }

        if (! $category) {
            abort(404);
        }

        return $this->categoryListingResponse(
            $request,
            $locale,
            $category,
            $this->descendantIds($category)->all(),
            $category->children,
            $this->present->category($category, $locale),
            $this->breadcrumbs($category, $locale),
            (int) $category->id,
        );
    }

    /**
     * All-products listing for the /catalog SPA when no root category row exists.
     *
     * @param  array<string, mixed>  $with
     */
    protected function catalogRootListing(Request $request, string $locale, array $with, string $slug = 'catalog'): JsonResponse
    {
        $roots = Category::query()
            ->whereNull('parent_id')
            ->where('is_enabled', true)
            ->orderBy('position')
            ->orderBy('id')
            ->with($with)
            ->get();

        $key = strtolower(trim($slug, '/'));
        $onlyNew = $key === 'new-arrivals';
        $path = $onlyNew ? '/new-arrivals' : '/catalog';
        $name = $onlyNew ? 'New products' : 'Catalog';
        $canonical = StorefrontStructuredData::publicUrl($path, $locale);
        $categoryPayload = [
            'id' => 0,
            'name' => $name,
            'slug' => $key,
            'url' => $path,
            'description' => null,
            'image' => null,
            'thumb' => null,
            'seo' => [
                'title' => $name,
                'description' => '',
                'canonical' => $canonical,
                'robots' => 'index,follow',
                'og_type' => 'website',
                'og_image' => StorefrontStructuredData::defaultOgImage(),
                'hreflang' => [],
                'json_ld' => StorefrontStructuredData::collectionPage($name, $canonical, ''),
            ],
        ];

        return $this->categoryListingResponse(
            $request,
            $locale,
            null,
            null,
            $roots,
            $categoryPayload,
            [['name' => $name, 'url' => $path]],
            0,
            $onlyNew,
        );
    }

    /**
     * @param  list<int>|null  $categoryIds  null = every catalog-visible product
     * @param  Collection<int, Category>|\Illuminate\Database\Eloquent\Collection<int, Category>  $children
     * @param  array<string, mixed>  $categoryPayload
     * @param  list<array{name: string, url: ?string}>  $breadcrumbs
     */
    protected function categoryListingResponse(
        Request $request,
        string $locale,
        ?Category $category,
        ?array $categoryIds,
        $children,
        array $categoryPayload,
        array $breadcrumbs,
        int $moduleCategoryId,
        bool $onlyNew = false,
    ): JsonResponse {
        $products = $this->present->withRatings(
            Product::query()->selectListing()->enabled()->visibleIn('catalog')
        );
        if ($onlyNew) {
            $products->where('is_new', true);
        }
        if ($categoryIds !== null) {
            CategoryProductQuery::constrain($products, $categoryIds);
        }
        $products->with(['featureValues.translations', 'featureValues.feature.translations']);

        ProductSettings::applyListingStockFilter($products);
        $this->applyListingFilters($products, $request);
        $this->applySort($products, SearchCatalogSettings::normalizeSort(
            (string) $request->input('sort', SearchCatalogSettings::defaultSort())
        ));

        $defaultPerPage = ProductSettings::storefrontPerPage();
        $perPage = min(48, max(8, (int) $request->input('per_page', $defaultPerPage)));
        $page = $products->paginate($perPage)->withQueryString();

        $facetKey = 'cat.facets.'.($categoryIds === null ? 'all' : (int) ($category?->id ?? 0)).'.'.$locale;
        $facet = StorefrontCatalogCache::remember($facetKey, 120, function () use ($categoryIds, $locale) {
            $inCategory = function ($q) use ($categoryIds) {
                $q->enabled()->visibleIn('catalog');
                if ($categoryIds !== null) {
                    CategoryProductQuery::constrain($q, $categoryIds);
                }
                ProductSettings::applyListingStockFilter($q);
            };

            $filters = Feature::query()
                ->where('is_filterable', true)
                ->where('is_enabled', true)
                ->with([
                    'translations',
                    'values' => fn ($q) => $q->where('is_enabled', true)
                        ->whereHas('products', $inCategory)
                        ->with('translations')
                        ->orderBy('position')
                        ->orderBy('id'),
                ])
                ->orderBy('position')
                ->get()
                ->map(fn (Feature $feature) => [
                    'id' => $feature->id,
                    'code' => $feature->code,
                    'type' => $feature->type,
                    'name' => $feature->translation($locale)?->name,
                    'values' => $feature->values->map(fn ($value) => [
                        'id' => $value->id,
                        'value' => $value->translation($locale)?->value,
                    ])->values()->all(),
                ])
                ->filter(fn (array $feature) => $feature['values'] !== [])
                ->values();

            $brandQuery = Brand::query()->where('is_enabled', true);
            $brandQuery->whereHas('products', $inCategory);
            $brands = $brandQuery
                ->with('translations')
                ->withCount(['products as products_count' => $inCategory])
                ->get()
                ->map(fn (Brand $brand) => [
                    'id' => $brand->id,
                    'name' => $brand->translation($locale)?->name,
                    'slug' => $brand->translation($locale)?->slug,
                    'count' => $brand->products_count,
                ]);

            $priceQuery = ProductSettings::applyListingStockFilter(
                Product::query()->enabled()->visibleIn('catalog')
            );
            if ($categoryIds !== null) {
                CategoryProductQuery::constrain($priceQuery, $categoryIds);
            }
            $priceBounds = $priceQuery
                ->selectRaw('MIN('.$this->rawTable(new Product).'.price) as min_price, MAX('.$this->rawTable(new Product).'.price) as max_price')
                ->first();

            return [
                'filters' => $filters->values()->all(),
                'brands' => $brands->values()->all(),
                'price' => [
                    'min' => (float) ($priceBounds->min_price ?? 0),
                    'max' => (float) ($priceBounds->max_price ?? 0),
                ],
            ];
        });

        return response()->json([
            'category' => $categoryPayload + [
                'products_count' => $page->total(),
            ],
            'breadcrumbs' => $breadcrumbs,
            'children' => $this->nestedCategoryTree($children, $locale),
            'filters' => $facet['filters'],
            'brands' => $facet['brands'],
            'price' => [
                'min' => Money::fromDefault((float) ($facet['price']['min'] ?? 0)),
                'max' => Money::fromDefault((float) ($facet['price']['max'] ?? 0)),
            ],
            'sort' => SearchCatalogSettings::normalizeSort(
                (string) $request->input('sort', SearchCatalogSettings::defaultSort())
            ),
            'products' => $page->through(fn (Product $p) => $this->present->product($p, $locale)),
            'modules' => [
                'after' => ModuleBuses::collectSlots('storefront.category.after', ['id' => $moduleCategoryId]),
            ],
        ]);
    }

    protected function isStorefrontCatalogSlug(string $slug): bool
    {
        return in_array(strtolower(trim($slug, '/')), ['catalog', 'new-arrivals'], true);
    }

    /**
     * One response for a storefront URL: resolve the path and return that page's payload.
     * The shop otherwise waits for /catalog/resolve and then a second catalog call.
     */
    public function open(Request $request, string $path): JsonResponse
    {
        $resolved = app(UrlResolver::class)->resolve($path);
        $redirect = trim((string) ($resolved['redirect_to'] ?? ''), '/');
        if ($redirect !== '' && $redirect !== trim($path, '/')) {
            return response()->json([
                'kind' => 'redirect',
                'redirect_to' => $redirect,
            ]);
        }

        $rewrite = $resolved['rewrite'] ?? null;
        $type = (string) ($rewrite?->entity_type ?? '');
        $canonical = trim((string) ($rewrite?->request_path ?: $path), '/');
        if (! in_array($type, ['product', 'category', 'brand', 'supplier', 'cms'], true)) {
            return response()->json(['kind' => 'missing'], 404);
        }

        try {
            $inner = match ($type) {
                'product' => $this->product($canonical),
                'category' => $this->category($request, $canonical),
                'brand' => $this->brand($canonical),
                'supplier' => $this->supplier($canonical),
                default => $this->page($canonical),
            };
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            return response()->json(['kind' => 'missing'], 404);
        }

        $payload = json_decode($inner->getContent(), true);

        return response()->json([
            'kind' => $type,
            'path' => $canonical,
            'payload' => is_array($payload) ? $payload : null,
        ]);
    }

    public function product(string $slug): JsonResponse
    {
        $locale = app()->getLocale();
        $rewrite = app(UrlResolver::class)->locate($slug);
        $query = $this->present->withRatings(Product::query()->enabled())
            ->with([
                'seller', 'attachments.translations', 'brand.translations', 'supplier.translations', 'suppliers.translations',
                'categories.translations', 'categories.parent.translations',
                'tags.translations', 'featureValues.translations', 'featureValues.feature.translations',
                'shippingMethods', 'customizationFields', 'productSubscriptionPlans.plan.translations',
                'giftCardConfig', 'giftCardAmounts', 'components.child.translations', 'serviceSetting',
                'rentalSetting', 'rentalPricingTiers', 'rentalAssets',
                'reviews' => fn ($q) => $q->where('is_approved', true)
                    ->with(['customer', 'votes' => function ($votes) {
                        $viewerId = auth('customer')->id();
                        $viewerId
                            ? $votes->where('customer_id', $viewerId)
                            : $votes->whereRaw('0 = 1');
                    }])
                    ->latest()
                    ->limit(12),
                'questions' => fn ($q) => $q->where('is_approved', true)
                    ->with(['customer', 'answers' => fn ($a) => $a->where('is_approved', true)->latest()])
                    ->latest()
                    ->limit(20),
            ]);
        if ($rewrite?->entity_type === 'product') {
            $query->whereKey($rewrite->entity_id);
        } else {
            $query->whereHas('translations', fn ($t) => $t->where('slug', $slug)->where('language_code', $locale));
        }
        $product = $query->firstOrFail();
        if (ProductSettings::hiddenFromStorefront($product)) {
            abort(404);
        }

        $this->trackCatalogAnalytics('catalog.product_view', [
            'product_id' => (int) $product->id,
            'name' => (string) ($product->translation($locale)?->name ?? $product->sku),
            'sku' => (string) $product->sku,
        ]);

        $relatedQuery = $this->present->withRatings($product->relationsOfType('related'));
        $related = ProductSettings::applyListingStockFilter($relatedQuery)->get();
        $upsellQuery = $this->present->withRatings($product->relationsOfType('upsell'));
        $upsell = ProductSettings::applyListingStockFilter($upsellQuery)->get();
        $crossQuery = $this->present->withRatings($product->relationsOfType('crosssell'));
        $cross = ProductSettings::applyListingStockFilter($crossQuery)->get();
        $also = $related->isNotEmpty() ? $related : ProductSettings::applyListingStockFilter(
            $this->present->withRatings(
                Product::query()->enabled()->visibleIn('catalog')->whereKeyNot($product->id)
            )
        )->latest()->limit(8)->get();

        $crumbs = [];
        $primary = $product->categories->first();
        $sameCategory = $primary
            ? tap(ProductSettings::applyListingStockFilter($this->present->withRatings(
                Product::query()
                    ->enabled()
                    ->visibleIn('catalog')
                    ->whereKeyNot($product->id)
            )), fn ($query) => CategoryProductQuery::constrain($query, [(int) $primary->id]))
                ->latest()->limit(16)->get()
            : collect();
        if ($primary) {
            $crumbs = $this->breadcrumbs($primary, $locale);
        }
        $crumbs[] = ['name' => $product->translation($locale)?->name, 'url' => null];

        $slotContext = [
            'id' => $product->id,
            'name' => (string) ($product->translation($locale)?->name ?? ''),
            'category_ids' => $product->categories->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
        ];

        return response()->json([
            'product' => $this->present->product($product, $locale, true),
            'breadcrumbs' => $crumbs,
            'related' => $related->map(fn (Product $p) => $this->present->product($p, $locale))->values(),
            'upsell' => $upsell->map(fn (Product $p) => $this->present->product($p, $locale))->values(),
            'crosssell' => $cross->map(fn (Product $p) => $this->present->product($p, $locale))->values(),
            'also_like' => $also->map(fn (Product $p) => $this->present->product($p, $locale))->values(),
            'same_category' => $sameCategory->map(fn (Product $p) => $this->present->product($p, $locale))->values(),
            'modules' => [
                'actions' => ModuleBuses::collectSlots('storefront.product.actions', $slotContext),
                'after' => ModuleBuses::collectSlots('storefront.product.after', $slotContext),
                'tabs' => ModuleBuses::collectSlots('storefront.product.tabs', $slotContext),
                'below_trust' => ModuleBuses::collectSlots('storefront.product.below_trust', $slotContext),
                'buybox' => ModuleBuses::productBuybox($slotContext),
            ],
            'review_eligibility' => $this->reviewEligibilityForRequest($product),
        ]);
    }

    public function productReviews(Request $request, string $slug): JsonResponse
    {
        $locale = app()->getLocale();
        $rewrite = app(UrlResolver::class)->locate($slug);
        $query = Product::query()->enabled();
        if ($rewrite?->entity_type === 'product') {
            $query->whereKey($rewrite->entity_id);
        } else {
            $query->whereHas('translations', fn ($t) => $t->where('slug', $slug)->where('language_code', $locale));
        }
        $product = $query->firstOrFail();
        if (ProductSettings::hiddenFromStorefront($product)) {
            abort(404);
        }

        $sort = (string) $request->query('sort', 'newest');
        if (! in_array($sort, ['newest', 'oldest', 'highest', 'lowest'], true)) {
            $sort = 'newest';
        }
        $perPage = min(50, max(5, (int) $request->query('per_page', 12)));

        $viewerId = auth('customer')->id();
        $reviewsQuery = Review::query()
            ->where('product_id', $product->id)
            ->where('is_approved', true)
            ->with(['customer', 'votes' => function ($votes) use ($viewerId) {
                $viewerId
                    ? $votes->where('customer_id', $viewerId)
                    : $votes->whereRaw('0 = 1');
            }]);

        $reviewsQuery = match ($sort) {
            'oldest' => $reviewsQuery->orderBy('id'),
            'highest' => $reviewsQuery->orderByDesc('rating')->orderByDesc('id'),
            'lowest' => $reviewsQuery->orderBy('rating')->orderByDesc('id'),
            default => $reviewsQuery->latest('id'),
        };

        $page = $reviewsQuery->paginate($perPage);
        $merchantReplies = \App\Domain\Settings\ReviewSettings::merchantReplies();

        return response()->json([
            'reviews' => collect($page->items())->map(fn (Review $review) => [
                'id' => $review->id,
                'rating' => $review->rating,
                'title' => $review->title,
                'body' => $review->body,
                'author' => $review->authorName(),
                'is_verified_purchase' => (bool) $review->is_verified_purchase,
                'admin_reply' => $merchantReplies ? $review->admin_reply : null,
                'admin_replied_at' => $merchantReplies ? $review->admin_replied_at?->toDateString() : null,
                'created_at' => $review->created_at?->toDateString(),
                ...$review->mediaPayload(),
                ...$review->votePayload($viewerId ? (int) $viewerId : null),
            ])->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'sort' => $sort,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function reviewEligibilityForRequest(Product $product): ?array
    {
        $customer = auth('customer')->user();
        if (! $customer) {
            return null;
        }

        return app(\App\Domain\Customer\Services\ReviewService::class)
            ->eligibility($customer, (int) $product->id);
    }

    public function subscribeCustomerStockAlert(Request $request, int $product): JsonResponse
    {
        $row = Product::query()->enabled()->findOrFail($product);
        $alerts = app(CustomerStockAlertService::class);
        $variantId = $request->filled('product_variant_id') ? (int) $request->input('product_variant_id') : null;

        /** @var Customer|null $customer */
        $customer = auth('customer')->user();
        if ($customer instanceof Customer) {
            $request->validate([
                'product_variant_id' => ['nullable', 'integer', 'min:1'],
            ]);
            $subscription = $alerts->subscribe($row, $customer, $variantId);
        } else {
            $data = $request->validate([
                'first_name' => ['required', 'string', 'max:120'],
                'last_name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email:rfc', 'max:190'],
                'product_variant_id' => ['nullable', 'integer', 'min:1'],
            ]);
            $subscription = $alerts->subscribeGuest(
                $row,
                (string) $data['first_name'],
                (string) $data['last_name'],
                (string) $data['email'],
                $variantId,
            );
        }

        return response()->json([
            'ok' => true,
            'subscribed' => true,
            'id' => $subscription->id,
            'guest' => $subscription->isGuest(),
            'message' => 'You are subscribed to a stock alert for this product.',
        ]);
    }

    public function unsubscribeCustomerStockAlert(Request $request, int $product): JsonResponse
    {
        /** @var Customer $customer */
        $customer = auth('customer')->user();
        $data = $request->validate([
            'product_variant_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $row = Product::query()->enabled()->findOrFail($product);
        app(CustomerStockAlertService::class)->unsubscribe(
            $row,
            $customer,
            isset($data['product_variant_id']) ? (int) $data['product_variant_id'] : null,
        );

        return response()->json([
            'ok' => true,
            'subscribed' => false,
            'message' => 'You have unsubscribed from the stock alert.',
        ]);
    }

    public function subscribePreorderNotify(int $product): JsonResponse
    {
        /** @var Customer $customer */
        $customer = auth('customer')->user();
        $row = Product::query()->enabled()->findOrFail($product);
        $preorders = app(PreorderService::class);
        $subscription = $preorders->subscribeNotify($row, $customer);

        return response()->json([
            'ok' => true,
            'subscribed' => true,
            'notify_me_subscribed' => $preorders->isNotifySubscribed($row, $customer),
            'id' => $subscription->id,
            'message' => 'You will be notified when this product becomes available.',
        ]);
    }

    public function unsubscribePreorderNotify(int $product): JsonResponse
    {
        /** @var Customer $customer */
        $customer = auth('customer')->user();
        $row = Product::query()->enabled()->findOrFail($product);
        $preorders = app(PreorderService::class);
        $preorders->unsubscribeNotify($row, $customer);

        return response()->json([
            'ok' => true,
            'subscribed' => false,
            'notify_me_subscribed' => false,
            'message' => 'You have unsubscribed from availability notifications for this product.',
        ]);
    }

    public function brand(string $slug): JsonResponse
    {
        $locale = app()->getLocale();
        $rewrite = app(UrlResolver::class)->locate($slug);
        $brand = $rewrite?->entity_type === 'brand'
            ? Brand::query()->with('translations')->findOrFail($rewrite->entity_id)
            : Brand::query()->whereHas('translations', fn ($t) => $t->where('slug', $slug))->with('translations')->firstOrFail();

        $t = $brand->translation($locale);
        $slugPath = $t?->slug;
        $url = '/brand/'.ltrim((string) ($slugPath ?: $brand->id), '/');
        $rewritePath = app(UrlRewriteService::class)->pathFor('brand', (int) $brand->id, $locale);
        if ($rewritePath) {
            $url = '/'.ltrim($rewritePath, '/');
        }
        $title = $t?->meta_title ?: $t?->name;
        $description = $t?->meta_description ?: $t?->description;
        $ogImage = StorefrontStructuredData::brandOgImage($brand);
        $canonicalAbs = StorefrontStructuredData::publicUrl($url, $locale);

        return response()->json([
            'brand' => [
                'id' => $brand->id,
                'name' => $t?->name,
                'slug' => $slugPath,
                'url' => $url,
                'logo' => CatalogMediaUrl::url($brand->logo_path, DefaultImage::SM),
                'banner' => CatalogMediaUrl::url($brand->banner_path, DefaultImage::LG),
                'short_description' => $t?->short_description,
                'description' => $t?->description,
                'website' => $this->publicWebsite($brand->website),
                'email' => $brand->email,
                'phone' => $this->cleanPhone($brand->phone),
                'address' => $this->brandAddress($brand),
                'social' => $this->brandSocial($brand),
                'seo' => [
                    'title' => $title,
                    'description' => $description,
                    'canonical' => $canonicalAbs,
                    'robots' => 'index,follow',
                    'og_type' => 'website',
                    'og_image' => $ogImage,
                    'hreflang' => StorefrontHreflang::forEntity('brand', (int) $brand->id),
                    'json_ld' => StorefrontStructuredData::brandNode((string) ($t?->name ?: ''), $canonicalAbs, $ogImage),
                ],
            ],
            'products' => tap(
                $this->present->withRatings($brand->products()->enabled()),
                fn ($q) => ProductSettings::applyListingStockFilter($q)
            )
                ->paginate(ProductSettings::storefrontPerPage())
                ->through(fn (Product $p) => $this->present->product($p, $locale)),
        ]);
    }

    /**
     * Only hand the storefront a website that is actually public — the admin
     * form historically defaulted this field to its own edit URL.
     */
    protected function publicWebsite(?string $website): ?string
    {
        $url = trim((string) $website);
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (str_starts_with($path, '/admin')) {
            return null;
        }

        return $url;
    }

    /** Strip the leading quote/space artefacts left by spreadsheet paste. */
    protected function cleanPhone(?string $phone): ?string
    {
        $value = trim((string) $phone, chr(32).chr(9).chr(10).chr(13).chr(0).chr(11).chr(39).chr(34));

        return $value === '' ? null : $value;
    }

    /** @return array<string, string>|null */
    protected function brandAddress(Brand $brand): ?array
    {
        $parts = array_filter([
            'line1' => $brand->address,
            'line2' => $brand->address_line2,
            'city' => $brand->city,
            'state' => $brand->state,
            'postcode' => $brand->postcode,
            'country' => $brand->country,
        ], fn ($value) => trim((string) $value) !== '');

        return $parts === [] ? null : array_map(fn ($v) => trim((string) $v), $parts);
    }

    /**
     * Social links that point somewhere real — drops empty values and the
     * admin-URL artefacts left behind by the demo seeder.
     *
     * @return list<array{key: string, label: string, url: string}>
     */
    protected function brandSocial(Brand $brand): array
    {
        $map = [
            'facebook_url' => ['facebook', 'Facebook'],
            'instagram_url' => ['instagram', 'Instagram'],
            'twitter_url' => ['twitter', 'X'],
            'youtube_url' => ['youtube', 'YouTube'],
            'linkedin_url' => ['linkedin', 'LinkedIn'],
            'tiktok_url' => ['tiktok', 'TikTok'],
        ];

        $out = [];
        foreach ($map as $column => $meta) {
            $url = trim((string) $brand->getAttribute($column));
            if ($url === '' || str_contains($url, '/admin')) {
                continue;
            }
            if (! preg_match('#^https?://#i', $url)) {
                continue;
            }
            $out[] = [
                'key' => $meta[0],
                'label' => $meta[1],
                'url' => $url,
            ];
        }

        return $out;
    }

    public function supplier(string $slug): JsonResponse
    {
        $locale = app()->getLocale();
        $rewrite = app(UrlResolver::class)->locate($slug);
        $supplier = $rewrite?->entity_type === 'supplier'
            ? Supplier::query()->where('is_enabled', true)->with('translations')->findOrFail($rewrite->entity_id)
            : Supplier::query()
                ->where('is_enabled', true)
                ->whereHas('translations', fn ($t) => $t->where('slug', $slug))
                ->with('translations')
                ->firstOrFail();

        return response()->json([
            'supplier' => [
                'id' => $supplier->id,
                'name' => $supplier->translation($locale)?->name,
                'slug' => $supplier->translation($locale)?->slug,
                'description' => $supplier->translation($locale)?->description,
            ],
            'products' => tap(
                $this->present->withRatings($supplier->products()->enabled()),
                fn ($q) => ProductSettings::applyListingStockFilter($q)
            )
                ->paginate(ProductSettings::storefrontPerPage())
                ->through(fn (Product $p) => $this->present->product($p, $locale)),
        ]);
    }

    public function pages(): JsonResponse
    {
        $locale = app()->getLocale();
        $rows = CmsPage::query()->public()->with('translations')->latest('id')->limit(24)->get();

        return response()->json([
            'pages' => $rows->map(function (CmsPage $page) use ($locale) {
                $t = $page->translation($locale);
                return [
                    'id' => $page->id,
                    'title' => $t?->title,
                    'slug' => $t?->slug,
                    'url' => '/'.ltrim((string) ($t?->slug ?: $page->id), '/'),
                    'short_description' => $t?->short_description,
                    'body' => $t?->body,
                ];
            })->values(),
        ]);
    }

    public function page(string $slug): JsonResponse
    {
        $locale = app()->getLocale();
        $rewrite = app(UrlResolver::class)->locate($slug);
        $page = $rewrite?->entity_type === 'cms'
            ? CmsPage::query()->public()->with('translations')->findOrFail($rewrite->entity_id)
            : CmsPage::query()->public()
                ->whereHas('translations', fn ($t) => $t->where('slug', $slug)->where('language_code', $locale))
                ->with('translations')
                ->firstOrFail();
        $t = $page->translation($locale);
        $slugPath = (string) ($t?->slug ?: $page->id);
        $url = '/'.ltrim($slugPath, '/');
        $canonical = $page->canonical_url ?: $url;
        $canonicalAbs = StorefrontStructuredData::publicUrl((string) $canonical, $locale);
        $ogImage = StorefrontStructuredData::cmsOgImage($page);
        $title = $t?->meta_title ?: $t?->title;
        $description = $t?->meta_description ?: $t?->short_description;
        $cmsEntityId = (int) ($rewrite?->entity_type === 'cms' ? $rewrite->entity_id : $page->id);

        return response()->json(['page' => [
            'title' => $t?->title,
            'slug' => $t?->slug,
            'template' => $page->template,
            'body' => HtmlSanitizer::clean($t?->body),
            'meta_title' => $t?->meta_title,
            'meta_description' => $t?->meta_description,
            'short_description' => HtmlSanitizer::clean($t?->short_description),
            'canonical_url' => $page->canonical_url,
            'robots' => $page->robots,
            'layout' => CmsPageLayout::forSlug($t?->slug, $page->template),
            'seo' => [
                'title' => $title,
                'description' => $description,
                'canonical' => $canonicalAbs,
                'robots' => $page->robots ?: 'index,follow',
                'og_type' => 'article',
                'og_image' => $ogImage,
                'hreflang' => StorefrontHreflang::forEntity('cms', $cmsEntityId),
                'json_ld' => StorefrontStructuredData::webPage(
                    (string) ($t?->title ?: ''),
                    $canonicalAbs,
                    (string) ($description ?: ''),
                ),
            ],
        ]]);
    }

    public function search(Request $request, CatalogSearch $search): JsonResponse
    {
        $locale = app()->getLocale();
        $q = (string) $request->q;
        $redirect = \App\Domain\Search\Models\SearchRedirect::targetFor($q);
        if ($redirect) {
            $safe = \App\Domain\Security\SafeRedirect::sanitize($redirect);
            if ($safe) {
                return response()->json(['redirect' => $safe, 'query' => $q]);
            }
        }

        $results = $search->products($q, $locale, ProductSettings::storefrontPerPage(), function ($query) use ($request) {
            $this->applyListingFilters($query, $request);
        });

        $filters = StorefrontCatalogCache::remember('search.facets.'.$locale, 120, function () use ($locale) {
            return Feature::query()
            ->where('is_filterable', true)
            ->where('is_enabled', true)
            ->with([
                'translations',
                'values' => fn ($qv) => $qv->where('is_enabled', true)
                    ->whereHas('products', fn ($p) => ProductSettings::applyListingStockFilter($p->enabled()->visibleIn('search')))
                    ->with('translations')
                    ->orderBy('position'),
            ])
            ->orderBy('position')
            ->get()
            ->map(fn ($feature) => [
                'id' => $feature->id,
                'code' => $feature->code,
                'type' => $feature->type,
                'name' => $feature->translation($locale)?->name,
                'values' => $feature->values->map(fn ($value) => [
                    'id' => $value->id,
                    'value' => $value->translation($locale)?->value,
                ])->values()->all(),
            ])
            ->filter(fn (array $feature) => $feature['values'] !== [])
            ->values()
            ->all();
        });

        $payload = $results->through(fn (Product $p) => $this->present->product($p, $locale))->toArray();
        $payload['filters'] = $filters;
        $payload['query'] = $q;

        $this->trackCatalogAnalytics('catalog.search', [
            'term' => $q,
            'q' => $q,
            'results' => (int) ($payload['total'] ?? $results->total() ?? count($payload['data'] ?? [])),
            'locale' => $locale,
        ]);

        return response()->json($payload);
    }

    public function compare(Request $request): JsonResponse
    {
        if (! ProductSettings::compareEnabled()) {
            return response()->json(['message' => 'Product comparison is disabled.', 'products' => [], 'fields' => []], 404);
        }

        $compareMax = ProductSettings::compareMax();
        $ids = collect($request->input('ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->take($compareMax)
            ->values();
        if ($ids->isEmpty()) {
            return response()->json(['products' => []]);
        }

        $locale = app()->getLocale();
        $order = $ids->flip();
        $products = $this->present->withRatings(
            Product::query()->with([
                'seller',
                'attachments.translations',
                'brand.translations',
                'supplier.translations',
                'suppliers.translations',
                'categories.translations',
                'categories.parent.translations',
                'featureValues.translations',
                'featureValues.feature.translations',
                'shippingMethods',
                'customizationFields',
                'productSubscriptionPlans.plan.translations',
                'giftCardConfig',
                'giftCardAmounts',
                'components.child.translations',
                'serviceSetting',
                'rentalSetting',
                'rentalPricingTiers',
                'rentalAssets',
            ])
        )->whereIn('id', $ids->all())->get()
            ->filter(fn (Product $product) => ! ProductSettings::hiddenFromStorefront($product))
            ->sortBy(fn (Product $product) => $order[$product->id] ?? PHP_INT_MAX)
            ->values();

        return response()->json([
            'products' => $products->map(fn (Product $p) => $this->present->product($p, $locale, true))->values()->all(),
            'fields' => ProductSettings::compareFieldsForStorefront(),
        ]);
    }

    public function resolve(string $path): JsonResponse
    {
        $resolved = app(UrlResolver::class)->resolve($path);
        $rewrite = $resolved['rewrite'];

        return response()->json([
            'rewrite' => $rewrite ? [
                'entity_type' => $rewrite->entity_type,
                'entity_id' => $rewrite->entity_id,
                'request_path' => $rewrite->request_path,
                'language_code' => $rewrite->language_code,
            ] : null,
            'redirect_to' => $resolved['redirect_to'],
        ]);
    }

    protected function applyListingFilters($products, Request $request): void
    {
        foreach ((array) $request->input('filters', []) as $featureId => $valueIds) {
            $products->whereHas('featureValues', fn ($q) => $q->whereIn('feature_values.id', (array) $valueIds));
        }

        $price = (string) $request->input('price', '');
        $min = $request->input('price_min');
        $max = $request->input('price_max');
        if (str_contains($price, '-')) {
            [$min, $max] = array_pad(explode('-', $price, 2), 2, null);
        }
        if ($min !== null && $min !== '') {
            $products->where('price', '>=', Money::toDefault((float) $min));
        }
        if ($max !== null && $max !== '') {
            $products->where('price', '<=', Money::toDefault((float) $max));
        }

        $brand = $request->input('brand');
        if (is_string($brand) && $brand !== '') {
            $parts = array_values(array_filter(explode(',', $brand)));
            if ($parts !== [] && collect($parts)->every(fn ($part) => ctype_digit((string) $part))) {
                $products->whereIn('brand_id', array_map('intval', $parts));
            } else {
                $slugs = array_map('strtolower', $parts);
                $products->whereHas('brand.translations', fn ($q) => $q->whereIn('slug', $slugs)->orWhereIn('name', $slugs));
            }
        } elseif (is_array($brand) && $brand !== []) {
            $products->whereIn('brand_id', $brand);
        }

        if ($request->filled('rating')) {
            $minRating = (float) $request->input('rating');
            $reviews = $this->rawTable(new Review);
            $productsTable = $this->rawTable(new Product);
            $products->whereRaw(
                "(select avg(rating) from {$reviews} where {$reviews}.product_id = {$productsTable}.id and {$reviews}.is_approved = 1) >= ?",
                [$minRating]
            );
        }

        if ($request->filled('discount')) {
            $minOff = (int) $request->input('discount');
            $table = $this->rawTable(new Product);
            $products->whereNotNull('compare_price')
                ->whereRaw("(1 - ({$table}.price / NULLIF({$table}.compare_price, 0))) * 100 >= ?", [$minOff]);
        }

        $availability = (string) $request->input('availability', '');
        if ($availability === 'in_stock') {
            $products->where('stock_status', 'in_stock');
        }

        $color = $request->input('color');
        if (is_string($color) && $color !== '') {
            $products->whereHas('featureValues.translations', fn ($q) => $q->where('value', $color));
        }

        if ($request->filled('values')) {
            $ids = array_filter(array_map('intval', explode(',', (string) $request->input('values'))));
            if ($ids !== []) {
                $products->whereHas('featureValues', fn ($q) => $q->whereIn('feature_values.id', $ids));
            }
        }

        foreach ((array) $request->input('feature', []) as $code => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $products->whereHas('featureValues', function ($q) use ($code, $value) {
                $q->whereHas('feature', fn ($f) => $f->where('code', $code))
                    ->where(function ($inner) use ($value) {
                        $inner->where('feature_values.id', $value)
                            ->orWhereHas('translations', fn ($t) => $t->where('value', $value));
                    });
            });
        }
    }

    protected function applySort($products, string $sort): void
    {
        $table = (new Product)->getTable();
        $raw = $this->rawTable(new Product);
        $sort = SearchCatalogSettings::normalizeSort($sort);

        match ($sort) {
            'newest' => $products->orderByDesc($table.'.id'),
            'price_asc' => $products->orderBy($table.'.price')->orderByDesc($table.'.id'),
            'price_desc' => $products->orderByDesc($table.'.price')->orderByDesc($table.'.id'),
            'rating' => $products->orderByDesc('reviews_avg_rating')->orderByDesc($table.'.id'),
            'discount' => $products->orderByRaw(
                'CASE WHEN '.$raw.'.compare_price > 0 THEN (('.$raw.'.compare_price - '.$raw.'.price) * 1.0) / '.$raw.'.compare_price ELSE 0 END DESC'
            )->orderByDesc($table.'.id'),
            // Browse listings have no query score; relevance follows popularity/featured.
            'relevance', 'popularity' => $products->orderByDesc($table.'.is_best_seller')->orderByDesc($table.'.id'),
            default => $products->orderByDesc($table.'.is_best_seller')->orderByDesc($table.'.id'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Direct children, with every deeper level nested under children.
     *
     * @param  iterable<int, Category>  $roots
     * @return list<array<string, mixed>>
     */
    protected function nestedCategoryTree(iterable $roots, string $locale): array
    {
        $rootIds = collect($roots)->pluck('id')->map(fn ($id) => (int) $id)->filter()->values()->all();
        if ($rootIds === []) {
            return [];
        }

        $all = Category::query()
            ->where('is_enabled', true)
            ->with('translations')
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        $forest = $this->present->categoryForest($all, $locale, $rootIds);

        $ids = [];
        $collect = function (array $nodes) use (&$collect, &$ids): void {
            foreach ($nodes as $node) {
                $ids[] = (int) ($node['id'] ?? 0);
                $collect($node['children'] ?? []);
            }
        };
        $collect($forest);
        $counts = CategoryProductQuery::counts(array_values(array_filter($ids)));

        $apply = function (array $nodes) use (&$apply, $counts): array {
            return array_map(function (array $node) use (&$apply, $counts) {
                $node['products_count'] = $counts[(int) ($node['id'] ?? 0)] ?? 0;
                $node['children'] = $apply($node['children'] ?? []);

                return $node;
            }, $nodes);
        };

        return $apply($forest);
    }

    protected function categoryListingRelations(): array
    {
        return [
            'translations',
            'parent.translations',
            'children' => function ($q) {
                $q->where('is_enabled', true)
                    ->orderBy('position')
                    ->with('translations');
            },
        ];
    }

    protected function descendantIds(Category $category): Collection
    {
        $ids = collect([$category->id]);
        $pending = [$category->id];
        while ($pending !== []) {
            $children = Category::query()->whereIn('parent_id', $pending)->pluck('id')->all();
            $pending = array_values(array_diff($children, $ids->all()));
            $ids = $ids->merge($children)->unique()->values();
        }

        return $ids;
    }

    protected function rawTable(Model $model): string
    {
        return $model->getConnection()->getTablePrefix().$model->getTable();
    }

    /**
     * @return list<array{name: string, url: ?string}>
     */
    protected function breadcrumbs(Category $category, string $locale): array
    {
        $nodes = [];
        $cursor = $category;
        $guard = 0;
        while ($cursor && $guard < 8) {
            array_unshift($nodes, [
                'name' => $cursor->translation($locale)?->name,
                'url' => '/'.ltrim((string) (app(UrlRewriteService::class)->pathFor('category', $cursor->id, $locale) ?: $cursor->translation($locale)?->slug), '/'),
            ]);
            $cursor->loadMissing('parent.translations');
            $cursor = $cursor->parent;
            $guard++;
        }
        array_unshift($nodes, ['name' => 'Home', 'url' => '/']);

        return $nodes;
    }

    protected function brandLogoUrl(?string $path): string
    {
        return CatalogMediaUrl::url($path, DefaultImage::SM);
    }

    /**
     * Lightweight analytics row for reporting panels (no webhook fan-out).
     *
     * @param  array<string, mixed>  $payload
     */
    protected function trackCatalogAnalytics(string $name, array $payload): void
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('commerce_events')) {
                return;
            }
            CommerceEvent::query()->create([
                'store_id' => app(StoreContext::class)->id() ?: null,
                'name' => $name,
                'operation_id' => null,
                'payload' => $payload,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Analytics must never break storefront reads.
        }
    }
}
