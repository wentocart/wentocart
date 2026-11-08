<?php

namespace App\Domain\Ai;

/**
 * Allowlisted shop tables the admin assistant may SELECT from.
 */
final class SchemaCatalog
{
    /**
     * table => columns (null = all common columns via describe in prompt text only).
     *
     * @return array<string, list<string>>
     */
    public static function tables(): array
    {
        return [
            'products' => [
                'id', 'type', 'sku', 'brand_id', 'price', 'compare_price', 'sale_price', 'cost_price',
                'stock_qty', 'stock_status', 'low_stock_threshold', 'is_enabled', 'visibility',
                'available_for_order', 'created_at', 'updated_at', 'store_id',
            ],
            'product_translations' => [
                'id', 'product_id', 'language_code', 'name', 'slug', 'short_description', 'description',
                'meta_title', 'meta_description',
            ],
            'categories' => ['id', 'parent_id', 'position', 'is_enabled', 'created_at'],
            'category_translations' => ['id', 'category_id', 'language_code', 'name', 'slug'],
            'brands' => ['id', 'name', 'slug', 'is_enabled'],
            'customers' => [
                'id', 'name', 'first_name', 'last_name', 'email', 'phone', 'is_enabled',
                'customer_group_id', 'newsletter', 'created_at', 'store_id',
            ],
            'addresses' => [
                'id', 'customer_id', 'first_name', 'last_name', 'company', 'address1', 'address2',
                'city', 'postcode', 'country_code', 'region_code', 'phone', 'is_default',
            ],
            'orders' => [
                'id', 'number', 'customer_id', 'email', 'status', 'currency_code',
                'subtotal', 'discount_total', 'shipping_total', 'tax_total', 'grand_total',
                'payment_status', 'created_at', 'updated_at', 'store_id',
            ],
            'order_items' => [
                'id', 'order_id', 'product_id', 'sku', 'name', 'qty', 'unit_price', 'line_total', 'tax_amount',
            ],
            'cms_pages' => ['id', 'status', 'is_visible', 'is_enabled', 'template', 'created_at'],
            'cms_page_translations' => ['id', 'cms_page_id', 'language_code', 'title', 'slug', 'short_description'],
            'currencies' => ['id', 'code', 'name', 'symbol', 'exchange_rate', 'is_enabled', 'is_default'],
            'shipping_methods' => ['id', 'name', 'code', 'type', 'price', 'tax_class_id', 'is_enabled'],
            'payment_methods' => ['id', 'name', 'code', 'is_enabled'],
        ];
    }

    public static function promptText(): string
    {
        $out = "WentoCart MySQL tables (use exact names, no table prefix):\n\n";
        foreach (self::tables() as $table => $cols) {
            $out .= $table."\n";
            foreach ($cols as $col) {
                $out .= '- '.$col."\n";
            }
            $out .= "\n";
        }
        $out .= "Join hints:\n";
        $out .= "- products.id = product_translations.product_id\n";
        $out .= "- products.id = order_items.product_id\n";
        $out .= "- orders.id = order_items.order_id\n";
        $out .= "- orders.customer_id = customers.id\n";
        $out .= "- customers.id = addresses.customer_id\n";
        $out .= "- categories.id = category_translations.category_id\n";
        $out .= "\nIMPORTANT: Do NOT add any database table prefix. Use the exact names above (e.g. orders, not prefixed_orders).\n";

        return $out;
    }

    /** @return list<string> */
    public static function tableNames(): array
    {
        return array_keys(self::tables());
    }
}
