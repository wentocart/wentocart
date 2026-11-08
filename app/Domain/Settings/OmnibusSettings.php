<?php

namespace App\Domain\Settings;

final class OmnibusSettings
{
    public const WINDOW_DAYS = 30;

    public static function enabled(): bool
    {
        return ShopSetting::bool('commerce.omnibus_enabled', false);
    }

    public static function windowDays(): int
    {
        return self::WINDOW_DAYS;
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        return [
            'omnibus_enabled' => self::enabled(),
            'omnibus_window_days' => self::windowDays(),
        ];
    }
}
