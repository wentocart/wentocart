<?php

namespace App\Domain\Settings;

/**
 * Recommended global defaults for common shop purposes.
 * Apply on demand — never silently overwrite merchant tweaks.
 */
final class StorePurpose
{
    /** @var list<string> */
    public const OPTIONS = ['general', 'clothing', 'electronics', 'catalog', 'private'];

    public static function current(): string
    {
        $raw = trim((string) ShopSetting::get('store.purpose', 'general'));

        return in_array($raw, self::OPTIONS, true) ? $raw : 'general';
    }

    /**
     * @return array<string, array{label: string, hint: string}>
     */
    public static function catalog(): array
    {
        return [
            'general' => [
                'label' => 'General store',
                'hint' => 'Sell physical and digital products with guest checkout and catalog prices.',
            ],
            'clothing' => [
                'label' => 'Clothing / fashion',
                'hint' => 'Variants, reviews, compare, and a public storefront with guest checkout.',
            ],
            'electronics' => [
                'label' => 'Electronics',
                'hint' => 'Compare, reviews, strict stock, and sale / compare-at pricing.',
            ],
            'catalog' => [
                'label' => 'Catalog only',
                'hint' => 'Browse products without buying. Prices optional. No cart coupons.',
            ],
            'private' => [
                'label' => 'Private shop',
                'hint' => 'Customers must register and wait for approval. Guest checkout off.',
            ],
        ];
    }

    /**
     * @return array<string, bool|string>
     */
    public static function planned(string $purpose): array
    {
        if (! in_array($purpose, self::OPTIONS, true)) {
            $purpose = 'general';
        }

        return [
            'store.purpose' => $purpose,
            'products.catalog_mode' => $purpose === 'catalog',
            'products.default_available_for_order' => $purpose !== 'catalog',
            'products.compare_enabled' => in_array($purpose, ['clothing', 'electronics', 'general'], true),
            'customers.guest_checkout' => $purpose !== 'private' && $purpose !== 'catalog',
            'orders.guest_checkout' => $purpose !== 'private' && $purpose !== 'catalog',
            'customers.registration_enabled' => true,
            'customers.account_activation' => $purpose === 'private' ? 'admin_approval' : 'automatic',
            'checkout.coupons_enabled' => $purpose !== 'catalog',
            'inventory.enabled' => $purpose !== 'catalog',
            'reviews.enabled' => in_array($purpose, ['clothing', 'electronics', 'general'], true),
            'catalog.wishlist_enabled' => $purpose !== 'catalog',
            'returns.enabled' => in_array($purpose, ['clothing', 'electronics', 'general'], true),
            'pricing.sale_enabled' => $purpose !== 'catalog',
            'pricing.compare_at_enabled' => in_array($purpose, ['electronics', 'clothing', 'general'], true),
        ];
    }

    /**
     * @return list<array{key: string, from: mixed, to: mixed, changed: bool}>
     */
    public static function preview(string $purpose): array
    {
        $rows = [];
        foreach (self::planned($purpose) as $key => $to) {
            $from = ShopSetting::get($key, null);
            $rows[] = [
                'key' => $key,
                'from' => $from,
                'to' => $to,
                'changed' => json_encode($from) !== json_encode($to),
            ];
        }

        return $rows;
    }

    public static function apply(string $purpose): void
    {
        foreach (self::planned($purpose) as $key => $value) {
            ShopSetting::put($key, $value);
        }
    }
}
