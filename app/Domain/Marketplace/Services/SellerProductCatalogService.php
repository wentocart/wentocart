<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Services\AttributeService;
use App\Domain\Catalog\Services\CombinationService;
use App\Domain\Catalog\Services\CompositionService;
use App\Domain\Catalog\Services\GiftCardService;
use App\Domain\Catalog\Services\PreorderService;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Catalog\Services\RentalProductService;
use App\Domain\Catalog\Services\ServiceProductService;
use App\Domain\Localization\Models\Language;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Tax\Models\TaxClass;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Seller-side twin of the admin ProductController form contract.
 *
 * Sellers reach the same catalog capabilities (types + fields) through ProductService,
 * but ownership, moderation and availability stay with the platform: seller_id is forced,
 * approval state is never taken from the payload, and supplier / warehouse / merchandising
 * flags are stripped before validation.
 */
class SellerProductCatalogService
{
    /** Keys a seller may never send — moderation and the platform own these. */
    public const FORBIDDEN_KEYS = [
        'seller_id',
        'product_id',
        'approval_status',
        'approval_note',
        'force_approved',
        'available_for_order',
        'is_new',
        'is_best_seller',
        'supplier_id',
        'supplier_ids',
        'warehouse_stocks',
        'commission',
        'commission_rate',
    ];

    public const CONDITIONS = ['new', 'new_open_box', 'new_minor_defect', 'used', 'refurbished', 'damaged'];

    public const WEIGHT_CLASSES = ['kg', 'g', 'lb'];

    public const LENGTH_CLASSES = ['cm', 'inch', 'mm'];

    public function __construct(
        protected ProductService $products,
        protected AttributeService $attributes,
        protected CombinationService $combinations,
        protected CompositionService $composition,
        protected GiftCardService $giftCards,
        protected ServiceProductService $serviceProducts,
        protected RentalProductService $rentalProducts,
        protected PreorderService $preorders,
    ) {}

    /** Same list the admin product form offers. @return list<string> */
    public function selectableTypes(): array
    {
        return ['simple', 'combination', 'group', 'virtual', 'bundle', 'gift_card', 'service', 'rental'];
    }

