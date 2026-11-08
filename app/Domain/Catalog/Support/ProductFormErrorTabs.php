<?php

namespace App\Domain\Catalog\Support;

/**
 * Maps product form / API validation keys to Product Edit tabs.
 * Keep in sync with resources/js/admin/Pages/Catalog/Products/productFormErrors.js
 */
final class ProductFormErrorTabs
{
    /** @var list<array{0: string, 1: string}> tab, regex */
    private const RULES = [
        ['seo', '/^(translations\.[^.]+\.)?(meta_|slug$|slug\.|canonical|robots|focus_keyword)/i'],
        ['subscription', '/^(subscription|purchase_option|translations\.[^.]+\.subscription_message)/i'],
        ['customizable', '/^customization/i'],
        ['preorder', '/^preorder/i'],
        ['gift_card', '/^gift_card/i'],
        ['service', '/^service/i'],
        ['rental', '/^rental/i'],
        ['combinations', '/^combination/i'],
        ['products', '/^components/i'],
        ['media', '/^(images|video_url|attachment)/i'],
        ['inventory', '/^(stock|warehouse|qty_|min_qty|max_qty|reserved|manage_stock|low_stock|critical_stock|sale_unit)/i'],
        ['shipping', '/^(length|width|height|weight|processing_time|delivery_|additional_shipping|shipping_method|available_all_carriers)/i'],
        ['pricing', '/^(price|sale_price|cost_price|tax_class|sale_starts|sale_ends|pricing_)/i'],
        ['details', '/^(sku|isbn|upc|barcode|mpn|gtin|supplier|condition|display_condition)/i'],
        ['attributes', '/^feature/i'],
        ['general', '/^(name|short_description|description|category|brand|cover|seller|type|is_enabled|available_for_order|show_price|translations)/i'],
    ];

    public static function tabForKey(string $key, array $availableTabs = []): ?string
    {
        $tab = null;
        foreach (self::RULES as [$id, $pattern]) {
            if (preg_match($pattern, $key)) {
                $tab = $id;
                break;
            }
        }

        if ($tab === null) {
            return null;
        }

        return self::resolveAvailable($tab, $availableTabs);
    }

    /**
     * @param  array<string, mixed>  $errors
     * @param  list<string>  $availableTabs
     * @return list<string>
     */
    public static function tabsForErrors(array $errors, array $availableTabs = []): array
    {
        $found = [];
        foreach (array_keys($errors) as $key) {
            $tab = self::tabForKey((string) $key, $availableTabs);
            if ($tab === null) {
                continue;
            }
            $found[$tab] = true;
        }

        if ($availableTabs !== []) {
            return array_values(array_filter($availableTabs, fn (string $tab) => isset($found[$tab])));
        }

        return array_keys($found);
    }

    /** @param  list<string>  $availableTabs */
    private static function resolveAvailable(string $tab, array $availableTabs): ?string
    {
        if ($availableTabs === []) {
            return $tab;
        }

        $set = array_fill_keys($availableTabs, true);
        if ($tab === 'products' && ! isset($set['products']) && isset($set['bundle'])) {
            return 'bundle';
        }
        if ($tab === 'combinations' && ! isset($set['combinations']) && isset($set['variants'])) {
            return 'variants';
        }
        if (! isset($set[$tab])) {
            return null;
        }

        return $tab;
    }
}
