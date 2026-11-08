<?php

namespace App\Domain\Payment\Support;

/**
 * Opaque checkout payment context passed from the storefront to the gateway.
 * Core validates shape/size only — providers interpret the keys.
 */
final class PaymentContext
{
    public const MAX_KEYS = 20;

    public const MAX_STRING = 255;

    public const MAX_DEPTH = 2;

    /**
     * @return array<string, mixed>
     */
    public static function validationRules(): array
    {
        return [
            'payment_context' => ['nullable', 'array'],
        ];
    }

    /**
     * @param  mixed  $raw
     * @return array<string, scalar|null>
     */
    public static function normalize(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        $count = 0;
        foreach ($raw as $key => $value) {
            if ($count >= self::MAX_KEYS) {
                break;
            }
            if (! is_string($key) || $key === '' || strlen($key) > 64) {
                continue;
            }
            if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key)) {
                continue;
            }
            $normalized = self::normalizeValue($value, 1);
            if ($normalized === null && $value !== null && $value !== '') {
                continue;
            }
            $out[$key] = $normalized;
            $count++;
        }

        return $out;
    }

    protected static function normalizeValue(mixed $value, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return null;
        }
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || strlen($trimmed) > self::MAX_STRING) {
                return null;
            }

            return $trimmed;
        }
        if (is_array($value)) {
            $nested = [];
            $count = 0;
            foreach ($value as $k => $v) {
                if ($count >= self::MAX_KEYS) {
                    break;
                }
                if (! is_string($k) || $k === '' || strlen($k) > 64) {
                    continue;
                }
                if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $k)) {
                    continue;
                }
                $child = self::normalizeValue($v, $depth + 1);
                if ($child === null && $v !== null && $v !== '') {
                    continue;
                }
                $nested[$k] = $child;
                $count++;
            }

            return $nested === [] ? null : $nested;
        }

        return null;
    }
}
