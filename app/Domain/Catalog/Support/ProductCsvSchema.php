<?php

namespace App\Domain\Catalog\Support;

use App\Domain\Catalog\Models\AttributeValue;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\CatalogAttribute;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\FeatureValue;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Catalog\Models\Tag;
use App\Domain\Security\CsvSafe;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Tax\Models\TaxClass;
use Illuminate\Support\Str;

/**
 * Single source of truth for product CSV import, export, and sample download.
 */
final class ProductCsvSchema
{
    public const DEFAULT_DELIMITER = ';';

    public const DEFAULT_MULTI_SEPARATOR = ',';

    /** Separates attribute values inside one combination attribute (Color:red/blue). */
    public const ATTRIBUTE_VALUE_SEPARATOR = '/';

    /**
     * Ordered importable column keys.
     *
     * @return list<string>
     */
    public static function columns(): array
    {
        return [
            'id',
            'sku',
            'type',
            'name',
            'slug',
            'short_description',
            'description',
            'price',
            'compare_price',
            'sale_price',
            'sale_price_type',
            'sale_starts_at',
            'sale_ends_at',
            'cost_price',
            'tax_class',
            'stock_qty',
            'stock_status',
            'manage_stock',
            'allow_backorder',
            'low_stock_threshold',
            'min_qty',
            'max_qty',
            'qty_step',
            'brand',
            'categories',
            'suppliers',
            'tags',
            'features',
            'attributes',
            'combinations',
            'components',
            'gift_card_amounts',
            'service_type',
            'service_duration',
            'service_duration_unit',
            'service_pricing_model',
            'service_delivery_method',
            'service_travel_fee',
            'service_meeting_instructions',
            'service_delivery_instructions',
            'service_online_url',
            'service_area',
            'service_location_info',
            'service_available_days',
            'service_start_time',
            'service_end_time',
            'service_min_advance_hours',
            'service_max_advance_days',
            'service_max_bookings',
            'rental_unit',
            'rental_min_duration',
            'rental_max_duration',
            'rental_default_duration',
            'rental_pricing_model',
            'rental_tiers',
            'rental_security_deposit_type',
            'rental_security_deposit_value',
            'rental_cleaning_fee',
            'rental_inventory_mode',
            'rental_late_fee_type',
            'rental_late_fee_value',
            'selection_mode',
            'bundle_type',
            'pricing_mode',
            'inventory_mode',
            'shipping_mode',
            'preorder_enabled',
            'preorder_start_at',
            'preorder_end_at',
            'preorder_release_at',
            'preorder_fulfillment_at',
            'preorder_expected_delivery_at',
            'preorder_payment_type',
            'preorder_deposit_type',
            'preorder_deposit_value',
            'preorder_min_quantity',
            'preorder_max_quantity',
            'preorder_total_limit',
            'preorder_message',
            'preorder_show_badge',
            'preorder_show_release_date',
            'preorder_show_delivery_date',
            'preorder_show_countdown_pdp',
            'preorder_show_countdown_listing',
            'preorder_show_image_label',
            'preorder_fulfillment_rule',
            'preorder_price',
            'preorder_price_valid_until',
            'meta_title',
            'meta_description',
            'meta_keywords',
            'focus_keyword',
            'canonical_url',
            'robots',
            'visibility',
            'is_enabled',
            'available_for_order',
            'show_price',
            'weight',
            'weight_class',
            'length',
            'width',
            'height',
            'length_class',
            'additional_shipping_cost',
            'available_all_carriers',
            'shipping_methods',
            'processing_time',
            'processing_time_unit',
            'delivery_from',
            'delivery_to',
            'barcode',
            'isbn',
            'upc',
            'mpn',
            'gtin',
            'condition',
            'display_condition',
            'is_new',
            'is_on_sale',
            'is_best_seller',
            'images',
            'related_skus',
            'upsell_skus',
            'crosssell_skus',
        ];
    }

    /**
     * Minimum headers required to treat a file as a Product CSV (legacy subsets allowed).
     *
     * @return list<string>
     */
    public static function requiredColumns(): array
    {
        return ['sku', 'name'];
    }

