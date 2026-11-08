<?php

namespace App\Domain\Localization;

use App\Domain\Localization\Models\Currency;
use App\Domain\Settings\PricingSettings;
use Illuminate\Support\Facades\Cache;

class Money
{
    /** @var array<string, Currency|null> */
    protected static array $currencyMemo = [];

    protected static ?string $defaultCodeMemo = null;

    protected static ?string $currentCodeMemo = null;

    public static function defaultCurrency(): ?Currency
    {
        $code = self::defaultCode();

        return $code ? self::find($code) : null;
    }

    public static function defaultCode(): string
    {
        if (self::$defaultCodeMemo !== null) {
            return self::$defaultCodeMemo;
        }

        self::$defaultCodeMemo = Cache::remember('wento.currency.default_code', 60, function () {
            $currency = Currency::query()->where('is_default', true)->where('is_enabled', true)->first()
                ?? Currency::query()->where('is_enabled', true)->orderBy('position')->first()
                ?? Currency::query()->where('code', config('wento.default_currency', 'USD'))->first();

            return $currency?->code ?? (string) config('wento.default_currency', 'USD');
        });

        return self::$defaultCodeMemo;
    }

    public static function find(string $code): ?Currency
    {
        $code = strtoupper($code);
        if (array_key_exists($code, self::$currencyMemo)) {
            return self::$currencyMemo[$code];
        }

        return self::$currencyMemo[$code] = Currency::query()->where('code', $code)->first();
    }

    public static function currentCode(): string
    {
        if (self::$currentCodeMemo !== null) {
            return self::$currentCodeMemo;
        }
        if (app()->bound('wento.currency')) {
            $code = app('wento.currency');
            if (is_string($code) && $code !== '') {
                return self::$currentCodeMemo = strtoupper($code);
            }
        }

        return self::$currentCodeMemo = self::defaultCode();
    }

    public static function format(float|int|string|null $amount, ?string $code = null): string
    {
        $currency = $code ? self::find($code) : self::find(self::currentCode());
        $code = $currency?->code ?? ($code ?: self::currentCode());
        $decimals = PricingSettings::decimalPrecision();
        $amount = PricingSettings::roundDisplay((float) $amount);
        $symbol = $currency?->symbol ?? $code;
        $position = $currency?->symbol_position ?? 'before';
        $value = number_format($amount, $decimals, '.', ',');

        return $position === 'after' ? "{$value} {$symbol}" : "{$symbol}{$value}";
    }

    /**
     * Convert using exchange_rate relative to the store default (default rate = 1).
     * Rate means units of that currency per 1 unit of the default (USD=1, INR=83).
     * amount_in_to = amount_in_from * (to.rate / from.rate)
     */
    public static function convert(float $amount, string $fromCode, string $toCode): float
    {
        $decimals = 2;
        $to = self::find($toCode);
        if ($to) {
            $decimals = (int) ($to->decimal_places ?? 2);
        }
        if (strtoupper($fromCode) === strtoupper($toCode)) {
            return round($amount, $decimals);
        }

        $from = self::find($fromCode);
        $fromRate = (float) ($from?->exchange_rate ?: 1);
        $toRate = (float) ($to?->exchange_rate ?: 1);
        if ($fromRate <= 0 || $toRate <= 0) {
            return round($amount, $decimals);
        }

        return round($amount * ($toRate / $fromRate), $decimals);
    }

    public static function fromDefault(float $amount, ?string $toCode = null): float
    {
        return self::convert($amount, self::defaultCode(), $toCode ?: self::currentCode());
    }

    public static function toDefault(float $amount, ?string $fromCode = null): float
    {
        return self::convert($amount, $fromCode ?: self::currentCode(), self::defaultCode());
    }

    /**
     * Rewrite default-currency amounts in storefront copy ("From $24") for the active currency.
     * Stored theme text stays in the default currency.
     */
    public static function convertDisplayCopy(mixed $value): mixed
    {
        if (self::currentCode() === self::defaultCode()) {
            return $value;
        }

        return self::walkDisplayCopy($value);
    }

    protected static function walkDisplayCopy(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = self::walkDisplayCopy($item);
            }

            return $out;
        }

        if (! is_string($value) || $value === '') {
            return $value;
        }

        if (preg_match('#^(https?:)?/|^\#|^[a-z][a-z0-9+.-]*://#i', $value)) {
            return $value;
        }

        $symbol = self::defaultCurrency()?->symbol ?: '$';
        if ($symbol === '' || ! str_contains($value, $symbol)) {
            return $value;
        }

        $pattern = '/'.preg_quote($symbol, '/').'\s*([0-9][0-9,]*(?:\.[0-9]+)?)/u';

        return preg_replace_callback($pattern, function (array $match): string {
            $amount = (float) str_replace(',', '', $match[1]);

            return self::format(self::fromDefault($amount));
        }, $value) ?? $value;
    }

    public static function rateFor(?string $code): float
    {
        if (! $code) {
            return 1.0;
        }

        return (float) (self::find($code)?->exchange_rate ?: 1);
    }

    public static function forgetCache(): void
    {
        self::$currencyMemo = [];
        self::$defaultCodeMemo = null;
        self::$currentCodeMemo = null;
        Cache::forget('wento.currency.default_code');
        Cache::forget('wento.currency.default'); // legacy key from earlier build
    }
}
