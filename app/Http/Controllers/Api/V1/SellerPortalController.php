<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\AttributeValue;
use App\Domain\Catalog\Models\CatalogAttribute;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\FeatureValue;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Support\ProductCsvSchema;
use App\Domain\Localization\Models\Country;
use App\Domain\Localization\Models\Language;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerPaymentMethod;
use App\Domain\Marketplace\Services\SellerCatalogService;
use App\Domain\Marketplace\Services\SellerProductCatalogService;
use App\Domain\Marketplace\Services\SellerOrderDocument;
use App\Domain\Marketplace\Services\SellerProductListService;
use App\Domain\Security\CsvSafe;
use App\Domain\Security\RasterImageUpload;
use App\Domain\Marketplace\Services\SellerService;
use App\Domain\Promo\Models\DiscountRule;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SellerPortalController extends Controller
{
    public function __construct(
        protected SellerService $sellers,
        protected SellerCatalogService $catalog,
        protected SellerProductCatalogService $productCatalog,
        protected SellerProductListService $productList,
        protected \App\Domain\Marketplace\Services\SellerEarningAvailabilityService $wallet,
        protected \App\Domain\Marketplace\Services\MarketplaceStatsService $marketplaceStats,
    ) {}

    public function uploadEditorImage(Request $request): JsonResponse
    {
        $this->seller($request);
        $request->validate([
            'image' => \App\Domain\Security\RasterImageUpload::requiredRules(5120),
        ]);

        $path = \App\Domain\Security\RasterImageUpload::storePublic($request->file('image'), 'editor', 5120);

        return response()->json([
            'url' => asset('storage/'.$path),
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        $seller->loadMissing(['translations', 'orders' => function ($query) {
            $query->select('id', 'seller_id', 'number', 'status', 'grand_total', 'created_at', 'customer_email')
                ->latest()
                ->limit(5)
                ->with('items:id,seller_order_id,name,qty');
        }]);
        $wallet = $this->wallet->walletBalances($seller);
        $stats = $this->marketplaceStats->sellerPortalStats($seller);
        $lowThreshold = MarketplaceSettings::lowStockThreshold();
        $lowStock = Product::query()
            ->where('seller_id', $seller->id)
            ->where('stock_qty', '<=', $lowThreshold)
            ->count();

        return response()->json([
            'seller' => $this->sellers->present($seller),
            'stats' => [
                'products' => $stats['products'],
                'orders' => $stats['orders'],
                'pending_orders' => $stats['pending_orders'],
                'open_returns' => $stats['open_returns'],
                'reviews' => $stats['reviews'],
                'earnings' => $stats['earnings'],
                'available' => $wallet['available_for_payout'],
                'commission' => (float) $seller->commission_rate,
                'lowStockProducts' => $lowStock,
                'lowStockThreshold' => $lowThreshold,
            ],
            'productForm' => [
                'enableSteppedProductForm' => MarketplaceSettings::enableSteppedProductForm(),
                'steppedFormNavigation' => MarketplaceSettings::steppedFormNavigation(),
                'showProgressBar' => MarketplaceSettings::showProgressBar(),
            ],
            'recent_orders' => $seller->orders,
        ]);
    }

    public function options(Request $request): JsonResponse
    {
        $seller = $this->seller($request);

        return response()->json([
            ...$this->productCatalog->options($seller),
            'shipping_types' => ShippingMethod::TYPES,
            'payment_types' => SellerPaymentMethod::TYPES,
            'discount_actions' => DiscountRule::ACTIONS,
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        if ($request->attributes->get('marketplace.actor') === 'staff') {
            abort(403, 'Only the shop owner can update profile.');
        }
        $seller = $this->seller($request);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'profile_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['required', 'string', 'max:40'],
            'contact_name' => ['required', 'string', 'max:120'],
            'contact_phone' => ['required', 'string', 'max:40'],
            'contact_email' => ['nullable', 'email', 'max:190'],
            'tax_id' => ['nullable', 'string', 'max:80'],
        ]);

        return response()->json(['seller' => $this->sellers->present($this->sellers->updateProfile($seller, $data))]);
    }

    public function updateShop(Request $request): JsonResponse
    {
        if ($request->attributes->get('marketplace.actor') === 'staff') {
            abort(403, 'Only the shop owner can update shop settings.');
        }
        $seller = $this->seller($request);
        // Ownership comes from session/middleware — never from body.
        $request->request->remove('seller_id');
        $request->request->remove('customer_id');
        $request->request->remove('status');
        $maxKb = MarketplaceSettings::maxUploadKb();
        $data = $request->validate([
            'shop_name' => ['required', 'string', 'max:120'],
            'shop_slug' => [
                'required',
                'string',
                'max:80',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('sellers', 'shop_slug')->ignore($seller->id),
            ],
            'address_line1' => ['required', 'string', 'max:190'],
            'address_line2' => ['nullable', 'string', 'max:190'],
            'city' => ['required', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:80'],
            'postal_code' => ['required', 'string', 'max:20'],
            'country_code' => ['required', 'string', 'max:4'],
            'description' => ['nullable', 'string'],
            'facebook_url' => ['nullable', 'string', 'max:190'],
            'twitter_url' => ['nullable', 'string', 'max:190'],
            'instagram_url' => ['nullable', 'string', 'max:190'],
            'youtube_url' => ['nullable', 'string', 'max:190'],
            'store_policy' => ['nullable', 'string'],
            'shipping_policy' => ['nullable', 'string'],
            'return_policy' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'vacation_enabled' => ['sometimes', 'boolean'],
            'vacation_message' => ['nullable', 'string'],
            'minimum_order_amount' => ['nullable', 'numeric', 'min:0'],
            'translations' => ['nullable', 'array'],
            'translations.*.language_code' => ['required_with:translations', 'string', Rule::exists('languages', 'code')->where('is_enabled', true)],
            'translations.*.shop_name' => ['nullable', 'string', 'max:120'],
            'translations.*.description' => ['nullable', 'string'],
            'translations.*.store_policy' => ['nullable', 'string'],
            'translations.*.shipping_policy' => ['nullable', 'string'],
            'translations.*.return_policy' => ['nullable', 'string'],
            'translations.*.vacation_message' => ['nullable', 'string'],
            'remove_logo' => ['sometimes', 'boolean'],
            'remove_banner' => ['sometimes', 'boolean'],
            'logo' => ['nullable', 'file', 'image', 'max:'.$maxKb],
            'banner' => ['nullable', 'file', 'image', 'max:'.$maxKb],
        ]);

        $data = $this->normalizeShopTranslations($data);

        $updated = $this->sellers->updateShop($seller, $data, [
            'logo' => $request->file('logo'),
            'banner' => $request->file('banner'),
        ]);

        return response()->json(['seller' => $this->sellers->present($updated)]);
    }

    public function products(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        $filters = $this->productList->filtersFromRequest($request);
        $products = $this->productList->paginate($seller, $filters);

        return response()->json([
            'products' => $products,
            'filters' => $filters,
        ]);
    }

    public function bulkProductStatus(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
            'enabled' => ['required', 'boolean'],
        ]);
        $owned = $this->productList->ownedProductIds($seller, $data['ids']);
        if ($owned === []) {
            abort(422, 'No matching products were found.');
        }
        $updated = $this->sellers->bulkSetEnabled($seller, $owned, (bool) $data['enabled']);

        return response()->json(['ok' => true, 'updated' => $updated]);
    }

    public function bulkDestroyProducts(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ]);
        $owned = $this->productList->ownedProductIds($seller, $data['ids']);
        if ($owned === []) {
            abort(422, 'No matching products were found.');
        }
        $results = $this->sellers->bulkDelete($seller, $owned);

        return response()->json([
            'ok' => true,
            'count' => count($results),
            'results' => $results,
        ]);
    }

    public function showProduct(Request $request, Product $product): JsonResponse
    {
        $seller = $this->seller($request);
        $this->ownedProduct($seller, $product);
        $copy = $seller->products()->where('product_id', $product->id)->first();

        return response()->json([
            'product' => $this->productCatalog->present($seller, $product, $copy),
        ]);
    }

    public function storeProduct(Request $request): JsonResponse
    {
        if (! MarketplaceSettings::sellerCanCreateProduct()) {
            abort(403, 'Sellers are not allowed to create products.');
        }
        $seller = $this->seller($request);
        $data = $this->productData($request, null, $seller);
        $product = $this->sellers->saveProduct($seller, $data);
        $this->storeProductImages($request, $seller, $product);

        return response()->json(['product' => $this->productPayload($seller, $product)], 201);
    }

    /**
     */
    public function searchCatalogProducts(Request $request): JsonResponse
    {
        if (! MarketplaceSettings::sellerCanAssignProduct()) {
            return response()->json([
                'message' => 'You are not allowed to assign this product.',
                'error' => 'assign_disabled',
            ], 403);
        }
        $seller = $this->seller($request);
        $q = trim((string) $request->query('q', $request->query('query', '')));
        if (mb_strlen($q) < 3) {
            return response()->json([
                'message' => 'Type at least three letters of the product name.',
                'error' => 'validation',
            ], 422);
        }
        $locale = app()->getLocale();
        $rows = Product::query()
            ->where(function ($query) {
                $query->whereNull('seller_id')->orWhere('seller_id', 0);
            })
            ->where('is_enabled', true)
            ->where(function ($query) use ($q, $locale) {
                $query->where('sku', 'like', '%'.$q.'%')
                    ->orWhereHas('translations', function ($t) use ($q, $locale) {
                        $t->where('language_code', $locale)
                            ->where('name', 'like', '%'.$q.'%');
                    });
            })
            ->orderBy('id')
            ->limit(25)
            ->get();

        $already = \App\Domain\Marketplace\Models\SellerProduct::query()
            ->where('seller_id', $seller->id)
            ->whereIn('product_id', $rows->pluck('id'))
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return response()->json([
            'data' => $rows->map(fn (Product $p) => [
                'id' => (int) $p->id,
                'sku' => (string) $p->sku,
                'name' => (string) ($p->translation($locale)?->name ?? $p->sku),
                'price' => (float) $p->price,
                'type' => (string) $p->type,
                'alreadySelling' => in_array((int) $p->id, $already, true) ? 1 : 0,
            ])->values()->all(),
        ]);
    }

    /**
     * Catalog product ownership stays put so multiple sellers can offer the same SKU.
     */
    public function sellAsYours(Request $request): JsonResponse
    {
        if (! MarketplaceSettings::sellerCanAssignProduct()) {
            return response()->json([
                'message' => 'You are not allowed to assign this product.',
                'error' => 'assign_disabled',
            ], 403);
        }
        $seller = $this->seller($request);
        \App\Domain\Marketplace\SellerProductOfferSchema::ensure();
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'stock_qty' => ['nullable', 'integer', 'min:0'],
            'condition' => ['nullable', 'string', Rule::in(['new', 'old'])],
            'description' => ['nullable', 'string', 'max:300'],
            'save_as_draft' => ['nullable', 'boolean'],
            'is_draft' => ['nullable', 'boolean'],
        ]);
        $product = Product::query()->find((int) $data['product_id']);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        if (\App\Domain\Marketplace\Models\SellerProduct::query()
            ->where('seller_id', $seller->id)
            ->where('product_id', $product->id)
            ->exists()) {
            return response()->json(['message' => 'You are already selling this product.', 'error' => 'already_selling'], 422);
        }

        app(\App\Domain\Marketplace\Services\SellerSubscriptionGate::class)
            ->assertCanCreateProduct($seller, (string) $product->type);

        $locale = app()->getLocale();
        $name = (string) ($product->translation($locale)?->name ?? $product->sku);
        $isDraft = (bool) ($data['save_as_draft'] ?? $data['is_draft'] ?? false);
        $price = isset($data['price']) ? round((float) $data['price'], 2) : (float) $product->price;
        $stock = isset($data['stock_qty']) ? (int) $data['stock_qty'] : (int) $product->stock_qty;
        $condition = (string) ($data['condition'] ?? 'new');

        if (! $isDraft && (! isset($data['price']) || ! isset($data['condition']))) {
            if (! isset($data['price'])) {
                return response()->json(['message' => 'Price is required.', 'error' => 'validation'], 422);
            }
        }

        $approval = $isDraft
            ? \App\Domain\Marketplace\Models\SellerProduct::STATUS_PENDING
            : (MarketplaceSettings::autoApproveProducts()
                ? \App\Domain\Marketplace\Models\SellerProduct::STATUS_APPROVED
                : \App\Domain\Marketplace\Models\SellerProduct::STATUS_PENDING);

        $listing = \App\Domain\Marketplace\Models\SellerProduct::query()->create([
            'seller_id' => $seller->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => $name,
            'slug' => $product->translation($locale)?->slug,
            'description' => $data['description'] ?? null,
            'short_description' => $product->translation($locale)?->short_description,
            'price' => $price,
            'compare_price' => $product->compare_price,
            'stock_qty' => $stock,
            'category_id' => $product->default_category_id,
            'is_enabled' => ! $isDraft && $approval === \App\Domain\Marketplace\Models\SellerProduct::STATUS_APPROVED,
            'approval_status' => $approval,
            'condition' => $condition,
            'is_catalog_offer' => true,
            'is_draft' => $isDraft,
        ]);

        return response()->json([
            'message' => $isDraft
                ? 'Catalog offer saved as draft.'
                : 'Product assigned successfully to the seller.',
            'data' => [
                'productId' => (int) $product->id,
                'sellerProductId' => (int) $listing->id,
                'approvalStatus' => (string) $listing->approval_status,
                'condition' => (string) $listing->condition,
                'price' => (float) $listing->price,
                'stockQty' => (int) $listing->stock_qty,
                'isDraft' => (bool) $listing->is_draft,
                'isCatalogOffer' => true,
                'catalogSellerId' => $product->seller_id !== null ? (int) $product->seller_id : null,
            ],
        ], 201);
    }

    /** Remove only this seller's catalog offer; catalog product stays. */
    public function destroyCatalogOffer(Request $request, int $productId): JsonResponse
    {
        $seller = $this->seller($request);
        \App\Domain\Marketplace\SellerProductOfferSchema::ensure();
        $listing = \App\Domain\Marketplace\Models\SellerProduct::query()
            ->where('seller_id', $seller->id)
            ->where('product_id', $productId)
            ->where('is_catalog_offer', true)
            ->first();
        if (! $listing) {
            return response()->json(['message' => 'Offer not found.', 'error' => 'not_found'], 404);
        }
        $listing->delete();

        return response()->json([
            'message' => 'Assigned product offer removed.',
            'data' => ['productId' => $productId, 'removed' => true],
        ]);
    }

    /** bulk "Sell Catalog Products" — create offers for many SKUs at once. */
    public function bulkCatalogOffers(Request $request): JsonResponse
    {
        if (! MarketplaceSettings::sellerCanAssignProduct()) {
            return response()->json([
                'message' => 'You are not allowed to assign this product.',
                'error' => 'assign_disabled',
            ], 403);
        }
        $seller = $this->seller($request);
        \App\Domain\Marketplace\SellerProductOfferSchema::ensure();
        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1', 'max:100'],
            'product_ids.*' => ['integer'],
            'price' => ['required', 'numeric', 'min:0'],
            'stock_qty' => ['nullable', 'integer', 'min:0'],
            'condition' => ['nullable', 'string', Rule::in(['new', 'old'])],
        ]);
        $created = 0;
        $skipped = 0;
        $errors = [];
        $locale = app()->getLocale();
        $condition = (string) ($data['condition'] ?? 'new');
        $stock = isset($data['stock_qty']) ? (int) $data['stock_qty'] : 0;
        $price = round((float) $data['price'], 2);
        $approval = MarketplaceSettings::autoApproveProducts()
            ? \App\Domain\Marketplace\Models\SellerProduct::STATUS_APPROVED
            : \App\Domain\Marketplace\Models\SellerProduct::STATUS_PENDING;

        foreach (array_values(array_unique(array_map('intval', $data['product_ids']))) as $pid) {
            $product = Product::query()->find($pid);
            if (! $product) {
                $skipped++;
                $errors[] = ['productId' => $pid, 'error' => 'not_found'];
                continue;
            }
            if (\App\Domain\Marketplace\Models\SellerProduct::query()
                ->where('seller_id', $seller->id)
                ->where('product_id', $product->id)
                ->exists()) {
                $skipped++;
                $errors[] = ['productId' => $pid, 'error' => 'already_selling'];
                continue;
            }
            try {
                app(\App\Domain\Marketplace\Services\SellerSubscriptionGate::class)
                    ->assertCanCreateProduct($seller, (string) $product->type);
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = ['productId' => $pid, 'error' => 'plan_limit', 'message' => $e->getMessage()];
                continue;
            }
            $name = (string) ($product->translation($locale)?->name ?? $product->sku);
            \App\Domain\Marketplace\Models\SellerProduct::query()->create([
                'seller_id' => $seller->id,
                'product_id' => $product->id,
                'sku' => $product->sku,
                'name' => $name,
                'slug' => $product->translation($locale)?->slug,
                'price' => $price,
                'compare_price' => $product->compare_price,
                'stock_qty' => $stock > 0 ? $stock : (int) $product->stock_qty,
                'category_id' => $product->default_category_id,
                'is_enabled' => $approval === \App\Domain\Marketplace\Models\SellerProduct::STATUS_APPROVED,
                'approval_status' => $approval,
                'condition' => $condition,
                'is_catalog_offer' => true,
                'is_draft' => false,
            ]);
            $created++;
        }

        return response()->json([
            'message' => 'Bulk catalog offers processed.',
            'data' => [
                'created' => $created,
                'skipped' => $skipped,
                'errors' => $errors,
            ],
        ]);
    }

    public function updateProduct(Request $request, Product $product): JsonResponse
    {
        $seller = $this->seller($request);
        $this->ownedProduct($seller, $product);
        $data = $this->productData($request, $product, $seller);
        $product = $this->sellers->saveProduct($seller, $data, $product);
        $this->storeProductImages($request, $seller, $product);

        if ($request->has('image_order')) {
            $this->productCatalog->reorderImages($seller, $product, (array) $request->input('image_order', []));
        }

        return response()->json(['product' => $this->productPayload($seller, $product)]);
    }

    public function uploadProductImage(Request $request, Product $product): JsonResponse
    {
        $seller = $this->seller($request);
        $this->ownedProduct($seller, $product);
        $request->validate([
            'images' => ['nullable', 'array'],
            'images.*' => ['file', 'image'],
            'image' => ['nullable', 'file', 'image'],
            'cover_index' => ['nullable', 'integer', 'min:0'],
        ]);

        $files = array_values($request->file('images', []) ?: []);
        if ($files === [] && $request->file('image')) {
            $files = [$request->file('image')];
        }
        if ($files === []) {
            abort(422, 'Attach at least one image.');
        }

        $coverIndex = $request->input('cover_index');
        $this->productCatalog->storeImages(
            $seller,
            $product,
            $files,
            ($coverIndex === null || $coverIndex === '') ? null : (int) $coverIndex,
        );

        return response()->json([
            'product' => $this->productCatalog->present($seller, $product->fresh()),
        ]);
    }

    public function deleteProductImage(Request $request, Product $product, ProductImage $image): JsonResponse
    {
        $seller = $this->seller($request);
        $this->ownedProduct($seller, $product);
        $this->productCatalog->deleteImage($seller, $product, $image);

        return response()->json([
            'product' => $this->productCatalog->present($seller, $product->fresh()),
        ]);
    }

    public function reorderProductImages(Request $request, Product $product): JsonResponse
    {
        $seller = $this->seller($request);
        $this->ownedProduct($seller, $product);
        $data = $request->validate([
            'image_order' => ['required', 'array'],
            'image_order.*' => ['integer'],
            'cover_image_id' => ['nullable', 'integer'],
        ]);

        $this->productCatalog->reorderImages($seller, $product, $data['image_order']);

        if (! empty($data['cover_image_id'])) {
            $cover = $product->images()->whereKey((int) $data['cover_image_id'])->first();
            if ($cover) {
                $this->productCatalog->setCoverImage($seller, $product, $cover);
            }
        }

        return response()->json([
            'product' => $this->productCatalog->present($seller, $product->fresh()),
        ]);
    }

    public function destroyProduct(Request $request, Product $product): JsonResponse
    {
        $seller = $this->seller($request);
        $this->ownedProduct($seller, $product);
        $result = $this->sellers->deleteProduct($seller, $product);

        return response()->json(['ok' => true, 'action' => $result['action']]);
    }

    public function inventory(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        $list = app(\App\Domain\Marketplace\Services\SellerInventoryListService::class);
        $filters = $list->filtersFromRequest($request);
        $inventory = $list->paginate($seller, $filters);

        return response()->json([
            'inventory' => $inventory,
            'filters' => $filters,
            'items' => collect($inventory->items())->values()->all(),
        ]);
    }

    public function bulkAdjustInventory(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
            'delta' => ['required', 'integer', 'not_in:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $result = app(\App\Domain\Marketplace\Services\SellerInventoryService::class)
            ->bulkAdjust($seller, $data['ids'], (int) $data['delta'], $data['note'] ?? null);

        return response()->json(array_merge(['ok' => true], $result));
    }

    public function productInventory(Request $request, Product $product): JsonResponse
    {
        $seller = $this->seller($request);
        $inventory = app(\App\Domain\Marketplace\Services\SellerInventoryService::class);
        $inventory->assertOwnsProduct($seller, $product);

        return response()->json([
            'summary' => $inventory->summary($seller, $product),
            'movements' => $inventory->movements($seller, $product),
        ]);
    }

    public function adjustProductInventory(Request $request, Product $product): JsonResponse
    {
        $seller = $this->seller($request);
        $data = $request->validate([
            'delta' => ['required', 'integer', 'not_in:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $inventory = app(\App\Domain\Marketplace\Services\SellerInventoryService::class);
        $updated = $inventory->adjust($seller, $product, (int) $data['delta'], $data['note'] ?? null);

        return response()->json([
            'summary' => $inventory->summary($seller, $updated),
            'movements' => $inventory->movements($seller, $updated),
        ]);
    }

    public function orders(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        $statusTab = (string) $request->input('status', 'all');
        $number = trim((string) $request->input('number', ''));
        $customer = trim((string) $request->input('customer', ''));
        $paymentStatus = trim((string) $request->input('payment_status', ''));
        $fulfillment = trim((string) $request->input('fulfillment', ''));
        $totalMin = $request->input('total_min');
        $totalMax = $request->input('total_max');
        $idMin = $request->input('id_min');
        $idMax = $request->input('id_max');
        $from = trim((string) $request->input('from', ''));
        $sort = (string) $request->input('sort', 'id');
        $allowedSort = ['id', 'number', 'customer', 'status', 'payment_status', 'grand_total', 'created_at'];
        if (! in_array($sort, $allowedSort, true)) {
            $sort = 'id';
        }
        if ($sort === 'customer') {
            $sort = 'customer_email';
        }
        $dir = strtolower((string) $request->input('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $counts = [
            'all' => $seller->orders()->count(),
            'pending' => $seller->orders()->where('status', 'pending')->count(),
            'processing' => $seller->orders()->where('status', 'processing')->count(),
            'shipped' => $seller->orders()->where('status', 'shipped')->count(),
            'completed' => $seller->orders()->where('status', 'completed')->count(),
            'cancelled' => $seller->orders()->where('status', 'cancelled')->count(),
        ];

        $query = $seller->orders()->with(['items', 'shipments', 'order.paymentMethod:id,name']);

        if ($statusTab !== '' && $statusTab !== 'all') {
            $query->where('status', $statusTab);
        }
        if ($number !== '') {
            $query->where('number', 'like', '%'.$number.'%');
        }
        if ($customer !== '') {
            $query->where('customer_email', 'like', '%'.$customer.'%');
        }
        if ($paymentStatus !== '') {
            $query->where('payment_status', $paymentStatus);
        }
        if ($fulfillment !== '') {
            $query->where('status', $fulfillment);
        }
        if ($totalMin !== null && $totalMin !== '') {
            $query->where('grand_total', '>=', (float) $totalMin);
        }
        if ($totalMax !== null && $totalMax !== '') {
            $query->where('grand_total', '<=', (float) $totalMax);
        }
        if ($idMin !== null && $idMin !== '') {
            $query->where('id', '>=', (int) $idMin);
        }
        if ($idMax !== null && $idMax !== '') {
            $query->where('id', '<=', (int) $idMax);
        }
        if ($from !== '') {
            $query->whereDate('created_at', '>=', $from);
        }

        $query->orderBy($sort, $dir);

        return response()->json([
            'orders' => $query->paginate(20)->withQueryString(),
            'counts' => $counts,
        ]);
    }

    public function shipments(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        $fulfillment = app(\App\Domain\Marketplace\Services\SellerFulfillmentService::class);
        $rows = $seller->shipments()
            ->with(['items', 'sellerOrder:id,number,status'])
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'shipments' => [
                'data' => collect($rows->items())->map(function ($shipment) use ($fulfillment) {
                    $payload = $fulfillment->presentShipment($shipment);

                    return array_merge($payload, [
                        'seller_order_number' => $shipment->sellerOrder?->number,
                        'seller_order_status' => $shipment->sellerOrder?->status,
                    ]);
                })->values()->all(),
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function showOrder(Request $request, int $order): JsonResponse
    {
        $seller = $this->seller($request);
        $row = $seller->orders()
            ->with(['items', 'shipments.items', 'returns.items', 'order:id,number,shipping_address,billing_address,shipping_method_id,status'])
            ->whereKey($order)
            ->firstOrFail();

        $fulfillment = app(\App\Domain\Marketplace\Services\SellerFulfillmentService::class);
        $returns = app(\App\Domain\Marketplace\Services\SellerReturnService::class);

        $orderPayload = $row->toArray();
        $shippingAddress = $row->order?->shipping_address;
        if (! MarketplaceSettings::canViewCustomers()) {
            $orderPayload['customer_email'] = $this->maskEmail((string) ($row->customer_email ?? ''));
            $orderPayload['customer_name'] = $this->maskName((string) ($row->customer_name ?? ''));
            $shippingAddress = null;
        }

        return response()->json([
            'order' => $orderPayload,
            'shipping_address' => $shippingAddress,
            'shipments' => $row->shipments->map(fn ($shipment) => $fulfillment->presentShipment($shipment))->values()->all(),
            'returns' => $row->returns->map(fn ($ret) => $returns->present($ret))
                ->values()
                ->all(),
            'capabilities' => [
                'canCreateInvoice' => MarketplaceSettings::canCreateInvoice(),
                'canCreateShipment' => MarketplaceSettings::canCreateShipment(),
                'canCancelOrder' => MarketplaceSettings::canCancelOrder(),
                'canViewCustomers' => MarketplaceSettings::canViewCustomers(),
            ],
        ]);
    }

    public function updateOrder(Request $request, int $order): JsonResponse
    {
        $seller = $this->seller($request);
        $row = $seller->orders()->whereKey($order)->firstOrFail();
        $data = $request->validate([
            'status' => ['required', 'in:pending,processing,cancelled'],
        ]);
        if ($data['status'] === 'cancelled' && ! MarketplaceSettings::canCancelOrder()) {
            abort(403, 'Sellers are not allowed to cancel orders.');
        }

        return response()->json([
            'order' => $this->sellers->updateSellerOrderStatus($seller, $row, $data['status']),
        ]);
    }

    public function cancelOrderItems(Request $request, int $order): JsonResponse
    {
        if (! MarketplaceSettings::canCancelOrder()) {
            abort(403, 'Sellers are not allowed to cancel orders.');
        }
        $seller = $this->seller($request);
        $row = $seller->orders()->whereKey($order)->firstOrFail();
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.seller_order_item_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
        ]);

        $returns = app(\App\Domain\Marketplace\Services\SellerReturnService::class);

        return response()->json([
            'order' => $returns->cancelItems($seller, $row, $data['items']),
        ]);
    }

    public function showOrderInvoice(Request $request, int $order, SellerOrderDocument $documents): Response
    {
        if (! MarketplaceSettings::canCreateInvoice()) {
            abort(403, 'Sellers are not allowed to create invoices.');
        }
        $sellerOrder = $this->sellerOrderForDocuments($request, $order);
        $documents->ensurePaid($sellerOrder);

        return response($documents->invoiceHtml($sellerOrder), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    public function downloadOrderInvoice(Request $request, int $order, SellerOrderDocument $documents): Response
    {
        if (! MarketplaceSettings::canCreateInvoice()) {
            abort(403, 'Sellers are not allowed to create invoices.');
        }
        $sellerOrder = $this->sellerOrderForDocuments($request, $order);
        $documents->ensurePaid($sellerOrder);
        $filename = 'invoice-'.$sellerOrder->number.'.html';

        return response($documents->invoiceHtml($sellerOrder), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function showOrderDeliverySlip(Request $request, int $order, SellerOrderDocument $documents): Response
    {
        $sellerOrder = $this->sellerOrderForDocuments($request, $order);
        $documents->ensureDeliverable($sellerOrder);

        return response($documents->deliveryHtml($sellerOrder), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    public function downloadOrderDeliverySlip(Request $request, int $order, SellerOrderDocument $documents): Response
    {
        $sellerOrder = $this->sellerOrderForDocuments($request, $order);
        $documents->ensureDeliverable($sellerOrder);
        $filename = 'delivery-slip-'.$sellerOrder->number.'.html';

        return response($documents->deliveryHtml($sellerOrder), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function returns(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        $rows = $seller->returns()->with('items')->latest('id')->paginate(20);
        $service = app(\App\Domain\Marketplace\Services\SellerReturnService::class);

        return response()->json([
            'returns' => [
                'data' => collect($rows->items())->map(fn ($row) => $service->present($row))->values()->all(),
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function approveReturn(Request $request, int $return): JsonResponse
    {
        $seller = $this->seller($request);
        $row = $seller->returns()->whereKey($return)->firstOrFail();
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $service = app(\App\Domain\Marketplace\Services\SellerReturnService::class);

        return response()->json([
            'return' => $service->present($service->approve($row, $seller, null, $data['note'] ?? null)),
        ]);
    }

    public function rejectReturn(Request $request, int $return): JsonResponse
    {
        $seller = $this->seller($request);
        $row = $seller->returns()->whereKey($return)->firstOrFail();
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $service = app(\App\Domain\Marketplace\Services\SellerReturnService::class);

        return response()->json([
            'return' => $service->present($service->reject($row, $seller, null, $data['note'] ?? null)),
        ]);
    }

    public function receiveReturn(Request $request, int $return): JsonResponse
    {
        $seller = $this->seller($request);
        $row = $seller->returns()->whereKey($return)->firstOrFail();
        $service = app(\App\Domain\Marketplace\Services\SellerReturnService::class);

        return response()->json([
            'return' => $service->present($service->markReceived($row, $seller)),
        ]);
    }

    public function refundReturn(Request $request, int $return): JsonResponse
    {
        $seller = $this->seller($request);
        $row = $seller->returns()->whereKey($return)->firstOrFail();
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $service = app(\App\Domain\Marketplace\Services\SellerReturnService::class);

        return response()->json([
            'return' => $service->present($service->refund($row, $seller, null, $data['note'] ?? null)),
        ]);
    }

    public function reviews(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        $service = app(\App\Domain\Marketplace\Services\SellerReviewService::class);
        $rows = $seller->sellerReviews()->with('customer')->latest('id')->paginate(20);

        return response()->json([
            'summary' => $service->publicSummary($seller),
            'reviews' => [
                'data' => collect($rows->items())->map(fn ($row) => $service->presentForSeller($row))->values()->all(),
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function createShipment(Request $request, int $order): JsonResponse
    {
        if (! MarketplaceSettings::canCreateShipment()) {
            abort(403, 'Sellers are not allowed to create shipments.');
        }
        $seller = $this->seller($request);
        $row = $seller->orders()->whereKey($order)->firstOrFail();
        // Ownership from auth + seller order — never trust seller_id / order_id from body.
        $request->request->remove('seller_id');
        $request->request->remove('order_id');
        $request->request->remove('customer_id');
        $data = $request->validate([
            'carrier' => ['nullable', 'string', 'max:120'],
            'tracking_number' => ['nullable', 'string', 'max:120'],
            'tracking_url' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $fulfillment = app(\App\Domain\Marketplace\Services\SellerFulfillmentService::class);
        $shipment = $fulfillment->createShipment($seller, $row, $data);

        return response()->json([
            'shipment' => $fulfillment->presentShipment($shipment),
            'order' => $seller->orders()->with(['items', 'shipments.items'])->whereKey($order)->first(),
        ], 201);
    }

    /** Seller Panel alias: Schedule Pickup → create shipment. */
    /** Note: carriers available when scheduling pickup (Self Ship always present). */
    public function fulfilmentCarriers(Request $request): JsonResponse
    {
        $this->seller($request);

        return response()->json([
            'data' => \App\Domain\Marketplace\MarketplaceFulfilmentCarriers::availableForSellers(),
        ]);
    }

    public function schedulePickup(Request $request, int $order): JsonResponse
    {
        return $this->createShipment($request, $order);
    }

    /** Seller Panel: Request Payment for a completed seller order. */
    public function requestPayment(Request $request, int $order): JsonResponse
    {
        $seller = $this->seller($request);
        $row = $seller->orders()->whereKey($order)->firstOrFail();
        $updated = app(\App\Domain\Marketplace\Services\SellerPaymentRequestService::class)
            ->request($seller, $row);

        return response()->json([
            'message' => 'Your payment request is submitted successfully.',
            'data' => [
                'id' => (int) $updated->id,
                'sellerPayoutStatus' => (string) ($updated->seller_payout_status ?? ''),
                'requestedAt' => optional($updated->seller_payout_requested_at)?->toIso8601String(),
                'earningTotal' => (float) $updated->earning_total,
            ],
        ]);
    }

    public function updateShipment(Request $request, int $shipment): JsonResponse
    {
        $seller = $this->seller($request);
        $row = \App\Domain\Shipping\Models\Shipment::query()
            ->where('seller_id', $seller->id)
            ->whereKey($shipment)
            ->firstOrFail();
        $request->request->remove('seller_id');
        $request->request->remove('order_id');
        $data = $request->validate([
            'carrier' => ['nullable', 'string', 'max:120'],
            'tracking_number' => ['nullable', 'string', 'max:120'],
            'tracking_url' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $fulfillment = app(\App\Domain\Marketplace\Services\SellerFulfillmentService::class);

        return response()->json([
            'shipment' => $fulfillment->presentShipment($fulfillment->updateTracking($seller, $row, $data)),
        ]);
    }

    public function shipShipment(Request $request, int $shipment): JsonResponse
    {
        $seller = $this->seller($request);
        $row = \App\Domain\Shipping\Models\Shipment::query()
            ->where('seller_id', $seller->id)
            ->whereKey($shipment)
            ->firstOrFail();
        $data = $request->validate([
            'carrier' => ['nullable', 'string', 'max:120'],
            'tracking_number' => ['nullable', 'string', 'max:120'],
            'tracking_url' => ['nullable', 'string', 'max:500'],
        ]);

        $fulfillment = app(\App\Domain\Marketplace\Services\SellerFulfillmentService::class);

        return response()->json([
            'shipment' => $fulfillment->presentShipment($fulfillment->markShipped($seller, $row, $data)),
        ]);
    }

    public function deliverShipment(Request $request, int $shipment): JsonResponse
    {
        $seller = $this->seller($request);
        $row = \App\Domain\Shipping\Models\Shipment::query()
            ->where('seller_id', $seller->id)
            ->whereKey($shipment)
            ->firstOrFail();
        $fulfillment = app(\App\Domain\Marketplace\Services\SellerFulfillmentService::class);

        return response()->json([
            'shipment' => $fulfillment->presentShipment($fulfillment->markDelivered($seller, $row)),
        ]);
    }

    public function readyShipment(Request $request, int $shipment): JsonResponse
    {
        $seller = $this->seller($request);
        $row = \App\Domain\Shipping\Models\Shipment::query()
            ->where('seller_id', $seller->id)
            ->whereKey($shipment)
            ->firstOrFail();
        $fulfillment = app(\App\Domain\Marketplace\Services\SellerFulfillmentService::class);

        return response()->json([
            'shipment' => $fulfillment->presentShipment($fulfillment->markReady($seller, $row)),
        ]);
    }

    public function commission(Request $request): JsonResponse
    {
        $seller = $this->seller($request);

        return response()->json([
            'rate' => (float) $seller->commission_rate,
            'commissions' => $seller->commissions()->latest()->get(),
        ]);
    }

    public function earnings(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        $wallet = app(\App\Domain\Marketplace\Services\SellerEarningAvailabilityService::class)->walletBalances($seller);
        $totals = app(\App\Domain\Marketplace\Services\MarketplaceStatsService::class)->earningsTotals($seller);

        return response()->json([
            'totals' => [
                'gross' => $totals['gross'],
                'commission' => $totals['commission'],
                'net' => $totals['net'],
                'ledger_balance' => $wallet['ledger_balance'],
                'pending' => $wallet['pending'],
                'available' => $wallet['available'],
                'paid' => $wallet['paid'],
                'available_for_payout' => $wallet['available_for_payout'],
            ],
            'earnings' => $seller->earnings()->with('sellerOrder:id,number')->latest()->paginate(20),
        ]);
    }

    public function ledger(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        $wallet = app(\App\Domain\Marketplace\Services\SellerEarningAvailabilityService::class)->walletBalances($seller);

        return response()->json([
            'balance' => $wallet['ledger_balance'],
            'available' => $wallet['available_for_payout'],
            'pending' => $wallet['pending'],
            'paid' => $wallet['paid'],
            'entries' => $seller->ledgerEntries()
                ->with('sellerOrder:id,number')
                ->latest('id')
                ->paginate(20),
        ]);
    }

    /**
     */
    public function transactions(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        \App\Domain\Marketplace\SellerPaymentRequestSchema::ensure();
        $from = $request->query('from') ?? now()->subDays(30)->toDateString();
        $to = $request->query('to') ?? now()->toDateString();

        $ordersQ = $seller->orders()
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to);
        $totalSaleInvoiced = (float) (clone $ordersQ)->sum('subtotal');
        $totalCommission = (float) (clone $ordersQ)->sum('commission_total');
        $totalSaleSeller = (float) (clone $ordersQ)->sum('earning_total');
        $totalPayout = (float) $seller->orders()
            ->where('seller_payout_status', \App\Domain\Marketplace\Services\SellerPaymentRequestService::STATUS_PAID)
            ->whereDate('seller_payout_paid_at', '>=', $from)
            ->whereDate('seller_payout_paid_at', '<=', $to)
            ->sum('earning_total');
        $remainingPayout = (float) $seller->orders()
            ->where('seller_payout_status', \App\Domain\Marketplace\Services\SellerPaymentRequestService::STATUS_REQUESTED)
            ->sum('earning_total');

        $paidRows = $seller->orders()
            ->where('seller_payout_status', \App\Domain\Marketplace\Services\SellerPaymentRequestService::STATUS_PAID)
            ->orderByDesc('seller_payout_paid_at')
            ->limit(50)
            ->get(['id', 'number', 'earning_total', 'commission_total', 'seller_payout_paid_at', 'seller_payout_comment']);

        $wallet = app(\App\Domain\Marketplace\Services\SellerEarningAvailabilityService::class)->walletBalances($seller);

        return response()->json([
            'meta' => [
                'from' => $from,
                'to' => $to,
            ],
            'cards' => [
                'totalSaleSeller' => round($totalSaleSeller, 2),
                'totalCommissionAdmin' => round($totalCommission, 2),
                'totalSaleInvoiced' => round($totalSaleInvoiced, 2),
                'totalPayout' => round($totalPayout, 2),
                'remainingPayout' => round($remainingPayout, 2),
                'totalPayoutShare' => $totalSaleSeller > 0 ? round(($totalPayout / $totalSaleSeller) * 100, 2) : 0,
                'remainingPayoutShare' => $totalSaleSeller > 0 ? round(($remainingPayout / $totalSaleSeller) * 100, 2) : 0,
            ],
            'transactions' => $paidRows->map(fn ($o) => [
                'id' => (int) $o->id,
                'transactionId' => (string) $o->number,
                'comment' => (string) ($o->seller_payout_comment ?? 'Manual Payment'),
                'total' => (float) $o->earning_total,
                'commission' => (float) $o->commission_total,
                'paidAt' => optional($o->seller_payout_paid_at)?->toIso8601String(),
            ])->values()->all(),
            'balance' => $wallet['ledger_balance'],
            'available' => $wallet['available_for_payout'],
            'entries' => $seller->ledgerEntries()
                ->with('sellerOrder:id,number')
                ->latest('id')
                ->paginate(20),
        ]);
    }

    public function orderFinancials(Request $request, int $order): JsonResponse
    {
        $seller = $this->seller($request);
        $row = $seller->orders()->with(['items', 'earnings', 'ledgerEntries'])->whereKey($order)->firstOrFail();

        return response()->json([
            'order' => [
                'id' => $row->id,
                'number' => $row->number,
                'payment_status' => $row->payment_status,
                'currency_code' => $row->currency_code,
                'subtotal' => (float) $row->subtotal,
                'discount_total' => (float) $row->discount_total,
                'tax_total' => (float) $row->tax_total,
                'shipping_total' => (float) $row->shipping_total,
                'commission_rate' => $row->commission_rate !== null ? (float) $row->commission_rate : null,
                'commission_source' => $row->commission_source,
                'commission_type' => $row->commission_type,
                'commission_value' => $row->commission_value !== null ? (float) $row->commission_value : null,
                'commission_rule_id' => $row->commission_rule_id,
                'commission_total' => (float) $row->commission_total,
                'earning_total' => (float) $row->earning_total,
                'grand_total' => (float) $row->grand_total,
                'commissionable_gross' => $row->commissionableGross(),
            ],
            'earnings' => $row->earnings,
            'ledger_entries' => $row->ledgerEntries,
        ]);
    }

    public function revenue(Request $request): JsonResponse
    {
        $seller = $this->seller($request);

        return response()->json([
            'revenues' => $seller->revenues()->orderByDesc('period_date')->paginate(31),
        ]);
    }

    public function shipping(Request $request): JsonResponse
    {
        return response()->json(['methods' => $this->seller($request)->shippingMethods()->latest()->get()]);
    }

    public function storeShipping(Request $request): JsonResponse
    {
        $row = $this->sellers->saveShipping($this->seller($request), $this->shippingData($request));

        return response()->json(['method' => $row], 201);
    }

    public function updateShipping(Request $request, int $method): JsonResponse
    {
        $seller = $this->seller($request);
        $row = $seller->shippingMethods()->whereKey($method)->firstOrFail();

        return response()->json(['method' => $this->sellers->saveShipping($seller, $this->shippingData($request), $row)]);
    }

    public function destroyShipping(Request $request, int $method): JsonResponse
    {
        $seller = $this->seller($request);
        $row = $seller->shippingMethods()->whereKey($method)->firstOrFail();
        $this->sellers->deleteShipping($seller, $row);

        return response()->json(['ok' => true]);
    }

    public function payments(Request $request): JsonResponse
    {
        return response()->json(['methods' => $this->seller($request)->paymentMethods()->latest()->get()]);
    }

    public function storePayment(Request $request): JsonResponse
    {
        $row = $this->sellers->savePayment($this->seller($request), $this->paymentData($request));

        return response()->json(['method' => $row], 201);
    }

    public function updatePayment(Request $request, int $method): JsonResponse
    {
        $seller = $this->seller($request);
        $row = $seller->paymentMethods()->whereKey($method)->firstOrFail();

        return response()->json(['method' => $this->sellers->savePayment($seller, $this->paymentData($request), $row)]);
    }

    public function destroyPayment(Request $request, int $method): JsonResponse
    {
        $seller = $this->seller($request);
        $row = $seller->paymentMethods()->whereKey($method)->firstOrFail();
        $this->sellers->deletePayment($seller, $row);

        return response()->json(['ok' => true]);
    }

    public function discounts(Request $request): JsonResponse
    {
        return response()->json(['discounts' => $this->seller($request)->discounts()->latest()->get()]);
    }

    public function storeDiscount(Request $request): JsonResponse
    {
        $row = $this->sellers->saveDiscount($this->seller($request), $this->discountData($request));

        return response()->json(['discount' => $row], 201);
    }

    public function updateDiscount(Request $request, int $discount): JsonResponse
    {
        $seller = $this->seller($request);
        $row = $seller->discounts()->whereKey($discount)->firstOrFail();

        return response()->json(['discount' => $this->sellers->saveDiscount($seller, $this->discountData($request), $row)]);
    }

    public function destroyDiscount(Request $request, int $discount): JsonResponse
    {
        $seller = $this->seller($request);
        $row = $seller->discounts()->whereKey($discount)->firstOrFail();
        $this->sellers->deleteDiscount($seller, $row);

        return response()->json(['ok' => true]);
    }

    public function exportProducts(Request $request): StreamedResponse
    {
        $seller = $this->seller($request);
        $filters = $this->productList->filtersFromRequest($request);
        $rows = $this->productList->exportRows($seller, $filters);

        return $this->csv('seller-products.csv', ['id', 'sku', 'name', 'category', 'price', 'stock', 'enabled', 'approval'], $rows->map(fn ($row) => [
            $row['product_id'],
            $row['sku'],
            $row['name'],
            $row['category_name'] ?? '',
            $row['price'],
            $row['stock_qty'],
            $row['is_enabled'] ? 'yes' : 'no',
            $row['approval_status'],
        ]));
    }

    public function importProducts(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:8192'],
            'language' => ['nullable', 'string', 'max:16'],
            'delimiter' => ['nullable', 'string', 'max:8'],
            'multivalue_separator' => ['nullable', 'string', 'max:8'],
        ]);

        $locale = $data['language'] ?? Language::defaultCode();
        $delimiter = $data['delimiter'] ?? ProductCsvSchema::DEFAULT_DELIMITER;
        $multi = $data['multivalue_separator'] ?? ProductCsvSchema::DEFAULT_MULTI_SEPARATOR;

        $count = $this->sellers->importProductsCsv(
            $seller,
            $request->file('file')->getContent(),
            $locale,
            $delimiter,
            $multi,
        );

        return response()->json([
            'ok' => true,
            'count' => $count,
            'message' => "Imported {$count} products.",
        ]);
    }

    public function importProductsSample(Request $request): StreamedResponse
    {
        $this->seller($request);
        $delimiter = (string) $request->input('delimiter', ProductCsvSchema::DEFAULT_DELIMITER);
        $multi = (string) $request->input('multivalue_separator', ProductCsvSchema::DEFAULT_MULTI_SEPARATOR);
        $csv = app(\App\Domain\Catalog\Services\ProductService::class)->sampleCsv($delimiter, $multi);

        return response()->streamDownload(fn () => print ($csv), 'seller-products-sample.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportOrders(Request $request): StreamedResponse
    {
        $seller = $this->seller($request);
        $rows = $seller->orders()->orderByDesc('id')->get();

        return $this->csv('seller-orders.csv', ['id', 'number', 'email', 'status', 'total', 'earning'], $rows->map(fn ($row) => [
            $row->id, $row->number, $row->customer_email, $row->status, $row->grand_total, $row->earning_total,
        ]));
    }

    public function messages(Request $request): JsonResponse
    {
        return response()->json([
            'messages' => $this->seller($request)->messages()->latest()->paginate(20),
        ]);
    }

    public function replyMessage(Request $request, int $message): JsonResponse
    {
        $seller = $this->seller($request);
        $row = $seller->messages()->whereKey($message)->firstOrFail();
        $data = $request->validate(['reply_body' => ['required', 'string', 'max:4000']]);
        $row->update([
            'reply_body' => $data['reply_body'],
            'replied_at' => now(),
            'is_read' => true,
        ]);

        return response()->json(['message' => $row->fresh()]);
    }

    public function payouts(Request $request): JsonResponse
    {
        $seller = $this->seller($request);

        return response()->json([
            'available' => $this->catalog->availablePayoutAmount($seller),
            'methods' => $seller->paymentMethods()->where('is_enabled', true)->get(['id', 'name', 'type']),
            'payouts' => $seller->withdrawals()->latest()->paginate(20),
        ]);
    }

    public function storePayout(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        foreach ([
            'seller_id', 'customer_id', 'status', 'payout_status', 'paid_at', 'processed_at',
        ] as $key) {
            $request->request->remove($key);
        }
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'seller_payment_method_id' => [
                'nullable',
                Rule::exists('seller_payment_methods', 'id')->where('seller_id', $seller->id),
            ],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $row = $this->catalog->requestWithdrawal($seller, $data);

        return response()->json(['payout' => $row], 201);
    }

    public function categories(Request $request): JsonResponse
    {
        $seller = $this->seller($request);

        return response()->json([
            'tree' => $this->catalog->sellerCategoryTree($seller),
            'counts' => $this->catalog->sellerCategoryCounts($seller),
            'parent_options' => $this->catalog->sellerCategoryParentOptions($seller),
            'rows' => $seller->categories()->orderBy('name')->get(),
            ...$this->categoryFormMeta(),
        ]);
    }

    public function showCategory(Request $request, int $id): JsonResponse
    {
        $seller = $this->seller($request);
        $row = $seller->categories()->whereKey($id)->firstOrFail();

        return response()->json([
            'category' => $this->catalog->presentSellerCategoryForm($seller, $row),
            'parent_options' => $this->catalog->sellerCategoryParentOptions($seller, $row),
            ...$this->categoryFormMeta(),
        ]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $row = $this->catalog->saveCategory(
            $this->seller($request),
            $this->categoryData($request),
            null,
            $this->categoryFiles($request),
        );

        return response()->json(['row' => $row], 201);
    }

    public function updateCategory(Request $request, int $id): JsonResponse
    {
        $seller = $this->seller($request);
        $row = $seller->categories()->whereKey($id)->firstOrFail();

        return response()->json([
            'row' => $this->catalog->saveCategory(
                $seller,
                $this->categoryData($request),
                $row,
                $this->categoryFiles($request),
            ),
        ]);
    }

    public function toggleCategory(Request $request, int $id): JsonResponse
    {
        $seller = $this->seller($request);
        $row = $seller->categories()->whereKey($id)->firstOrFail();

        return response()->json(['row' => $this->catalog->toggleCategory($seller, $row)]);
    }

    public function reorderCategories(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);
        $this->catalog->reorderCategories($this->seller($request), $data['ids']);

        return response()->json(['ok' => true]);
    }

    public function bulkCategoryStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'enabled' => ['required', 'boolean'],
        ]);
        $updated = $this->catalog->bulkCategoryStatus($this->seller($request), $data['ids'], (bool) $data['enabled']);

        return response()->json(['updated' => $updated]);
    }

    public function bulkDestroyCategories(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);
        $deleted = $this->catalog->bulkDeleteCategories($this->seller($request), $data['ids']);

        return response()->json(['deleted' => $deleted]);
    }

    public function destroyCategory(Request $request, int $id): JsonResponse
    {
        $seller = $this->seller($request);
        $this->catalog->deleteCategory($seller, $seller->categories()->whereKey($id)->firstOrFail());

        return response()->json(['ok' => true]);
    }

    public function features(Request $request): JsonResponse
    {
        $seller = $this->seller($request);

        return response()->json([
            'rows' => $this->catalog->sellerFeatureRows($seller),
            'counts' => $this->catalog->sellerFeatureCounts($seller),
            ...$this->featureFormMeta(),
        ]);
    }

    public function showFeature(Request $request, int $id): JsonResponse
    {
        $seller = $this->seller($request);
        $row = $seller->features()->whereKey($id)->firstOrFail();

        return response()->json([
            'feature' => $this->catalog->presentSellerFeatureForm($seller, $row),
            ...$this->featureFormMeta(),
        ]);
    }

    public function storeFeature(Request $request): JsonResponse
    {
        $row = $this->catalog->saveFeature($this->seller($request), $this->featureData($request));

        return response()->json(['row' => $row], 201);
    }

    public function updateFeature(Request $request, int $id): JsonResponse
    {
        $seller = $this->seller($request);
        $row = $seller->features()->whereKey($id)->firstOrFail();

        return response()->json([
            'row' => $this->catalog->saveFeature($seller, $this->featureData($request), $row),
        ]);
    }

    public function toggleFeature(Request $request, int $id): JsonResponse
    {
        $seller = $this->seller($request);
        $row = $seller->features()->whereKey($id)->firstOrFail();

        return response()->json(['row' => $this->catalog->toggleFeature($seller, $row)]);
    }

    public function reorderFeatures(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);
        $this->catalog->reorderFeatures($this->seller($request), $data['ids']);

        return response()->json(['ok' => true]);
    }

    public function bulkFeatureStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'enabled' => ['required', 'boolean'],
        ]);
        $updated = $this->catalog->bulkFeatureStatus($this->seller($request), $data['ids'], (bool) $data['enabled']);

        return response()->json(['updated' => $updated]);
    }

    public function bulkDestroyFeatures(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);
        $deleted = $this->catalog->bulkDeleteFeatures($this->seller($request), $data['ids']);

        return response()->json(['deleted' => $deleted]);
    }

    public function destroyFeature(Request $request, int $id): JsonResponse
    {
        $seller = $this->seller($request);
        $this->catalog->deleteFeature($seller, $seller->features()->whereKey($id)->firstOrFail());

        return response()->json(['ok' => true]);
    }

    public function featureValues(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        $featureId = $request->integer('feature_id') ?: null;

        return response()->json([
            'rows' => $this->catalog->sellerFeatureValueRows($seller, $featureId),
            'counts' => $this->catalog->sellerFeatureValueCounts($seller, $featureId),
            'feature' => $featureId ? $this->catalog->presentSellerFeatureForValues($seller, $featureId) : null,
            'features' => $this->catalog->sellerFeatureOptions($seller),
            ...$this->featureValueFormMeta(),
        ]);
    }

    public function showFeatureValue(Request $request, int $id): JsonResponse
    {
        $seller = $this->seller($request);
        $value = FeatureValue::query()->whereKey($id)->firstOrFail();
        $presented = $this->catalog->presentSellerFeatureValueForm($seller, $value);

        return response()->json([
            'feature_value' => $presented,
            'features' => $this->catalog->sellerFeatureOptions($seller, $presented['seller_feature_id'] ?? null),
            ...$this->featureValueFormMeta(),
        ]);
    }

    public function storeFeatureValue(Request $request): JsonResponse
    {
        $row = $this->catalog->saveFeatureValue($this->seller($request), $this->featureValueData($request));

        return response()->json(['row' => $row], 201);
    }

    public function updateFeatureValue(Request $request, int $id): JsonResponse
    {
        $seller = $this->seller($request);
        $value = FeatureValue::query()->whereKey($id)->firstOrFail();

        return response()->json([
            'row' => $this->catalog->saveFeatureValue($seller, $this->featureValueData($request), $value),
        ]);
    }

    public function toggleFeatureValue(Request $request, int $id): JsonResponse
    {
        $seller = $this->seller($request);
        $value = FeatureValue::query()->whereKey($id)->firstOrFail();

        return response()->json(['row' => $this->catalog->toggleFeatureValue($seller, $value)]);
    }

    public function reorderFeatureValues(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:2'],
            'ids.*' => ['integer'],
        ]);
        $this->catalog->reorderFeatureValues($this->seller($request), $data['ids']);

        return response()->json(['ok' => true]);
    }

    public function destroyFeatureValue(Request $request, int $id): JsonResponse
    {
        $seller = $this->seller($request);
        $value = FeatureValue::query()->whereKey($id)->firstOrFail();
        $this->catalog->deleteFeatureValue($seller, $value);

        return response()->json(['ok' => true]);
    }

    public function attributes(Request $request): JsonResponse
    {
        $seller = $this->seller($request);

        return response()->json([
            'rows' => $this->catalog->sellerAttributeRows($seller),
            'counts' => $this->catalog->sellerAttributeCounts($seller),
            ...$this->attributeFormMeta(),
        ]);
    }

    public function showAttribute(Request $request, int $id): JsonResponse
    {
        $seller = $this->seller($request);
        $row = $seller->attributes()->whereKey($id)->firstOrFail();

        return response()->json([
            'attribute' => $this->catalog->presentSellerAttributeForm($seller, $row),
            ...$this->attributeFormMeta(),
        ]);
    }

    public function storeAttribute(Request $request): JsonResponse
    {
        $row = $this->catalog->saveAttribute($this->seller($request), $this->attributeData($request));

        return response()->json(['row' => $row], 201);
    }

    public function updateAttribute(Request $request, int $id): JsonResponse
    {
        $seller = $this->seller($request);
        $row = $seller->attributes()->whereKey($id)->firstOrFail();

        return response()->json([
            'row' => $this->catalog->saveAttribute($seller, $this->attributeData($request), $row),
        ]);
    }

    public function toggleAttribute(Request $request, int $id): JsonResponse
    {
        $seller = $this->seller($request);
        $row = $seller->attributes()->whereKey($id)->firstOrFail();

        return response()->json(['row' => $this->catalog->toggleAttribute($seller, $row)]);
    }

    public function reorderAttributes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);
        $this->catalog->reorderAttributes($this->seller($request), $data['ids']);

        return response()->json(['ok' => true]);
    }

    public function bulkAttributeStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'enabled' => ['required', 'boolean'],
        ]);
        $updated = $this->catalog->bulkAttributeStatus($this->seller($request), $data['ids'], (bool) $data['enabled']);

        return response()->json(['updated' => $updated]);
    }

    public function bulkDestroyAttributes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);
        $deleted = $this->catalog->bulkDeleteAttributes($this->seller($request), $data['ids']);

        return response()->json(['deleted' => $deleted]);
    }

    public function destroyAttribute(Request $request, int $id): JsonResponse
    {
        $seller = $this->seller($request);
        $this->catalog->deleteAttribute($seller, $seller->attributes()->whereKey($id)->firstOrFail());

        return response()->json(['ok' => true]);
    }

    public function attributeValues(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        $attributeId = $request->integer('attribute_id') ?: null;

        return response()->json([
            'rows' => $this->catalog->sellerAttributeValueRows($seller, $attributeId),
            'counts' => $this->catalog->sellerAttributeValueCounts($seller, $attributeId),
            'attribute' => $attributeId ? $this->catalog->presentSellerAttributeForValues($seller, $attributeId) : null,
            'attributes' => $this->catalog->sellerAttributeOptions($seller),
            ...$this->attributeValueFormMeta(),
        ]);
    }

    public function showAttributeValue(Request $request, int $id): JsonResponse
    {
        $seller = $this->seller($request);
        $value = AttributeValue::query()->whereKey($id)->firstOrFail();
        $presented = $this->catalog->presentSellerAttributeValueForm($seller, $value);

        return response()->json([
            'attribute_value' => $presented,
            'attributes' => $this->catalog->sellerAttributeOptions($seller, $presented['seller_attribute_id'] ?? null),
            ...$this->attributeValueFormMeta(),
        ]);
    }

    public function storeAttributeValue(Request $request): JsonResponse
    {
        $row = $this->catalog->saveAttributeValue($this->seller($request), $this->attributeValueData($request));

        return response()->json(['row' => $row], 201);
    }

    public function updateAttributeValue(Request $request, int $id): JsonResponse
    {
        $seller = $this->seller($request);
        $value = AttributeValue::query()->whereKey($id)->firstOrFail();

        return response()->json([
            'row' => $this->catalog->saveAttributeValue($seller, $this->attributeValueData($request), $value),
        ]);
    }

    public function toggleAttributeValue(Request $request, int $id): JsonResponse
    {
        $seller = $this->seller($request);
        $value = AttributeValue::query()->whereKey($id)->firstOrFail();

        return response()->json(['row' => $this->catalog->toggleAttributeValue($seller, $value)]);
    }

    public function reorderAttributeValues(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:2'],
            'ids.*' => ['integer'],
        ]);
        $this->catalog->reorderAttributeValues($this->seller($request), $data['ids']);

        return response()->json(['ok' => true]);
    }

    public function destroyAttributeValue(Request $request, int $id): JsonResponse
    {
        $seller = $this->seller($request);
        $value = AttributeValue::query()->whereKey($id)->firstOrFail();
        $this->catalog->deleteAttributeValue($seller, $value);

        return response()->json(['ok' => true]);
    }

    public function brands(Request $request): JsonResponse
    {
        $seller = $this->seller($request);

        return response()->json([
            'rows' => $this->catalog->sellerBrandRows($seller),
            'counts' => $this->catalog->sellerBrandCounts($seller),
            ...$this->brandFormMeta(),
        ]);
    }

    public function showBrand(Request $request, int $id): JsonResponse
    {
        $seller = $this->seller($request);
        $row = $seller->brands()->whereKey($id)->firstOrFail();

        return response()->json([
            'brand' => $this->catalog->presentSellerBrandForm($seller, $row),
            ...$this->brandFormMeta(),
        ]);
    }

    public function storeBrand(Request $request): JsonResponse
    {
        $row = $this->catalog->saveBrand(
            $this->seller($request),
            $this->brandData($request),
            null,
            $this->brandFiles($request),
        );

        return response()->json(['row' => $row], 201);
    }

    public function updateBrand(Request $request, int $id): JsonResponse
    {
        $seller = $this->seller($request);
        $row = $seller->brands()->whereKey($id)->firstOrFail();

        return response()->json([
            'row' => $this->catalog->saveBrand(
                $seller,
                $this->brandData($request),
                $row,
                $this->brandFiles($request),
            ),
        ]);
    }

    public function toggleBrand(Request $request, int $id): JsonResponse
    {
        $seller = $this->seller($request);
        $row = $seller->brands()->whereKey($id)->firstOrFail();

        return response()->json(['row' => $this->catalog->toggleBrand($seller, $row)]);
    }

    public function reorderBrands(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);
        $this->catalog->reorderBrands($this->seller($request), $data['ids']);

        return response()->json(['ok' => true]);
    }

    public function bulkBrandStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'enabled' => ['required', 'boolean'],
        ]);
        $updated = $this->catalog->bulkBrandStatus($this->seller($request), $data['ids'], (bool) $data['enabled']);

        return response()->json(['updated' => $updated]);
    }

    public function bulkDestroyBrands(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);
        $deleted = $this->catalog->bulkDeleteBrands($this->seller($request), $data['ids']);

        return response()->json(['deleted' => $deleted]);
    }

    public function destroyBrand(Request $request, int $id): JsonResponse
    {
        $seller = $this->seller($request);
        $this->catalog->deleteBrand($seller, $seller->brands()->whereKey($id)->firstOrFail());

        return response()->json(['ok' => true]);
    }

    public function suppliers(Request $request): JsonResponse
    {
        $seller = $this->seller($request);

        return response()->json([
            'rows' => $this->catalog->sellerSupplierRows($seller),
            'counts' => $this->catalog->sellerSupplierCounts($seller),
            ...$this->supplierFormMeta(),
        ]);
    }

    public function showSupplier(Request $request, int $id): JsonResponse
    {
        $seller = $this->seller($request);
        $row = $seller->suppliers()->whereKey($id)->firstOrFail();

        return response()->json([
            'supplier' => $this->catalog->presentSellerSupplierForm($seller, $row),
            ...$this->supplierFormMeta(),
        ]);
    }

    public function storeSupplier(Request $request): JsonResponse
    {
        $row = $this->catalog->saveSupplier($this->seller($request), $this->supplierData($request));

        return response()->json(['row' => $row], 201);
    }

    public function updateSupplier(Request $request, int $id): JsonResponse
    {
        $seller = $this->seller($request);
        $row = $seller->suppliers()->whereKey($id)->firstOrFail();

        return response()->json([
            'row' => $this->catalog->saveSupplier($seller, $this->supplierData($request), $row),
        ]);
    }

    public function toggleSupplier(Request $request, int $id): JsonResponse
    {
        $seller = $this->seller($request);
        $row = $seller->suppliers()->whereKey($id)->firstOrFail();

        return response()->json(['row' => $this->catalog->toggleSupplier($seller, $row)]);
    }

    public function reorderSuppliers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);
        $this->catalog->reorderSuppliers($this->seller($request), $data['ids']);

        return response()->json(['ok' => true]);
    }

    public function bulkSupplierStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'enabled' => ['required', 'boolean'],
        ]);
        $updated = $this->catalog->bulkSupplierStatus($this->seller($request), $data['ids'], (bool) $data['enabled']);

        return response()->json(['updated' => $updated]);
    }

    public function bulkDestroySuppliers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);
        $deleted = $this->catalog->bulkDeleteSuppliers($this->seller($request), $data['ids']);

        return response()->json(['deleted' => $deleted]);
    }

    public function destroySupplier(Request $request, int $id): JsonResponse
    {
        $seller = $this->seller($request);
        $this->catalog->deleteSupplier($seller, $seller->suppliers()->whereKey($id)->firstOrFail());

        return response()->json(['ok' => true]);
    }

    /**
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    protected function csv(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            CsvSafe::put($out, $headers);
            foreach ($rows as $row) {
                CsvSafe::put($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** @return array<string, mixed> */
    protected function featureFormMeta(): array
    {
        return [
            'languages' => Language::query()
                ->where('is_enabled', true)
                ->orderBy('position')
                ->orderBy('id')
                ->get(['code', 'name', 'is_default']),
            'type_options' => \App\Domain\Catalog\Models\Feature::typeOptions(),
        ];
    }

    /** @return array<string, mixed> */
    protected function featureData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string'],
            'translations' => ['nullable', 'array'],
            'translations.*.language_code' => ['required_with:translations', 'string', Rule::exists('languages', 'code')->where('is_enabled', true)],
            'translations.*.name' => ['nullable', 'string', 'max:160'],
            'translations.*.description' => ['nullable', 'string'],
            'is_enabled' => ['sometimes', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        $data['is_enabled'] = (bool) ($data['is_enabled'] ?? true);
        $data['translations'] = $this->normalizeFeatureTranslations($data);
        $default = Language::defaultCode();
        $defaultRow = $data['translations'][$default] ?? null;
        if ($defaultRow) {
            $data['name'] = $defaultRow['name'];
            $data['description'] = $defaultRow['description'];
        }

        return $data;
    }

    /** @return array<string, array<string, mixed>> */
    protected function normalizeFeatureTranslations(array $data): array
    {
        $default = Language::defaultCode();
        $rows = [];

        foreach ((array) ($data['translations'] ?? []) as $key => $row) {
            if (! is_array($row)) {
                continue;
            }
            $language = (string) ($row['language_code'] ?? $key);
            if ($language === '') {
                continue;
            }
            $rows[$language] = [
                'language_code' => $language,
                'name' => trim((string) ($row['name'] ?? '')),
                'description' => ($row['description'] ?? '') ?: null,
            ];
        }

        if ($rows === []) {
            $rows[$default] = [
                'language_code' => $default,
                'name' => trim((string) ($data['name'] ?? '')),
                'description' => ($data['description'] ?? '') ?: null,
            ];
        }

        if (($rows[$default]['name'] ?? '') === '') {
            throw ValidationException::withMessages([
                "translations.$default.name" => 'Feature name is required for the default language.',
            ]);
        }

        foreach ($rows as $language => $row) {
            $hasContent = collect($row)
                ->except(['language_code'])
                ->contains(fn ($value) => is_string($value) ? trim($value) !== '' : $value !== null);

            if ($language !== $default && $hasContent && $row['name'] === '') {
                throw ValidationException::withMessages([
                    "translations.$language.name" => 'Feature name is required when saving a translation.',
                ]);
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    protected function featureValueFormMeta(): array
    {
        return [
            'languages' => Language::query()
                ->where('is_enabled', true)
                ->orderBy('position')
                ->orderBy('id')
                ->get(['code', 'name', 'is_default']),
        ];
    }

    /** @return array<string, mixed> */
    protected function featureValueData(Request $request): array
    {
        $seller = $this->seller($request);
        $data = $request->validate([
            'feature_id' => [
                'required',
                'integer',
                Rule::exists('seller_features', 'id')->where('seller_id', $seller->id),
            ],
            'value' => ['nullable', 'string', 'max:255'],
            'translations' => ['nullable', 'array'],
            'translations.*.language_code' => ['required_with:translations', 'string', Rule::exists('languages', 'code')->where('is_enabled', true)],
            'translations.*.value' => ['nullable', 'string', 'max:255'],
            'is_enabled' => ['sometimes', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        $data['is_enabled'] = (bool) ($data['is_enabled'] ?? true);
        $data['translations'] = $this->normalizeFeatureValueTranslations($data);
        $default = Language::defaultCode();
        $defaultRow = $data['translations'][$default] ?? null;
        if ($defaultRow) {
            $data['value'] = $defaultRow['value'];
        }

        return $data;
    }

    /** @return array<string, array<string, mixed>> */
    protected function normalizeFeatureValueTranslations(array $data): array
    {
        $default = Language::defaultCode();
        $rows = [];

        foreach ((array) ($data['translations'] ?? []) as $key => $row) {
            if (! is_array($row)) {
                continue;
            }
            $language = (string) ($row['language_code'] ?? $key);
            if ($language === '') {
                continue;
            }
            $rows[$language] = [
                'language_code' => $language,
                'value' => trim((string) ($row['value'] ?? '')),
            ];
        }

        if ($rows === []) {
            $rows[$default] = [
                'language_code' => $default,
                'value' => trim((string) ($data['value'] ?? '')),
            ];
        }

        if (($rows[$default]['value'] ?? '') === '') {
            throw ValidationException::withMessages([
                "translations.$default.value" => 'Feature value is required for the default language.',
            ]);
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    protected function attributeFormMeta(): array
    {
        return [
            'languages' => Language::query()
                ->where('is_enabled', true)
                ->orderBy('position')
                ->orderBy('id')
                ->get(['code', 'name', 'is_default']),
            'type_options' => CatalogAttribute::typeOptions(),
        ];
    }

    /** @return array<string, mixed> */
    protected function attributeData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string'],
            'translations' => ['nullable', 'array'],
            'translations.*.language_code' => ['required_with:translations', 'string', Rule::exists('languages', 'code')->where('is_enabled', true)],
            'translations.*.name' => ['nullable', 'string', 'max:160'],
            'translations.*.description' => ['nullable', 'string'],
            'type' => ['nullable', 'string', Rule::in(array_keys(CatalogAttribute::TYPES))],
            'supports_swatches' => ['sometimes', 'boolean'],
            'is_enabled' => ['sometimes', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        $data['is_enabled'] = (bool) ($data['is_enabled'] ?? true);
        $data['supports_swatches'] = (bool) ($data['supports_swatches'] ?? false);
        $data['translations'] = $this->normalizeAttributeTranslations($data);
        $default = Language::defaultCode();
        $defaultRow = $data['translations'][$default] ?? null;
        if ($defaultRow) {
            $data['name'] = $defaultRow['name'];
            $data['description'] = $defaultRow['description'];
        }

        return $data;
    }

    /** @return array<string, array<string, mixed>> */
    protected function normalizeAttributeTranslations(array $data): array
    {
        $default = Language::defaultCode();
        $rows = [];

        foreach ((array) ($data['translations'] ?? []) as $key => $row) {
            if (! is_array($row)) {
                continue;
            }
            $language = (string) ($row['language_code'] ?? $key);
            if ($language === '') {
                continue;
            }
            $rows[$language] = [
                'language_code' => $language,
                'name' => trim((string) ($row['name'] ?? '')),
                'description' => ($row['description'] ?? '') ?: null,
            ];
        }

        if ($rows === []) {
            $rows[$default] = [
                'language_code' => $default,
                'name' => trim((string) ($data['name'] ?? '')),
                'description' => ($data['description'] ?? '') ?: null,
            ];
        }

        if (($rows[$default]['name'] ?? '') === '') {
            throw ValidationException::withMessages([
                "translations.$default.name" => 'Attribute name is required for the default language.',
            ]);
        }

        foreach ($rows as $language => $row) {
            $hasContent = collect($row)
                ->except(['language_code'])
                ->contains(fn ($value) => is_string($value) ? trim($value) !== '' : $value !== null);

            if ($language !== $default && $hasContent && $row['name'] === '') {
                throw ValidationException::withMessages([
                    "translations.$language.name" => 'Attribute name is required when saving a translation.',
                ]);
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    protected function attributeValueFormMeta(): array
    {
        return [
            'languages' => Language::query()
                ->where('is_enabled', true)
                ->orderBy('position')
                ->orderBy('id')
                ->get(['code', 'name', 'is_default']),
            'swatch_types' => CatalogAttribute::SWATCH_TYPES,
        ];
    }

    /** @return array<string, mixed> */
    protected function attributeValueData(Request $request): array
    {
        $seller = $this->seller($request);
        $data = $request->validate([
            'attribute_id' => [
                'required',
                'integer',
                Rule::exists('seller_attributes', 'id')->where('seller_id', $seller->id),
            ],
            'value' => ['nullable', 'string', 'max:255'],
            'translations' => ['nullable', 'array'],
            'translations.*.language_code' => ['required_with:translations', 'string', Rule::exists('languages', 'code')->where('is_enabled', true)],
            'translations.*.value' => ['nullable', 'string', 'max:255'],
            'swatch_type' => ['nullable', Rule::in(array_keys(CatalogAttribute::SWATCH_TYPES))],
            'color_code' => ['nullable', 'string', 'max:32'],
            'swatch_image' => ['nullable', 'string', 'max:255'],
            'is_enabled' => ['sometimes', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        $data['is_enabled'] = (bool) ($data['is_enabled'] ?? true);
        $data['translations'] = $this->normalizeAttributeValueTranslations($data);
        $default = Language::defaultCode();
        $defaultRow = $data['translations'][$default] ?? null;
        if ($defaultRow) {
            $data['value'] = $defaultRow['value'];
        }

        return $data;
    }

    /** @return array<string, array<string, mixed>> */
    protected function normalizeAttributeValueTranslations(array $data): array
    {
        $default = Language::defaultCode();
        $rows = [];

        foreach ((array) ($data['translations'] ?? []) as $key => $row) {
            if (! is_array($row)) {
                continue;
            }
            $language = (string) ($row['language_code'] ?? $key);
            if ($language === '') {
                continue;
            }
            $rows[$language] = [
                'language_code' => $language,
                'value' => trim((string) ($row['value'] ?? '')),
            ];
        }

        if ($rows === []) {
            $rows[$default] = [
                'language_code' => $default,
                'value' => trim((string) ($data['value'] ?? '')),
            ];
        }

        if (($rows[$default]['value'] ?? '') === '') {
            throw ValidationException::withMessages([
                "translations.$default.value" => 'Attribute value is required for the default language.',
            ]);
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    protected function supplierFormMeta(): array
    {
        return [
            'languages' => Language::query()
                ->where('is_enabled', true)
                ->orderBy('position')
                ->orderBy('id')
                ->get(['code', 'name', 'is_default']),
        ];
    }

    /** @return array<string, mixed> */
    protected function supplierData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'translations' => ['nullable', 'array'],
            'translations.*.language_code' => ['required_with:translations', 'string', Rule::exists('languages', 'code')->where('is_enabled', true)],
            'translations.*.name' => ['nullable', 'string', 'max:255'],
            'translations.*.description' => ['nullable', 'string'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'website' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'postcode' => ['nullable', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'max:120'],
            'is_enabled' => ['sometimes', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        $data['is_enabled'] = (bool) ($data['is_enabled'] ?? true);
        $data['translations'] = $this->normalizeSupplierTranslations($data);
        $default = Language::defaultCode();
        $defaultRow = $data['translations'][$default] ?? null;
        if ($defaultRow) {
            $data['name'] = $defaultRow['name'];
            $data['description'] = $defaultRow['description'];
        }

        return $data;
    }

    /** @return array<string, array<string, mixed>> */
    protected function normalizeSupplierTranslations(array $data): array
    {
        $default = Language::defaultCode();
        $rows = [];

        foreach ((array) ($data['translations'] ?? []) as $key => $row) {
            if (! is_array($row)) {
                continue;
            }
            $language = (string) ($row['language_code'] ?? $key);
            if ($language === '') {
                continue;
            }
            $rows[$language] = [
                'language_code' => $language,
                'name' => trim((string) ($row['name'] ?? '')),
                'description' => ($row['description'] ?? '') ?: null,
            ];
        }

        if ($rows === []) {
            $rows[$default] = [
                'language_code' => $default,
                'name' => trim((string) ($data['name'] ?? '')),
                'description' => ($data['description'] ?? '') ?: null,
            ];
        }

        if (($rows[$default]['name'] ?? '') === '') {
            throw ValidationException::withMessages([
                "translations.$default.name" => 'Supplier name is required for the default language.',
            ]);
        }

        foreach ($rows as $language => $row) {
            $hasContent = collect($row)
                ->except(['language_code'])
                ->contains(fn ($value) => is_string($value) ? trim($value) !== '' : $value !== null);

            if ($language !== $default && $hasContent && $row['name'] === '') {
                throw ValidationException::withMessages([
                    "translations.$language.name" => 'Supplier name is required when saving a translation.',
                ]);
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    protected function brandFormMeta(): array
    {
        return [
            'languages' => Language::query()
                ->where('is_enabled', true)
                ->orderBy('position')
                ->orderBy('id')
                ->get(['code', 'name', 'is_default']),
            'shop_url' => rtrim((string) config('app.url'), '/'),
            'countries' => Country::query()
                ->where('is_enabled', true)
                ->orderBy('name')
                ->get(['code', 'name']),
        ];
    }

    /** @return array<string, mixed> */
    protected function brandData(Request $request): array
    {
        $maxKb = (int) config('wento.upload.max_image_kb', 5120);
        $locale = Language::defaultCode();
        $year = (int) date('Y') + 1;
        $url = ['nullable', 'string', 'max:255', 'url'];

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'short_description' => ['nullable', 'string'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'website' => $url,
            'country_code' => ['nullable', 'string', 'max:8', Rule::exists('countries', 'code')],
            'established_year' => ['nullable', 'integer', 'min:1800', 'max:'.$year],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'facebook_url' => $url,
            'instagram_url' => $url,
            'youtube_url' => $url,
            'twitter_url' => $url,
            'linkedin_url' => $url,
            'tiktok_url' => $url,
            'meta_title' => ['nullable', 'string', 'max:60'],
            'meta_description' => ['nullable', 'string', 'max:160'],
            'meta_keywords' => ['nullable', 'string', 'max:255'],
            'translations' => ['nullable', 'array'],
            'translations.*.language_code' => ['required_with:translations', 'string', Rule::exists('languages', 'code')->where('is_enabled', true)],
            'translations.*.name' => ['nullable', 'string', 'max:255'],
            'translations.*.slug' => ['nullable', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'translations.*.short_description' => ['nullable', 'string'],
            'translations.*.tagline' => ['nullable', 'string', 'max:255'],
            'translations.*.description' => ['nullable', 'string'],
            'translations.*.meta_title' => ['nullable', 'string', 'max:60'],
            'translations.*.meta_description' => ['nullable', 'string', 'max:160'],
            'translations.*.meta_keywords' => ['nullable', 'string', 'max:255'],
            'is_enabled' => ['sometimes', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
            'remove_logo' => ['nullable', 'boolean'],
            'remove_banner' => ['nullable', 'boolean'],
            'gallery_keep' => ['nullable', 'array'],
            'gallery_keep.*' => ['integer'],
            'logo' => ['nullable', 'file', 'max:'.$maxKb],
            'banner' => ['nullable', 'file', 'max:'.$maxKb],
            'gallery' => ['nullable', 'array'],
            'gallery.*' => ['file', 'max:'.$maxKb],
        ]);

        $data['is_enabled'] = (bool) ($data['is_enabled'] ?? true);
        $data['remove_logo'] = (bool) ($data['remove_logo'] ?? false);
        $data['remove_banner'] = (bool) ($data['remove_banner'] ?? false);
        $data['gallery_keep'] = array_values(array_map('intval', $data['gallery_keep'] ?? []));
        $data['translations'] = $this->normalizeBrandTranslations($data);
        $default = $data['translations'][$locale] ?? null;
        if ($default) {
            $data['name'] = $default['name'];
            $data['slug'] = $default['slug'];
            $data['short_description'] = $default['short_description'];
            $data['tagline'] = $default['tagline'];
            $data['description'] = $default['description'];
            $data['meta_title'] = $default['meta_title'];
            $data['meta_description'] = $default['meta_description'];
            $data['meta_keywords'] = $default['meta_keywords'];
        }

        if (($data['established_year'] ?? '') === '') {
            $data['established_year'] = null;
        }

        return $data;
    }

    /** @return array<string, array<string, mixed>> */
    protected function normalizeBrandTranslations(array $data): array
    {
        $default = Language::defaultCode();
        $rows = [];

        foreach ((array) ($data['translations'] ?? []) as $key => $row) {
            if (! is_array($row)) {
                continue;
            }
            $language = (string) ($row['language_code'] ?? $key);
            if ($language === '') {
                continue;
            }
            $rows[$language] = [
                'language_code' => $language,
                'name' => trim((string) ($row['name'] ?? '')),
                'slug' => trim((string) ($row['slug'] ?? '')),
                'short_description' => $row['short_description'] ?? null,
                'tagline' => trim((string) ($row['tagline'] ?? '')),
                'description' => $row['description'] ?? null,
                'meta_title' => trim((string) ($row['meta_title'] ?? '')),
                'meta_description' => trim((string) ($row['meta_description'] ?? '')),
                'meta_keywords' => trim((string) ($row['meta_keywords'] ?? '')),
            ];
        }

        if ($rows === []) {
            $rows[$default] = [
                'language_code' => $default,
                'name' => trim((string) ($data['name'] ?? '')),
                'slug' => trim((string) ($data['slug'] ?? '')),
                'short_description' => $data['short_description'] ?? null,
                'tagline' => trim((string) ($data['tagline'] ?? '')),
                'description' => $data['description'] ?? null,
                'meta_title' => trim((string) ($data['meta_title'] ?? '')),
                'meta_description' => trim((string) ($data['meta_description'] ?? '')),
                'meta_keywords' => trim((string) ($data['meta_keywords'] ?? '')),
            ];
        }

        if (($rows[$default]['name'] ?? '') === '') {
            throw ValidationException::withMessages([
                "translations.$default.name" => 'Brand name is required for the default language.',
            ]);
        }

        foreach ($rows as $language => $row) {
            $hasContent = collect($row)
                ->except(['language_code'])
                ->contains(fn ($value) => is_string($value) ? trim($value) !== '' : $value !== null);

            if ($language !== $default && $hasContent && $row['name'] === '') {
                throw ValidationException::withMessages([
                    "translations.$language.name" => 'Brand name is required when saving a translation.',
                ]);
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    protected function brandFiles(Request $request): array
    {
        return [
            'logo' => $request->file('logo'),
            'banner' => $request->file('banner'),
            'gallery' => array_values($request->file('gallery', []) ?: []),
        ];
    }

    /** @return array<string, mixed> */
    protected function categoryFormMeta(): array
    {
        return [
            'languages' => Language::query()
                ->where('is_enabled', true)
                ->orderBy('position')
                ->orderBy('id')
                ->get(['code', 'name', 'is_default']),
            'shop_url' => rtrim((string) config('app.url'), '/'),
            'customer_groups' => CustomerGroup::catalog(true)
                ->map(fn (CustomerGroup $group) => ['id' => $group->id, 'name' => $group->name])
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    protected function categoryData(Request $request): array
    {
        $seller = $this->seller($request);
        $maxKb = (int) config('wento.upload.max_image_kb', 5120);
        $locale = Language::defaultCode();

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'parent_id' => ['nullable', 'integer', Rule::exists('seller_categories', 'id')->where('seller_id', $seller->id)],
            'description' => ['nullable', 'string'],
            'meta_title' => ['nullable', 'string', 'max:60'],
            'meta_description' => ['nullable', 'string', 'max:160'],
            'meta_keywords' => ['nullable', 'string', 'max:255'],
            'canonical_url' => ['nullable', 'string', 'max:500'],
            'translations' => ['nullable', 'array'],
            'translations.*.language_code' => ['required_with:translations', 'string', Rule::exists('languages', 'code')->where('is_enabled', true)],
            'translations.*.name' => ['nullable', 'string', 'max:255'],
            'translations.*.slug' => ['nullable', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'translations.*.description' => ['nullable', 'string'],
            'translations.*.meta_title' => ['nullable', 'string', 'max:60'],
            'translations.*.meta_description' => ['nullable', 'string', 'max:160'],
            'translations.*.meta_keywords' => ['nullable', 'string', 'max:255'],
            'translations.*.canonical_url' => ['nullable', 'string', 'max:500'],
            'customer_group_ids' => ['nullable', 'array'],
            'customer_group_ids.*' => ['integer', 'exists:customer_groups,id'],
            'is_enabled' => ['sometimes', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
            'remove_image' => ['nullable', 'boolean'],
            'remove_thumbnail' => ['nullable', 'boolean'],
            'image' => RasterImageUpload::rules($maxKb),
            'thumbnail' => RasterImageUpload::rules($maxKb),
        ]);

        $data['is_enabled'] = (bool) ($data['is_enabled'] ?? true);
        $data['parent_id'] = ($data['parent_id'] ?? '') === '' ? null : (int) $data['parent_id'];
        $data['customer_group_ids'] = Category::normalizeCustomerGroupIds($data['customer_group_ids'] ?? null);
        $data['translations'] = $this->normalizeCategoryTranslations($data);
        $default = $data['translations'][$locale] ?? null;
        if ($default) {
            $data['name'] = $default['name'];
            $data['slug'] = $default['slug'];
            $data['description'] = $default['description'];
            $data['meta_title'] = $default['meta_title'];
            $data['meta_description'] = $default['meta_description'];
            $data['meta_keywords'] = $default['meta_keywords'];
            $data['canonical_url'] = $default['canonical_url'];
        }

        return $data;
    }

    /** @return array<string, array<string, mixed>> */
    protected function normalizeCategoryTranslations(array $data): array
    {
        $default = Language::defaultCode();
        $rows = [];

        foreach ((array) ($data['translations'] ?? []) as $key => $row) {
            if (! is_array($row)) {
                continue;
            }
            $language = (string) ($row['language_code'] ?? $key);
            if ($language === '') {
                continue;
            }
            $rows[$language] = [
                'language_code' => $language,
                'name' => trim((string) ($row['name'] ?? '')),
                'slug' => trim((string) ($row['slug'] ?? '')),
                'description' => $row['description'] ?? null,
                'meta_title' => trim((string) ($row['meta_title'] ?? '')),
                'meta_description' => trim((string) ($row['meta_description'] ?? '')),
                'meta_keywords' => trim((string) ($row['meta_keywords'] ?? '')),
                'canonical_url' => trim((string) ($row['canonical_url'] ?? '')),
            ];
        }

        if ($rows === []) {
            $rows[$default] = [
                'language_code' => $default,
                'name' => trim((string) ($data['name'] ?? '')),
                'slug' => trim((string) ($data['slug'] ?? '')),
                'description' => $data['description'] ?? null,
                'meta_title' => trim((string) ($data['meta_title'] ?? '')),
                'meta_description' => trim((string) ($data['meta_description'] ?? '')),
                'meta_keywords' => trim((string) ($data['meta_keywords'] ?? '')),
                'canonical_url' => trim((string) ($data['canonical_url'] ?? '')),
            ];
        }

        if (($rows[$default]['name'] ?? '') === '') {
            throw ValidationException::withMessages([
                "translations.$default.name" => 'Category name is required for the default language.',
            ]);
        }

        foreach ($rows as $language => $row) {
            $hasContent = collect($row)
                ->except(['language_code'])
                ->contains(fn ($value) => is_string($value) ? trim($value) !== '' : $value !== null);

            if ($language !== $default && $hasContent && $row['name'] === '') {
                throw ValidationException::withMessages([
                    "translations.$language.name" => 'Category name is required when saving a translation.',
                ]);
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    protected function categoryFiles(Request $request): array
    {
        $files = [];
        if ($request->hasFile('image')) {
            $files['image'] = $request->file('image');
        }
        if ($request->hasFile('thumbnail')) {
            $files['thumbnail'] = $request->file('thumbnail');
        }

        return $files;
    }

    /** @return array<string, mixed> */
    protected function taxonomyData(Request $request, string $kind): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:160'],
            'is_enabled' => ['sometimes', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
        ];
        if ($kind === 'categories') {
            $rules['parent_id'] = ['nullable', 'integer'];
            $rules['slug'] = ['nullable', 'string', 'max:160'];
        } elseif ($kind === 'features') {
            $rules['type'] = ['nullable', 'in:select,color,text'];
            $rules['is_filterable'] = ['sometimes', 'boolean'];
        } elseif ($kind === 'brands') {
            $rules['slug'] = ['nullable', 'string', 'max:160'];
        } else {
            $rules['slug'] = ['nullable', 'string', 'max:160'];
            $rules['email'] = ['nullable', 'email', 'max:190'];
            $rules['phone'] = ['nullable', 'string', 'max:40'];
            $rules['city'] = ['nullable', 'string', 'max:80'];
            $rules['country'] = ['nullable', 'string', 'max:80'];
        }

        return $request->validate($rules);
    }

    protected function sellerOrderForDocuments(Request $request, int $order): \App\Domain\Marketplace\Models\SellerOrder
    {
        return $this->seller($request)->orders()
            ->with(['items', 'seller', 'order.paymentMethod', 'order.shippingMethod', 'shippingMethod', 'shipments'])
            ->whereKey($order)
            ->firstOrFail();
    }

    protected function seller(Request $request): Seller
    {
        $seller = $request->attributes->get('seller') ?? $request->user('customer')?->seller;
        if (! $seller instanceof Seller) {
            abort(403, 'Seller access is not available.');
        }

        return $seller;
    }

    protected function ownedProduct(Seller $seller, Product $product): void
    {
        if ((int) $product->seller_id !== (int) $seller->id) {
            abort(404);
        }
    }

    /** @return array<string, mixed> */
    protected function productData(Request $request, ?Product $product = null, ?Seller $seller = null): array
    {
        return $this->productCatalog->validate($request, $product, $seller);
    }

    protected function storeProductImages(Request $request, Seller $seller, Product $product): void
    {
        $files = array_values($request->file('images', []) ?: []);
        if ($files === []) {
            return;
        }

        $coverIndex = $request->input('cover_index');
        $this->productCatalog->storeImages(
            $seller,
            $product,
            $files,
            ($coverIndex === null || $coverIndex === '') ? null : (int) $coverIndex,
        );
    }

    /** @return array<string, mixed> */
    protected function productPayload(Seller $seller, Product $product): array
    {
        $copy = $seller->products()->where('product_id', $product->id)->first();

        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $copy?->name,
            'price' => (float) $product->price,
            'stock_qty' => (int) $product->stock_qty,
            'is_enabled' => (bool) $product->is_enabled,
            'approval_status' => $copy?->approval_status,
            'approval_note' => $copy?->approval_note,
        ];
    }

    /** @return array<string, mixed> */
    protected function shippingData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:40'],
            'price' => ['required', 'numeric', 'min:0'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    protected function paymentData(Request $request): array
    {
        return $request->validate([
            'type' => ['required', 'in:bank,paypal,other'],
            'name' => ['required', 'string', 'max:120'],
            'account_name' => ['nullable', 'string', 'max:120'],
            'account_number' => ['nullable', 'string', 'max:80'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'routing_number' => ['nullable', 'string', 'max:80'],
            'paypal_email' => ['nullable', 'email', 'max:190'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_default' => ['nullable', 'boolean'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    protected function discountData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:40'],
            'apply_as' => ['required', 'in:coupon,cart,catalog'],
            'action' => ['required', 'in:percent,amount,set_price'],
            'value' => ['required', 'numeric', 'min:0'],
            'min_cart' => ['nullable', 'numeric', 'min:0'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    protected function normalizeShopTranslations(array $data): array
    {
        $default = Language::defaultCode();
        $rows = [];

        foreach ((array) ($data['translations'] ?? []) as $key => $row) {
            if (! is_array($row)) {
                continue;
            }
            $language = strtolower(trim((string) ($row['language_code'] ?? $key)));
            if ($language === '') {
                continue;
            }
            $rows[$language] = [
                'language_code' => $language,
                'shop_name' => trim((string) ($row['shop_name'] ?? $data['shop_name'] ?? '')),
                'description' => $row['description'] ?? null,
                'store_policy' => $row['store_policy'] ?? null,
                'shipping_policy' => $row['shipping_policy'] ?? null,
                'return_policy' => $row['return_policy'] ?? null,
                'vacation_message' => $row['vacation_message'] ?? null,
            ];
        }

        if ($rows !== [] && ! isset($rows[$default])) {
            $rows[$default] = [
                'language_code' => $default,
                'shop_name' => (string) ($data['shop_name'] ?? ''),
                'description' => $data['description'] ?? null,
                'store_policy' => $data['store_policy'] ?? null,
                'shipping_policy' => $data['shipping_policy'] ?? null,
                'return_policy' => $data['return_policy'] ?? null,
                'vacation_message' => $data['vacation_message'] ?? null,
            ];
        }

        $defaultRow = $rows[$default] ?? null;
        if ($defaultRow) {
            $data['description'] = $defaultRow['description'];
            $data['store_policy'] = $defaultRow['store_policy'];
            $data['shipping_policy'] = $defaultRow['shipping_policy'];
            $data['return_policy'] = $defaultRow['return_policy'];
            $data['vacation_message'] = $defaultRow['vacation_message'];
        }

        $data['translations'] = array_values($rows);

        return $data;
    }

    protected function maskEmail(string $email): string
    {
        if ($email === '' || ! str_contains($email, '@')) {
            return '***';
        }
        [$user, $domain] = explode('@', $email, 2);

        return substr($user, 0, 1).'***@'.$domain;
    }

    protected function maskName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '***';
        }

        return substr($name, 0, 1).str_repeat('*', max(2, mb_strlen($name) - 1));
    }
}