    /**
     * Fifteen realistic sample products covering supported types and configs.
     * Multi-value fields are arrays and are joined with the multi-value separator when writing CSV.
     *
     * @return list<array<string, mixed>>
     */
    public static function sampleProducts(): array
    {
        return [
            [
                'sku' => 'STD-MOUSE-01',
                'type' => 'simple',
                'name' => 'Wireless Mouse',
                'slug' => 'wireless-mouse',
                'short_description' => 'Ergonomic wireless mouse for everyday work.',
                'description' => 'Comfortable grip with silent clicks and long battery life.',
                'price' => '29.99',
                'compare_price' => '39.99',
                'sale_price' => '24.99',
                'sale_price_type' => 'fixed',
                'sale_starts_at' => '2026-01-01',
                'sale_ends_at' => '2026-12-31',
                'cost_price' => '12.50',
                'tax_class' => 'Standard',
                'stock_qty' => '50',
                'stock_status' => 'in_stock',
                'manage_stock' => '1',
                'allow_backorder' => '0',
                'low_stock_threshold' => '5',
                'min_qty' => '1',
                'max_qty' => '10',
                'qty_step' => '1',
                'brand' => 'volt',
                'categories' => ['electronics', 'accessories'],
                'suppliers' => ['wento-supply'],
                'tags' => ['wireless', 'office'],
                'features' => ['Color:Black', 'Connectivity:Wireless'],
                'meta_title' => 'Wireless Mouse | Shop',
                'meta_description' => 'Buy an ergonomic wireless mouse with silent clicks.',
                'meta_keywords' => 'mouse,wireless,office',
                'focus_keyword' => 'wireless mouse',
                'robots' => 'index,follow',
                'visibility' => 'both',
                'is_enabled' => '1',
                'available_for_order' => '1',
                'show_price' => '1',
                'weight' => '0.120',
                'weight_class' => 'kg',
                'length' => '10',
                'width' => '6',
                'height' => '4',
                'length_class' => 'cm',
                'available_all_carriers' => '1',
                'barcode' => '0123456789012',
                'upc' => '012345678905',
                'mpn' => 'WM-1001',
                'gtin' => '0123456789012',
                'condition' => 'new',
                'display_condition' => '0',
                'is_new' => '1',
                'is_on_sale' => '1',
                'is_best_seller' => '0',
                'images' => ['https://example.com/images/wireless-mouse.jpg'],
            ],
            [
                'sku' => 'COMBO-TEE-01',
                'type' => 'combination',
                'name' => 'Classic Cotton T-Shirt',
                'slug' => 'classic-cotton-t-shirt',
                'short_description' => 'Soft cotton tee with color and size options.',
                'description' => 'A wardrobe staple available in multiple colors and sizes.',
                'price' => '19.99',
                'tax_class' => 'Standard',
                'stock_qty' => '0',
                'stock_status' => 'in_stock',
                'manage_stock' => '0',
                'brand' => 'volt',
                'categories' => ['fashion'],
                'attributes' => 'Color:red/blue,Size:S/L',
                'combinations' => 'red/S:COMBO-TEE-RED-S:19.99:12,red/L:COMBO-TEE-RED-L:19.99:8,blue/S:COMBO-TEE-BLU-S:19.99:10,blue/L:COMBO-TEE-BLU-L:21.99:6',
                'visibility' => 'both',
                'is_enabled' => '1',
                'available_for_order' => '1',
                'show_price' => '1',
                'weight' => '0.200',
                'weight_class' => 'kg',
                'images' => ['https://example.com/images/tshirt-red.jpg', 'https://example.com/images/tshirt-blue.jpg'],
            ],
            [
                'sku' => 'COMBO-MUG-02',
                'type' => 'combination',
                'name' => 'Ceramic Mug Set',
                'slug' => 'ceramic-mug-set',
                'short_description' => 'Dishwasher-safe mug with color variants.',
                'description' => 'Choose your favorite glaze color. Each mug holds 350ml.',
                'price' => '14.50',
                'tax_class' => 'Standard',
                'manage_stock' => '0',
                'categories' => ['home'],
                'attributes' => 'Color:red/blue',
                'combinations' => 'red:COMBO-MUG-RED:14.50:20,blue:COMBO-MUG-BLU:14.50:15',
                'visibility' => 'catalog',
                'is_enabled' => '1',
                'available_for_order' => '1',
                'show_price' => '1',
            ],
            [
                'sku' => 'VIRT-EBOOK-01',
                'type' => 'virtual',
                'name' => 'Store Marketing Ebook',
                'slug' => 'store-marketing-ebook',
                'short_description' => 'Downloadable ebook for growing online sales.',
                'description' => 'Instant digital download. No shipping required.',
                'price' => '9.00',
                'tax_class' => 'Standard',
                'stock_qty' => '0',
                'manage_stock' => '0',
                'stock_status' => 'in_stock',
                'categories' => ['digital'],
                'tags' => ['ebook', 'marketing'],
                'visibility' => 'both',
                'is_enabled' => '1',
                'available_for_order' => '1',
                'show_price' => '1',
                'images' => ['https://example.com/images/ebook-cover.jpg'],
            ],
            [
                'sku' => 'SVC-CONSULT-01',
                'type' => 'service',
                'name' => '1-Hour Store Consultation',
                'slug' => 'store-consultation',
                'short_description' => 'Live video consultation for catalog setup.',
                'description' => 'Book a one-hour online session with a catalog specialist.',
                'price' => '79.00',
                'tax_class' => 'Standard',
                'manage_stock' => '0',
                'service_type' => 'appointment',
                'service_duration' => '60',
                'service_duration_unit' => 'minutes',
                'service_pricing_model' => 'fixed',
                'service_delivery_method' => 'online',
                'service_travel_fee' => '',
                'service_meeting_instructions' => 'Join the video link sent after purchase.',
                'service_delivery_instructions' => '',
                'service_online_url' => 'https://example.com/meet',
                'service_area' => '',
                'service_location_info' => '',
                'service_available_days' => 'mon,tue,wed,thu,fri',
                'service_start_time' => '09:00',
                'service_end_time' => '18:00',
                'service_min_advance_hours' => '24',
                'service_max_advance_days' => '30',
                'service_max_bookings' => '4',
                'categories' => ['services'],
                'visibility' => 'both',
                'is_enabled' => '1',
                'available_for_order' => '1',
                'show_price' => '1',
            ],
            [
                'sku' => 'RENT-CAM-01',
                'type' => 'rental',
                'name' => 'Mirrorless Camera Rental',
                'slug' => 'mirrorless-camera-rental',
                'short_description' => 'Daily camera rental for events and travel.',
                'description' => 'Includes body, kit lens, and battery. Security deposit optional at checkout.',
                'price' => '35.00',
                'tax_class' => 'Standard',
                'stock_qty' => '8',
                'manage_stock' => '1',
                'stock_status' => 'in_stock',
                'rental_unit' => 'day',
                'rental_min_duration' => '1',
                'rental_max_duration' => '14',
                'rental_default_duration' => '2',
                'rental_pricing_model' => 'per_day',
                'rental_tiers' => '1:3:day:35,4:7:day:30,8:14:day:25',
                'rental_security_deposit_type' => 'fixed',
                'rental_security_deposit_value' => '100',
                'rental_cleaning_fee' => '15',
                'rental_inventory_mode' => 'quantity',
                'rental_late_fee_type' => 'per_day',
                'rental_late_fee_value' => '20',
                'categories' => ['electronics'],
                'brand' => 'volt',
                'visibility' => 'both',
                'is_enabled' => '1',
                'available_for_order' => '1',
                'show_price' => '1',
                'weight' => '1.200',
                'weight_class' => 'kg',
            ],
            [
                'sku' => 'GIFT-CARD-01',
                'type' => 'gift_card',
                'name' => 'Digital Gift Card',
                'slug' => 'digital-gift-card',
                'short_description' => 'Send a digital gift card by email.',
                'description' => 'Fixed amounts for easy gifting. Redeemable on any product.',
                'price' => '0',
                'tax_class' => 'Standard',
                'manage_stock' => '0',
                'gift_card_amounts' => '25:25,50:50,100:95',
                'categories' => ['gift-cards'],
                'visibility' => 'both',
                'is_enabled' => '1',
                'available_for_order' => '1',
                'show_price' => '1',
                'images' => ['https://example.com/images/gift-card.jpg'],
            ],
            [
                'sku' => 'BNDL-DESK-01',
                'type' => 'bundle',
                'name' => 'Home Office Starter Bundle',
                'slug' => 'home-office-starter-bundle',
                'short_description' => 'Mouse and cable kit at a fixed bundle price.',
                'description' => 'Everything you need to tidy up a work desk.',
                'price' => '34.99',
                'tax_class' => 'Standard',
                'manage_stock' => '0',
                'bundle_type' => 'fixed',
                'pricing_mode' => 'fixed',
                'inventory_mode' => 'derived',
                'shipping_mode' => 'derived',
                'components' => 'STD-MOUSE-01:1,STD-CABLE-01:1',
                'categories' => ['electronics', 'accessories'],
                'visibility' => 'both',
                'is_enabled' => '1',
                'available_for_order' => '1',
                'show_price' => '1',
            ],
            [
                'sku' => 'GRP-OFFICE-01',
                'type' => 'group',
                'name' => 'Office Essentials Group',
                'slug' => 'office-essentials-group',
                'short_description' => 'Grouped products customers can buy together.',
                'description' => 'A curated group of everyday office products.',
                'price' => '0',
                'tax_class' => 'Standard',
                'manage_stock' => '0',
                'selection_mode' => 'all',
                'pricing_mode' => 'sum',
                'inventory_mode' => 'derived',
                'shipping_mode' => 'derived',
                'components' => 'STD-MOUSE-01:1,STD-CABLE-01:2',
                'categories' => ['accessories'],
                'visibility' => 'both',
                'is_enabled' => '1',
                'available_for_order' => '1',
                'show_price' => '1',
            ],
            [
                'sku' => 'STD-CABLE-01',
                'type' => 'simple',
                'name' => 'USB-C Cable',
                'slug' => 'usb-c-cable',
                'short_description' => '1m braided USB-C cable.',
                'description' => 'Durable braided cable with reinforced connectors.',
                'price' => '9.99',
                'compare_price' => '14.99',
                'cost_price' => '3.25',
                'tax_class' => 'Standard',
                'stock_qty' => '200',
                'stock_status' => 'in_stock',
                'manage_stock' => '1',
                'allow_backorder' => '0',
                'low_stock_threshold' => '10',
                'brand' => 'volt',
                'categories' => ['accessories'],
                'suppliers' => ['wento-supply'],
                'tags' => ['cables'],
                'features' => ['Length:1m', 'Color:Black'],
                'visibility' => 'both',
                'is_enabled' => '1',
                'available_for_order' => '1',
                'show_price' => '1',
                'weight' => '0.050',
                'weight_class' => 'kg',
                'upc' => '012345678906',
                'mpn' => 'UC-1002',
                'related_skus' => ['STD-MOUSE-01'],
                'images' => ['https://example.com/images/usb-c-cable.jpg'],
            ],
            [
                'sku' => 'STD-BACKORDER-01',
                'type' => 'simple',
                'name' => 'Mechanical Keyboard',
                'slug' => 'mechanical-keyboard',
                'short_description' => 'Hot-swap mechanical keyboard currently on backorder.',
                'description' => 'Tactile switches and RGB lighting. Ships when restocked.',
                'price' => '89.00',
                'tax_class' => 'Standard',
                'stock_qty' => '0',
                'stock_status' => 'on_backorder',
                'manage_stock' => '1',
                'allow_backorder' => '1',
                'low_stock_threshold' => '3',
                'brand' => 'volt',
                'categories' => ['electronics'],
                'visibility' => 'both',
                'is_enabled' => '1',
                'available_for_order' => '1',
                'show_price' => '1',
                'weight' => '0.900',
                'weight_class' => 'kg',
            ],
            [
                'sku' => 'STD-NOSALE-01',
                'type' => 'simple',
                'name' => 'Legacy Adapter',
                'slug' => 'legacy-adapter',
                'short_description' => 'Discontinued adapter kept for reference.',
                'description' => 'Inactive product used to demonstrate disabled status and hidden visibility.',
                'price' => '5.00',
                'tax_class' => 'Standard',
                'stock_qty' => '2',
                'stock_status' => 'out_of_stock',
                'manage_stock' => '1',
                'allow_backorder' => '0',
                'visibility' => 'hidden',
                'is_enabled' => '0',
                'available_for_order' => '0',
                'show_price' => '1',
                'categories' => ['accessories'],
            ],
            [
                'sku' => 'STD-PREORDER-01',
                'type' => 'simple',
                'name' => 'Next-Gen Smart Speaker',
                'slug' => 'next-gen-smart-speaker',
                'short_description' => 'Upcoming smart speaker available for pre-order.',
                'description' => 'Reserve yours before the official release date.',
                'price' => '129.00',
                'tax_class' => 'Standard',
                'stock_qty' => '25',
                'stock_status' => 'in_stock',
                'manage_stock' => '1',
                'preorder_enabled' => '1',
                'preorder_start_at' => '2026-10-01 09:00:00',
                'preorder_end_at' => '2026-11-01 18:00:00',
                'preorder_release_at' => '2026-11-15 00:00:00',
                'preorder_payment_type' => 'full',
                'preorder_min_quantity' => '1',
                'preorder_total_limit' => '500',
                'preorder_message' => 'Reserve yours before launch.',
                'preorder_show_badge' => '1',
                'preorder_fulfillment_rule' => 'auto',
                'brand' => 'volt',
                'categories' => ['electronics'],
                'visibility' => 'both',
                'is_enabled' => '1',
                'available_for_order' => '1',
                'show_price' => '1',
                'is_new' => '1',
                'weight' => '1.100',
                'weight_class' => 'kg',
            ],
            [
                'sku' => 'STD-SHIP-01',
                'type' => 'simple',
                'name' => 'Fragile Glass Vase',
                'slug' => 'fragile-glass-vase',
                'short_description' => 'Hand-blown vase with carrier restrictions.',
                'description' => 'Ships only with selected carriers due to fragility.',
                'price' => '45.00',
                'tax_class' => 'Standard',
                'stock_qty' => '15',
                'stock_status' => 'in_stock',
                'manage_stock' => '1',
                'categories' => ['home'],
                'visibility' => 'search',
                'is_enabled' => '1',
                'available_for_order' => '1',
                'show_price' => '1',
                'weight' => '1.500',
                'weight_class' => 'kg',
                'length' => '30',
                'width' => '15',
                'height' => '15',
                'length_class' => 'cm',
                'additional_shipping_cost' => '4.50',
                'available_all_carriers' => '0',
                'shipping_methods' => ['flat', 'free'],
                'processing_time' => '2',
                'processing_time_unit' => 'days',
                'delivery_from' => '3',
                'delivery_to' => '7',
                'condition' => 'new',
                'display_condition' => '1',
            ],
            [
                'sku' => 'STD-RICH-01',
                'type' => 'simple',
                'name' => 'Premium Desk Lamp',
                'slug' => 'premium-desk-lamp',
                'short_description' => 'Adjustable LED desk lamp with warm/cool modes.',
                'description' => 'Aluminum body, touch dimmer, and USB charging port.',
                'price' => '59.00',
                'compare_price' => '79.00',
                'sale_price' => '15',
                'sale_price_type' => 'percent',
                'cost_price' => '22.00',
                'tax_class' => 'Standard',
                'stock_qty' => '40',
                'stock_status' => 'in_stock',
                'manage_stock' => '1',
                'brand' => 'volt',
                'categories' => ['home', 'accessories'],
                'suppliers' => ['wento-supply'],
                'tags' => ['lighting', 'desk', 'led'],
                'features' => ['Color:Black', 'Material:Aluminum'],
                'meta_title' => 'Premium Desk Lamp',
                'meta_description' => 'Adjustable LED desk lamp with USB charging.',
                'meta_keywords' => 'lamp,led,desk',
                'focus_keyword' => 'desk lamp',
                'robots' => 'index,follow',
                'visibility' => 'both',
                'is_enabled' => '1',
                'available_for_order' => '1',
                'show_price' => '1',
                'weight' => '0.850',
                'weight_class' => 'kg',
                'is_best_seller' => '1',
                'is_on_sale' => '1',
                'images' => [
                    'https://example.com/images/desk-lamp-1.jpg',
                    'https://example.com/images/desk-lamp-2.jpg',
                ],
                'related_skus' => ['STD-MOUSE-01', 'STD-CABLE-01'],
                'upsell_skus' => ['STD-PREORDER-01'],
                'crosssell_skus' => ['STD-CABLE-01'],
            ],
        ];
    }

