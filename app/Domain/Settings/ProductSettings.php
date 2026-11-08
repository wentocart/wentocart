<?php

namespace App\Domain\Settings;

use App\Domain\Settings\Models\Setting;

final class ProductSettings
{
    public const DEFAULT_COLOR = '#ffece5';

    public const DEFAULT_DURATION_MS = 1100;

    public const DEFAULT_ADMIN_PER_PAGE = 10;

    public const DEFAULT_STOREFRONT_PER_PAGE = 24;

    public const DEFAULT_LOW_STOCK_ALERT = 5;

    public const DEFAULT_COMPARE_MAX = 4;

    /**
     * Always shown in FO compare headers (not configurable in BO).
     *
     * @var array<string, string>
     */
    public const COMPARE_FIXED_FIELDS = [
        'name' => 'Product Name',
        'image' => 'Product Image',
    ];

    /**
     * Configurable storefront compare columns (key => admin label). Order is display order.
     *
     * @var array<string, string>
     */
    public const COMPARE_DISPLAY_FIELDS = [
        'price' => 'Price',
        'brand' => 'Brand',
        'size' => 'Size',
        'color' => 'Color',
        'condition' => 'Condition',
        'availability' => 'Availability / Stock Status',
        'shipping' => 'Shipping Availability',
        'type' => 'Product Type',
        'subscription' => 'Subscription Availability',
        'preorder' => 'Pre-order Availability',
        'weight' => 'Weight',
        'description' => 'Product Description',
    ];

    /** @var list<string> */
    public const DEFAULT_COMPARE_FIELDS_ENABLED = [
        'price',
        'brand',
        'availability',
    ];

    public const DEFAULT_MIN_QTY = 1;

    public const DEFAULT_QTY_STEP = 1;

    public const DEFAULT_TYPE = 'simple';

    public const DEFAULT_SKU_PREFIX = 'WENTO-';

    /** @var list<string> */
    public const STATUS_OPTIONS = ['draft', 'active'];

    /** @var list<string> */
    public const SKU_MODE_OPTIONS = ['auto', 'manual'];

    /** @var list<string> */
    public const BARCODE_MODE_OPTIONS = ['off', 'optional', 'required'];

    /** @var list<string> Unified shop default + product out-of-stock / backorder policy. */
    public const OOS_OPTIONS = ['allow', 'deny', 'hide'];

    /** @var list<string> Accepted product backorder_status values (legacy allow_notify maps to allow). */
    public const BACKORDER_STATUS_OPTIONS = ['allow', 'deny', 'hide'];

    /** @var list<string> */
    public const DEFAULT_TYPE_OPTIONS = ['simple', 'combination', 'group', 'virtual', 'bundle', 'gift_card', 'service', 'rental'];

    // ── Product defaults ─────────────────────────────────────────────

    public static function defaultStatus(): string
    {
        $raw = trim((string) ShopSetting::get('products.default_status', 'active'));

        return in_array($raw, self::STATUS_OPTIONS, true) ? $raw : 'active';
    }

    public static function defaultIsEnabled(): bool
    {
        return self::defaultStatus() === 'active';
    }

    public static function defaultType(): string
    {
        $raw = trim((string) ShopSetting::get('products.default_type', self::DEFAULT_TYPE));

        return in_array($raw, self::DEFAULT_TYPE_OPTIONS, true) ? $raw : self::DEFAULT_TYPE;
    }

    public static function skuMode(): string
    {
        $raw = trim((string) ShopSetting::get('products.sku_mode', 'auto'));

        return in_array($raw, self::SKU_MODE_OPTIONS, true) ? $raw : 'auto';
    }

    public static function skuPrefix(): string
    {
        $raw = strtoupper(trim((string) ShopSetting::get('products.sku_prefix', self::DEFAULT_SKU_PREFIX)));
        $raw = preg_replace('/[^A-Z0-9\-_]/', '', $raw) ?? '';

        return $raw !== '' ? $raw : self::DEFAULT_SKU_PREFIX;
    }

    public static function barcodeMode(): string
    {
        $raw = trim((string) ShopSetting::get('products.barcode_mode', 'optional'));

        return in_array($raw, self::BARCODE_MODE_OPTIONS, true) ? $raw : 'optional';
    }

    // ── Inventory defaults ───────────────────────────────────────────

    public static function defaultManageStock(): bool
    {
        return ShopSetting::bool('products.default_manage_stock', true);
    }

    public static function defaultAllowBackorder(): bool
    {
        return self::outOfStockBehavior() === 'allow';
    }

    public static function defaultBackorderStatus(): string
    {
        return self::outOfStockBehavior();
    }

