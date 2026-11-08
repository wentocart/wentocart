<?php

namespace App\Domain\Settings;

final class InventorySettings
{
    /** @var list<string> */
    public const SCOPE_OPTIONS = ['global', 'warehouse'];

    /** @var list<string> Legacy values accepted and normalized. */
    public const LEGACY_SCOPE_OPTIONS = ['shop'];

    public static function enabled(): bool
    {
        return ShopSetting::bool('inventory.enabled', true);
    }

    public static function allowNegative(): bool
    {
        return ShopSetting::bool('inventory.allow_negative', false);
    }

    public static function notifications(): bool
    {
        return ShopSetting::bool('inventory.notifications', true);
    }

    public static function scope(): string
    {
        $raw = trim((string) ShopSetting::get('inventory.scope', 'global'));
        // "shop" was never a distinct stock counter (MULTISTORE: qty stays on warehouses).
        if ($raw === 'shop' || ! in_array($raw, self::SCOPE_OPTIONS, true)) {
            return 'global';
        }

        return $raw;
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        return [
            'enabled' => self::enabled(),
            'allow_negative' => self::allowNegative(),
            'notifications' => self::notifications(),
            'scope' => self::scope(),
            'linked' => [
                'low_stock_alert' => ProductSettings::defaultLowStockAlert(),
                'out_of_stock_behavior' => ProductSettings::outOfStockBehavior(),
                'allow_backorder' => ProductSettings::outOfStockBehavior() === 'allow',
                'manage_stock' => ProductSettings::defaultManageStock(),
                'reduce_stock_at' => OrderSettings::reduceStockAt(),
                'reserve_stock_unpaid' => OrderSettings::reserveStockUnpaid(),
                'restock_on_cancellation' => OrderSettings::restockOnCancellation(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function storefrontPayload(): array
    {
        return [
            'enabled' => self::enabled(),
            'allow_negative' => self::allowNegative(),
            'scope' => self::scope(),
        ];
    }
}
