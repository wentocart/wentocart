<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\ProductObserver;
use App\Domain\Catalog\SaleUnit;
use App\Domain\Catalog\Services\CompositionService;
use App\Domain\Catalog\Services\PreorderService;
use App\Domain\Customer\Models\ProductQuestion;
use App\Domain\Customer\Models\Review;
use App\Domain\Localization\Models\Language;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Ops\StaffActivityObserver;
use App\Domain\Promo\DiscountContext;
use App\Domain\Promo\DiscountEngine;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Store\Concerns\ShopAssigned;
use App\Domain\Store\Models\ProductStore;
use App\Domain\Store\StoreContext;
use App\Domain\Subscription\Models\ProductSubscriptionPlan;
use App\Domain\Tax\Models\TaxClass;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[ObservedBy([ProductObserver::class, StaffActivityObserver::class])]
#[Fillable([
    'type', 'sku', 'seller_id', 'brand_id', 'supplier_id', 'tax_class_id', 'default_category_id',
    'price', 'compare_price', 'sale_price', 'sale_price_type', 'sale_starts_at', 'sale_ends_at', 'cost_price',
    'stock_qty', 'reserved_qty', 'stock_status', 'low_stock_threshold', 'low_stock_alert', 'critical_stock',
    'manage_stock', 'allow_backorder', 'backorder_status', 'notify_low_stock', 'notify_out_of_stock',
    'low_stock_notified_at',
    'min_qty', 'max_qty', 'qty_step', 'sale_unit', 'sale_unit_code', 'weight', 'weight_class',
    'length', 'width', 'height', 'length_class',
    'processing_time', 'processing_time_unit', 'delivery_from', 'delivery_to',
    'additional_shipping_cost', 'available_all_carriers',
    'barcode', 'isbn', 'upc', 'mpn', 'gtin', 'display_condition', 'condition',
    'visibility', 'available_for_order', 'show_price', 'customization_enabled',
    'subscription_enabled', 'purchase_option', 'preorder_enabled',
    'selection_mode', 'bundle_type', 'pricing_mode', 'pricing_discount_type', 'pricing_discount_value',
    'inventory_mode', 'shipping_mode',
    'is_enabled', 'is_new', 'is_on_sale', 'is_best_seller',
    'cover_path', 'video_url',
])]
class Product extends Model
{
    use ShopAssigned;

    protected $appends = ['sale_unit_label'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'sale_starts_at' => 'date',
            'sale_ends_at' => 'date',
            'stock_qty' => 'decimal:3',
            'reserved_qty' => 'decimal:3',
            'min_qty' => 'decimal:3',
            'max_qty' => 'decimal:3',
            'qty_step' => 'decimal:3',
            'weight' => 'decimal:3',
            'length' => 'decimal:3',
            'width' => 'decimal:3',
            'height' => 'decimal:3',
            'additional_shipping_cost' => 'decimal:6',
            'available_all_carriers' => 'boolean',
            'manage_stock' => 'boolean',
            'allow_backorder' => 'boolean',
            'notify_low_stock' => 'boolean',
            'notify_out_of_stock' => 'boolean',
            'low_stock_notified_at' => 'datetime',
            'is_enabled' => 'boolean',
            'is_new' => 'boolean',
            'is_on_sale' => 'boolean',
            'is_best_seller' => 'boolean',
            'display_condition' => 'boolean',
            'available_for_order' => 'boolean',
            'show_price' => 'boolean',
            'customization_enabled' => 'boolean',
            'subscription_enabled' => 'boolean',
            'preorder_enabled' => 'boolean',
            'pricing_discount_value' => 'decimal:2',
        ];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ProductTranslation::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function translation(?string $locale = null): ?ProductTranslation
    {
        return Language::pickTranslation($this->translations, $locale);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_product')->withPivot('is_default');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class, 'product_supplier')
            ->withPivot(['supplier_reference', 'wholesale_price', 'is_default']);
    }