    /**
     * Everything the seller product form needs to render, scoped to what a seller may pick.
     *
     * @return array<string, mixed>
     */
    public function options(Seller $seller): array
    {
        $locale = Language::defaultCode();

        return [
            'categories' => Category::query()
                ->with('translations')
                ->where('is_enabled', true)
                ->orderBy('position')
                ->orderBy('id')
                ->get()
                ->map(fn (Category $row) => [
                    'id' => $row->id,
                    'parent_id' => $row->parent_id,
                    'position' => (int) $row->position,
                    'is_enabled' => (bool) $row->is_enabled,
                    'name' => $row->translation($locale)?->name ?? 'Category #'.$row->id,
                ])
                ->values()
                ->all(),
            'brands' => Brand::query()
                ->with('translations')
                ->where('is_enabled', true)
                ->orderBy('position')
                ->orderBy('id')
                ->get()
                ->map(fn (Brand $row) => [
                    'id' => $row->id,
                    'name' => $row->translation($locale)?->name ?? 'Brand #'.$row->id,
                ])
                ->values()
                ->all(),
            'tax_classes' => TaxClass::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (TaxClass $row) => ['id' => $row->id, 'name' => $row->name])
                ->values()
                ->all(),
            'languages' => Language::query()
                ->where('is_enabled', true)
                ->orderBy('position')
                ->orderBy('id')
                ->get(['code', 'name', 'is_default'])
                ->map(fn (Language $row) => [
                    'code' => $row->code,
                    'name' => $row->name,
                    'is_default' => (bool) $row->is_default,
                ])
                ->values()
                ->all(),
            'combination_attributes' => $this->attributes->combinationAttributeCatalog($seller->id),
            'features' => Feature::query()
                ->with([
                    'translations',
                    'values' => fn ($query) => $query->where('is_enabled', true)->orderBy('position')->orderBy('id'),
                    'values.translations',
                ])
                ->where('is_enabled', true)
                ->orderBy('position')
                ->orderBy('id')
                ->get()
                ->map(fn (Feature $feature) => [
                    'id' => $feature->id,
                    'name' => $feature->translation($locale)?->name ?? $feature->code,
                    'values' => $feature->values
                        ->map(fn ($value) => [
                            'id' => $value->id,
                            'value' => $value->translation($locale)?->value ?? $value->code,
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
            'shipping_methods' => ShippingMethod::query()
                ->where('is_enabled', true)
                ->orderBy('position')
                ->orderBy('name')
                ->get(['id', 'name', 'price'])
                ->map(fn (ShippingMethod $row) => [
                    'id' => $row->id,
                    'name' => $row->name,
                    'price' => (float) $row->price,
                ])
                ->values()
                ->all(),
            'weight_classes' => self::WEIGHT_CLASSES,
            'length_classes' => self::LENGTH_CLASSES,
            'visibility' => array_values((array) config('wento.visibility')),
            'conditions' => self::CONDITIONS,
            'sale_price_types' => ['fixed', 'percent'],
            'stock_statuses' => ['in_stock', 'out_of_stock', 'on_backorder'],
            'backorder_statuses' => ['allow', 'deny', 'hide'],
            'product_types' => [
                ['value' => 'simple', 'label' => 'Standard'],
                ['value' => 'combination', 'label' => 'Combinations'],
                ['value' => 'group', 'label' => 'Grouped'],
                ['value' => 'virtual', 'label' => 'Virtual'],
                ['value' => 'bundle', 'label' => 'Bundle'],
                ['value' => 'gift_card', 'label' => 'Gift Card'],
                ['value' => 'service', 'label' => 'Service'],
                ['value' => 'rental', 'label' => 'Rental'],
            ],
            'own_products' => Product::query()
                ->with('translations')
                ->where('seller_id', $seller->id)
                ->orderBy('id')
                ->get()
                ->map(fn (Product $row) => [
                    'id' => $row->id,
                    'sku' => $row->sku,
                    'type' => $row->type,
                    'name' => $row->translation($locale)?->name ?? $row->sku,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Validate a legacy simple payload or the full catalog payload.
     *
     * @return array<string, mixed>
     */
    public function validate(Request $request, ?Product $product = null, ?Seller $seller = null): array
    {
        $seller ??= $this->requestSeller($request);
        $this->stripForbidden($request);

        $data = $request->validate($this->rules());

        // Laravel drops nested arrays that only have partially declared children.
        foreach ([
            'gift_card', 'gift_card_amounts', 'service', 'rental', 'rental_pricing_tiers',
            'rental_assets', 'preorder', 'preorder_enabled',
        ] as $key) {
            if ($request->has($key)) {
                $data[$key] = $request->input($key);
            }
        }

        if (! empty($data['brand_id'])) {
            $brand = Brand::query()->find($data['brand_id']);
            if ($brand && ! $brand->is_enabled && (int) $brand->id !== (int) ($product?->brand_id)) {
                throw ValidationException::withMessages([
                    'brand_id' => 'This brand is disabled and cannot be assigned to products.',
                ]);
            }
        }

        $default = Language::defaultCode();
        $rootName = trim((string) ($data['name'] ?? ''));
        $localeName = '';
        foreach ((array) ($data['translations'] ?? []) as $key => $row) {
            if (! is_array($row)) {
                continue;
            }
            if ((string) ($row['language_code'] ?? $key) === $default) {
                $localeName = trim((string) ($row['name'] ?? ''));
            }
        }
        if ($rootName === '' && $localeName === '') {
            throw ValidationException::withMessages([
                'name' => 'Product name is required.',
            ]);
        }

        foreach (['related_ids', 'upsell_ids', 'crosssell_ids'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = $this->ownedProductIds($seller, (array) $data[$key], $key);
            }
        }

        foreach ((array) ($data['components'] ?? []) as $index => $row) {
            $this->ownedProductIds($seller, [$row['id'] ?? 0], "components.$index.id");
        }

        return $data;
    }

    /**
     * Shape a validated payload for ProductService::save.
     *
     * Ownership and availability are decided here, not by the caller's payload.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function buildSavePayload(
        Seller $seller,
        array $validated,
        ?Product $product,
        string $approvalStatus,
        bool $enabled,
    ): array {
        foreach (self::FORBIDDEN_KEYS as $key) {
            unset($validated[$key]);
        }

        $locale = Language::defaultCode();
        $translations = $this->normalizeTranslations($seller, $validated);
        $defaultTranslation = $translations[$locale] ?? reset($translations) ?: null;
        $name = (string) ($defaultTranslation['name'] ?? '');

        // Type is chosen once, on create. An edit never migrates an existing product.
        $type = $product?->exists ? (string) $product->type : (string) ($validated['type'] ?? 'simple');
        if (! in_array($type, $this->selectableTypes(), true)) {
            $type = 'simple';
        }

        $approved = $approvalStatus === SellerProduct::STATUS_APPROVED;
        [$categoryIds, $defaultCategoryId] = $this->categorySelection($validated, $product);

        $backorderStatus = \App\Domain\Settings\ProductSettings::normalizeStockBehavior(
            $validated['backorder_status'] ?? (($validated['allow_backorder'] ?? false) ? 'allow' : null),
            \App\Domain\Settings\ProductSettings::defaultBackorderStatus()
        );
        $stockQty = (int) $this->pick($validated, 'stock_qty', $product?->stock_qty ?? 0);
        $salePrice = $this->pick($validated, 'sale_price', $product?->sale_price);
        $manageStock = array_key_exists('manage_stock', $validated)
            ? (bool) $validated['manage_stock']
            : (ProductType::tryFrom($type)?->defaultManageStock() ?? true);

        $out = [
            'product' => [
                'type' => $type,
                'sku' => $this->sku($seller, $validated, $product),
                'seller_id' => $seller->id,
                'price' => $this->pick($validated, 'price', $product?->price ?? 0),
                'compare_price' => $this->pick($validated, 'compare_price', $product?->compare_price),
                'sale_price' => $salePrice,
                'sale_price_type' => $this->pick($validated, 'sale_price_type', $product?->sale_price_type ?? 'fixed'),
                'sale_starts_at' => $this->pick($validated, 'sale_starts_at', $product?->sale_starts_at),
                'sale_ends_at' => $this->pick($validated, 'sale_ends_at', $product?->sale_ends_at),
                'cost_price' => $this->pick($validated, 'cost_price', $product?->cost_price),
                'stock_qty' => $stockQty,
                'low_stock_alert' => $this->pick($validated, 'low_stock_alert', $product?->low_stock_alert ?? 10),
                'critical_stock' => $this->pick($validated, 'critical_stock', $product?->critical_stock ?? 3),
                'backorder_status' => $backorderStatus,
                'allow_backorder' => $backorderStatus === 'allow',
                'notify_low_stock' => (bool) $this->pick($validated, 'notify_low_stock', $product?->notify_low_stock ?? true),
                'notify_out_of_stock' => (bool) $this->pick($validated, 'notify_out_of_stock', $product?->notify_out_of_stock ?? true),
                'manage_stock' => $manageStock,
                'stock_status' => $this->pick($validated, 'stock_status', $stockQty > 0 ? 'in_stock' : 'out_of_stock'),
                'low_stock_threshold' => $this->pick($validated, 'low_stock_threshold', $product?->low_stock_threshold ?? 5),
                'min_qty' => $this->pick($validated, 'min_qty', $product?->min_qty ?? 1),
                'max_qty' => $this->pick($validated, 'max_qty', $product?->max_qty),
                'qty_step' => $this->pick($validated, 'qty_step', $product?->qty_step ?? 1),
                'visibility' => $this->pick($validated, 'visibility', $product?->visibility ?? 'both'),
                'show_price' => (bool) $this->pick($validated, 'show_price', $product?->show_price ?? true),
                'brand_id' => $this->pick($validated, 'brand_id', $product?->brand_id),
                'tax_class_id' => $this->pick($validated, 'tax_class_id', $product?->tax_class_id),
                'default_category_id' => $defaultCategoryId,
                'weight' => $this->pick($validated, 'weight', $product?->weight),
                'weight_class' => $this->pick($validated, 'weight_class', $product?->weight_class ?? 'kg'),
                'length' => $this->pick($validated, 'length', $product?->length),
                'width' => $this->pick($validated, 'width', $product?->width),
                'height' => $this->pick($validated, 'height', $product?->height),
                'length_class' => $this->pick($validated, 'length_class', $product?->length_class ?? 'cm'),
                'processing_time' => $this->pick($validated, 'processing_time', $product?->processing_time),
                'processing_time_unit' => $this->pick($validated, 'processing_time_unit', $product?->processing_time_unit ?? 'days'),
                'delivery_from' => $this->pick($validated, 'delivery_from', $product?->delivery_from),
                'delivery_to' => $this->pick($validated, 'delivery_to', $product?->delivery_to),
                'additional_shipping_cost' => $this->pick($validated, 'additional_shipping_cost', $product?->additional_shipping_cost ?? 0) ?? 0,
                'barcode' => $this->pick($validated, 'barcode', $product?->barcode),
                'isbn' => $this->pick($validated, 'isbn', $product?->isbn),
                'upc' => $this->pick($validated, 'upc', $product?->upc),
                'mpn' => $this->pick($validated, 'mpn', $product?->mpn),
                'gtin' => $this->pick($validated, 'gtin', $product?->gtin),
                'display_condition' => (bool) $this->pick($validated, 'display_condition', $product?->display_condition ?? false),
                'is_on_sale' => $salePrice !== null && $salePrice !== '',
                // Moderation owns listing state; a seller cannot publish or unlist by payload.
                'is_enabled' => $enabled,
                'available_for_order' => $approved && ! $seller->vacation_enabled,
            ],
            'translation' => $defaultTranslation,
            'translations' => $translations,
            'category_ids' => $categoryIds,
        ];

        $out['product']['condition'] = $out['product']['display_condition']
            ? $this->pick($validated, 'condition', $product?->condition)
            : null;

        if (array_key_exists('feature_value_ids', $validated)) {
            $out['feature_value_ids'] = $validated['feature_value_ids'] ?? [];
        }

        if (
            array_key_exists('related_ids', $validated)
            || array_key_exists('upsell_ids', $validated)
            || array_key_exists('crosssell_ids', $validated)
        ) {
            $out['relations'] = [
                'related' => array_values(array_map('intval', $validated['related_ids'] ?? [])),
                'upsell' => array_values(array_map('intval', $validated['upsell_ids'] ?? [])),
                'crosssell' => array_values(array_map('intval', $validated['crosssell_ids'] ?? [])),
            ];
        }

        if (array_key_exists('available_all_carriers', $validated) || array_key_exists('shipping_method_ids', $validated)) {
            $all = (bool) ($validated['available_all_carriers'] ?? true);
            $out['product']['available_all_carriers'] = $all;
            $out['available_all_carriers'] = $all;
            $out['shipping_method_ids'] = $validated['shipping_method_ids'] ?? [];
        }

        $this->applyTypeDefaults($out, $type, $validated, $product);

        if (array_key_exists('preorder_enabled', $validated) || array_key_exists('preorder', $validated)) {
            $out['preorder_enabled'] = (bool) ($validated['preorder_enabled'] ?? false);
            $out['preorder'] = $validated['preorder'] ?? [];
            $out['product']['preorder_enabled'] = $out['preorder_enabled'];
        }

        $subscriptionEnabled = (bool) ($out['product']['subscription_enabled'] ?? $product?->subscription_enabled ?? false);
        $preorderEnabled = array_key_exists('preorder_enabled', $out)
            ? (bool) $out['preorder_enabled']
            : (bool) ($product?->preorder_enabled ?? false);
        \App\Domain\Catalog\Support\ProductModeConflict::assertExclusive($subscriptionEnabled, $preorderEnabled);

        if ($name === '' && ! $product?->exists) {
            throw ValidationException::withMessages(['name' => 'Product name is required.']);
        }

        return $out;
    }

    /**
     * Full edit-form JSON for one owned listing.
     *
     * @return array<string, mixed>
     */
    public function present(Seller $seller, Product $product, ?SellerProduct $copy = null): array
    {
        $this->assertOwned($seller, $product);

        $product->loadMissing([
            'translations', 'categories', 'images', 'featureValues', 'shippingMethods',
        ]);
        $locale = Language::defaultCode();
        $translation = $product->translation($locale);
        $copy ??= SellerProduct::query()
            ->where('seller_id', $seller->id)
            ->where('product_id', $product->id)
            ->first();

        $defaultCategoryId = $product->default_category_id
            ?? $product->categories->firstWhere('pivot.is_default', true)?->id;

        $payload = [
            'id' => $product->id,
            'type' => $product->type,
            'sku' => $product->sku,
            'name' => $translation?->name ?? $copy?->name,
            'slug' => $translation?->slug ?? $copy?->slug,
            'description' => $translation?->description ?? $copy?->description,
            'short_description' => $translation?->short_description ?? $copy?->short_description,
            'price' => (float) $product->price,
            'compare_price' => $product->compare_price !== null ? (float) $product->compare_price : null,
            'sale_price' => $product->sale_price !== null ? (float) $product->sale_price : null,
            'sale_price_type' => $product->sale_price_type,
            'sale_starts_at' => $product->sale_starts_at?->toDateString(),
            'sale_ends_at' => $product->sale_ends_at?->toDateString(),
            'cost_price' => $product->cost_price !== null ? (float) $product->cost_price : null,
            'stock_qty' => (int) $product->stock_qty,
            'reserved_qty' => (int) ($product->reserved_qty ?? 0),
            'manage_stock' => (bool) $product->manage_stock,
            'stock_status' => $product->stock_status,
            'backorder_status' => $product->backorder_status,
            'allow_backorder' => (bool) $product->allow_backorder,
            'low_stock_threshold' => $product->low_stock_threshold,
            'low_stock_alert' => $product->low_stock_alert,
            'critical_stock' => $product->critical_stock,
            'notify_low_stock' => (bool) $product->notify_low_stock,
            'notify_out_of_stock' => (bool) $product->notify_out_of_stock,
            'min_qty' => $product->min_qty,
            'max_qty' => $product->max_qty,
            'qty_step' => $product->qty_step,
            'brand_id' => $product->brand_id,
            'tax_class_id' => $product->tax_class_id,
            'visibility' => $product->visibility,
            'show_price' => (bool) $product->show_price,
            'weight' => $product->weight,
            'weight_class' => $product->weight_class,
            'length' => $product->length,
            'width' => $product->width,
            'height' => $product->height,
            'length_class' => $product->length_class,
            'processing_time' => $product->processing_time,
            'processing_time_unit' => $product->processing_time_unit,
            'delivery_from' => $product->delivery_from,
            'delivery_to' => $product->delivery_to,
            'additional_shipping_cost' => (float) $product->additional_shipping_cost,
            'available_all_carriers' => (bool) $product->available_all_carriers,
            'shipping_method_ids' => $product->shippingMethods->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'barcode' => $product->barcode,
            'isbn' => $product->isbn,
            'upc' => $product->upc,
            'mpn' => $product->mpn,
            'gtin' => $product->gtin,
            'display_condition' => (bool) $product->display_condition,
            'condition' => $product->condition,
            'selection_mode' => $product->selection_mode,
            'bundle_type' => $product->bundle_type,
            'pricing_mode' => $product->pricing_mode,
            'pricing_discount_type' => $product->pricing_discount_type,
            'pricing_discount_value' => $product->pricing_discount_value,
            'inventory_mode' => $product->inventory_mode,
            'shipping_mode' => $product->shipping_mode,
            'preorder_enabled' => (bool) $product->preorder_enabled,
            // Platform-controlled, read-only for the seller.
            'is_enabled' => (bool) $product->is_enabled,
            'available_for_order' => (bool) $product->available_for_order,
            'category_id' => $defaultCategoryId,
            'default_category_id' => $defaultCategoryId,
            'category_ids' => $product->categories->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'feature_value_ids' => $product->featureValues
                ->map(fn ($value) => [
                    'feature_id' => (int) $value->feature_id,
                    'feature_value_id' => (int) $value->id,
                ])
                ->values()
                ->all(),
            'related_ids' => $product->relationsOfType('related')->pluck('products.id')->map(fn ($id) => (int) $id)->values()->all(),
            'translations' => $product->translations
                ->keyBy('language_code')
                ->map(fn ($row) => [
                    'language_code' => $row->language_code,
                    'name' => $row->name,
                    'slug' => $row->slug,
                    'description' => $row->description,
                    'short_description' => $row->short_description,
                    'meta_title' => $row->meta_title,
                    'meta_description' => $row->meta_description,
                    'meta_keywords' => $row->meta_keywords,
                    'focus_keyword' => $row->focus_keyword,
                    'canonical_url' => $row->canonical_url,
                    'robots' => $row->robots,
                ])
                ->all(),
            'images' => $product->images
                ->map(fn (ProductImage $image) => [
                    'id' => $image->id,
                    'path' => $image->path,
                    'url' => '/storage/'.$image->path,
                    'alt' => $image->alt,
                    'position' => (int) $image->position,
                    'is_cover' => $product->cover_path === $image->path,
                ])
                ->values()
                ->all(),
            'approval_status' => $copy?->approval_status,
            'approval_note' => $copy?->approval_note,
            'reviewed_at' => $copy?->reviewed_at?->toIso8601String(),
        ];

        if ($product->isCombination()) {
            $payload['combination_axes'] = $this->combinations->presentConfig($product);
            $payload['combination_variants'] = $this->combinations->presentVariants($product);
        }

        if ($product->isComposite()) {
            $payload['components'] = $this->composition->present($product);
        }

        $payload['gift_card'] = $product->isGiftCard() ? $this->giftCards->presentConfig($product) : null;
        $payload['service'] = $product->isService() ? $this->serviceProducts->presentConfig($product) : null;
        $payload['rental'] = $product->isRental() ? $this->rentalProducts->presentConfig($product) : null;
        $payload['preorder'] = $this->preorders->presentConfig($product);

        return $payload;
    }

    /**
     * @param  array<int, mixed>  $files
     */
    public function storeImages(Seller $seller, Product $product, array $files, ?int $coverIndex = null): void
    {
        $this->assertOwned($seller, $product);

        foreach (array_values($files) as $index => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }
            // storeImage promotes the first image when the product has no cover yet.
            $this->products->storeImage($product, $file, null, $coverIndex !== null && $index === $coverIndex);
        }
    }

    /**
     * @param  array<int, mixed>  $ids
     */
    public function reorderImages(Seller $seller, Product $product, array $ids): void
    {
        $this->assertOwned($seller, $product);
        $this->products->reorderImages($product, $ids);
    }

    public function setCoverImage(Seller $seller, Product $product, ProductImage $image): void
    {
        $this->assertOwned($seller, $product);
        $this->assertOwnedImage($product, $image);
        $product->update(['cover_path' => $image->path]);
    }

    public function deleteImage(Seller $seller, Product $product, ProductImage $image): void
    {
        $this->assertOwned($seller, $product);
        $this->assertOwnedImage($product, $image);

        $wasCover = $product->cover_path === $image->path;
        \App\Domain\Settings\ObjectStorageSettings::disk()->delete($image->path);
        $image->delete();

        if ($wasCover) {
            $next = $product->images()->orderBy('position')->orderBy('id')->first();
            $product->update(['cover_path' => $next?->path]);
        }
    }

    public function assertOwned(Seller $seller, Product $product): void
    {
        if ((int) $product->seller_id !== (int) $seller->id) {
            abort(404);
        }
    }

    protected function assertOwnedImage(Product $product, ProductImage $image): void
    {
        if ((int) $image->product_id !== (int) $product->id) {
            abort(404);
        }
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'type' => ['nullable', Rule::in($this->selectableTypes())],
            'sku' => ['nullable', 'string', 'max:64'],
            'price' => ['required', 'numeric', 'min:0'],
            'compare_price' => ['nullable', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'sale_price_type' => ['nullable', 'in:fixed,percent'],
            'sale_starts_at' => ['nullable', 'date'],
            'sale_ends_at' => ['nullable', 'date', 'after_or_equal:sale_starts_at'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'stock_qty' => ['nullable', 'integer', 'min:0'],
            'low_stock_alert' => ['nullable', 'integer', 'min:0'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'critical_stock' => ['nullable', 'integer', 'min:0'],
            'backorder_status' => ['nullable', 'in:allow,deny,hide,allow_notify'],
            'allow_backorder' => ['nullable', 'boolean'],
            'manage_stock' => ['nullable', 'boolean'],
            'stock_status' => ['nullable', 'in:in_stock,out_of_stock,on_backorder'],
            'notify_low_stock' => ['nullable', 'boolean'],
            'notify_out_of_stock' => ['nullable', 'boolean'],
            'min_qty' => ['nullable', 'integer', 'min:1'],
            'max_qty' => ['nullable', 'integer', 'min:1'],
            'qty_step' => ['nullable', 'integer', 'min:1'],
            'name' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'description' => ['nullable', 'string'],
            'short_description' => ['nullable', 'string'],
            'meta_title' => ['nullable', 'string', 'max:60'],
            'meta_description' => ['nullable', 'string', 'max:160'],
            'meta_keywords' => ['nullable', 'string'],
            'focus_keyword' => ['nullable', 'string', 'max:255'],
            'canonical_url' => ['nullable', 'string', 'max:500'],
            'robots' => ['nullable', Rule::in(['index,follow', 'index,nofollow', 'noindex,follow', 'noindex,nofollow'])],
            'translations' => ['nullable', 'array'],
            'translations.*.language_code' => ['required_with:translations', 'string', Rule::exists('languages', 'code')->where('is_enabled', true)],
            'translations.*.name' => ['nullable', 'string', 'max:255'],
            'translations.*.slug' => ['nullable', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'translations.*.description' => ['nullable', 'string'],
            'translations.*.short_description' => ['nullable', 'string'],
            'translations.*.meta_title' => ['nullable', 'string', 'max:60'],
            'translations.*.meta_description' => ['nullable', 'string', 'max:160'],
            'translations.*.meta_keywords' => ['nullable', 'string'],
            'translations.*.focus_keyword' => ['nullable', 'string', 'max:255'],
            'translations.*.canonical_url' => ['nullable', 'string', 'max:500'],
            'translations.*.robots' => ['nullable', Rule::in(['index,follow', 'index,nofollow', 'noindex,follow', 'noindex,nofollow'])],
            'visibility' => ['nullable', Rule::in(config('wento.visibility'))],
            'show_price' => ['nullable', 'boolean'],
            'is_enabled' => ['nullable', 'boolean'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'tax_class_id' => ['nullable', 'integer', 'exists:tax_classes,id'],
            // Categories are optional for sellers: moderation can place the listing later.
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'default_category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'weight_class' => ['nullable', Rule::in(self::WEIGHT_CLASSES)],
            'length' => ['nullable', 'numeric', 'min:0'],
            'width' => ['nullable', 'numeric', 'min:0'],
            'height' => ['nullable', 'numeric', 'min:0'],
            'length_class' => ['nullable', Rule::in(self::LENGTH_CLASSES)],
            'processing_time' => ['nullable', 'integer', 'min:0'],
            'processing_time_unit' => ['nullable', 'in:days,weeks'],
            'delivery_from' => ['nullable', 'integer', 'min:0'],
            'delivery_to' => ['nullable', 'integer', 'min:0'],
            'additional_shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'available_all_carriers' => ['nullable', 'boolean'],
            'shipping_method_ids' => ['nullable', 'array'],
            'shipping_method_ids.*' => ['integer', 'exists:shipping_methods,id'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'isbn' => ['nullable', 'string', 'max:64'],
            'upc' => ['nullable', 'string', 'max:64'],
            'mpn' => ['nullable', 'string', 'max:64'],
            'gtin' => ['nullable', 'string', 'max:64'],
            'display_condition' => ['nullable', 'boolean'],
            'condition' => ['nullable', Rule::in(self::CONDITIONS)],
            'feature_value_ids' => ['nullable', 'array'],
            'feature_value_ids.*.feature_id' => ['required', 'integer', 'exists:features,id'],
            'feature_value_ids.*.feature_value_id' => ['required', 'integer', 'exists:feature_values,id'],
            'related_ids' => ['nullable', 'array'],
            'related_ids.*' => ['integer'],
            'upsell_ids' => ['nullable', 'array'],
            'upsell_ids.*' => ['integer'],
            'crosssell_ids' => ['nullable', 'array'],
            'crosssell_ids.*' => ['integer'],
            'selection_mode' => ['nullable', 'in:all,customer'],
            'bundle_type' => ['nullable', 'in:fixed,configurable'],
            'pricing_mode' => ['nullable', 'in:sum,sum_discount,sum_adjustment,fixed'],
            'pricing_discount_type' => ['nullable', 'in:fixed,percent'],
            'pricing_discount_value' => ['nullable', 'numeric'],
            'inventory_mode' => ['nullable', 'in:derived,manual'],
            'shipping_mode' => ['nullable', 'in:derived,custom'],
            'components' => ['nullable', 'array'],
            'components.*.id' => ['required_with:components', 'integer', 'exists:products,id'],
            'components.*.quantity' => ['required_with:components', 'integer', 'min:1'],
            'components.*.min_quantity' => ['nullable', 'integer', 'min:1'],
            'components.*.max_quantity' => ['nullable', 'integer', 'min:1'],
            'components.*.qty_editable' => ['nullable', 'boolean'],
            'components.*.is_required' => ['nullable', 'boolean'],
            'components.*.default_selected' => ['nullable', 'boolean'],
            'components.*.option_group' => ['nullable', 'string', 'max:120'],
            'combination_axes' => ['nullable', 'array'],
            'combination_axes.*.attribute_id' => ['required_with:combination_axes', 'integer', 'exists:attributes,id'],
            'combination_axes.*.value_ids' => ['nullable', 'array'],
            'combination_axes.*.value_ids.*' => ['integer', 'exists:attribute_values,id'],
            'combination_variants' => ['nullable', 'array'],
            'combination_variants.*.id' => ['nullable', 'integer'],
            'combination_variants.*.attribute_value_ids' => ['nullable', 'array'],
            'combination_variants.*.attribute_value_ids.*' => ['integer', 'exists:attribute_values,id'],
            'combination_variants.*.sku' => ['nullable', 'string', 'max:64'],
            'combination_variants.*.barcode' => ['nullable', 'string', 'max:64'],
            'combination_variants.*.price' => ['nullable', 'numeric', 'min:0'],
            'combination_variants.*.sale_price' => ['nullable', 'numeric', 'min:0'],
            'combination_variants.*.sale_price_type' => ['nullable', 'in:fixed,percent'],
            'combination_variants.*.cost_price' => ['nullable', 'numeric', 'min:0'],
            'combination_variants.*.stock_qty' => ['nullable', 'numeric', 'min:0'],
            'combination_variants.*.manage_stock' => ['nullable', 'boolean'],
            'combination_variants.*.stock_status' => ['nullable', 'in:in_stock,out_of_stock,on_backorder'],
            'combination_variants.*.allow_backorder' => ['nullable', 'boolean'],
            'combination_variants.*.weight' => ['nullable', 'numeric', 'min:0'],
            'combination_variants.*.length' => ['nullable', 'numeric', 'min:0'],
            'combination_variants.*.width' => ['nullable', 'numeric', 'min:0'],
            'combination_variants.*.height' => ['nullable', 'numeric', 'min:0'],
            'combination_variants.*.cover_image_id' => ['nullable', 'integer', 'exists:product_images,id'],
            'combination_variants.*.is_enabled' => ['nullable', 'boolean'],
            'combination_variants.*.is_default' => ['nullable', 'boolean'],
            'combination_variants.*.position' => ['nullable', 'integer', 'min:0'],
            'remove_missing_combinations' => ['nullable', 'boolean'],
            'deleted_combination_variant_ids' => ['nullable', 'array'],
            'deleted_combination_variant_ids.*' => ['integer'],
            'gift_card' => ['nullable', 'array'],
            'gift_card.amount_type' => ['nullable', 'in:fixed,custom'],
            'gift_card.min_amount' => ['nullable', 'numeric', 'min:0'],
            'gift_card.max_amount' => ['nullable', 'numeric', 'min:0'],
            'gift_card.default_amount' => ['nullable', 'numeric', 'min:0'],
            'gift_card.amount_step' => ['nullable', 'numeric', 'min:0'],
            'gift_card.delivery_method' => ['nullable', 'in:email,account,both'],
            'gift_card.validity_type' => ['nullable', 'in:never,days,fixed_date'],
            'gift_card.validity_days' => ['nullable', 'integer', 'min:1'],
            'gift_card.expiry_date' => ['nullable', 'date'],
            'gift_card.usage_type' => ['nullable', 'in:single,multiple'],
            'gift_card.code_length' => ['nullable', 'integer', 'min:6', 'max:32'],
            'gift_card.code_prefix' => ['nullable', 'string', 'max:32'],
            'gift_card.code_charset' => ['nullable', 'in:alphanumeric,numeric,alpha'],
            'gift_card_amounts' => ['nullable', 'array'],
            'gift_card_amounts.*.id' => ['nullable', 'integer'],
            'gift_card_amounts.*.value' => ['nullable', 'numeric', 'min:0'],
            'gift_card_amounts.*.selling_price' => ['nullable', 'numeric', 'min:0'],
            'gift_card_amounts.*.is_enabled' => ['nullable', 'boolean'],
            'gift_card_amounts.*.position' => ['nullable', 'integer', 'min:0'],
            'service' => ['nullable', 'array'],
            'service.service_type' => ['nullable', 'in:one_time,recurring,hourly,appointment'],
            'service.duration' => ['nullable', 'numeric', 'min:0'],
            'service.duration_unit' => ['nullable', 'in:minutes,hours,days'],
            'service.pricing_model' => ['nullable', 'in:fixed,per_hour,per_day,starting_from'],
            'service.delivery_method' => ['nullable', 'in:online,onsite,both'],
            'service.travel_fee' => ['nullable', 'numeric', 'min:0'],
            'rental' => ['nullable', 'array'],
            'rental.rental_unit' => ['nullable', 'in:hour,day,week,month'],
            'rental.min_duration' => ['nullable', 'integer', 'min:1'],
            'rental.max_duration' => ['nullable', 'integer', 'min:1'],
            'rental.default_duration' => ['nullable', 'integer', 'min:1'],
            'rental.pricing_model' => ['nullable', 'in:per_hour,per_day,per_week,per_month'],
            'rental.security_deposit_type' => ['nullable', 'in:none,fixed,percent'],
            'rental.inventory_mode' => ['nullable', 'in:quantity,asset'],
            'rental_pricing_tiers' => ['nullable', 'array'],
            'rental_pricing_tiers.*.id' => ['nullable', 'integer'],
            'rental_pricing_tiers.*.min_duration' => ['nullable', 'integer', 'min:1'],
            'rental_pricing_tiers.*.max_duration' => ['nullable', 'integer', 'min:1'],
            'rental_pricing_tiers.*.unit' => ['nullable', 'in:hour,day,week,month'],
            'rental_pricing_tiers.*.price' => ['nullable', 'numeric', 'min:0'],
            'rental_assets' => ['nullable', 'array'],
            'rental_assets.*.id' => ['nullable', 'integer'],
            'rental_assets.*.asset_id' => ['nullable', 'string', 'max:64'],
            'preorder_enabled' => ['nullable', 'boolean'],
            'preorder' => ['nullable', 'array'],
            'preorder.start_at' => ['nullable', 'date'],
            'preorder.end_at' => ['nullable', 'date'],
            'preorder.release_at' => ['nullable', 'date'],
            'preorder.fulfillment_at' => ['nullable', 'date'],
            'preorder.expected_delivery_at' => ['nullable', 'date'],
            'preorder.payment_type' => ['nullable', 'in:full,deposit'],
            'preorder.deposit_type' => ['nullable', 'in:fixed,percent'],
            'preorder.deposit_value' => ['nullable', 'numeric', 'min:0'],
            'preorder.min_quantity' => ['nullable', 'integer', 'min:1'],
            'preorder.max_quantity' => ['nullable', 'integer', 'min:1'],
            'preorder.total_limit' => ['nullable', 'integer', 'min:1'],
            'preorder.message' => ['nullable', 'string'],
            'preorder.show_badge' => ['nullable', 'boolean'],
            'preorder.show_release_date' => ['nullable', 'boolean'],
            'preorder.show_delivery_date' => ['nullable', 'boolean'],
            'preorder.show_countdown_pdp' => ['nullable', 'boolean'],
            'preorder.show_countdown_listing' => ['nullable', 'boolean'],
            'preorder.show_image_label' => ['nullable', 'boolean'],
            'preorder.fulfillment_rule' => ['nullable', 'in:auto,manual'],
            'preorder.preorder_price' => ['nullable', 'numeric', 'min:0'],
            'preorder.preorder_price_valid_until' => ['nullable', 'date'],
            'images' => ['nullable', 'array'],
            'images.*' => ['file', 'image'],
            'cover_index' => ['nullable', 'integer', 'min:0'],
            'image_order' => ['nullable', 'array'],
            'image_order.*' => ['integer'],
        ];
    }

    /**
     * @param  array<string, mixed>  $out
     * @param  array<string, mixed>  $validated
     */
    protected function applyTypeDefaults(array &$out, string $type, array $validated, ?Product $product): void
    {
        if (in_array($type, ['group', 'bundle'], true)) {
            $out['product']['selection_mode'] = $this->pick($validated, 'selection_mode', $product?->selection_mode)
                ?: ($type === 'group' ? 'all' : 'customer');
            $out['product']['bundle_type'] = $this->pick($validated, 'bundle_type', $product?->bundle_type)
                ?: ($type === 'bundle' ? 'fixed' : null);
            $out['product']['pricing_mode'] = $this->pick($validated, 'pricing_mode', $product?->pricing_mode)
                ?: ($type === 'bundle' ? 'fixed' : 'sum');
            $out['product']['pricing_discount_type'] = $this->pick($validated, 'pricing_discount_type', $product?->pricing_discount_type);
            $out['product']['pricing_discount_value'] = $this->pick($validated, 'pricing_discount_value', $product?->pricing_discount_value);
            $out['product']['inventory_mode'] = $this->pick($validated, 'inventory_mode', $product?->inventory_mode) ?: 'derived';
            $out['product']['shipping_mode'] = $this->pick($validated, 'shipping_mode', $product?->shipping_mode) ?: 'derived';

            if ($out['product']['inventory_mode'] === 'derived') {
                $out['product']['manage_stock'] = false;
            }
            if (
                $out['product']['pricing_discount_type'] === 'percent'
                && (float) ($out['product']['pricing_discount_value'] ?? 0) > 100
            ) {
                throw ValidationException::withMessages([
                    'pricing_discount_value' => 'Percentage discount cannot exceed 100%.',
                ]);
            }
            if (array_key_exists('components', $validated)) {
                $out['components'] = $validated['components'] ?? [];
            }
        }

        if ($type === 'combination') {
            $out['product']['manage_stock'] = false;
            // Only touch the axis config when the request actually carried one.
            if (array_key_exists('combination_axes', $validated)) {
                $out['combination_axes'] = $validated['combination_axes'] ?? [];
                $out['combination_variants'] = $validated['combination_variants'] ?? [];
                $out['remove_missing_combinations'] = (bool) ($validated['remove_missing_combinations'] ?? false);
                $out['deleted_combination_variant_ids'] = $validated['deleted_combination_variant_ids'] ?? [];
            }
        }

        if ($type === 'gift_card' && array_key_exists('gift_card', $validated)) {
            $out['gift_card'] = $validated['gift_card'] ?? [];
            $out['gift_card_amounts'] = $validated['gift_card_amounts'] ?? [];
        }

        if ($type === 'service' && array_key_exists('service', $validated)) {
            $out['service'] = $validated['service'] ?? [];
        }

        if ($type === 'rental' && array_key_exists('rental', $validated)) {
            $out['rental'] = $validated['rental'] ?? [];
            $out['rental_pricing_tiers'] = $validated['rental_pricing_tiers'] ?? [];
            $out['rental_assets'] = $validated['rental_assets'] ?? [];
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: list<int>, 1: int|null}
     */
    protected function categorySelection(array $validated, ?Product $product): array
    {
        $ids = [];
        if (array_key_exists('category_ids', $validated)) {
            $ids = (array) ($validated['category_ids'] ?? []);
        } elseif (array_key_exists('category_id', $validated)) {
            $ids = $validated['category_id'] !== null && $validated['category_id'] !== ''
                ? [$validated['category_id']]
                : [];
        } elseif ($product?->exists) {
            $ids = $product->categories()->pluck('categories.id')->all();
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        $default = (int) ($validated['default_category_id'] ?? $validated['category_id'] ?? 0);
        if ($default < 1 || ! in_array($default, $ids, true)) {
            $default = $ids[0] ?? 0;
        }

        return [$ids, $default > 0 ? $default : null];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, array<string, mixed>>
     */
    protected function normalizeTranslations(Seller $seller, array $validated): array
    {
        $default = Language::defaultCode();
        $rows = [];

        foreach ((array) ($validated['translations'] ?? []) as $key => $row) {
            if (! is_array($row)) {
                continue;
            }
            $code = (string) ($row['language_code'] ?? $key);
            if ($code === '') {
                continue;
            }
            $rows[$code] = $this->translationRow($seller, $code, $row);
        }

        if (! isset($rows[$default])) {
            $root = $this->translationRow($seller, $default, $validated);
            if ($root['name'] !== '' || $rows === []) {
                $rows[$default] = $root;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function translationRow(Seller $seller, string $code, array $row): array
    {
        $name = trim((string) ($row['name'] ?? ''));
        $slug = trim((string) ($row['slug'] ?? ''));

        return [
            'language_code' => $code,
            'name' => $name,
            'slug' => (string) ($this->shopSlug($seller, $slug !== '' ? $slug : $name) ?? ''),
            'description' => $row['description'] ?? null,
            'short_description' => $row['short_description'] ?? null,
            'meta_title' => trim((string) ($row['meta_title'] ?? '')),
            'meta_description' => trim((string) ($row['meta_description'] ?? '')),
            'meta_keywords' => trim((string) ($row['meta_keywords'] ?? '')),
            'focus_keyword' => trim((string) ($row['focus_keyword'] ?? '')),
            'canonical_url' => trim((string) ($row['canonical_url'] ?? '')),
            'robots' => $row['robots'] ?? 'index,follow',
        ];
    }

    /** Seller listings live under the shop namespace so slugs never collide with platform products. */
    protected function shopSlug(Seller $seller, string $value): ?string
    {
        $slug = Str::slug($value);
        if ($slug === '') {
            return null;
        }

        $prefix = Str::slug((string) $seller->shop_slug);
        if ($prefix === '' || $slug === $prefix || Str::startsWith($slug, $prefix.'-')) {
            return $slug;
        }

        return $prefix.'-'.$slug;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function sku(Seller $seller, array $validated, ?Product $product): string
    {
        $sku = trim((string) ($validated['sku'] ?? ''));
        if ($sku === '') {
            return $product?->sku ?: Str::upper($seller->shop_slug.'-'.Str::lower(Str::random(6)));
        }
        if ($product?->exists) {
            return Str::upper($sku);
        }

        return Str::upper($seller->shop_slug.'-'.$sku);
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return list<int>
     */
    protected function ownedProductIds(Seller $seller, array $ids, string $key): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }

        $owned = Product::query()
            ->whereIn('id', $ids)
            ->where('seller_id', $seller->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (array_diff($ids, $owned) !== []) {
            throw ValidationException::withMessages([
                $key => 'Choose products from your own catalog.',
            ]);
        }

        return $ids;
    }

    protected function stripForbidden(Request $request): void
    {
        foreach (self::FORBIDDEN_KEYS as $key) {
            $request->request->remove($key);
            $request->query->remove($key);
            if ($request->isJson()) {
                $request->json()->remove($key);
            }
        }
    }

    protected function requestSeller(Request $request): Seller
    {
        $seller = $request->attributes->get('seller') ?? $request->user('customer')?->seller;
        if (! $seller instanceof Seller) {
            abort(403, 'Seller access is not available.');
        }

        return $seller;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function pick(array $data, string $key, mixed $fallback): mixed
    {
        if (! array_key_exists($key, $data)) {
            return $fallback;
        }

        return $data[$key] === '' ? null : $data[$key];
    }
}