    public static function sampleCsv(
        string $delimiter = self::DEFAULT_DELIMITER,
        string $multiSeparator = self::DEFAULT_MULTI_SEPARATOR,
    ): string {
        $out = fopen('php://temp', 'r+');
        CsvSafe::put($out, self::columns(), $delimiter);
        foreach (self::sampleProducts() as $product) {
            CsvSafe::put($out, self::productToRow($product, $multiSeparator), $delimiter);
        }
        rewind($out);

        return stream_get_contents($out) ?: '';
    }

    /**
     * @param  array<string, mixed>  $product
     * @return list<string>
     */
    public static function productToRow(array $product, string $multiSeparator = self::DEFAULT_MULTI_SEPARATOR): array
    {
        $row = [];
        foreach (self::columns() as $key) {
            $value = $product[$key] ?? '';
            if (is_array($value)) {
                $value = implode($multiSeparator, array_map(static fn ($part) => (string) $part, $value));
            }
            $row[] = (string) $value;
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function mapRow(
        array $row,
        string $locale = 'en',
        string $multiSeparator = self::DEFAULT_MULTI_SEPARATOR,
    ): array {
        $get = static fn (string $key): ?string => array_key_exists($key, $row)
            ? trim((string) $row[$key])
            : null;

        $sku = (string) ($get('sku') ?? '');
        $name = (string) ($get('name') ?? '');
        $type = self::filled($get('type')) ? (string) $get('type') : 'simple';
        if ($type === 'preorder') {
            $type = 'simple';
        }

        $product = [
            'type' => $type,
            'sku' => $sku,
            'price' => self::floatOr($get('price'), 0),
            'stock_qty' => self::intOr($get('stock_qty'), 0),
            'is_enabled' => self::boolOr($get('is_enabled'), true),
            'visibility' => self::filled($get('visibility')) ? (string) $get('visibility') : 'both',
            'available_for_order' => self::boolOr($get('available_for_order'), true),
            'show_price' => self::boolOr($get('show_price'), true),
        ];

        foreach ([
            'compare_price', 'sale_price', 'cost_price', 'weight', 'length', 'width', 'height',
            'additional_shipping_cost',
        ] as $field) {
            if (self::filled($get($field))) {
                $product[$field] = (float) $get($field);
            }
        }

        foreach ([
            'sale_price_type', 'sale_starts_at', 'sale_ends_at', 'stock_status', 'weight_class',
            'length_class', 'processing_time_unit', 'barcode', 'isbn', 'upc', 'mpn', 'gtin', 'condition',
            'selection_mode', 'bundle_type', 'pricing_mode', 'inventory_mode', 'shipping_mode',
        ] as $field) {
            if (self::filled($get($field))) {
                $product[$field] = $get($field);
            }
        }

        foreach ([
            'low_stock_threshold', 'min_qty', 'max_qty', 'qty_step', 'processing_time',
            'delivery_from', 'delivery_to',
        ] as $field) {
            if (self::filled($get($field))) {
                $product[$field] = (int) $get($field);
            }
        }

        foreach ([
            'manage_stock', 'allow_backorder', 'display_condition', 'is_new', 'is_on_sale', 'is_best_seller',
            'preorder_enabled',
        ] as $field) {
            if ($get($field) !== null && $get($field) !== '') {
                $product[$field] = self::boolOr($get($field), false);
            }
        }

        $brandId = self::resolveBrandId($get('brand'));
        if ($brandId) {
            $product['brand_id'] = $brandId;
        }

        $taxClassId = self::resolveTaxClassId($get('tax_class'));
        if ($taxClassId) {
            $product['tax_class_id'] = $taxClassId;
        }

        $categoryIds = self::resolveCategoryIds($get('categories'), $multiSeparator, $locale);
        if ($categoryIds !== []) {
            $product['default_category_id'] = $categoryIds[0];
        }

        $supplierIds = self::resolveSupplierIds($get('suppliers'), $multiSeparator);
        if ($supplierIds !== []) {
            $product['supplier_id'] = $supplierIds[0];
        }

        $availableAll = self::boolOr($get('available_all_carriers'), true);
        $shippingMethodIds = $availableAll
            ? []
            : self::resolveShippingMethodIds($get('shipping_methods'), $multiSeparator);

        $slug = self::filled($get('slug')) ? (string) $get('slug') : Str::slug($name);

        $translation = [
            'language_code' => $locale,
            'name' => $name,
            'slug' => $slug,
            'short_description' => self::nullable($get('short_description')),
            'description' => self::nullable($get('description')),
            'meta_title' => self::nullable($get('meta_title')),
            'meta_description' => self::nullable($get('meta_description')),
            'meta_keywords' => self::nullable($get('meta_keywords')),
            'focus_keyword' => self::nullable($get('focus_keyword')),
            'canonical_url' => self::nullable($get('canonical_url')),
            'robots' => self::filled($get('robots')) ? (string) $get('robots') : 'index,follow',
        ];

        $mapped = [
            'product' => $product,
            'translation' => $translation,
            'category_ids' => $categoryIds,
            'supplier_ids' => $supplierIds,
            'tag_ids' => self::resolveTagIds($get('tags'), $locale, $multiSeparator),
            'feature_value_ids' => self::resolveFeatureValueIds($get('features'), $locale, $multiSeparator),
            'shipping_method_ids' => $shippingMethodIds,
            'available_all_carriers' => $availableAll,
            'relations' => [
                'related' => self::resolveProductIdsBySku($get('related_skus'), $multiSeparator),
                'upsell' => self::resolveProductIdsBySku($get('upsell_skus'), $multiSeparator),
                'crosssell' => self::resolveProductIdsBySku($get('crosssell_skus'), $multiSeparator),
            ],
            'image_urls' => self::splitMulti($get('images'), $multiSeparator),
            'components' => self::resolveComponents($get('components'), $multiSeparator),
            'combination_axes' => self::resolveCombinationAxes($get('attributes'), $multiSeparator),
            'combination_variants' => self::resolveCombinationVariants(
                $get('combinations'),
                $get('attributes'),
                $multiSeparator,
            ),
            'gift_card' => null,
            'gift_card_amounts' => [],
            'service' => null,
            'rental' => null,
            'rental_pricing_tiers' => [],
            'preorder_enabled' => self::boolOr($get('preorder_enabled'), false),
            'preorder' => null,
        ];

        if ($type === 'gift_card') {
            $mapped['gift_card'] = [
                'amount_type' => 'fixed',
                'delivery_method' => 'email',
                'validity_type' => 'never',
            ];
            $mapped['gift_card_amounts'] = self::resolveGiftCardAmounts($get('gift_card_amounts'), $multiSeparator);
        }

        if ($type === 'service') {
            $days = self::splitMulti($get('service_available_days'), $multiSeparator);
            $mapped['service'] = [
                'service_type' => self::filled($get('service_type')) ? (string) $get('service_type') : 'one_time',
                'duration' => self::filled($get('service_duration')) ? (float) $get('service_duration') : null,
                'duration_unit' => self::filled($get('service_duration_unit')) ? (string) $get('service_duration_unit') : 'hours',
                'pricing_model' => self::filled($get('service_pricing_model')) ? (string) $get('service_pricing_model') : 'fixed',
                'delivery_method' => self::filled($get('service_delivery_method')) ? (string) $get('service_delivery_method') : 'online',
                'travel_fee' => self::filled($get('service_travel_fee')) ? (float) $get('service_travel_fee') : null,
                'meeting_instructions' => self::filled($get('service_meeting_instructions')) ? (string) $get('service_meeting_instructions') : null,
                'delivery_instructions' => self::filled($get('service_delivery_instructions')) ? (string) $get('service_delivery_instructions') : null,
                'online_service_url' => self::filled($get('service_online_url')) ? (string) $get('service_online_url') : null,
                'service_area' => self::filled($get('service_area')) ? (string) $get('service_area') : null,
                'location_info' => self::filled($get('service_location_info')) ? (string) $get('service_location_info') : null,
                'availability_config' => (
                    (self::filled($get('service_type')) ? (string) $get('service_type') : 'one_time') === 'appointment'
                ) ? [
                    'available_days' => $days !== [] ? $days : ['mon', 'tue', 'wed', 'thu', 'fri'],
                    'start_time' => self::filled($get('service_start_time')) ? (string) $get('service_start_time') : '09:00',
                    'end_time' => self::filled($get('service_end_time')) ? (string) $get('service_end_time') : '18:00',
                    'booking_duration' => 60,
                    'buffer_time' => 15,
                    'min_advance_hours' => self::filled($get('service_min_advance_hours')) ? (float) $get('service_min_advance_hours') : 24,
                    'max_advance_days' => self::filled($get('service_max_advance_days')) ? (int) $get('service_max_advance_days') : 30,
                    'max_bookings' => self::filled($get('service_max_bookings')) ? (int) $get('service_max_bookings') : null,
                ] : null,
            ];
        }

        if ($type === 'rental') {
            $mapped['rental'] = [
                'rental_unit' => self::filled($get('rental_unit')) ? (string) $get('rental_unit') : 'day',
                'min_duration' => self::intOr($get('rental_min_duration'), 1),
                'max_duration' => self::intOr($get('rental_max_duration'), 30),
                'default_duration' => self::intOr($get('rental_default_duration'), 1),
                'pricing_model' => self::filled($get('rental_pricing_model')) ? (string) $get('rental_pricing_model') : 'per_day',
                'security_deposit_type' => self::filled($get('rental_security_deposit_type')) ? (string) $get('rental_security_deposit_type') : 'none',
                'security_deposit_value' => self::filled($get('rental_security_deposit_value')) ? (float) $get('rental_security_deposit_value') : null,
                'cleaning_fee' => self::filled($get('rental_cleaning_fee')) ? (float) $get('rental_cleaning_fee') : null,
                'inventory_mode' => self::filled($get('rental_inventory_mode')) ? (string) $get('rental_inventory_mode') : 'quantity',
                'fulfillment_method' => 'both',
                'return_method' => 'both',
                'late_fee_type' => self::filled($get('rental_late_fee_type')) ? (string) $get('rental_late_fee_type') : 'none',
                'late_fee_value' => self::filled($get('rental_late_fee_value')) ? (float) $get('rental_late_fee_value') : null,
                'damage_fee_type' => 'none',
                'lost_item_fee_type' => 'none',
                'availability_config' => [],
            ];
            $mapped['rental_pricing_tiers'] = self::resolveRentalTiers($get('rental_tiers'), $multiSeparator);
        }

        if (self::boolOr($get('preorder_enabled'), false) || self::filled($get('preorder_payment_type')) || self::filled($get('preorder_start_at'))) {
            $mapped['preorder_enabled'] = true;
            $mapped['preorder'] = [
                'start_at' => self::filled($get('preorder_start_at')) ? (string) $get('preorder_start_at') : null,
                'end_at' => self::filled($get('preorder_end_at')) ? (string) $get('preorder_end_at') : null,
                'release_at' => self::filled($get('preorder_release_at')) ? (string) $get('preorder_release_at') : null,
                'fulfillment_at' => self::filled($get('preorder_fulfillment_at')) ? (string) $get('preorder_fulfillment_at') : null,
                'expected_delivery_at' => self::filled($get('preorder_expected_delivery_at')) ? (string) $get('preorder_expected_delivery_at') : null,
                'payment_type' => self::filled($get('preorder_payment_type')) ? (string) $get('preorder_payment_type') : 'full',
                'deposit_type' => self::filled($get('preorder_deposit_type')) ? (string) $get('preorder_deposit_type') : null,
                'deposit_value' => self::filled($get('preorder_deposit_value')) ? (float) $get('preorder_deposit_value') : null,
                'min_quantity' => self::intOr($get('preorder_min_quantity'), 1),
                'max_quantity' => self::filled($get('preorder_max_quantity')) ? (int) $get('preorder_max_quantity') : null,
                'total_limit' => self::filled($get('preorder_total_limit')) ? (int) $get('preorder_total_limit') : null,
                'message' => self::filled($get('preorder_message')) ? (string) $get('preorder_message') : null,
                'show_badge' => self::boolOr($get('preorder_show_badge'), true),
                'show_release_date' => self::boolOr($get('preorder_show_release_date'), true),
                'show_delivery_date' => self::boolOr($get('preorder_show_delivery_date'), true),
                'show_countdown_pdp' => self::boolOr($get('preorder_show_countdown_pdp'), false),
                'show_countdown_listing' => self::boolOr($get('preorder_show_countdown_listing'), false),
                'show_image_label' => self::boolOr($get('preorder_show_image_label'), false),
                'fulfillment_rule' => self::filled($get('preorder_fulfillment_rule')) ? (string) $get('preorder_fulfillment_rule') : 'auto',
                'preorder_price' => self::filled($get('preorder_price')) ? (float) $get('preorder_price') : null,
                'preorder_price_valid_until' => self::filled($get('preorder_price_valid_until')) ? (string) $get('preorder_price_valid_until') : null,
            ];
        }

        return $mapped;
    }

    /**
     * @return list<string|int|float|null>
     */
    public static function exportValues(
        Product $product,
        string $locale = 'en',
        string $multiSeparator = self::DEFAULT_MULTI_SEPARATOR,
    ): array {
        $t = $product->translation($locale);
        $brand = $product->brand;
        $brandLabel = $brand?->code
            ?: $brand?->translation($locale)?->slug
            ?: $brand?->translation($locale)?->name
            ?: '';

        $join = static fn (array $parts): string => implode($multiSeparator, array_values(array_filter(
            array_map(static fn ($part) => trim((string) $part), $parts),
            static fn (string $part) => $part !== ''
        )));

        $categories = $product->categories
            ->map(fn (Category $c) => $c->translation($locale)?->slug ?: $c->translation($locale)?->name)
            ->filter()
            ->values()
            ->all();

        $suppliers = $product->suppliers
            ->map(fn (Supplier $s) => $s->translation($locale)?->slug ?: $s->translation($locale)?->name)
            ->filter()
            ->values()
            ->all();

        $tags = $product->tags
            ->map(fn (Tag $tag) => $tag->translation($locale)?->name)
            ->filter()
            ->values()
            ->all();

        $features = [];
        foreach ($product->featureValues as $value) {
            $featureName = $value->feature?->translation($locale)?->name;
            $valueName = $value->translation($locale)?->value;
            if ($featureName && $valueName) {
                $features[] = $featureName.':'.$valueName;
            }
        }

        $shipping = $product->available_all_carriers
            ? []
            : $product->shippingMethods->map(fn (ShippingMethod $m) => $m->code ?: $m->name)->filter()->values()->all();

        $images = $product->images->pluck('path')->filter()->values()->all();
        $giftAmounts = [];
        if ($product->isGiftCard()) {
            foreach ($product->giftCardAmounts ?? [] as $amount) {
                $giftAmounts[] = $amount->selling_price !== null
                    ? $amount->value.':'.$amount->selling_price
                    : (string) $amount->value;
            }
        }

        $components = [];
        foreach ($product->components ?? [] as $component) {
            $childSku = $component->child?->sku;
            if ($childSku) {
                $components[] = $childSku.':'.max(1, (int) $component->quantity);
            }
        }

        $map = [
            'id' => $product->id,
            'sku' => $product->sku,
            'type' => $product->type,
            'name' => $t?->name,
            'slug' => $t?->slug,
            'short_description' => $t?->short_description,
            'description' => $t?->description,
            'price' => $product->price,
            'compare_price' => $product->compare_price,
            'sale_price' => $product->sale_price,
            'sale_price_type' => $product->sale_price_type,
            'sale_starts_at' => optional($product->sale_starts_at)?->format('Y-m-d'),
            'sale_ends_at' => optional($product->sale_ends_at)?->format('Y-m-d'),
            'cost_price' => $product->cost_price,
            'tax_class' => $product->taxClass?->name,
            'stock_qty' => $product->stock_qty,
            'stock_status' => $product->stock_status,
            'manage_stock' => self::boolToCsv($product->manage_stock),
            'allow_backorder' => self::boolToCsv($product->allow_backorder),
            'low_stock_threshold' => $product->low_stock_threshold,
            'min_qty' => $product->min_qty,
            'max_qty' => $product->max_qty,
            'qty_step' => $product->qty_step,
            'brand' => $brandLabel,
            'categories' => $join($categories),
            'suppliers' => $join($suppliers),
            'tags' => $join($tags),
            'features' => $join($features),
            'attributes' => '',
            'combinations' => '',
            'components' => $join($components),
            'gift_card_amounts' => $join($giftAmounts),
            'service_type' => $product->serviceSetting?->service_type,
            'service_duration' => $product->serviceSetting?->duration,
            'service_duration_unit' => $product->serviceSetting?->duration_unit,
            'service_pricing_model' => $product->serviceSetting?->pricing_model,
            'service_delivery_method' => $product->serviceSetting?->delivery_method,
            'service_travel_fee' => $product->serviceSetting?->travel_fee,
            'service_meeting_instructions' => $product->serviceSetting?->meeting_instructions,
            'service_delivery_instructions' => $product->serviceSetting?->delivery_instructions,
            'service_online_url' => $product->serviceSetting?->online_service_url,
            'service_area' => $product->serviceSetting?->service_area,
            'service_location_info' => $product->serviceSetting?->location_info,
            'service_available_days' => $join(is_array($product->serviceSetting?->availability_config['available_days'] ?? null)
                ? $product->serviceSetting->availability_config['available_days']
                : []),
            'service_start_time' => $product->serviceSetting?->availability_config['start_time'] ?? null,
            'service_end_time' => $product->serviceSetting?->availability_config['end_time'] ?? null,
            'service_min_advance_hours' => $product->serviceSetting?->availability_config['min_advance_hours'] ?? null,
            'service_max_advance_days' => $product->serviceSetting?->availability_config['max_advance_days'] ?? null,
            'service_max_bookings' => $product->serviceSetting?->availability_config['max_bookings'] ?? null,
            'rental_unit' => $product->rentalSetting?->rental_unit,
            'rental_min_duration' => $product->rentalSetting?->min_duration,
            'rental_max_duration' => $product->rentalSetting?->max_duration,
            'rental_default_duration' => $product->rentalSetting?->default_duration,
            'rental_pricing_model' => $product->rentalSetting?->pricing_model,
            'rental_tiers' => $join(($product->relationLoaded('rentalPricingTiers')
                ? $product->rentalPricingTiers
                : $product->rentalPricingTiers()->orderBy('position')->get()
            )->map(fn ($tier) => implode(':', [
                (int) $tier->min_duration,
                (int) $tier->max_duration,
                (string) $tier->unit,
                rtrim(rtrim(number_format((float) $tier->price, 2, '.', ''), '0'), '.') ?: '0',
            ]))->all()),
            'rental_security_deposit_type' => $product->rentalSetting?->security_deposit_type,
            'rental_security_deposit_value' => $product->rentalSetting?->security_deposit_value,
            'rental_cleaning_fee' => $product->rentalSetting?->cleaning_fee,
            'rental_inventory_mode' => $product->rentalSetting?->inventory_mode,
            'rental_late_fee_type' => $product->rentalSetting?->late_fee_type,
            'rental_late_fee_value' => $product->rentalSetting?->late_fee_value,
            'selection_mode' => $product->selection_mode,
            'bundle_type' => $product->bundle_type,
            'pricing_mode' => $product->pricing_mode,
            'inventory_mode' => $product->inventory_mode,
            'shipping_mode' => $product->shipping_mode,
            'preorder_enabled' => self::boolToCsv($product->preorder_enabled),
            'preorder_start_at' => $product->preorderSetting?->start_at?->format('Y-m-d H:i:s'),
            'preorder_end_at' => $product->preorderSetting?->end_at?->format('Y-m-d H:i:s'),
            'preorder_release_at' => $product->preorderSetting?->release_at?->format('Y-m-d H:i:s'),
            'preorder_fulfillment_at' => $product->preorderSetting?->fulfillment_at?->format('Y-m-d H:i:s'),
            'preorder_expected_delivery_at' => $product->preorderSetting?->expected_delivery_at?->format('Y-m-d H:i:s'),
            'preorder_payment_type' => $product->preorderSetting?->payment_type,
            'preorder_deposit_type' => $product->preorderSetting?->deposit_type,
            'preorder_deposit_value' => $product->preorderSetting?->deposit_value,
            'preorder_min_quantity' => $product->preorderSetting?->min_quantity,
            'preorder_max_quantity' => $product->preorderSetting?->max_quantity,
            'preorder_total_limit' => $product->preorderSetting?->total_limit,
            'preorder_message' => $product->preorderSetting?->message,
            'preorder_show_badge' => self::boolToCsv($product->preorderSetting?->show_badge),
            'preorder_show_release_date' => self::boolToCsv($product->preorderSetting?->show_release_date),
            'preorder_show_delivery_date' => self::boolToCsv($product->preorderSetting?->show_delivery_date),
            'preorder_show_countdown_pdp' => self::boolToCsv($product->preorderSetting?->show_countdown_pdp),
            'preorder_show_countdown_listing' => self::boolToCsv($product->preorderSetting?->show_countdown_listing),
            'preorder_show_image_label' => self::boolToCsv($product->preorderSetting?->show_image_label),
            'preorder_fulfillment_rule' => $product->preorderSetting?->fulfillment_rule,
            'preorder_price' => $product->preorderSetting?->preorder_price,
            'preorder_price_valid_until' => $product->preorderSetting?->preorder_price_valid_until?->format('Y-m-d H:i:s'),
            'meta_title' => $t?->meta_title,
            'meta_description' => $t?->meta_description,
            'meta_keywords' => $t?->meta_keywords,
            'focus_keyword' => $t?->focus_keyword,
            'canonical_url' => $t?->canonical_url,
            'robots' => $t?->robots,
            'visibility' => $product->visibility,
            'is_enabled' => self::boolToCsv($product->is_enabled),
            'available_for_order' => self::boolToCsv($product->available_for_order),
            'show_price' => self::boolToCsv($product->show_price),
            'weight' => $product->weight,
            'weight_class' => $product->weight_class,
            'length' => $product->length,
            'width' => $product->width,
            'height' => $product->height,
            'length_class' => $product->length_class,
            'additional_shipping_cost' => $product->additional_shipping_cost,
            'available_all_carriers' => self::boolToCsv($product->available_all_carriers),
            'shipping_methods' => $join($shipping),
            'processing_time' => $product->processing_time,
            'processing_time_unit' => $product->processing_time_unit,
            'delivery_from' => $product->delivery_from,
            'delivery_to' => $product->delivery_to,
            'barcode' => $product->barcode,
            'isbn' => $product->isbn,
            'upc' => $product->upc,
            'mpn' => $product->mpn,
            'gtin' => $product->gtin,
            'condition' => $product->condition,
            'display_condition' => self::boolToCsv($product->display_condition),
            'is_new' => self::boolToCsv($product->is_new),
            'is_on_sale' => self::boolToCsv($product->is_on_sale),
            'is_best_seller' => self::boolToCsv($product->is_best_seller),
            'images' => $join($images),
            'related_skus' => $join(self::relationSkus($product, 'related')),
            'upsell_skus' => $join(self::relationSkus($product, 'upsell')),
            'crosssell_skus' => $join(self::relationSkus($product, 'crosssell')),
        ];

        return array_map(fn (string $key) => $map[$key] ?? '', self::columns());
    }

    protected static function filled(?string $value): bool
    {
        return $value !== null && $value !== '';
    }

    protected static function nullable(?string $value): ?string
    {
        return self::filled($value) ? $value : null;
    }

    protected static function floatOr(?string $value, float $default): float
    {
        return self::filled($value) ? (float) $value : $default;
    }

    protected static function intOr(?string $value, int $default): int
    {
        return self::filled($value) ? (int) $value : $default;
    }

    protected static function boolOr(?string $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        $normalized = strtolower(trim($value));

        return match ($normalized) {
            '1', 'true', 'yes', 'y', 'on', 'active', 'enabled' => true,
            '0', 'false', 'no', 'n', 'off', 'inactive', 'disabled' => false,
            default => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default,
        };
    }

    protected static function boolToCsv(mixed $value): string
    {
        return $value ? '1' : '0';
    }

    /** @return list<string> */
    protected static function splitMulti(?string $value, string $multiSeparator): array
    {
        if (! self::filled($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $part) => trim($part),
            explode($multiSeparator, $value)
        ), static fn (string $part) => $part !== ''));
    }

