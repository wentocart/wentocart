<?php

namespace App\Domain\Settings;

/** @deprecated Prefer ProductSettings::listPayload() */
final class ProductListSettings
{
    public static function color(): string
    {
        return ProductSettings::color();
    }

    public static function durationMs(): int
    {
        return ProductSettings::durationMs();
    }

    /**
     * @return array{color: string, duration_ms: int}
     */
    public static function payload(): array
    {
        return ProductSettings::listPayload();
    }
}
