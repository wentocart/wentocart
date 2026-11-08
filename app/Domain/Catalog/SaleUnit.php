<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Settings\ProductSettings;

/**
 * How the qty box is measured. Orthogonal to product type.
 *
 * Type = simple / combination / rental / virtual.
 * Sale unit = piece / kg / metre / m² / litre.
 */
final class SaleUnit
{
    public const PIECE = 'piece';

    public const WEIGHT = 'weight';

    public const LENGTH = 'length';

    public const AREA = 'area';

    public const VOLUME = 'volume';

    /** @var list<string> */
    public const TYPES = [self::PIECE, self::WEIGHT, self::LENGTH, self::AREA, self::VOLUME];

    /** @var array<string, list<string>> */
    public const CODES = [
        self::PIECE => ['pc'],
        self::WEIGHT => ['kg', 'g', 'lb', 'oz'],
        self::LENGTH => ['m', 'cm', 'mm', 'ft', 'in', 'yd'],
        self::AREA => ['m2', 'ft2'],
        self::VOLUME => ['l', 'ml', 'gal'],
    ];

    /** @var array<string, string> */
    public const LABELS = [
        'pc' => 'piece',
        'kg' => 'kg',
        'g' => 'g',
        'lb' => 'lb',
        'oz' => 'oz',
        'm' => 'm',
        'cm' => 'cm',
        'mm' => 'mm',
        'ft' => 'ft',
        'in' => 'in',
        'yd' => 'yd',
        'm2' => 'm²',
        'ft2' => 'ft²',
        'l' => 'L',
        'ml' => 'ml',
        'gal' => 'gal',
    ];

    /** @var array<string, float> */
    public const TO_KG = [
        'kg' => 1.0,
        'g' => 0.001,
        'lb' => 0.45359237,
        'oz' => 0.028349523125,
    ];

    public static function allowedForType(?string $productType): bool
    {
        return in_array($productType, ['simple', 'combination'], true);
    }

    /**
     * @return array{sale_unit: string, sale_unit_code: string}
     */
    public static function normalizeInput(?string $productType, ?string $unit, ?string $code): array
    {
        if (! self::allowedForType($productType)) {
            return ['sale_unit' => self::PIECE, 'sale_unit_code' => 'pc'];
        }

        $unit = in_array($unit, self::TYPES, true) ? $unit : self::PIECE;
        $allowed = self::CODES[$unit];
        $code = strtolower(trim((string) $code));
        if (! in_array($code, $allowed, true)) {
            $code = match ($unit) {
                self::WEIGHT => strtolower((string) (ProductSettings::shopWeightUnit() ?: 'kg')),
                self::LENGTH => 'm',
                self::AREA => 'm2',
                self::VOLUME => 'l',
                default => 'pc',
            };
            if (! in_array($code, $allowed, true)) {
                $code = $allowed[0];
            }
        }

        return ['sale_unit' => $unit, 'sale_unit_code' => $code];
    }

    public static function typeFor(Product $product): string
    {
        if (! self::allowedForType($product->type)) {
            return self::PIECE;
        }

        $unit = (string) ($product->sale_unit ?: self::PIECE);

        return in_array($unit, self::TYPES, true) ? $unit : self::PIECE;
    }

    public static function isPiece(Product $product): bool
    {
        return self::typeFor($product) === self::PIECE;
    }

    public static function codeFor(Product $product): string
    {
        $unit = self::typeFor($product);
        $code = strtolower(trim((string) ($product->sale_unit_code ?: '')));
        $allowed = self::CODES[$unit];

        return in_array($code, $allowed, true) ? $code : $allowed[0];
    }

    public static function label(Product $product): string
    {
        $code = self::codeFor($product);

        return self::LABELS[$code] ?? $code;
    }

    public static function decimals(Product $product): int
    {
        return self::isPiece($product) ? 0 : 3;
    }

    public static function snapQty(Product $product, float $qty): float
    {
        $decimals = self::decimals($product);
        $rules = \App\Domain\Settings\ProductSettings::quantityRules($product);
        $min = (float) $rules['min'];
        $step = (float) $rules['step'];
        if (! self::isPiece($product) && $min < 0.001) {
            $min = 0.001;
        }
        if ($step <= 0) {
            $step = self::isPiece($product) ? 1.0 : $min;
        }

        $qty = max($min, $qty);
        $steps = (int) ceil(round(($qty - $min) / $step, 6));
        $qty = round($min + ($steps * $step), $decimals);
        $max = $rules['max'];
        if ($max !== null && $qty > (float) $max) {
            $down = (int) floor(round(((float) $max - $min) / $step, 6));
            $qty = round($min + (max(0, $down) * $step), $decimals);
        }

        return max($min, round($qty, $decimals));
    }

    public static function convertWeight(float $value, ?string $from, ?string $to): float
    {
        $from = strtolower((string) ($from ?: 'kg'));
        $to = strtolower((string) ($to ?: 'kg'));
        if ($from === $to) {
            return $value;
        }

        $kg = $value * (self::TO_KG[$from] ?? 1.0);
        $div = self::TO_KG[$to] ?? 1.0;

        return $div > 0 ? $kg / $div : $kg;
    }

    /**
     * Cart/shipping weight in the shop weight unit.
     *
     * Sold by weight: qty is the goods weight; product.weight is extra packaging per ordered unit.
     * Sold by length/area/volume or piece: product.weight is the shipping weight of one sale unit.
     */
    public static function lineShippingWeight(Product $product, float $qty, ?float $variantWeight = null): float
    {
        $shop = strtolower((string) (ProductSettings::shopWeightUnit() ?: 'kg'));
        $class = strtolower((string) ($product->weight_class ?: $shop));
        $package = $variantWeight !== null ? (float) $variantWeight : (float) ($product->weight ?? 0);

        if (self::typeFor($product) === self::WEIGHT) {
            $goods = self::convertWeight($qty, self::codeFor($product), $shop);
            $tare = self::convertWeight($package, $class, $shop);

            return round($goods + ($tare * $qty), 3);
        }

        $perUnit = self::convertWeight($package, $class, $shop);

        return round($perUnit * $qty, 3);
    }

    /**
     * @return array{sale_unit: string, sale_unit_code: string, sale_unit_label: string, qty_decimals: int}
     */
    public static function present(Product $product): array
    {
        return [
            'sale_unit' => self::typeFor($product),
            'sale_unit_code' => self::codeFor($product),
            'sale_unit_label' => self::label($product),
            'qty_decimals' => self::decimals($product),
        ];
    }
}
