<?php

namespace App\Domain\Store;

final class Sharing
{
    public const MODE_OWN = 'own';

    public const MODE_SHARE = 'share';

    public const MODE_SHARE_OVERRIDE = 'share_override';

    public const FAMILY_CATALOG = 'catalog';

    public const FAMILY_CUSTOMERS = 'customers';

    public const FAMILY_ORDERS = 'orders';

    public const FAMILY_CMS = 'cms';

    public const FAMILY_EMAIL = 'email';

    public const FAMILY_SETTINGS = 'settings';

    public const FAMILY_MODULES = 'modules';

    public const FAMILY_DISCOUNTS = 'discounts';

    public const PRESET_SAME = 'same';

    public const PRESET_EMPTY = 'empty';

    public const PRESET_CUSTOM = 'custom';

    /** @return list<string> */
    public static function families(): array
    {
        return [
            self::FAMILY_CATALOG,
            self::FAMILY_CUSTOMERS,
            self::FAMILY_ORDERS,
            self::FAMILY_CMS,
            self::FAMILY_EMAIL,
            self::FAMILY_SETTINGS,
            self::FAMILY_MODULES,
            self::FAMILY_DISCOUNTS,
        ];
    }

    /** @return list<string> */
    public static function modes(): array
    {
        return [self::MODE_OWN, self::MODE_SHARE, self::MODE_SHARE_OVERRIDE];
    }

    /**
     * @return array<string, array{mode: string, source: bool}>
     */
    public static function presetPolicies(string $preset): array
    {
        $own = ['mode' => self::MODE_OWN, 'source' => false];
        $share = ['mode' => self::MODE_SHARE, 'source' => true];
        $override = ['mode' => self::MODE_SHARE_OVERRIDE, 'source' => true];

        if ($preset === self::PRESET_SAME) {
            return [
                self::FAMILY_CATALOG => $override,
                self::FAMILY_CUSTOMERS => $share,
                self::FAMILY_ORDERS => $share,
                self::FAMILY_CMS => $own,
                self::FAMILY_EMAIL => $own,
                self::FAMILY_SETTINGS => $own,
                self::FAMILY_MODULES => $own,
                self::FAMILY_DISCOUNTS => $own,
            ];
        }

        $allOwn = [];
        foreach (self::families() as $family) {
            $allOwn[$family] = $own;
        }

        return $allOwn;
    }

    public static function familyLabel(string $family): string
    {
        return match ($family) {
            self::FAMILY_CATALOG => 'Catalog',
            self::FAMILY_CUSTOMERS => 'Customers',
            self::FAMILY_ORDERS => 'Orders',
            self::FAMILY_CMS => 'CMS',
            self::FAMILY_EMAIL => 'Email',
            self::FAMILY_SETTINGS => 'Settings',
            self::FAMILY_MODULES => 'Modules',
            self::FAMILY_DISCOUNTS => 'Discounts',
            default => $family,
        };
    }

    public static function familyHint(string $family): string
    {
        return match ($family) {
            self::FAMILY_CATALOG => 'Products and categories listed on this shop.',
            self::FAMILY_CUSTOMERS => 'Customer accounts. Same customers is also a shop-group switch.',
            self::FAMILY_ORDERS => 'Admin visibility only. Checkout always stamps this shop. See orders is also a shop-group switch.',
            self::FAMILY_CMS => 'Pages, blocks, and menus. Default is a copy you own.',
            self::FAMILY_EMAIL => 'Events and templates for this shop.',
            self::FAMILY_SETTINGS => 'Identity, theme, and configure values.',
            self::FAMILY_MODULES => 'Payment, shipping, and plugin enablement.',
            self::FAMILY_DISCOUNTS => 'Catalog discount rules and coupon codes.',
            default => '',
        };
    }

    public static function modeLabel(string $mode): string
    {
        return match ($mode) {
            self::MODE_OWN => 'Own',
            self::MODE_SHARE => 'Share from',
            self::MODE_SHARE_OVERRIDE => 'Share from + overrides',
            default => $mode,
        };
    }

