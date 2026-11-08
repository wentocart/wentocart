<?php

namespace App\Domain\Ai;

/**
 * Public shop tables the storefront assistant may SELECT from.
 */
final class StorefrontSchemaCatalog
{
    /**
     * @return array<string, list<string>>
     */
    public static function tables(): array
    {
        return [
            'products' => [
                'id', 'sku', 'price', 'compare_price', 'sale_price', 'stock_qty', 'stock_status',
                'is_enabled', 'visibility', 'available_for_order', 'brand_id',
            ],
            'product_translations' => [
                'id', 'product_id', 'language_code', 'name', 'slug', 'short_description',
            ],
            'categories' => ['id', 'parent_id', 'position', 'is_enabled'],
            'category_translations' => ['id', 'category_id', 'language_code', 'name', 'slug'],
            'brands' => ['id', 'name', 'slug', 'is_enabled'],
            'shipping_methods' => ['id', 'name', 'code', 'type', 'price', 'tax_class_id', 'is_enabled'],
            'payment_methods' => ['id', 'name', 'code', 'is_enabled'],
            'cms_pages' => ['id', 'status', 'is_visible', 'is_enabled', 'template'],
            'cms_page_translations' => ['id', 'cms_page_id', 'language_code', 'title', 'slug', 'short_description'],
            'orders' => [
                'id', 'number', 'customer_id', 'email', 'status', 'currency_code',
                'grand_total', 'payment_status', 'created_at',
            ],
            'order_items' => [
                'id', 'order_id', 'product_id', 'sku', 'name', 'qty', 'unit_price', 'line_total',
            ],
        ];
    }

    public static function promptText(): string
    {
        $out = "Storefront MySQL tables (exact names, no prefix):\n\n";
        foreach (self::tables() as $table => $cols) {
            $out .= $table."\n";
            foreach ($cols as $col) {
                $out .= '- '.$col."\n";
            }
            $out .= "\n";
        }
        $out .= "Join hints:\n";
        $out .= "- products.id = product_translations.product_id\n";
        $out .= "- categories.id = category_translations.category_id\n";
        $out .= "- orders.id = order_items.order_id\n";
        $out .= "- Only query enabled / public catalog rows when listing products.\n";
        $out .= "- For customer orders, ALWAYS filter orders.customer_id = {CUSTOMER_ID} when provided.\n";
        $out .= "- Do NOT add a database table prefix.\n";

        return $out;
    }

    /** @return list<string> */
    public static function tableNames(): array
    {
        return array_keys(self::tables());
    }
}