    public static function defaultLowStockAlert(): int
    {
        return self::clampInt(ShopSetting::get('products.default_low_stock_alert', self::DEFAULT_LOW_STOCK_ALERT), 0, 100000, self::DEFAULT_LOW_STOCK_ALERT);
    }

    /**
     * Shop-wide out-of-stock policy: allow | deny | hide.
     * Legacy values keep_visible / disable_purchase are normalized on read.
     */
    public static function outOfStockBehavior(): string
    {
        $raw = trim((string) ShopSetting::get('products.out_of_stock_behavior', ''));
        if ($raw === '' && ShopSetting::bool('products.catalog.hide_out_of_stock', false)) {
            return 'hide';
        }

        return self::normalizeStockBehavior($raw, ShopSetting::bool('products.default_allow_backorder', false) ? 'allow' : 'deny');
    }

    /**
     * Normalize shop or product stock policy to allow | deny | hide.
     */
    public static function normalizeStockBehavior(?string $raw, string $fallback = 'deny'): string
    {
        $raw = trim((string) $raw);
        if (in_array($raw, self::OOS_OPTIONS, true)) {
            return $raw;
        }

        return match ($raw) {
            'allow_notify' => 'allow',
            'keep_visible' => $fallback === 'allow' ? 'allow' : 'deny',
            'disable_purchase' => 'deny',
            default => in_array($fallback, self::OOS_OPTIONS, true) ? $fallback : 'deny',
        };
    }

    /**
     * Effective policy for a product (explicit backorder_status, else shop default).
     */
    public static function productStockBehavior(object $product): string
    {
        $raw = $product->backorder_status ?? null;
        if ($raw === null || $raw === '') {
            if (array_key_exists('allow_backorder', (array) $product) || isset($product->allow_backorder)) {
                return ($product->allow_backorder ?? false) ? 'allow' : self::outOfStockBehavior();
            }

            return self::outOfStockBehavior();
        }

        return self::normalizeStockBehavior((string) $raw, self::outOfStockBehavior());
    }

    public static function hideOutOfStock(): bool
    {
        return self::outOfStockBehavior() === 'hide';
    }

    /** @deprecated Prefer productStockBehavior() / productIsOutOfStock(); kept for callers expecting a shop-wide flag. */
    public static function disablePurchaseWhenOutOfStock(): bool
    {
        return self::outOfStockBehavior() !== 'allow';
    }

    // ── Quantity defaults ────────────────────────────────────────────

    public static function defaultMinQty(): int
    {
        return self::clampInt(ShopSetting::get('products.default_min_qty', self::DEFAULT_MIN_QTY), 1, 100000, self::DEFAULT_MIN_QTY);
    }

    public static function defaultQtyStep(): int
    {
        return self::clampInt(ShopSetting::get('products.default_qty_step', self::DEFAULT_QTY_STEP), 1, 100000, self::DEFAULT_QTY_STEP);
    }

    public static function defaultMaxQty(): ?int
    {
        $raw = ShopSetting::get('products.default_max_qty', null);
        if ($raw === null || $raw === '' || (int) $raw < 1) {
            return null;
        }

        return self::clampInt($raw, 1, 100000, 1);
    }

    /**
     * Storefront order quantity. An empty product field uses the shop default.
     *
     * @return array{min: float, step: float, max: float|null}
     */
    public static function quantityRules(object $product): array
    {
        $min = self::positiveQty($product->min_qty ?? null) ?? (float) self::defaultMinQty();
        $step = self::positiveQty($product->qty_step ?? null) ?? (float) self::defaultQtyStep();
        if ($step <= 0) {
            $step = 1.0;
        }
        if ($min <= 0) {
            $min = $step;
        }
        $max = self::positiveQty($product->max_qty ?? null);
        if ($max === null) {
            $shopMax = self::defaultMaxQty();
            $max = $shopMax !== null ? (float) $shopMax : null;
        }
        if ($max !== null && $max < $min) {
            $max = $min;
        }

        return [
            'min' => $min,
            'step' => $step,
            'max' => $max,
        ];
    }

    public static function positiveQty(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $number = (float) $value;

        return $number > 0 ? $number : null;
    }

    public static function shopWeightUnit(): string
    {
        return (string) Setting::getValue('unit_weight', 'kg');
    }

    public static function shopDimensionUnit(): string
    {
        return (string) Setting::getValue('unit_dimension', 'cm');
    }

    // ── Product features ─────────────────────────────────────────────

    public static function compareEnabled(): bool
    {
        return ShopSetting::bool('products.compare_enabled', true);
    }

