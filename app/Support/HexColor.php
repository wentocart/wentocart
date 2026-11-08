<?php

namespace App\Support;

final class HexColor
{
    /** @var array<string, string> */
    public const TONES = [
        'primary' => '#7C3AED',
        'info' => '#0284C7',
        'warning' => '#D97706',
        'success' => '#16A34A',
        'danger' => '#DC2626',
        'neutral' => '#737373',
    ];

    public static function parse(mixed $value, string $fallback = '#7C3AED'): string
    {
        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return strtoupper($fallback);
        }
        if (isset(self::TONES[$raw])) {
            return self::TONES[$raw];
        }
        if (preg_match('/^#?([0-9a-f]{6})$/', $raw, $match)) {
            return '#'.strtoupper($match[1]);
        }
        if (preg_match('/^#?([0-9a-f]{3})$/', $raw, $match)) {
            $h = $match[1];

            return '#'.strtoupper($h[0].$h[0].$h[1].$h[1].$h[2].$h[2]);
        }

        return strtoupper($fallback);
    }

    public static function isValid(mixed $value): bool
    {
        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return false;
        }
        if (isset(self::TONES[$raw])) {
            return true;
        }

        return (bool) preg_match('/^#?[0-9a-f]{3}([0-9a-f]{3})?$/', $raw);
    }

    /**
     * @return list<mixed>
     */
    public static function rules(bool $required = true): array
    {
        $check = function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }
            if (! self::isValid($value)) {
                $fail('Enter a 6-digit hex color.');
            }
        };
        $base = ['string', 'max:32', $check];

        return $required ? array_merge(['required'], $base) : array_merge(['nullable'], $base);
    }
}
