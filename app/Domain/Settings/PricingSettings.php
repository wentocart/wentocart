<?php

namespace App\Domain\Settings;

use App\Domain\Store\ShopIdentity;
use App\Domain\Tax\TaxEngine;

final class PricingSettings
{
    /** @var list<string> */
    public const DISPLAY_OPTIONS = ['with_tax', 'without_tax', 'both'];

    /** @var list<string> */
    public const ZERO_PRICE_OPTIONS = ['allow', 'hide', 'contact'];

    /** @var list<string> */
    public const ROUNDING_OPTIONS = ['none', 'nearest', 'up', 'down'];

    public static function taxInclusiveInput(): bool
    {
        return ShopSetting::bool('pricing.tax_inclusive_input', false);
    }

    public static function shippingTax(): bool
    {
        return ShopSetting::bool('tax.shipping', true);
    }

    public static function storefrontDisplay(): string
    {
        $raw = trim((string) ShopSetting::get('pricing.storefront_display', 'with_tax'));

        return in_array($raw, self::DISPLAY_OPTIONS, true) ? $raw : 'with_tax';
    }

    public static function decimalPrecision(): int
    {
        $value = (int) ShopSetting::get('pricing.decimal_precision', 2);

        return max(0, min(4, $value));
    }

    public static function rounding(): string
    {
        $raw = trim((string) ShopSetting::get('pricing.rounding', 'none'));

        return in_array($raw, self::ROUNDING_OPTIONS, true) ? $raw : 'none';
    }

    public static function zeroPrice(): string
    {
        $raw = trim((string) ShopSetting::get('pricing.zero_price', 'allow'));

        return in_array($raw, self::ZERO_PRICE_OPTIONS, true) ? $raw : 'allow';
    }

    public static function salePricing(): bool
    {
        return ShopSetting::bool('pricing.sale_enabled', true);
    }

    public static function compareAtEnabled(): bool
    {
        return ShopSetting::bool('pricing.compare_at_enabled', true);
    }

    /**
     * True when a catalog amount must not be sold (Contact us for price).
     */
    public static function blocksZeroPricePurchase(float $amount): bool
    {
        return self::zeroPrice() === 'contact' && $amount <= 0;
    }

    public static function roundDisplay(float $amount): float
    {
        $decimals = self::decimalPrecision();
        $factor = 10 ** $decimals;

        return match (self::rounding()) {
            'up' => $factor > 0 ? ceil($amount * $factor - 1e-9) / $factor : $amount,
            'down' => $factor > 0 ? floor($amount * $factor + 1e-9) / $factor : $amount,
            'nearest' => round($amount, $decimals),
            // "none" = standard half-up to shop precision (same as nearest; no up/down bias).
            default => round($amount, $decimals),
        };
    }

    /**
     * Money for cart/order columns: apply shop rounding, then cap at 2 places for storage.
     */
    public static function roundMoney(float $amount): float
    {
        $rounded = self::roundDisplay($amount);
        $persist = min(2, self::decimalPrecision());

        return round($rounded, $persist);
    }

    /**
     * Split a catalog unit amount into excl/incl for storefront display using the shop country tax rules.
     *
     * @return array{
     *   amount: float,
     *   amount_excl: float,
     *   amount_incl: float,
     *   tax: float,
     *   display: string
     * }
     */
    public static function catalogDisplayAmounts(float $catalogAmount, ?int $taxClassId, ?string $countryCode = null, ?string $regionCode = null): array
    {
        $amount = max(0.0, $catalogAmount);
        $country = $countryCode;
        if ($country === null || $country === '') {
            $country = strtoupper(trim((string) (ShopIdentity::get()['country_code'] ?? ''))) ?: null;
        }
        $inclusive = self::taxInclusiveInput();
        $layers = app(TaxEngine::class)->resolveLayers($taxClassId, $country, $regionCode);
        $applied = app(TaxEngine::class)->applyLayers($amount, $layers, $inclusive);
        $tax = (float) $applied['tax'];

        if ($inclusive) {
            $incl = $amount;
            $excl = max(0.0, round($amount - $tax, 4));
        } else {
            $excl = $amount;
            $incl = round($amount + $tax, 4);
        }

        $display = self::storefrontDisplay();
        // Main price follows storefront display: excl-only → excl; with-tax / both → incl (payable).
        $primary = $display === 'without_tax' ? $excl : $incl;

        return [
            'amount' => self::roundDisplay($primary),
            'amount_excl' => self::roundDisplay($excl),
            'amount_incl' => self::roundDisplay($incl),
            'tax' => self::roundDisplay($tax),
            'display' => $display,
        ];
    }

    /**
     * True when storefront main prices should show amounts that include tax.
     */
    public static function storefrontShowsInclusive(): bool
    {
        return self::storefrontDisplay() !== 'without_tax';
    }

    /**
     * Map a catalog (input) money amount into the storefront display space for the same tax class.
     */
    public static function toStorefrontAmount(float $catalogAmount, ?int $taxClassId, ?string $countryCode = null, ?string $regionCode = null): float
    {
        $bundle = self::catalogDisplayAmounts($catalogAmount, $taxClassId, $countryCode, $regionCode);

        return (float) $bundle['amount'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        return array_merge([
            'tax_inclusive_input' => self::taxInclusiveInput(),
            'storefront_display' => self::storefrontDisplay(),
            'decimal_precision' => self::decimalPrecision(),
            'rounding' => self::rounding(),
            'zero_price' => self::zeroPrice(),
            'sale_enabled' => self::salePricing(),
            'compare_at_enabled' => self::compareAtEnabled(),
            'shipping_tax' => self::shippingTax(),
        ], OmnibusSettings::payload());
    }

    /**
     * @return array<string, mixed>
     */
    public static function storefrontPayload(): array
    {
        return self::payload();
    }
}