    public function taxClass(): BelongsTo
    {
        return $this->belongsTo(TaxClass::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'product_tag');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    public function attachments(): BelongsToMany
    {
        return $this->belongsToMany(Attachment::class, 'attachment_product')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * The pivot row linking this product to an attachment, or null when not linked.
     */
    public function attachmentLink(int $attachmentId): ?object
    {
        return $this->attachments()->where('attachments.id', $attachmentId)->first()?->pivot;
    }

    /**
     * Public spec sheets / manuals. Paid deliverables are excluded.
     */
    public function documentAttachments(): BelongsToMany
    {
        return $this->attachments()->wherePivot('role', \App\Domain\Catalog\Services\AttachmentService::ROLE_DOCUMENT);
    }

    /**
     * The purchasable deliverable files handed out per order as download tokens.
     */
    public function paidAttachments(): BelongsToMany
    {
        return $this->attachments()->wherePivot('role', \App\Domain\Catalog\Services\AttachmentService::ROLE_PAID);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(ProductQuestion::class);
    }

    public function customizationFields(): HasMany
    {
        return $this->hasMany(ProductCustomizationField::class)->orderBy('position');
    }

    public function productSubscriptionPlans(): HasMany
    {
        return $this->hasMany(ProductSubscriptionPlan::class)->orderBy('position');
    }

    public function featureValues(): BelongsToMany
    {
        return $this->belongsToMany(FeatureValue::class, 'product_feature_values')
            ->withPivot('feature_id');
    }

    public function relationsOfType(string $type): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'product_relations', 'product_id', 'related_product_id')
            ->withPivot('type')
            ->wherePivot('type', $type);
    }

    public function components(): HasMany
    {
        return $this->hasMany(ProductComponent::class, 'parent_product_id')->orderBy('position');
    }

    public function componentOf(): HasMany
    {
        return $this->hasMany(ProductComponent::class, 'child_product_id');
    }

    public function combinationAxes(): HasMany
    {
        return $this->hasMany(ProductCombinationAxis::class)->orderBy('position');
    }

