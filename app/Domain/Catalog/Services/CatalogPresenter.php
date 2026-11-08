<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Catalog\SaleUnit;
use App\Domain\Catalog\Support\CatalogMediaUrl;
use App\Domain\Catalog\Support\DefaultImage;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Customer\Models\ProductQuestion;
use App\Domain\Customer\Models\Review;
use App\Domain\Localization\Money;
use App\Domain\Security\HtmlSanitizer;
use App\Domain\Settings\CommunityFeatures;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\OmnibusSettings;
use App\Domain\Settings\PricingSettings;
use App\Domain\Settings\ProductSettings;
use App\Domain\Settings\ReviewSettings;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Subscription\Services\SubscriptionService;

class CatalogPresenter
{
    public function __construct(
        protected ImageOptimizer $images,
        protected UrlRewriteService $urls,
        protected CustomizationService $customizations,
        protected SubscriptionService $subscriptions,
        protected CombinationService $combinations,
    ) {}

    public function product(Product $product, string $locale, bool $full = false, bool $trustCatalogFilters = false): array
    {
        $t = $product->translation($locale);
        $showPrice = $product->show_price ?? true;
        if (ProductSettings::catalogMode() && ! ProductSettings::showPrice()) {
            $showPrice = false;
        }
        $customer = auth('customer')->user();
        if (ProductSettings::hidePricesGuests() && ! $customer) {
            $showPrice = false;
        }
        if (\App\Domain\Settings\CustomerSettings::customerGroup($customer)?->hide_prices) {
            $showPrice = false;
        }
        $selling = $showPrice ? $product->sellingPrice() : null;
        if ($showPrice && $product->isService()) {
            $product->loadMissing('serviceSetting');
            $selling = app(ServiceProductService::class)->applyPricingModel($product, (float) ($selling ?? $product->sellingPrice()));
        }
        if ($showPrice && $product->isRental()) {
            $product->loadMissing(['rentalSetting', 'rentalPricingTiers']);
            $duration = max(1, (int) ($product->rentalSetting?->default_duration ?: 1));
            $selling = app(RentalProductService::class)->unitPriceForDuration(
                $product,
                $duration,
                (float) ($selling ?? $product->sellingPrice()),
            );
        }
        $compare = null;
        if ($showPrice && PricingSettings::compareAtEnabled()) {
            $regular = (float) $product->price;
            if ($product->compare_price) {
                $compare = (float) $product->compare_price;
            } elseif ($product->saleIsLive() && $selling !== null && $selling < $regular) {
                // Auto “was” price from regular when a live sale has no explicit MSRP.
                $compare = $regular;
            }
            if ($compare !== null && $selling !== null && $compare <= $selling) {
                $compare = null;
            }
        }
        $groupPercent = \App\Domain\Settings\CustomerSettings::groupDiscountPercent($customer);
        if ($showPrice && $selling !== null && $groupPercent > 0 && $groupPercent < 100) {
            $beforeGroup = round(((float) $selling) / (1 - ($groupPercent / 100)), 2);
            if ($beforeGroup > (float) $selling && ($compare === null || $compare <= (float) $selling)) {
                $compare = $beforeGroup;
            }
        }
        $discount = null;
        if ($selling !== null && $compare && $compare > $selling) {
            $discount = (int) round((1 - ($selling / $compare)) * 100);
        }

        $zeroMode = PricingSettings::zeroPrice();
        $isZero = $selling !== null && (float) $selling <= 0;
        if ($isZero && $zeroMode === 'hide') {
            $showPrice = false;
            $selling = null;
            $compare = null;
            $discount = null;
        }

        $ratingAvg = $product->reviews_avg_rating ?? null;
        $ratingCount = (int) ($product->approved_reviews_count ?? $product->reviews_count ?? 0);
        if (! ReviewSettings::enabled()) {
            $ratingAvg = null;
            $ratingCount = 0;
        }

        $taxClassId = $product->tax_class_id ? (int) $product->tax_class_id : ProductSettings::defaultTaxClassId();
        $displayBundle = $selling !== null
            ? PricingSettings::catalogDisplayAmounts((float) $selling, $taxClassId)
            : null;
        $displaySelling = $displayBundle !== null
            ? Money::fromDefault((float) $displayBundle['amount'])
            : null;
        $displaySellingExcl = $displayBundle !== null
            ? Money::fromDefault((float) $displayBundle['amount_excl'])
            : null;
        $displaySellingIncl = $displayBundle !== null
            ? Money::fromDefault((float) $displayBundle['amount_incl'])
            : null;
        $displayCompare = null;
        if ($compare !== null) {
            $compareBundle = PricingSettings::catalogDisplayAmounts((float) $compare, $taxClassId);
            $displayCompare = Money::fromDefault((float) $compareBundle['amount']);
        }

        $brand = $product->relationLoaded('brand') ? $product->brand : null;
        $coverType = $full ? 'large' : 'home';
        $cover = $this->images->publicPath($product->cover_path, $coverType) ?: $product->cover_path;
        $coverSize = DefaultImage::sizeForType($coverType);

        // Catalog "available for order" must not collapse the PDP buy box when stock is zero
        // under "Do not allow" — storefront shows a disabled Add to cart + notify instead.
        $purchasable = $trustCatalogFilters
            ? (bool) ($product->available_for_order ?? true)
            : app(CartService::class)->isPurchasable($product, false);
        $canAddToCart = $purchasable && (
            $trustCatalogFilters
                ? true
                : app(CartService::class)->isPurchasable($product, true)
        );
        $contactForPrice = $isZero && $zeroMode === 'contact';
        if ($contactForPrice) {
            $purchasable = false;
            $canAddToCart = false;
        }
        if ((ProductSettings::hidePricesGuests() && ! $customer) || \App\Domain\Settings\CustomerSettings::customerGroup($customer)?->hide_prices) {
            $purchasable = false;
            $canAddToCart = false;
        }

        $catalogSelling = $selling !== null ? Money::fromDefault((float) $selling) : null;

        $onSale = $product->saleIsLive()
            && $selling !== null
            && (float) $selling < (float) $product->price;
        $omnibusWindowDays = null;
        $omnibusLowest = null;
        if ($showPrice && $onSale && OmnibusSettings::enabled() && $selling !== null) {
            $windowDays = OmnibusSettings::windowDays();
            $rawLowest = app(PriceHistoryRecorder::class)->lowestPriceLastDays(
                (int) $product->id,
                $windowDays,
                (float) $selling,
            );
            if ($rawLowest !== null) {
                $omnibusWindowDays = $windowDays;
                $omnibusLowest = Money::fromDefault(
                    PricingSettings::toStorefrontAmount($rawLowest, $taxClassId),
                );
            }
        }

        $data = [
            'id' => $product->id,
            'type' => $product->type,
            'sku' => $product->sku,
            // Storefront main price (respects storefront_display). Cart math uses price_catalog.
            'price' => $displaySelling,
            'price_formatted' => $displaySelling !== null ? Money::format($displaySelling) : null,
            'price_catalog' => $catalogSelling,
            'price_catalog_formatted' => $catalogSelling !== null ? Money::format($catalogSelling) : null,
            'price_excl' => $displaySellingExcl,
            'price_excl_formatted' => $displaySellingExcl !== null ? Money::format($displaySellingExcl) : null,
            'price_incl' => $displaySellingIncl,
            'price_incl_formatted' => $displaySellingIncl !== null ? Money::format($displaySellingIncl) : null,
            'compare_price' => $displayCompare,
            'compare_price_formatted' => $displayCompare !== null ? Money::format($displayCompare) : null,
            'discount_percent' => $discount,
            'omnibus_lowest_price' => $omnibusLowest,
            'omnibus_lowest_price_formatted' => $omnibusLowest !== null ? Money::format($omnibusLowest) : null,
            'omnibus_window_days' => $omnibusWindowDays,
            'show_price' => (bool) $showPrice,
            'available_for_order' => $purchasable,
            'can_add_to_cart' => $canAddToCart,
            'contact_for_price' => $contactForPrice,
            'stock_status' => $product->stock_status,
            // Exact qty only on PDP; listing cards use stock_status to avoid leaking inventory depth.
            'stock_qty' => $full ? $product->stock_qty : null,
            'manage_stock' => (bool) $product->manage_stock,
            'allow_backorder' => (bool) $product->allow_backorder,
            'backorder_status' => ProductSettings::productStockBehavior($product),
            'flags' => [
                'new' => (bool) $product->is_new,
                'on_sale' => $onSale,
                'best_seller' => (bool) $product->is_best_seller,
            ],
            'name' => $t?->name,
            'slug' => $t?->slug,
            'url' => $trustCatalogFilters
                ? '/'.ltrim((string) $t?->slug, '/')
                : '/'.ltrim((string) ($this->urls->pathFor('product', $product->id, $locale) ?: $t?->slug), '/'),
            'short_description' => HtmlSanitizer::clean($t?->short_description),
            'cover' => $cover,
            'cover_url' => CatalogMediaUrl::url($cover, $coverSize),
            'brand' => $brand ? [
                'id' => $brand->id,
                'name' => $brand->translation($locale)?->name,
                'slug' => $brand->translation($locale)?->slug,
                'logo' => CatalogMediaUrl::url($brand->logo_path, DefaultImage::SM),
                'url' => '/'.ltrim((string) ($this->urls->pathFor('brand', (int) $brand->id, $locale) ?: ('brand/'.($brand->translation($locale)?->slug ?: $brand->id))), '/'),
            ] : null,
            'rating' => [
                'average' => ($ratingCount > 0 && $ratingAvg !== null) ? round((float) $ratingAvg, 1) : null,
                'count' => $ratingCount,
            ],
            'social_proof' => $product->is_best_seller ? 'Popular this month' : null,
            'colors' => $this->colors($product, $locale),
            'dimensions' => $this->dimensions($product, $locale),
            ...SaleUnit::present($product),
            'min_qty' => ProductSettings::quantityRules($product)['min'],
            'max_qty' => ProductSettings::quantityRules($product)['max'],
            'qty_step' => ProductSettings::quantityRules($product)['step'],
        ];

        if ($product->preorder_enabled) {
            $product->loadMissing(['preorderSetting']);
            $listCustomer = auth('customer')->user();
            $listPreorder = app(PreorderService::class)->presentForStorefront(
                $product,
                $listCustomer instanceof \App\Domain\Customer\Models\Customer ? $listCustomer : null,
            );
            if ($listPreorder) {
                if (! empty($listPreorder['show_image_label'])) {
                    $data['flags']['preorder'] = true;
                }
                if (! $full) {
                    $data['preorder'] = [
                        'enabled' => true,
                        'active' => ! empty($listPreorder['active']),
                        'status' => $listPreorder['status'] ?? null,
                        'cta_label' => $listPreorder['cta_label'] ?? 'Pre-order',
                        'show_countdown_pdp' => ! empty($listPreorder['show_countdown_pdp']),
                        'show_countdown_listing' => ! empty($listPreorder['show_countdown_listing']),
                        'show_image_label' => ! empty($listPreorder['show_image_label']),
                        'countdown_kind' => $listPreorder['countdown_kind'] ?? null,
                        'countdown_at' => $listPreorder['countdown_at'] ?? null,
                        'server_now' => $listPreorder['server_now'] ?? now()->toIso8601String(),
                    ];
                }
            }
        }

        $seller = $product->relationLoaded('seller') ? $product->seller : null;
        if ($seller && $seller->isPublic()) {
            $data['seller'] = [
                'id' => $seller->id,
                'shop_name' => $seller->shop_name,
                'shop_slug' => $seller->shop_slug,
                'url' => '/shop/'.$seller->shop_slug,
                'vacation_enabled' => (bool) $seller->vacation_enabled,
                'vacation_message' => trim(strip_tags((string) ($seller->vacation_message ?: ''))),
            ];
        }

        if ($full) {
            $product->loadMissing([
                'categories.translations',
                'categories.parent.translations',
                'supplier.translations',
                'suppliers.translations',
                'shippingMethods',
                'images',
                'featureValues.translations',
                'featureValues.feature.translations',
                'brand.translations',
            ]);
            $data['description'] = HtmlSanitizer::clean($t?->description);
            $seoTitle = filled($t?->meta_title) ? (string) $t->meta_title : strtr(
                \App\Domain\Settings\ShopTools::titlePattern(),
                [
                    '{name}' => (string) ($t?->name ?: $product->sku),
                    '{shop}' => (string) (config('app.name') ?: 'Shop'),
                ]
            );
            $seoDescription = (string) ($t?->meta_description ?: '');
            if ($seoDescription === '' && \App\Domain\Settings\ShopSetting::bool('seo.use_short_description_fallback', true)) {
                $seoDescription = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($t?->short_description ?: ''))) ?: '');
            }
            if ($seoDescription === '') {
                $seoDescription = (string) \App\Domain\Settings\ShopSetting::get('seo.default_description', '');
            }
            $canonicalPath = $t?->canonical_url ?: $data['url'];
            $canonicalAbs = \App\Domain\Seo\StorefrontStructuredData::publicUrl((string) $canonicalPath, $locale);
            $ogImage = \App\Domain\Seo\StorefrontStructuredData::absoluteMedia(
                $product->cover_path ?: (string) \App\Domain\Settings\ShopSetting::get('seo.og_image', ''),
                'large'
            );
            $trail = \App\Domain\Seo\StorefrontStructuredData::categoryTrail(
                $product,
                $locale,
                fn (Category $category) => '/'.ltrim((string) ($this->urls->pathFor('category', $category->id, $locale) ?: $category->translation($locale)?->slug), '/')
            );
            $reviewRows = [];
            if ($product->relationLoaded('reviews')) {
                $reviewRows = $product->reviews
                    ->where('is_approved', true)
                    ->take(5)
                    ->map(fn ($review) => [
                        'author' => $review->authorName(),
                        'rating' => (int) $review->rating,
                        'body' => (string) ($review->body ?: ''),
                        'date' => $review->created_at?->toDateString(),
                    ])->values()->all();
            }
            $ratingAvg = $product->reviews_avg_rating !== null ? (float) $product->reviews_avg_rating : null;
            $ratingCount = (int) ($product->approved_reviews_count ?? 0);
            $data['seo'] = [
                'title' => $seoTitle,
                'description' => $seoDescription,
                'keywords' => $t?->focus_keyword ?: $t?->meta_keywords,
                'og_title' => $t?->og_title ?: $seoTitle,
                'og_description' => $t?->og_description ?: $seoDescription,
                'og_image' => $ogImage,
                'og_type' => 'product',
                'canonical' => $canonicalAbs,
                'robots' => $t?->robots ?: 'index,follow',
                'hreflang' => \App\Domain\Seo\StorefrontHreflang::forEntity('product', (int) $product->id),
                'json_ld' => \App\Domain\Seo\StorefrontStructuredData::productGraph(
                    $product,
                    (string) ($t?->name ?: $product->sku),
                    $seoDescription,
                    $canonicalAbs,
                    $ogImage,
                    $trail,
                    $ratingCount > 0 ? $ratingAvg : null,
                    $ratingCount,
                    $reviewRows,
                ),
            ];
            $data['translations'] = $product->relationLoaded('translations')
                ? $product->translations
                    ->mapWithKeys(fn ($row) => [$row->language_code => [
                        'language_code' => $row->language_code,
                        'name' => $row->name,
                        'slug' => $row->slug,
                        'short_description' => $row->short_description,
                        'description' => $row->description,
                        'meta_title' => $row->meta_title,
                        'meta_description' => $row->meta_description,
                        'meta_keywords' => $row->meta_keywords,
                        'og_title' => $row->og_title,
                        'og_description' => $row->og_description,
                        'focus_keyword' => $row->focus_keyword,
                        'canonical_url' => $row->canonical_url,
                        'robots' => $row->robots,
                    ]])->all()
                : [];
            $data['images'] = $product->images->map(function ($img) {
                $src = $this->images->publicPath($img->path, 'medium') ?: $img->path;
                $large = $this->images->publicPath($img->path, 'large') ?: $img->path;

                return [
                    'id' => $img->id,
                    'path' => $img->path,
                    'src' => $src,
                    'src_url' => CatalogMediaUrl::url($src, DefaultImage::MD),
                    'large' => $large,
                    'large_url' => CatalogMediaUrl::url($large, DefaultImage::LG),
                    'alt' => $img->alt,
                    'position' => $img->position,
                ];
            })->values()->all();
            $data['video_url'] = $product->video_url;
            $data['video_embed'] = CommunityFeatures::videoEmbed($product->video_url);
            $data['attachments'] = app(AttachmentService::class)->presentDocumentsOnly($product);
            $quantityRules = ProductSettings::quantityRules($product);
            $data['min_qty'] = $quantityRules['min'];
            $data['max_qty'] = $quantityRules['max'];
            $data['qty_step'] = $quantityRules['step'];
            $data['qty_prices'] = $product->qtyPrices()
                ->orderBy('min_qty')
                ->get()
                ->map(function ($row) use ($taxClassId) {
                    $catalog = \App\Domain\Settings\CustomerSettings::applyGroupDiscount((float) $row->price, $customer);
                    $display = PricingSettings::toStorefrontAmount($catalog, $taxClassId);

                    return [
                        'min_qty' => (float) $row->min_qty,
                        'max_qty' => $row->max_qty !== null ? (float) $row->max_qty : null,
                        'price' => $display,
                        'price_catalog' => $catalog,
                        'price_formatted' => Money::format($display),
                    ];
                })->values()->all();
            $data['weight'] = $product->weight;
            $data['weight_class'] = $product->weight_class;
            $data['length'] = $product->length;
            $data['width'] = $product->width;
            $data['height'] = $product->height;
            $data['length_class'] = $product->length_class;
            $data['processing_time'] = $product->processing_time;
            $data['processing_time_unit'] = $product->processing_time_unit;
            $data['delivery_from'] = $product->delivery_from;
            $data['delivery_to'] = $product->delivery_to;
            $data['additional_shipping_cost'] = $product->additional_shipping_cost;
            $data['available_all_carriers'] = $product->available_all_carriers;
            $data['customization_enabled'] = (bool) $product->customization_enabled;
            $data['customization_fields'] = $product->customization_enabled
                ? $this->customizations->present($product)
                : [];
            $data['subscription_enabled'] = (bool) $product->subscription_enabled;
            $data['purchase_option'] = $product->purchase_option ?: 'one_time';
            $product->loadMissing(['productSubscriptionPlans.plan.translations']);
            $data['subscription_plans'] = $this->subscriptions->storefrontPlans($product, $locale);
            $data['subscription_message'] = $this->subscriptions->storefrontMessage($product, $locale);
            $data['shipping_methods'] = $product->shippingMethods->map(fn (ShippingMethod $method) => [
                'id' => $method->id,
                'name' => $method->name,
            ]);
            $data['isbn'] = $product->isbn;
            $data['upc'] = $product->upc;
            $data['mpn'] = $product->mpn;
            $data['gtin'] = $product->gtin;
            $data['display_condition'] = $product->display_condition;
            $data['condition'] = $product->condition;
            $data['features'] = $product->featureValues
                ->filter(function ($v) {
                    $code = strtolower((string) $v->feature?->code);

                    return $code !== 'color' && $code !== 'dimension';
                })
                ->map(fn ($v) => [
                    'id' => $v->id,
                    'feature_id' => $v->feature_id ?? $v->pivot->feature_id ?? $v->feature?->id,
                    'code' => $v->feature?->code,
                    'feature' => $v->feature?->translation($locale)?->name,
                    'value' => $v->translation($locale)?->value,
                ])->values()->all();
            $data['shipping_html'] = HtmlSanitizer::clean(Setting::getValue('classic_pdp_shipping_html') ?: null) ?: null;
            $data['size_chart_html'] = HtmlSanitizer::clean(Setting::getValue('classic_pdp_size_chart_html') ?: null) ?: null;
            $combo = $this->combinations->presentStorefront($product);
            $data['combination_axes'] = $combo['axes'];
            $data['combination_variants'] = $combo['variants'];
            $data['supplier'] = $product->supplier?->translation($locale)?->name;
            $data['suppliers'] = $product->suppliers->map(fn (Supplier $supplier) => [
                'id' => $supplier->id,
                'name' => $supplier->translation($locale)?->name,
                'slug' => $supplier->translation($locale)?->slug,
            ])->values()->all();
            $data['reviews'] = (! ReviewSettings::enabled() || ! $product->relationLoaded('reviews'))
                ? []
                : $product->reviews->where('is_approved', true)->values()->map(fn (Review $review) => [
                    'id' => $review->id,
                    'rating' => $review->rating,
                    'title' => $review->title,
                    'body' => $review->body,
                    'author' => $review->authorName(),
                    'is_verified_purchase' => (bool) $review->is_verified_purchase,
                    'admin_reply' => ReviewSettings::merchantReplies() ? $review->admin_reply : null,
                    'admin_replied_at' => ReviewSettings::merchantReplies() ? $review->admin_replied_at?->toDateString() : null,
                    'created_at' => $review->created_at?->toDateString(),
                    ...$review->mediaPayload(),
                    ...$review->votePayload(auth('customer')->id() ? (int) auth('customer')->id() : null),
                ])->all();
            $data['rating_breakdown'] = $this->ratingBreakdown($product);
            $data['questions'] = $product->relationLoaded('questions')
                ? $product->questions->where('is_approved', true)->values()->map(fn (ProductQuestion $question) => [
                    'id' => $question->id,
                    'body' => $question->body,
                    'author' => $question->customer?->displayName() ?: 'Customer',
                    'created_at' => $question->created_at?->toDateString(),
                    'answers' => $question->answers
                        ->where('is_approved', true)
                        ->values()
                        ->map(fn ($answer) => [
                            'id' => $answer->id,
                            'body' => $answer->body,
                            'is_official' => (bool) $answer->is_official,
                            'created_at' => $answer->created_at?->toDateString(),
                        ])->all(),
                ])->all()
                : [];
            $data['categories'] = $product->relationLoaded('categories')
                ? $product->categories->map(fn (Category $category) => $this->category($category, $locale))->values()->all()
                : [];
            $data['default_category_id'] = $product->default_category_id;
            $data['requires_shipping'] = $product->requiresShipping();
            $gift = $product->isGiftCard() ? app(GiftCardService::class)->presentConfig($product) : null;
            $data['gift_card'] = $gift;
            $data['gift_card_amounts'] = $gift['amounts'] ?? [];
            $service = $product->isService() ? app(ServiceProductService::class)->presentConfig($product) : null;
            $data['service'] = $service;
            $rental = $product->isRental() ? app(RentalProductService::class)->presentConfig($product) : null;
            $data['rental'] = $rental;
            $product->loadMissing(['preorderSetting']);
            $preorderCustomer = auth('customer')->user();
            $preorder = app(PreorderService::class)->presentForStorefront(
                $product,
                $preorderCustomer instanceof \App\Domain\Customer\Models\Customer ? $preorderCustomer : null,
            );
            $data['preorder'] = $preorder;
            if ($preorder && ! empty($preorder['active']) && isset($preorder['charge_unit_price'])) {
                $data['price'] = (float) $preorder['charge_unit_price'];
                if (($preorder['payment_type'] ?? 'full') === 'deposit') {
                    $data['price_prefix'] = $data['price_prefix'] ?? null;
                    $data['preorder_full_price'] = (float) ($preorder['full_unit_price'] ?? $data['price']);
                }
            }
            if (! empty($preorder['show_image_label'])) {
                $data['flags'] = array_merge($data['flags'] ?? [], ['preorder' => true]);
            }
            $stockAlerts = app(CustomerStockAlertService::class);
            $data['stock_alert'] = [
                'enabled' => $stockAlerts->enabled(),
                'subscribed' => false,
                'subscribed_variant_ids' => [],
                'out_of_stock' => $stockAlerts->targetIsOutOfStock($product),
            ];
            if ($stockAlerts->enabled()) {
                $customer = auth('customer')->user();
                if ($customer instanceof \App\Domain\Customer\Models\Customer) {
                    $variantIds = $stockAlerts->pendingVariantIds($product, $customer);
                    $data['stock_alert']['subscribed_variant_ids'] = $variantIds;
                    $data['stock_alert']['subscribed'] = in_array(0, $variantIds, true);
                }
            }
            $data['requires_booking'] = $product->isService()
                ? (bool) ($service['requires_booking'] ?? false)
                : ($product->isRental());
            if ($service && ($service['pricing_model'] ?? '') === 'starting_from') {
                $data['price_prefix'] = 'from';
            }
            if ($rental && isset($rental['preview']['unit_price'])) {
                $data['price'] = (float) $rental['preview']['unit_price'];
                $data['price_prefix'] = $data['price_prefix'] ?? null;
            }
            $data['components'] = in_array($product->type, ['bundle', 'group'], true)
                ? app(CompositionService::class)->present($product)
                : [];
            if ($product->type === 'group' || $product->type === 'bundle') {
                $data['selection_mode'] = $product->selection_mode;
                $data['bundle_type'] = $product->bundle_type;
                $data['pricing_mode'] = $product->pricing_mode;
                $data['pricing_discount_type'] = $product->pricing_discount_type;
                $data['pricing_discount_value'] = $product->pricing_discount_value !== null
                    ? (float) $product->pricing_discount_value
                    : null;
                $data['inventory_mode'] = $product->inventory_mode;
                $data['shipping_mode'] = $product->shipping_mode;
            }
        }

        if (! $showPrice) {
            $data = $this->withoutPublicPrices($data);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function withoutPublicPrices(array $data): array
    {
        $data['price'] = null;
        $data['price_formatted'] = null;
        $data['compare_price'] = null;
        $data['compare_price_formatted'] = null;
        $data['discount_percent'] = null;
        $data['show_price'] = false;
        $data['qty_prices'] = [];
        unset($data['preorder_full_price'], $data['price_prefix']);

        if (isset($data['components']) && is_array($data['components'])) {
            $data['components'] = array_map(function ($row) {
                if (! is_array($row)) {
                    return $row;
                }
                foreach (['selling_price', 'price', 'price_formatted', 'compare_price', 'compare_price_formatted'] as $key) {
                    if (array_key_exists($key, $row)) {
                        $row[$key] = null;
                    }
                }

                return $row;
            }, $data['components']);
        }

        if (isset($data['gift_card']) && is_array($data['gift_card'])) {
            $data['gift_card']['amounts'] = [];
            $data['gift_card']['default_amount'] = null;
        }
        $data['gift_card_amounts'] = [];

        if (isset($data['rental']) && is_array($data['rental'])) {
            if (isset($data['rental']['preview']) && is_array($data['rental']['preview'])) {
                $data['rental']['preview']['unit_price'] = null;
            }
            if (isset($data['rental']['pricing_tiers']) && is_array($data['rental']['pricing_tiers'])) {
                $data['rental']['pricing_tiers'] = array_map(function ($tier) {
                    if (is_array($tier)) {
                        $tier['price'] = null;
                    }

                    return $tier;
                }, $data['rental']['pricing_tiers']);
            }
            $data['rental']['security_deposit_value'] = null;
            $data['rental']['cleaning_fee'] = null;
        }

        if (isset($data['service']) && is_array($data['service'])) {
            $data['service']['travel_fee'] = null;
        }

        if (isset($data['preorder']) && is_array($data['preorder'])) {
            $data['preorder']['charge_unit_price'] = null;
            $data['preorder']['full_unit_price'] = null;
            $data['preorder']['remaining_per_unit'] = null;
        }

        return $data;
    }

    public function category(Category $category, string $locale): array
    {
        $t = $category->translation($locale);
        $slug = $t?->slug;
        $url = '/'.ltrim((string) ($this->urls->pathFor('category', $category->id, $locale) ?: $slug), '/');
        $title = $t?->meta_title ?: $t?->name;
        $rawDescription = (string) ($t?->description ?: '');
        $description = $t?->meta_description
            ?: trim(preg_replace('/\s+/', ' ', strip_tags($rawDescription)) ?? '');
        $canonical = $t?->canonical_url ?: $url;
        $ogImage = \App\Domain\Seo\StorefrontStructuredData::absoluteMedia(
            $category->cover_path ?: $category->banner_path ?: $category->image_path,
            'large'
        ) ?: \App\Domain\Seo\StorefrontStructuredData::defaultOgImage();
        $canonicalAbs = \App\Domain\Seo\StorefrontStructuredData::publicUrl((string) $canonical, $locale);

        // Logo only (same fields as admin category image) — never use banner as the circular logo.
        // Null when unset so homepage shelves can hide the left image instead of a placeholder.
        $logoPath = $category->cover_path ?: $category->image_path ?: $category->thumbnail_path;
        $thumbPath = $category->thumbnail_path ?: $category->cover_path ?: $category->image_path;

        return [
            'id' => $category->id,
            'name' => $t?->name,
            'slug' => $slug,
            'url' => $url,
            'description' => HtmlSanitizer::clean($t?->description),
            'image' => $logoPath ? CatalogMediaUrl::url($logoPath, DefaultImage::MD) : null,
            'thumb' => $thumbPath ? CatalogMediaUrl::url($thumbPath, DefaultImage::SM) : null,
            'seo' => [
                'title' => $title,
                'description' => $description,
                'canonical' => $canonicalAbs,
                'robots' => 'index,follow',
                'og_type' => 'website',
                'og_image' => $ogImage,
                'hreflang' => \App\Domain\Seo\StorefrontHreflang::forEntity('category', (int) $category->id),
                'json_ld' => \App\Domain\Seo\StorefrontStructuredData::collectionPage(
                    (string) ($t?->name ?: ''),
                    $canonicalAbs,
                    (string) ($description ?: ''),
                ),
            ],
        ];
    }

    /**
     * Nested storefront category tree (unlimited depth).
     *
     * @param  iterable<int, Category>  $allEnabled
     * @param  list<int>|null  $rootIds  Preserve this order when set; otherwise all roots (parent_id null).
     * @return list<array<string, mixed>>
     */
    public function categoryForest(iterable $allEnabled, string $locale, ?array $rootIds = null): array
    {
        $all = collect($allEnabled);
        $byId = $all->keyBy(fn (Category $category) => (int) $category->id);
        $byParent = $all->groupBy(fn (Category $category) => $category->parent_id === null ? 'root' : (string) (int) $category->parent_id);

        $buildChildren = function (string $parentKey) use (&$buildChildren, $byParent, $locale): array {
            return ($byParent->get($parentKey) ?? collect())
                ->sortBy([
                    ['position', 'asc'],
                    ['id', 'asc'],
                ])
                ->values()
                ->map(function (Category $category) use (&$buildChildren, $locale) {
                    $children = $buildChildren((string) (int) $category->id);

                    return $this->category($category, $locale) + [
                        'children' => $children,
                        'has_children' => $children !== [],
                    ];
                })
                ->all();
        };

        if (is_array($rootIds) && $rootIds !== []) {
            return collect($rootIds)
                ->map(function ($id) use ($byId, $buildChildren, $locale) {
                    $category = $byId->get((int) $id);
                    if (! $category) {
                        return null;
                    }
                    $children = $buildChildren((string) (int) $category->id);

                    return $this->category($category, $locale) + [
                        'children' => $children,
                        'has_children' => $children !== [],
                    ];
                })
                ->filter()
                ->values()
                ->all();
        }

        return $buildChildren('root');
    }

    public function withRatings($query)
    {
        return $query
            ->with(['translations', 'images', 'brand.translations'])
            ->withAvg(['reviews as reviews_avg_rating' => fn ($q) => $q->where('is_approved', true)], 'rating')
            ->withCount(['reviews as approved_reviews_count' => fn ($q) => $q->where('is_approved', true)]);
    }

    /**
     * @return list<array{rating: int, count: int}>
     */
    protected function ratingBreakdown(Product $product): array
    {
        $rows = Review::query()
            ->where('product_id', $product->id)
            ->where('is_approved', true)
            ->selectRaw('rating, count(*) as total')
            ->groupBy('rating')
            ->pluck('total', 'rating');

        $out = [];
        for ($i = 5; $i >= 1; $i--) {
            $out[] = ['rating' => $i, 'count' => (int) ($rows[$i] ?? 0)];
        }

        return $out;
    }

    protected function colors(Product $product, string $locale): array
    {
        if (! $product->relationLoaded('featureValues')) {
            return [];
        }

        return $product->featureValues
            ->filter(function ($value) {
                $code = strtolower((string) $value->feature?->code);
                $name = strtolower((string) $value->feature?->translation()?->name);

                return $code === 'color' || str_contains($code, 'color') || str_contains($name, 'color') || str_contains($name, 'colour');
            })
            ->map(function ($value) use ($locale) {
                $hex = $this->hexFromFeatureValueCode($value->code);

                return [
                    'id' => $value->id,
                    'name' => $value->translation($locale)?->value,
                    'hex' => $hex,
                ];
            })
            ->values()
            ->all();
    }

    protected function dimensions(Product $product, string $locale): array
    {
        if (! $product->relationLoaded('featureValues')) {
            return [];
        }

        return $product->featureValues
            ->filter(function ($value) {
                $code = strtolower((string) $value->feature?->code);
                $name = strtolower((string) $value->feature?->translation()?->name);

                return $code === 'dimension';
            })
            ->map(fn ($value) => $value->translation($locale)?->value)
            ->filter()
            ->values()
            ->all();
    }

    protected function hexFromFeatureValueCode(?string $code): ?string
    {
        $raw = strtolower(trim((string) $code));
        $raw = ltrim($raw, '#');
        if (preg_match('/^[0-9a-f]{3}$|^[0-9a-f]{6}$/', $raw)) {
            return '#'.strtoupper($raw);
        }

        return null;
    }

    protected function mediaUrl(?string $path, string $size = DefaultImage::MD): string
    {
        return CatalogMediaUrl::url($path, $size);
    }
}