    public static function compareMax(): int
    {
        return self::clampInt(ShopSetting::get('products.catalog.compare_max', self::DEFAULT_COMPARE_MAX), 2, 4, self::DEFAULT_COMPARE_MAX);
    }

    /**
     * Predefined compare display fields with enable flags (BO checklist).
     *
     * @return list<array{key: string, label: string, enabled: bool}>
     */
    public static function compareFields(): array
    {
        return self::normalizeCompareFields(ShopSetting::json('products.compare_fields', []));
    }

    /**
     * @return list<array{key: string, label: string, enabled: bool}>
     */
    public static function defaultCompareFields(): array
    {
        $enabled = array_fill_keys(self::DEFAULT_COMPARE_FIELDS_ENABLED, true);
        $fields = [];
        foreach (self::COMPARE_DISPLAY_FIELDS as $key => $label) {
            $fields[] = [
                'key' => $key,
                'label' => $label,
                'enabled' => isset($enabled[$key]),
            ];
        }

        return $fields;
    }

    /**
     * @return list<string>
     */
    public static function compareFieldKeys(): array
    {
        return array_keys(self::COMPARE_DISPLAY_FIELDS);
    }

    /**
     * @param  mixed  $raw
     * @return list<array{key: string, label: string, enabled: bool}>
     */
    public static function normalizeCompareFields(mixed $raw): array
    {
        $defaults = self::defaultCompareFields();
        if (! is_array($raw) || $raw === []) {
            return $defaults;
        }

        // Legacy feature-dropdown rows ({enabled, feature_id}) → reset to product-field defaults.
        $first = $raw[0] ?? null;
        if (is_array($first) && array_key_exists('feature_id', $first) && ! array_key_exists('key', $first)) {
            return $defaults;
        }

        $byKey = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = (string) ($row['key'] ?? '');
            if ($key === '' || ! array_key_exists($key, self::COMPARE_DISPLAY_FIELDS)) {
                continue;
            }
            $byKey[$key] = (bool) ($row['enabled'] ?? false);
        }

        $out = [];
        foreach (self::COMPARE_DISPLAY_FIELDS as $key => $label) {
            $defaultEnabled = false;
            foreach ($defaults as $default) {
                if ($default['key'] === $key) {
                    $defaultEnabled = $default['enabled'];
                    break;
                }
            }
            $out[] = [
                'key' => $key,
                'label' => $label,
                'enabled' => array_key_exists($key, $byKey) ? $byKey[$key] : $defaultEnabled,
            ];
        }