    protected static function resolveBrandId(?string $value): ?int
    {
        if (! self::filled($value)) {
            return null;
        }

        $brand = Brand::query()
            ->where(function ($q) use ($value) {
                $q->where('code', $value)
                    ->orWhereHas('translations', fn ($t) => $t->where('slug', $value)->orWhere('name', $value));
            })
            ->first();

        return $brand?->id;
    }

    protected static function resolveTaxClassId(?string $value): ?int
    {
        if (! self::filled($value)) {
            return null;
        }

        return TaxClass::query()->where('name', $value)->value('id');
    }

    /** @return list<int> */
    protected static function resolveCategoryIds(?string $value, string $multiSeparator, string $locale = 'en'): array
    {
        $ids = [];
        foreach (self::splitMulti($value, $multiSeparator) as $token) {
            $slug = Str::slug($token) ?: $token;
            $id = Category::query()
                ->whereHas('translations', fn ($q) => $q->where('slug', $token)->orWhere('name', $token)->orWhere('slug', $slug))
                ->value('id');
            if (! $id) {
                $category = new Category;
                $category->is_enabled = true;
                $category->position = (int) (Category::query()->max('position') ?? 0) + 1;
                $category->save();
                $category->translations()->create([
                    'language_code' => $locale,
                    'name' => $token,
                    'slug' => $slug !== '' ? $slug : 'category-'.$category->id,
                ]);
                $id = $category->id;
            }
            $ids[] = (int) $id;
        }

        return array_values(array_unique($ids));
    }

