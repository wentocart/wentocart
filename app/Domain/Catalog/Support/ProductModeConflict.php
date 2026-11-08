<?php

namespace App\Domain\Catalog\Support;

use Illuminate\Validation\ValidationException;

/**
 * Subscription and pre-order are mutually exclusive product modes.
 */
class ProductModeConflict
{
    public const MESSAGE = 'A product cannot be both a subscription product and a pre-order product. Disable one before enabling the other.';

    public static function assertExclusive(bool $subscriptionEnabled, bool $preorderEnabled): void
    {
        if (! $subscriptionEnabled || ! $preorderEnabled) {
            return;
        }

        throw ValidationException::withMessages([
            'subscription_enabled' => self::MESSAGE,
            'preorder_enabled' => self::MESSAGE,
        ]);
    }
}