    public function combinationAxisValues(): HasMany
    {
        return $this->hasMany(ProductCombinationAxisValue::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position');
    }

    public function getSaleUnitLabelAttribute(): string
    {
        return SaleUnit::label($this);
    }

    public function isCombination(): bool
    {
        return $this->type === 'combination';
    }

    public function isGiftCard(): bool
    {
        return $this->type === 'gift_card';
    }

    public function isService(): bool
    {
        return $this->type === 'service';
    }

    public function isRental(): bool
    {
        return $this->type === 'rental';
    }

    public function requiresShipping(): bool
    {
        if ($this->isGiftCard() || $this->isService() || $this->type === 'virtual') {
            return false;
        }

        return true;
    }

    public function giftCardConfig(): HasOne
    {
        return $this->hasOne(ProductGiftCard::class);
    }

    public function giftCardAmounts(): HasMany
    {
        return $this->hasMany(ProductGiftCardAmount::class)->orderBy('position');
    }

    public function serviceSetting(): HasOne
    {
        return $this->hasOne(ProductServiceSetting::class, 'product_id');
    }

    public function rentalSetting(): HasOne
    {
        return $this->hasOne(ProductRentalSetting::class, 'product_id');
    }

    public function rentalPricingTiers(): HasMany
    {
        return $this->hasMany(ProductRentalPricingTier::class)->orderBy('position');
    }

    public function rentalAssets(): HasMany
    {
        return $this->hasMany(ProductRentalAsset::class)->orderBy('position');
    }

    public function preorderSetting(): HasOne
    {
        return $this->hasOne(ProductPreorderSetting::class, 'product_id');
    }

    public function preorderNotifySubscriptions(): HasMany
    {
        return $this->hasMany(PreorderNotifySubscription::class);
    }

    public function supportsPreorder(): bool
    {
        return app(PreorderService::class)->supportsProductType($this->type);
    }

    public function hasActivePreorder(): bool
    {
        return app(PreorderService::class)->isActive($this);
    }

    public function isComposite(): bool
    {
        return in_array($this->type, ['group', 'bundle'], true);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    public function scopeVisibleIn(Builder $query, string $context): Builder
    {
        return $query->where(function (Builder $q) use ($context) {
            $q->where('visibility', 'both')->orWhere('visibility', $context);
        });
    }

    /** Columns needed for storefront listing cards (avoids select * on catalog rails). */
    public function scopeSelectListing(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->select([
            "{$table}.id",
            "{$table}.type",
            "{$table}.sku",
            "{$table}.seller_id",
            "{$table}.brand_id",
            "{$table}.price",
            "{$table}.compare_price",
            "{$table}.sale_price",
            "{$table}.sale_price_type",
            "{$table}.sale_starts_at",
            "{$table}.sale_ends_at",
            "{$table}.stock_qty",
            "{$table}.stock_status",
            "{$table}.manage_stock",
            "{$table}.allow_backorder",
            "{$table}.available_for_order",
            "{$table}.show_price",
            "{$table}.visibility",
            "{$table}.is_enabled",
            "{$table}.is_new",
            "{$table}.is_on_sale",
            "{$table}.is_best_seller",
            "{$table}.cover_path",
            "{$table}.min_qty",
            "{$table}.max_qty",
            "{$table}.qty_step",
            "{$table}.sale_unit",
            "{$table}.sale_unit_code",
            "{$table}.pricing_mode",
            "{$table}.pricing_discount_type",
            "{$table}.pricing_discount_value",
            "{$table}.created_at",
            "{$table}.updated_at",
        ]);
    }

    public function saleAdjustedPrice(): float
    {
        if ($this->isComposite() && $this->pricing_mode && $this->pricing_mode !== 'fixed') {
            return app(CompositionService::class)->sellingPrice($this);
        }

        return $this->catalogSellingPrice();
    }

    /** Whether the product sale schedule is currently open (ignores sale_enabled / sale_price). */
    public function saleWindowOpen(): bool
    {
        $today = now()->startOfDay();
        if ($this->sale_starts_at && $today->lt($this->sale_starts_at->startOfDay())) {
            return false;
        }
        if ($this->sale_ends_at && $today->gt($this->sale_ends_at->startOfDay())) {
            return false;
        }

        return true;
    }

    /** Sale pricing setting on, price set, and schedule open. */
    public function saleIsLive(): bool
    {
        if (! \App\Domain\Settings\PricingSettings::salePricing()) {
            return false;
        }
        if ($this->sale_price === null || $this->sale_price === '') {
            return false;
        }

        return $this->saleWindowOpen();
    }

    public function catalogSellingPrice(): float
    {
        $regular = max(0, (float) $this->price);
        if (! $this->saleIsLive()) {
            return $regular;
        }

        if ($this->sale_price_type === 'percent') {
            $percent = max(0, min(100, (float) $this->sale_price));

            return max(0, \App\Domain\Settings\PricingSettings::roundMoney($regular * (1 - ($percent / 100))));
        }

        // Fixed sale cannot exceed the regular price (invalid / inverted "discount").
        return max(0, min($regular, \App\Domain\Settings\PricingSettings::roundMoney((float) $this->sale_price)));
    }

    public function sellingPrice(?DiscountContext $context = null): float
    {
        return app(DiscountEngine::class)->catalogUnitPrice($this, $context);
    }

    public function shippingMethods(): BelongsToMany
    {
        return $this->belongsToMany(ShippingMethod::class, 'product_shipping_method');
    }

    public function warehouseStocks(): HasMany
    {
        return $this->hasMany(WarehouseStock::class);
    }

    public function qtyPrices(): HasMany
    {
        return $this->hasMany(ProductQtyPrice::class)->orderByDesc('min_qty')->orderBy('position');
    }

    public function stockAlerts(): HasMany
    {
        return $this->hasMany(StockAlert::class);
    }

    public function unitPriceForQty(float $qty): ?float
    {
        $qty = max(0, $qty);
        $this->loadMissing('qtyPrices');
        $row = $this->qtyPrices->first(fn (ProductQtyPrice $price) => $price->matches($qty));

        return $row ? (float) $row->price : null;
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class)->latest();
    }

    public function storeAssignments(): HasMany
    {
        return $this->hasMany(ProductStore::class);
    }

    public function constrainToCurrentShop(Builder $query): Builder
    {
        return app(StoreContext::class)->constrainProducts($query);
    }

    public function attachToCurrentShop(): void
    {
        foreach (app(StoreContext::class)->associationIds() as $storeId) {
            if ($storeId < 1) {
                continue;
            }
            ProductStore::query()->firstOrCreate(
                ['product_id' => $this->id, 'store_id' => $storeId],
                ['is_visible' => true],
            );
        }
    }

    public function availableStock(): float
    {
        if ($this->isComposite() && ($this->inventory_mode ?: 'derived') === 'derived') {
            return (float) app(CompositionService::class)->availableQty($this);
        }

        return max(0, (float) $this->stock_qty - (float) ($this->reserved_qty ?? 0));
    }

    public function inStockFor(float $qty): bool
    {
        if ($this->isComposite() && ($this->inventory_mode ?: 'derived') === 'derived') {
            return $this->allow_backorder || $this->availableStock() >= $qty;
        }

        if (! $this->manage_stock) {
            return true;
        }

        if ($this->allow_backorder) {
            return true;
        }

        return $this->availableStock() >= $qty;
    }
}