    /** @return list<int> */
    protected static function resolveSupplierIds(?string $value, string $multiSeparator): array
    {
        $ids = [];
        foreach (self::splitMulti($value, $multiSeparator) as $token) {
            $id = Supplier::query()
                ->whereHas('translations', fn ($q) => $q->where('slug', $token)->orWhere('name', $token))
                ->value('id');
            if ($id) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return list<int> */
    protected static function resolveTagIds(?string $value, string $locale, string $multiSeparator): array
    {
        $ids = [];
        foreach (self::splitMulti($value, $multiSeparator) as $token) {
            $tag = Tag::query()
                ->whereHas('translations', fn ($q) => $q->where('name', $token)->orWhere('slug', Str::slug($token)))
                ->first();

            if (! $tag) {
                $tag = Tag::query()->create([]);
                $tag->translations()->create([
                    'language_code' => $locale,
                    'name' => $token,
                    'slug' => Str::slug($token) ?: 'tag-'.$tag->id,
                ]);
            }

            $ids[] = (int) $tag->id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<array{feature_id: int, feature_value_id: int}>
     */
    protected static function resolveFeatureValueIds(?string $value, string $locale, string $multiSeparator): array
    {
        $rows = [];
        foreach (self::splitMulti($value, $multiSeparator) as $token) {
            if (! str_contains($token, ':')) {
                continue;
            }
            [$featureName, $valueName] = array_map('trim', explode(':', $token, 2));
            if ($featureName === '' || $valueName === '') {
                continue;
            }

            $feature = Feature::query()
                ->where(function ($q) use ($featureName) {
                    $q->where('code', Str::slug($featureName))
                        ->orWhereHas('translations', fn ($t) => $t->where('name', $featureName));
                })
                ->first();
            if (! $feature) {
                continue;
            }

            $featureValue = FeatureValue::query()
                ->where('feature_id', $feature->id)
                ->whereHas('translations', fn ($q) => $q->where('value', $valueName))
                ->first();
            if (! $featureValue) {
                continue;
            }

            $rows[] = [
                'feature_id' => (int) $feature->id,
                'feature_value_id' => (int) $featureValue->id,
            ];
        }

        return $rows;
    }

    /** @return list<int> */
    protected static function resolveShippingMethodIds(?string $value, string $multiSeparator): array
    {
        $ids = [];
        foreach (self::splitMulti($value, $multiSeparator) as $token) {
            $id = ShippingMethod::query()
                ->where(function ($q) use ($token) {
                    $q->where('code', $token)->orWhere('name', $token);
                })
                ->value('id');
            if ($id) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return list<int> */
    protected static function resolveProductIdsBySku(?string $value, string $multiSeparator): array
    {
        $ids = [];
        foreach (self::splitMulti($value, $multiSeparator) as $sku) {
            $id = Product::query()->where('sku', $sku)->value('id');
            if ($id) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<array{child_product_id: int, quantity: int}>
     */
    protected static function resolveComponents(?string $value, string $multiSeparator): array
    {
        $rows = [];
        foreach (self::splitMulti($value, $multiSeparator) as $token) {
            [$sku, $qty] = array_pad(array_map('trim', explode(':', $token, 2)), 2, '1');
            $id = Product::query()->where('sku', $sku)->value('id');
            if (! $id) {
                continue;
            }
            $rows[] = [
                'child_product_id' => (int) $id,
                'quantity' => max(1, (int) $qty),
            ];
        }

        return $rows;
    }

    /**
     * attributes format: Color:red/blue,Size:S/L
     *
     * @return list<array{attribute_id: int, value_ids: list<int>}>
     */
    protected static function resolveCombinationAxes(?string $value, string $multiSeparator): array
    {
        $axes = [];
        foreach (self::splitMulti($value, $multiSeparator) as $token) {
            if (! str_contains($token, ':')) {
                continue;
            }
            [$attrName, $valuesRaw] = array_map('trim', explode(':', $token, 2));
            $attribute = CatalogAttribute::query()
                ->where(function ($q) use ($attrName) {
                    $lower = mb_strtolower($attrName);
                    $q->whereRaw('LOWER(code) = ?', [$lower])
                        ->orWhereHas('translations', fn ($t) => $t->whereRaw('LOWER(name) = ?', [$lower]));
                })
                ->first();
            if (! $attribute) {
                continue;
            }

            $valueIds = [];
            foreach (explode(self::ATTRIBUTE_VALUE_SEPARATOR, $valuesRaw) as $valueName) {
                $valueName = trim($valueName);
                if ($valueName === '') {
                    continue;
                }
                $lowerValue = mb_strtolower($valueName);
                $valueId = AttributeValue::query()
                    ->where('attribute_id', $attribute->id)
                    ->where(function ($q) use ($lowerValue) {
                        $q->whereRaw('LOWER(code) = ?', [$lowerValue])
                            ->orWhereHas('translations', fn ($t) => $t->whereRaw('LOWER(value) = ?', [$lowerValue]));
                    })
                    ->value('id');
                if ($valueId) {
                    $valueIds[] = (int) $valueId;
                }
            }

            if ($valueIds === []) {
                continue;
            }

            $axes[] = [
                'attribute_id' => (int) $attribute->id,
                'value_ids' => array_values(array_unique($valueIds)),
            ];
        }

        return $axes;
    }

    /**
     * combinations format: red/S:SKU:price:qty,blue/L:SKU:price:qty
     *
     * @return list<array<string, mixed>>
     */
    protected static function resolveCombinationVariants(
        ?string $combinations,
        ?string $attributes,
        string $multiSeparator,
    ): array {
        if (! self::filled($combinations)) {
            return [];
        }

        $axes = self::resolveCombinationAxes($attributes, $multiSeparator);
        $orderedValueMaps = [];
        foreach ($axes as $axis) {
            $map = [];
            foreach ($axis['value_ids'] as $valueId) {
                $value = AttributeValue::query()->with('translations')->find($valueId);
                if (! $value) {
                    continue;
                }
                $label = strtolower((string) ($value->translation()?->value ?: $value->code));
                $map[$label] = (int) $value->id;
            }
            $orderedValueMaps[] = $map;
        }

        $variants = [];
        foreach (self::splitMulti($combinations, $multiSeparator) as $index => $token) {
            $parts = array_map('trim', explode(':', $token));
            if (count($parts) < 1) {
                continue;
            }
            $path = strtolower($parts[0]);
            $labels = explode(self::ATTRIBUTE_VALUE_SEPARATOR, $path);
            $valueIds = [];
            foreach ($labels as $i => $label) {
                $id = $orderedValueMaps[$i][$label] ?? null;
                if (! $id) {
                    $valueIds = [];
                    break;
                }
                $valueIds[] = $id;
            }
            if ($valueIds === []) {
                continue;
            }

            $variants[] = [
                'attribute_value_ids' => $valueIds,
                'sku' => $parts[1] ?? null,
                'price' => isset($parts[2]) && $parts[2] !== '' ? (float) $parts[2] : null,
                'stock_qty' => isset($parts[3]) && $parts[3] !== '' ? (int) $parts[3] : 0,
                'manage_stock' => true,
                'is_enabled' => true,
                'is_default' => $index === 0,
            ];
        }

        return $variants;
    }

    /**
     * @return list<array{value: float, selling_price: ?float, is_enabled: bool}>
     */
    protected static function resolveGiftCardAmounts(?string $value, string $multiSeparator): array
    {
        $rows = [];
        foreach (self::splitMulti($value, $multiSeparator) as $token) {
            [$amount, $selling] = array_pad(array_map('trim', explode(':', $token, 2)), 2, '');
            if ($amount === '' || (float) $amount <= 0) {
                continue;
            }
            $rows[] = [
                'value' => (float) $amount,
                'selling_price' => $selling === '' ? null : (float) $selling,
                'is_enabled' => true,
            ];
        }

        return $rows;
    }

    /**
     * rental_tiers format: min:max:unit:price
     *
     * @return list<array{min_duration: int, max_duration: int, unit: string, price: float}>
     */
    protected static function resolveRentalTiers(?string $value, string $multiSeparator): array
    {
        $rows = [];
        foreach (self::splitMulti($value, $multiSeparator) as $token) {
            $parts = array_map('trim', explode(':', $token));
            if (count($parts) < 4) {
                continue;
            }
            $rows[] = [
                'min_duration' => (int) $parts[0],
                'max_duration' => (int) $parts[1],
                'unit' => $parts[2],
                'price' => (float) $parts[3],
            ];
        }

        return $rows;
    }

    /** @return list<string> */
    protected static function relationSkus(Product $product, string $type): array
    {
        return $product->relationsOfType($type)
            ->get()
            ->pluck('sku')
            ->filter()
            ->values()
            ->all();
    }
}