        return $out;
    }

    /**
     * Fixed header fields + enabled configurable fields in BO order (for FO).
     *
     * @return list<array{key: string, label: string}>
     */
    public static function compareFieldsForStorefront(): array
    {
        $rows = [];
        foreach (self::COMPARE_FIXED_FIELDS as $key => $label) {
            $rows[] = [
                'key' => $key,
                'label' => $label,
            ];
        }
        foreach (self::compareFields() as $field) {
            if (! $field['enabled']) {
                continue;
            }
            if (array_key_exists($field['key'], self::COMPARE_FIXED_FIELDS)) {
                continue;
            }
            $rows[] = [
                'key' => $field['key'],
                'label' => $field['label'],
            ];
        }

        return $rows;
    }

    public static function requireCoverToPublish(): bool
    {
        return ShopSetting::bool('products.require_cover_to_publish', false);
    }

    public static function requireBrandToPublish(): bool
    {
        return ShopSetting::bool('products.require_brand_to_publish', false);
    }

    public static function defaultAvailableForOrder(): bool
    {
        return ShopSetting::bool('products.default_available_for_order', true);
    }

    public static function outOfStockNotification(): bool
    {
        return ShopSetting::bool('products.out_of_stock_notification', false);
    }

    // ── Legacy / still used elsewhere ────────────────────────────────

    public static function highlightEnabled(): bool
    {
        return ShopSetting::bool('products.list.row_highlight_enabled', true);
    }

    public static function color(): string
    {
        $raw = trim((string) ShopSetting::get('products.list.row_highlight_color', self::DEFAULT_COLOR));
        if (! preg_match('/^#[0-9A-Fa-f]{6}$/', $raw)) {
            return self::DEFAULT_COLOR;
        }

        return strtolower($raw);
    }

    public static function durationMs(): int
    {
        return self::clampInt(ShopSetting::get('products.list.row_highlight_ms', self::DEFAULT_DURATION_MS), 100, 5000, self::DEFAULT_DURATION_MS);
    }

    public static function catalogMode(): bool
    {
        return ShopSetting::bool('products.catalog_mode', false);
    }

    public static function showPrice(): bool
    {
        return ShopSetting::bool('products.catalog.show_price', true);
    }

    public static function hidePricesGuests(): bool
    {
        return ShopSetting::bool('products.hide_prices_guests', false);
    }

    public static function adminPerPage(): int
    {
        return self::clampInt(ShopSetting::get('products.list.admin_per_page', self::DEFAULT_ADMIN_PER_PAGE), 5, 100, self::DEFAULT_ADMIN_PER_PAGE);
    }

    public static function storefrontPerPage(): int
    {
        return self::clampInt(ShopSetting::get('products.list.storefront_per_page', self::DEFAULT_STOREFRONT_PER_PAGE), 8, 48, self::DEFAULT_STOREFRONT_PER_PAGE);
    }

    public static function shortDescriptionMax(): int
    {
        $raw = ShopSetting::get('products.content.short_description_max', null);
        if ($raw === null) {
            $raw = ShopSetting::get('products.catalog.short_description_max', 400);
        }

        return self::clampInt($raw, 50, 5000, 400);
    }

    public static function descriptionMax(): int
    {
        $raw = ShopSetting::get('products.content.description_max', null);
        if ($raw === null) {
            $raw = ShopSetting::get('products.catalog.description_max', 5000);
        }

        return self::clampInt($raw, 100, 50000, 5000);
    }

    public static function metaTitleMax(): int
    {
        return self::clampInt(ShopSetting::get('products.content.meta_title_max', 70), 30, 255, 70);
    }

    public static function metaDescriptionMax(): int
    {
        return self::clampInt(ShopSetting::get('products.content.meta_description_max', 160), 50, 500, 160);
    }

    public static function requireSku(): bool
    {
        // Manual SKU mode always requires a merchant-entered SKU.
        if (self::skuMode() === 'manual') {
            return true;
        }

        return ShopSetting::bool('products.require_sku', false);
    }

    public static function requireMetaTitleToPublish(): bool
    {
        return ShopSetting::bool('products.require_meta_title_to_publish', false);
    }

    public static function defaultVisibility(): string
    {
        $raw = trim((string) ShopSetting::get('products.default_visibility', 'both'));

        return in_array($raw, ['both', 'catalog', 'search', 'hidden'], true) ? $raw : 'both';
    }

    public static function defaultShowPrice(): bool
    {
        return ShopSetting::bool('products.default_show_price', true);
    }

    public static function defaultCriticalStock(): int
    {
        return self::clampInt(ShopSetting::get('products.default_critical_stock', 3), 0, 100000, 3);
    }

    public static function defaultNotifyLowStock(): bool
    {
        return ShopSetting::bool('products.default_notify_low_stock', true);
    }

    public static function defaultNotifyOutOfStock(): bool
    {
        return ShopSetting::bool('products.default_notify_out_of_stock', true);
    }

    public static function defaultTaxClassId(): ?int
    {
        $raw = ShopSetting::get('products.default_tax_class_id', null);
        if ($raw === null || $raw === '' || (int) $raw < 1) {
            return null;
        }

        return (int) $raw;
    }

    public static function newProductDays(): int
    {
        return self::clampInt(ShopSetting::get('products.new_product_days', 30), 0, 365, 30);
    }

    public static function plainLength(?string $html): int
    {
        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';

        return mb_strlen($text);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation  $query
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
     */
    public static function applyListingStockFilter($query)
    {
        // Exclude OOS products marked hide, and when the shop default is hide, also exclude
        // other non-allow OOS products (preserves legacy shop-wide hide).
        $shopHide = self::hideOutOfStock();

        return $query->where(function ($inner) use ($shopHide) {
            $inner->where('manage_stock', false)
                ->orWhereRaw('(COALESCE(stock_qty, 0) - COALESCE(reserved_qty, 0)) > 0')
                ->orWhereIn('backorder_status', ['allow', 'allow_notify']);
            if (! $shopHide) {
                // Shop is allow/deny: only product-level hide is excluded when OOS.
                $inner->orWhere(function ($visible) {
                    $visible->whereNull('backorder_status')
                        ->orWhere('backorder_status', '!=', 'hide');
                });
            }
        });
    }

    public static function hiddenFromStorefront(object $product): bool
    {
        if (! self::productIsOutOfStock($product)) {
            return false;
        }

        $behavior = self::productStockBehavior($product);
        if ($behavior === 'hide') {
            return true;
        }

        // Legacy shop-wide hide still conceals deny/default OOS products.
        return self::hideOutOfStock() && $behavior !== 'allow';
    }

    public static function productIsOutOfStock(object $product): bool
    {
        if (! ($product->manage_stock ?? true)) {
            return false;
        }

        $available = max(0, (float) ($product->stock_qty ?? 0) - (float) ($product->reserved_qty ?? 0));
        if ($available > 0.0001) {
            return false;
        }

        return self::productStockBehavior($product) !== 'allow';
    }

    /** Whether add-to-cart / checkout must reject this OOS product. */
    public static function blocksPurchaseWhenOutOfStock(object $product): bool
    {
        return self::productIsOutOfStock($product);
    }

    /**
     * @return array{enabled: bool, color: string, duration_ms: int}
     */
    public static function listPayload(): array
    {
        return [
            'enabled' => self::highlightEnabled(),
            'color' => self::color(),
            'duration_ms' => self::durationMs(),
        ];
    }

    /**
     * @return array{catalog_mode: bool, show_price: bool, hide_out_of_stock: bool, compare_max: int, compare_enabled: bool}
     */
    public static function catalogPayload(): array
    {
        return [
            'catalog_mode' => self::catalogMode(),
            'show_price' => self::showPrice(),
            'hide_prices_guests' => self::hidePricesGuests(),
            'short_description_max' => self::shortDescriptionMax(),
            'description_max' => self::descriptionMax(),
            'hide_out_of_stock' => self::hideOutOfStock(),
            'compare_max' => self::compareMax(),
            'compare_enabled' => self::compareEnabled(),
            'out_of_stock_behavior' => self::outOfStockBehavior(),
            'out_of_stock_notification' => self::outOfStockNotification(),
        ];
    }

    /**
     * @return array{short_description_max: int, description_max: int, meta_title_max: int, meta_description_max: int}
     */
    public static function contentPayload(): array
    {
        return [
            'short_description_max' => self::shortDescriptionMax(),
            'description_max' => self::descriptionMax(),
            'meta_title_max' => self::metaTitleMax(),
            'meta_description_max' => self::metaDescriptionMax(),
        ];
    }

    /**
     * Settings form + product form bootstrap.
     *
     * @return array<string, mixed>
     */
    public static function defaultsPayload(): array
    {
        return [
            'default_status' => self::defaultStatus(),
            'default_type' => self::defaultType(),
            'sku_mode' => self::skuMode(),
            'sku_prefix' => self::skuPrefix(),
            'barcode_mode' => self::barcodeMode(),
            'default_manage_stock' => self::defaultManageStock(),
            'default_allow_backorder' => self::defaultAllowBackorder(),
            'default_low_stock_alert' => self::defaultLowStockAlert(),
            'out_of_stock_behavior' => self::outOfStockBehavior(),
            'default_min_qty' => self::defaultMinQty(),
            'default_qty_step' => self::defaultQtyStep(),
            'default_max_qty' => self::defaultMaxQty(),
            'weight_unit' => self::shopWeightUnit(),
            'dimension_unit' => self::shopDimensionUnit(),
            'compare_enabled' => self::compareEnabled(),
            'compare_max' => self::compareMax(),
            'compare_fields' => self::compareFields(),
            'require_cover_to_publish' => self::requireCoverToPublish(),
            'require_brand_to_publish' => self::requireBrandToPublish(),
            'product_video' => CommunityFeatures::productVideo(),
            'default_available_for_order' => self::defaultAvailableForOrder(),
            'out_of_stock_notification' => self::outOfStockNotification(),
            // still used by product form defaults
            'default_visibility' => self::defaultVisibility(),
            'default_show_price' => self::defaultShowPrice(),
            'default_critical_stock' => self::defaultCriticalStock(),
            'default_notify_low_stock' => self::defaultNotifyLowStock(),
            'default_notify_out_of_stock' => self::defaultNotifyOutOfStock(),
            'default_tax_class_id' => self::defaultTaxClassId(),
            'require_sku' => self::requireSku(),
            'admin_per_page' => self::adminPerPage(),
            'storefront_per_page' => self::storefrontPerPage(),
        ];
    }

    /**
     * @return array{list: array, catalog: array, content: array, defaults: array, typeOptions: list<string>}
     */
    public static function payload(): array
    {
        return [
            'list' => self::listPayload(),
            'catalog' => self::catalogPayload(),
            'content' => self::contentPayload(),
            'defaults' => self::defaultsPayload(),
            'typeOptions' => self::DEFAULT_TYPE_OPTIONS,
        ];
    }

    protected static function clampInt(mixed $raw, int $min, int $max, int $default): int
    {
        $value = (int) $raw;
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }

        return $value >= 0 ? $value : $default;
    }
}
