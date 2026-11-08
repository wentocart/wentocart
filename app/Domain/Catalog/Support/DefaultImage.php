<?php

namespace App\Domain\Catalog\Support;

final class DefaultImage
{
    public const SM = 'sm';

    public const MD = 'md';

    public const LG = 'lg';

    public static function path(string $size = self::MD): string
    {
        $size = match ($size) {
            self::SM, self::MD, self::LG => $size,
            default => self::MD,
        };

        return '/images/default/p-'.$size.'-default.png';
    }

    public static function sizeForType(string $type): string
    {
        return match ($type) {
            'cart' => self::SM,
            'large' => self::LG,
            default => self::MD,
        };
    }
}
