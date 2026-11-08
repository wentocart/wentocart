<?php

namespace App\Domain\Localization\Services;

use App\Domain\Localization\Models\Currency;
use App\Domain\Localization\Money;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CurrencyRateSyncService
{
    /**
     * Synchronize foreign exchange rates for all enabled currencies against the default store currency.
     *
     * @param  array<string, float>|null  $manualRates  Optional custom rates for offline/testing use
     * @return array{ok: bool, base: string, updated: int, rates: array<string, float>, message: string}
     */
    public function sync(?array $manualRates = null): array
    {
        $base = Currency::query()->where('is_default', true)->first()
            ?: Currency::query()->where('code', Money::defaultCode())->first()
            ?: Currency::query()->first();

        if (! $base) {
            return [
                'ok' => false,
                'base' => 'USD',
                'updated' => 0,
                'rates' => [],
                'message' => 'No currencies defined.',
            ];
        }

        $baseCode = strtoupper((string) $base->code);
        $rates = $manualRates ?? $this->fetchLiveRates($baseCode);

        if ($rates === []) {
            return [
                'ok' => false,
                'base' => $baseCode,
                'updated' => 0,
                'rates' => [],
                'message' => 'Could not fetch live exchange rates.',
            ];
        }

        $currencies = Currency::query()->where('is_enabled', true)->get();
        $updated = 0;
        $applied = [];

        foreach ($currencies as $currency) {
            $code = strtoupper((string) $currency->code);
            if ($code === $baseCode) {
                $currency->update(['exchange_rate' => 1.000000]);
                $applied[$code] = 1.0;
                continue;
            }

            if (isset($rates[$code]) && is_numeric($rates[$code]) && (float) $rates[$code] > 0) {
                $rate = round((float) $rates[$code], 6);
                $currency->update(['exchange_rate' => $rate]);
                $applied[$code] = $rate;
                $updated++;
            }
        }

        Money::forgetCache();

        return [
            'ok' => true,
            'base' => $baseCode,
            'updated' => $updated,
            'rates' => $applied,
            'message' => "Successfully updated {$updated} currency exchange rates.",
        ];
    }

    /**
     * Fetch live rates using public, keyless European Central Bank feeds.
     *
     * @return array<string, float>
     */
    protected function fetchLiveRates(string $baseCode): array
    {
        try {
            // Primary provider: Frankfurter API (European Central Bank data, keyless, CORS enabled)
            $response = Http::timeout(6)->get("https://api.frankfurter.app/latest?from={$baseCode}");
            if ($response->successful()) {
                $json = $response->json();
                if (isset($json['rates']) && is_array($json['rates'])) {
                    return $json['rates'];
                }
            }

            // Fallback provider: Open ER-API (keyless, daily update)
            $fallback = Http::timeout(6)->get("https://open.er-api.com/v6/latest/{$baseCode}");
            if ($fallback->successful()) {
                $json = $fallback->json();
                if (isset($json['rates']) && is_array($json['rates'])) {
                    return $json['rates'];
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Currency rate sync transport error: {$e->getMessage()}");
        }

        return [];
    }
}
