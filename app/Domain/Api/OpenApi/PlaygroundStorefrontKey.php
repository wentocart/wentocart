<?php

namespace App\Domain\Api\OpenApi;

/**
 */
final class PlaygroundStorefrontKey
{
    public static function autoInjectEnabled(): bool
    {
        return (bool) config('wento.api_playground.auto_inject_storefront_key', false);
    }

    public static function key(): string
    {
        return trim((string) config('wento.api_playground.storefront_key', ''));
    }

    /**
     * Key to inject into playground requests, or empty when disabled / unset.
     */
    public static function injectKey(): string
    {
        if (! self::autoInjectEnabled()) {
            return '';
        }

        return self::key();
    }
}