    /**
     * @return list<array{value: string, label: string, hint: string}>
     */
    public static function modeOptions(): array
    {
        return [
            ['value' => self::MODE_OWN, 'label' => self::modeLabel(self::MODE_OWN), 'hint' => 'This shop has its own records.'],
            ['value' => self::MODE_SHARE, 'label' => self::modeLabel(self::MODE_SHARE), 'hint' => 'Use another shop records. Edits follow the source.'],
            ['value' => self::MODE_SHARE_OVERRIDE, 'label' => self::modeLabel(self::MODE_SHARE_OVERRIDE), 'hint' => 'Use the source, and hide or override rows here.'],
        ];
    }

    /**
     * @return list<array{value: string, label: string, hint: string}>
     */
    public static function presetOptions(): array
    {
        return [
            ['value' => self::PRESET_EMPTY, 'label' => 'Empty shop', 'hint' => 'No products, customers, orders, or copied settings. Clothes vs phones each start clean.'],
            ['value' => self::PRESET_SAME, 'label' => 'Share catalog', 'hint' => 'Use the source shop catalog. This shop can still hide or override items.'],
            ['value' => self::PRESET_CUSTOM, 'label' => 'Custom', 'hint' => 'Own everything. Fine-tune sharing after you save.'],
        ];
    }

    public static function importKeys(): array
    {
        return [
            'products', 'categories', 'brands', 'suppliers', 'features', 'attributes',
            'files', 'plans', 'warehouses', 'order_stages', 'customer_groups', 'addresses',
            'reviews', 'questions', 'cms', 'email', 'payment', 'shipping', 'webhooks', 'webservices',
            'catalog', 'methods',
        ];
    }

    /**
     * Optional imports when creating a shop. Customers, orders, and configure settings are never copied.
     *
     * @return list<array{value: string, label: string, hint: string}>
     */
    public static function importOptions(): array
    {
        return [
            ['value' => 'products', 'label' => 'Products', 'hint' => 'Same product rows. This shop is added on product_store.'],
            ['value' => 'categories', 'label' => 'Categories', 'hint' => 'Same category rows. This shop is added on category_store.'],
            ['value' => 'brands', 'label' => 'Brand', 'hint' => 'Same brand rows. Linked to this shop.'],
            ['value' => 'suppliers', 'label' => 'Supplier', 'hint' => 'Same supplier rows. Linked to this shop.'],
            ['value' => 'features', 'label' => 'Feature', 'hint' => 'Same feature rows. Linked to this shop.'],
            ['value' => 'attributes', 'label' => 'Attribute', 'hint' => 'Same attribute rows. Linked to this shop.'],
            ['value' => 'files', 'label' => 'Files', 'hint' => 'Same file library rows. Linked to this shop.'],
            ['value' => 'plans', 'label' => 'Subscription plans', 'hint' => 'Same plan rows. Linked to this shop.'],
            ['value' => 'warehouses', 'label' => 'Warehouses', 'hint' => 'Same warehouse rows. Linked to this shop.'],
            ['value' => 'order_stages', 'label' => 'Order statuses', 'hint' => 'Same status rows. Linked to this shop.'],
            ['value' => 'customer_groups', 'label' => 'Customer groups', 'hint' => 'Same group rows. Linked to this shop.'],
            ['value' => 'addresses', 'label' => 'Addresses', 'hint' => 'Same address rows. Linked to this shop.'],
            ['value' => 'reviews', 'label' => 'Reviews', 'hint' => 'Same review rows. Linked to this shop.'],
            ['value' => 'questions', 'label' => 'Questions', 'hint' => 'Same question rows. Linked to this shop.'],
            ['value' => 'cms', 'label' => 'CMS', 'hint' => 'Pages, blocks, and menus copied with this shop’s store_id.'],
            ['value' => 'email', 'label' => 'Email templates', 'hint' => 'Copied with this shop’s store_id.'],
            ['value' => 'payment', 'label' => 'Payment', 'hint' => 'Same methods. This shop is added on payment_method_store.'],
            ['value' => 'shipping', 'label' => 'Shipping', 'hint' => 'Same methods. This shop is added on shipping_method_store.'],
            ['value' => 'webhooks', 'label' => 'Webhooks', 'hint' => 'Copied for this shop (same URL, this shop’s store_id).'],
            ['value' => 'webservices', 'label' => 'Web services', 'hint' => 'New keys with the same name and scopes. Issue the secret from Web services.'],
        ];
    }
}
