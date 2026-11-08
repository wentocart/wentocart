<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\CatalogPresenter;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\ProductFlag;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerFlag;
use App\Domain\Marketplace\Models\SellerMessage;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Marketplace\Models\SellerReview;
use App\Domain\Marketplace\ProductFlagSchema;
use App\Domain\Marketplace\SellerFeaturedSchema;
use App\Domain\Marketplace\SellerFlagSchema;
use App\Domain\Marketplace\MarketplaceLandingSettings;
use App\Domain\Marketplace\SellerProductOfferSchema;
use App\Domain\Marketplace\Services\MarketplaceStatsService;
use App\Domain\Marketplace\Services\SellerReviewService;
use App\Domain\Marketplace\Services\SellerService;
use App\Domain\Marketplace\Services\SellerSubscriptionGate;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketplaceShopController extends Controller
{
    public function __construct(
        protected CatalogPresenter $present,
        protected SellerReviewService $reviews,
        protected MarketplaceStatsService $stats,
        protected SellerService $sellers,
        protected SellerSubscriptionGate $subscriptionGate,
    ) {}

    public function show(Request $request, string $slug): JsonResponse
    {
        $seller = $this->publicSeller($slug);
        $locale = app()->getLocale();
        $baseQuery = $this->sellerProductQuery($seller);

        $searchQuery = clone $baseQuery;
        $this->applySellerSearch($searchQuery, (string) $request->input('q', ''), $locale);

        $tabCounts = (clone $searchQuery)->selectRaw(
            'COUNT(*) as total,
            SUM(CASE WHEN is_best_seller = 1 THEN 1 ELSE 0 END) as bestsellers,
            SUM(CASE WHEN is_on_sale = 1 OR (compare_price IS NOT NULL AND compare_price > price) THEN 1 ELSE 0 END) as discounted'
        )->first();
        $productsCount = (int) ($tabCounts->total ?? 0);
        $discountedCount = (int) ($tabCounts->discounted ?? 0);

        $tab = (string) $request->input('tab', 'all');
        if (! in_array($tab, ['all', 'bestsellers', 'discounted'], true)) {
            $tab = 'all';
        }

        $listingQuery = clone $searchQuery;
        $this->applySellerTab($listingQuery, $tab);
        $this->applySellerSort($listingQuery, (string) $request->input('sort', 'price_asc'));

        $products = $listingQuery->paginate(12)->withQueryString();

        $summary = $this->reviews->publicSummary($seller);
        if ($summary['count'] > 0) {
            $reviewPage = max(1, (int) $request->input('review_page', 1));
            $approvedReviews = $seller->sellerReviews()
                ->where('status', SellerReview::STATUS_APPROVED)
                ->with('customer:id,name')
                ->latest()
                ->paginate(10, ['*'], 'review_page', $reviewPage);
        } else {
            $approvedReviews = new \Illuminate\Pagination\LengthAwarePaginator([], 0, 10, 1, ['pageName' => 'review_page']);
        }
        $customer = request()->user('customer');
        $canReview = $customer ? $this->reviews->customerCanReview($customer, $seller) : false;

        return response()->json([
            'shop' => $this->presentShop($seller, $summary, $productsCount),
            'can_review' => $canReview,
            'products' => [
                'data' => $products->getCollection()->map(fn (Product $product) => $this->present->product($product, $locale, false, true))->values(),
                'meta' => [
                    'current_page' => $products->currentPage(),
                    'last_page' => $products->lastPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                    'from' => $products->firstItem(),
                    'to' => $products->lastItem(),
                    'tabs' => [
                        'all' => $productsCount,
                        'bestsellers' => (int) ($tabCounts->bestsellers ?? 0),
                        'discounted' => $discountedCount,
                    ],
                ],
            ],
            'reviews' => [
                'data' => $approvedReviews->getCollection()->map(fn (SellerReview $review) => $this->reviews->presentPublic($review))->values(),
                'meta' => [
                    'current_page' => $approvedReviews->currentPage(),
                    'last_page' => $approvedReviews->lastPage(),
                    'per_page' => $approvedReviews->perPage(),
                    'total' => $approvedReviews->total(),
                ],
            ],
        ]);
    }

    public function featured(Request $request): JsonResponse
    {
        if (! MarketplaceSettings::showPublicly()) {
            return response()->json(['data' => []]);
        }
        SellerFeaturedSchema::ensure();
        $limit = min(50, max(1, (int) $request->query('limit', $request->query('per_page', 12))));
        $rows = Seller::query()
            ->where('status', Seller::STATUS_APPROVED)
            ->where('is_featured', true)
            ->where(function ($q) {
                $q->whereNull('is_active')->orWhere('is_active', true);
            })
            ->orderBy('featured_position')
            ->orderBy('shop_name')
            ->limit($limit * 3)
            ->get(['id', 'shop_name', 'shop_slug', 'logo_path', 'banner_path', 'featured_position', 'description'])
            ->filter(fn (Seller $s) => $this->subscriptionGate->hasActivePlan($s))
            ->take($limit)
            ->values();

        return response()->json([
            'data' => $rows->map(fn (Seller $s) => [
                'id' => (int) $s->id,
                'shopName' => (string) $s->shop_name,
                'shopSlug' => (string) $s->shop_slug,
                'logoPath' => $s->logo_path,
                'bannerPath' => $s->banner_path,
                'featuredPosition' => (int) ($s->featured_position ?? 0),
                'description' => (string) ($s->description ?? ''),
            ])->values()->all(),
        ]);
    }

    public function flag(Request $request, string $slug): JsonResponse
    {
        $seller = $this->publicSeller($slug);
        $customer = $request->user('customer');
        if (! $customer) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }
        if ((int) $seller->customer_id === (int) $customer->id) {
            return response()->json(['message' => 'You cannot flag your own shop.', 'error' => 'validation'], 422);
        }
        $purchased = SellerOrder::query()
            ->where('seller_id', $seller->id)
            ->whereHas('order', fn ($q) => $q->where('customer_id', $customer->id))
            ->exists();
        if (! $purchased) {
            return response()->json([
                'message' => 'Buy from this shop before reporting the seller.',
                'error' => 'validation',
            ], 422);
        }
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:2000'],
        ]);
        SellerFlagSchema::ensure();
        if (SellerFlag::query()
            ->where('seller_id', $seller->id)
            ->where('customer_id', $customer->id)
            ->where('status', SellerFlag::STATUS_PENDING)
            ->exists()) {
            return response()->json(['message' => 'You already have a pending report for this seller.', 'error' => 'validation'], 422);
        }
        $flag = SellerFlag::query()->create([
            'seller_id' => $seller->id,
            'customer_id' => $customer->id,
            'reason' => $data['reason'],
            'body' => $data['body'] ?? null,
            'status' => SellerFlag::STATUS_PENDING,
        ]);

        return response()->json([
            'ok' => true,
            'flag' => [
                'id' => (int) $flag->id,
                'status' => $flag->status,
                'reason' => $flag->reason,
            ],
        ], 201);
    }

    /** marketplace landing page (become-a-seller). */
    public function landing(): JsonResponse
    {
        if (! MarketplaceSettings::enabled()) {
            return response()->json(['message' => 'Marketplace is disabled.', 'error' => 'disabled'], 404);
        }
        $all = MarketplaceLandingSettings::all();
        $sellers = Seller::query()->where('status', Seller::STATUS_APPROVED)->count();

        return response()->json([
            'data' => [
                'bannerTitle' => (string) ($all['banner_title'] ?? ''),
                'bannerDescription' => (string) ($all['banner_description'] ?? ''),
                'bannerButtonTitle' => (string) ($all['banner_button_title'] ?? ''),
                'bannerImage' => (string) ($all['banner_image'] ?? ''),
                'highlights' => [
                    'communityCount' => (string) (($all['community_count'] ?? '') !== '' ? $all['community_count'] : $sellers),
                    'businessHour' => (string) ($all['business_hour'] ?? ''),
                    'paymentDuration' => (string) ($all['payment_duration'] ?? ''),
                    'serviceablePincode' => (string) ($all['serviceable_pincode'] ?? ''),
                ],
                'featuresHeading' => (string) ($all['features_heading'] ?? ''),
                'featuresDescription' => (string) ($all['features_description'] ?? ''),
                'featuresImage' => (string) ($all['features_image'] ?? ''),
                'featureBoxes' => array_values($all['feature_boxes'] ?? []),
                'journeyHeading' => (string) ($all['journey_heading'] ?? ''),
                'journeyDescription' => (string) ($all['journey_description'] ?? ''),
                'journeySteps' => array_values($all['journey_steps'] ?? []),
            ],
        ]);
    }

    /**
     */
    public function sellerOffers(int $id): JsonResponse
    {
        $product = Product::query()->find($id);
        if (! $product || ! $product->is_enabled) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        SellerProductOfferSchema::ensure();
        $rows = SellerProduct::query()
            ->with(['seller:id,shop_name,shop_slug,status,rating_avg,rating_count'])
            ->where('product_id', $product->id)
            ->where('is_catalog_offer', true)
            ->where('is_draft', false)
            ->where('approval_status', SellerProduct::STATUS_APPROVED)
            ->where('is_enabled', true)
            ->whereHas('seller', fn (Builder $q) => $q->where('status', Seller::STATUS_APPROVED))
            ->orderBy('price')
            ->orderBy('id')
            ->get()
            ->map(fn (SellerProduct $offer) => [
                'sellerProductId' => (int) $offer->id,
                'sellerId' => (int) $offer->seller_id,
                'shopName' => (string) ($offer->seller?->shop_name ?? ''),
                'shopSlug' => (string) ($offer->seller?->shop_slug ?? ''),
                'price' => (float) $offer->price,
                'stockQty' => (int) $offer->stock_qty,
                'condition' => (string) ($offer->condition ?? 'new'),
                'description' => (string) ($offer->description ?? ''),
                'ratingAvg' => (float) ($offer->seller?->rating_avg ?? 0),
                'ratingCount' => (int) ($offer->seller?->rating_count ?? 0),
            ])
            ->values()
            ->all();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'productId' => (int) $product->id,
                'catalogPrice' => (float) $product->price,
                'total' => count($rows),
            ],
        ]);
    }

    public function flagProduct(Request $request, int $id): JsonResponse
    {
        $customer = $request->user('customer');
        if (! $customer) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }
        $product = Product::query()->find($id);
        if (! $product || ! $product->is_enabled) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:2000'],
        ]);
        ProductFlagSchema::ensure();
        if (ProductFlag::query()
            ->where('product_id', $product->id)
            ->where('customer_id', $customer->id)
            ->where('status', ProductFlag::STATUS_PENDING)
            ->exists()) {
            return response()->json(['message' => 'You already have a pending report for this product.', 'error' => 'validation'], 422);
        }
        $flag = ProductFlag::query()->create([
            'product_id' => $product->id,
            'seller_id' => $product->seller_id ? (int) $product->seller_id : null,
            'customer_id' => $customer->id,
            'reason' => $data['reason'],
            'body' => $data['body'] ?? null,
            'status' => ProductFlag::STATUS_PENDING,
        ]);

        return response()->json([
            'ok' => true,
            'flag' => [
                'id' => (int) $flag->id,
                'status' => $flag->status,
                'reason' => $flag->reason,
            ],
        ], 201);
    }

    public function contact(Request $request, string $slug): JsonResponse
    {
        $seller = $this->publicSeller($slug);
        $customer = $request->user('customer');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'body' => ['required', 'string', 'min:8', 'max:4000'],
        ]);

        $message = SellerMessage::query()->create([
            'seller_id' => $seller->id,
            'customer_id' => $customer?->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'body' => $data['body'],
        ]);

        return response()->json(['ok' => true, 'id' => $message->id], 201);
    }

    public function review(Request $request): JsonResponse
    {
        $customer = $request->user('customer');
        // Never trust client status / ownership fields.
        $request->request->remove('status');
        $request->request->remove('customer_id');
        $request->request->remove('order_id');
        $request->request->remove('seller_order_id');
        $request->request->remove('is_verified_purchase');
        $request->request->remove('moderated_by');

        $data = $request->validate([
            'seller_id' => ['required', 'integer', 'exists:sellers,id'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:160'],
            'body' => ['nullable', 'string', 'max:4000'],
        ]);

        $seller = Seller::query()->findOrFail((int) $data['seller_id']);
        $review = $this->reviews->create($customer, $seller, $data);

        return response()->json([
            'review' => [
                'id' => (int) $review->id,
                'rating' => (int) $review->rating,
                'title' => $review->title,
                'body' => $review->body,
                'status' => (string) $review->status,
                'verified_purchase' => (bool) $review->is_verified_purchase,
            ],
        ], 201);
    }

    protected function publicSeller(string $slug): Seller
    {
        if (! MarketplaceSettings::showPublicly()) {
            abort(404);
        }
        $seller = Seller::query()->where('shop_slug', $slug)->with('translations')->firstOrFail();
        if (! $seller->isPublic()) {
            abort(404);
        }
        $this->subscriptionGate->assertPubliclyVisible($seller);

        return $seller;
    }

    /**
     * @param  array{average: float|null, count: int, distribution: array<int, int>}  $summary
     * @return array<string, mixed>
     */
    protected function presentShop(Seller $seller, array $summary, int $productsCount): array
    {
        $locale = app()->getLocale();
        $localized = $seller->translation($locale);
        $stats = $this->stats->publicShopStats($seller, $productsCount);

        return [
            'id' => $seller->id,
            'shop_name' => $localized?->shop_name ?: $seller->shop_name,
            'shop_slug' => $seller->shop_slug,
            'url' => '/store/'.$seller->shop_slug,
            'description' => $localized?->description ?? $seller->description,
            'logo_url' => $this->sellers->publicMediaUrl($seller->logo_path),
            'banner_url' => $this->sellers->publicMediaUrl($seller->banner_path),
            'city' => $seller->city,
            'country_code' => $seller->country_code,
            'contact_email' => $seller->contact_email,
            'contact_phone' => $seller->contact_phone,
            'facebook_url' => $seller->facebook_url,
            'twitter_url' => $seller->twitter_url,
            'instagram_url' => $seller->instagram_url,
            'youtube_url' => $seller->youtube_url,
            'store_policy' => $localized?->store_policy ?? $seller->store_policy,
            'shipping_policy' => $localized?->shipping_policy ?? $seller->shipping_policy,
            'return_policy' => $localized?->return_policy ?? $seller->return_policy,
            'vacation_enabled' => (bool) $seller->vacation_enabled,
            'vacation_message' => $localized?->vacation_message ?? $seller->vacation_message,
            'products_count' => $stats['products_count'],
            'sold_count' => $stats['sold_count'],
            'member_since' => $stats['member_since'],
            'rating' => $summary,
        ];
    }

    protected function sellerProductQuery(Seller $seller): Builder
    {
        return $this->present->withRatings(
            Product::query()->where('seller_id', $seller->id)
        )->enabled()
            ->visibleIn('catalog');
    }

    protected function applySellerSearch(Builder $products, string $term, string $locale): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }

        $like = '%'.$term.'%';
        $products->where(function (Builder $query) use ($like, $locale) {
            $query->where('sku', 'like', $like)
                ->orWhereHas('translations', fn (Builder $t) => $t
                    ->where('language_code', $locale)
                    ->where('name', 'like', $like));
        });
    }

    protected function applySellerTab(Builder $products, string $tab): void
    {
        match ($tab) {
            'bestsellers' => $products->where('is_best_seller', true),
            'discounted' => $this->discountedSellerProducts($products),
            default => null,
        };
    }

    protected function discountedSellerProducts(Builder $products): Builder
    {
        $table = $products->getModel()->getTable();

        return $products->where(function (Builder $query) use ($table) {
            $query->where("{$table}.is_on_sale", true)
                ->orWhere(function (Builder $inner) use ($table) {
                    $inner->whereNotNull("{$table}.compare_price")
                        ->whereColumn("{$table}.compare_price", '>', "{$table}.price");
                });
        });
    }

    protected function applySellerSort(Builder $products, string $sort): void
    {
        $table = $products->getModel()->getTable();
        match ($sort) {
            'newest' => $products->orderByDesc("{$table}.id"),
            'price_desc', 'price-high' => $products->orderByDesc("{$table}.price"),
            'price_asc', 'price-low' => $products->orderBy("{$table}.price"),
            'rating' => $products->orderByDesc('reviews_avg_rating'),
            default => $products->orderBy("{$table}.price"),
        };
    }
}
