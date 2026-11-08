<?php

namespace App\Domain\Settings;

/**
 * Wento-native configuration slug schema for Console REST + GraphQL.
 *
 * Public codes are the same keys stored in {@see ShopSetting} — no foreign alias layer.
 *
 * @phpstan-type ConfigField array{code: string, type: string, title: string, default: string, storeBased: bool, localeBased: bool}
 */
class ConfigurationCatalog
{
    /**
     * @return array<string, list<ConfigField>>
     */
    public static function schema(): array
    {
        return [
            'shop' => [
                ['code' => 'shop.name', 'type' => 'text', 'title' => 'Store name', 'default' => '', 'storeBased' => true, 'localeBased' => true],
                ['code' => 'shop.email', 'type' => 'text', 'title' => 'Store email', 'default' => '', 'storeBased' => true, 'localeBased' => false],
                ['code' => 'shop.phone', 'type' => 'text', 'title' => 'Store phone', 'default' => '', 'storeBased' => true, 'localeBased' => false],
            ],
            'catalog' => [
                ['code' => 'catalog.products_per_page', 'type' => 'text', 'title' => 'Products per page', 'default' => '24', 'storeBased' => false, 'localeBased' => false],
                ['code' => 'catalog.default_sort', 'type' => 'select', 'title' => 'Default sort', 'default' => 'name', 'storeBased' => false, 'localeBased' => false],
            ],
            'orders' => [
                ['code' => 'orders.admin_reorder_enabled', 'type' => 'boolean', 'title' => 'Allow admin reorder', 'default' => '1', 'storeBased' => false, 'localeBased' => false],
                ['code' => 'checkout.guest_checkout', 'type' => 'boolean', 'title' => 'Guest checkout', 'default' => '1', 'storeBased' => true, 'localeBased' => false],
                ['code' => 'orders.minimum_order_enabled', 'type' => 'boolean', 'title' => 'Enable minimum order amount', 'default' => '0', 'storeBased' => true, 'localeBased' => false],
                ['code' => 'orders.min_amount', 'type' => 'text', 'title' => 'Minimum order amount', 'default' => '0', 'storeBased' => true, 'localeBased' => false],
                ['code' => 'orders.minimum_order_include_discount', 'type' => 'boolean', 'title' => 'Include discount in minimum check', 'default' => '0', 'storeBased' => false, 'localeBased' => false],
                ['code' => 'orders.minimum_order_description', 'type' => 'text', 'title' => 'Minimum order message', 'default' => '', 'storeBased' => true, 'localeBased' => true],
            ],
            'seo' => [
                ['code' => 'seo.sitemap_enabled', 'type' => 'boolean', 'title' => 'Sitemap enabled', 'default' => '1', 'storeBased' => false, 'localeBased' => false],
            ],
            'shipping' => [
                ['code' => 'shipping.origin_country', 'type' => 'text', 'title' => 'Origin country', 'default' => '', 'storeBased' => true, 'localeBased' => false],
                ['code' => 'shipping.free_threshold', 'type' => 'text', 'title' => 'Free shipping threshold', 'default' => '0', 'storeBased' => true, 'localeBased' => false],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_keys(self::schema());
    }

    /**
     * @return list<ConfigField>
     */
    public static function fieldsFor(?string $slug): array
    {
        $schema = self::schema();
        if ($slug === null || $slug === '') {
            $all = [];
            foreach ($schema as $fields) {
                foreach ($fields as $field) {
                    $all[] = $field;
                }
            }

            return $all;
        }
        if (! isset($schema[$slug])) {
            return [];
        }

        return $schema[$slug];
    }

    /**
     * Public code is the storage key.
     */
    public static function storageKey(string $code): string
    {
        return $code;
    }

    /**
     * @return array<string, string>
     */
    public static function valuesFor(string $slug): array
    {
        $out = [];
        foreach (self::fieldsFor($slug) as $field) {
            $stored = ShopSetting::get(self::storageKey($field['code']), null);
            if ($stored === null || $stored === '') {
                $out[$field['code']] = (string) $field['default'];
            } elseif (is_bool($stored)) {
                $out[$field['code']] = $stored ? '1' : '0';
            } else {
                $out[$field['code']] = (string) $stored;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    public static function update(string $slug, array $values): array
    {
        $allowed = collect(self::fieldsFor($slug))->keyBy('code');
        foreach ($values as $code => $value) {
            $code = (string) $code;
            if (! $allowed->has($code)) {
                throw new \InvalidArgumentException('Unknown configuration code "'.$code.'" for slug "'.$slug.'".');
            }
            ShopSetting::put(self::storageKey($code), is_bool($value) ? ($value ? '1' : '0') : (string) $value);
        }

        return self::valuesFor($slug);
    }
}
