<?php

namespace App\Domain\Settings;

final class LoyaltySettings
{
    public static function enabled(): bool
    {
        return ShopSetting::bool('loyalty.enabled', false);
    }

    public static function earnPercent(): float
    {
        return max(0, min(100, (float) ShopSetting::get('loyalty.earn_percent', 1)));
    }

    public static function pointValue(): float
    {
        $value = (float) ShopSetting::get('loyalty.point_value', 0.01);

        return $value > 0 ? round($value, 4) : 0.01;
    }

    /** @return array<string, mixed> */
    public static function payload(): array
    {
        return [
            'enabled' => self::enabled(),
            'earn_percent' => self::earnPercent(),
            'point_value' => self::pointValue(),
        ];
    }

    /** @return array<string, mixed> */
    public static function storefrontPayload(): array
    {
        return self::payload();
    }
}
