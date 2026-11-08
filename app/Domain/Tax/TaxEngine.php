<?php

namespace App\Domain\Tax;

use App\Domain\Customer\Models\Customer;
use App\Domain\Localization\Models\Country;
use App\Domain\Localization\Models\CountryRegion;
use App\Domain\Settings\CustomerSettings;
use App\Domain\Tax\Models\TaxRule;

class TaxEngine
{
    /**
     * @return array{rate: float, rule_id: ?int, rate_id: ?int, zone_id: ?int, name: ?string}
     */
    public function resolve(?int $taxClassId, ?string $countryCode = null, ?string $regionCode = null, ?Customer $customer = null): array
    {
        $empty = ['rate' => 0.0, 'rule_id' => null, 'rate_id' => null, 'zone_id' => null, 'name' => null];

        if (! $taxClassId) {
            return $empty;
        }

        if ($this->customerIsExempt($customer)) {
            return $empty;
        }

        $country = $countryCode
            ? Country::query()->where('code', strtoupper($countryCode))->where('is_enabled', true)->first()
            : null;

        $region = ($country && $regionCode)
            ? CountryRegion::query()
                ->where('country_id', $country->id)
                ->where('code', strtoupper($regionCode))
                ->where('is_enabled', true)
                ->first()
            : null;

        $rules = TaxRule::query()
            ->with(['zone.regions', 'taxRate', 'country'])
            ->where('tax_class_id', $taxClassId)
            ->where('is_enabled', true)
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            if (! $this->zoneMatches($rule, $country, $region)) {
                continue;
            }

            $rate = $rule->resolvedRate();

            return [
                'rate' => $rate,
                'rule_id' => $rule->id,
                'rate_id' => $rule->tax_rate_id,
                'zone_id' => $rule->tax_zone_id,
                'name' => $rule->taxRate?->name ?? $rule->name,
            ];
        }

        return $empty;
    }

    /**
     * Every matching rule for a class/geo, lowest priority first so compound layers stack.
     *
     * @return list<array{rate: float, rule_id: ?int, rate_id: ?int, zone_id: ?int, name: ?string, is_compound: bool}>
     */
    public function resolveLayers(?int $taxClassId, ?string $countryCode = null, ?string $regionCode = null, ?Customer $customer = null): array
    {
        if (! $taxClassId || $this->customerIsExempt($customer)) {
            return [];
        }

        $country = $countryCode
            ? Country::query()->where('code', strtoupper($countryCode))->where('is_enabled', true)->first()
            : null;

        $region = ($country && $regionCode)
            ? CountryRegion::query()
                ->where('country_id', $country->id)
                ->where('code', strtoupper($regionCode))
                ->where('is_enabled', true)
                ->first()
            : null;

        $rules = TaxRule::query()
            ->with(['zone.regions', 'taxRate', 'country'])
            ->where('tax_class_id', $taxClassId)
            ->where('is_enabled', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        $layers = [];
        foreach ($rules as $rule) {
            if (! $this->zoneMatches($rule, $country, $region)) {
                continue;
            }
            $layers[] = [
                'rate' => $rule->resolvedRate(),
                'rule_id' => $rule->id,
                'rate_id' => $rule->tax_rate_id,
                'zone_id' => $rule->tax_zone_id,
                'name' => $rule->taxRate?->name ?? $rule->name,
                'is_compound' => (bool) $rule->is_compound,
            ];
        }

        return $layers;
    }

    /**
     * @param  list<array{rate: float, name: ?string, is_compound?: bool}>  $layers
     * @return array{tax: float, details: array<string, float>, effective_rate: float}
     */
    public function applyLayers(float $amount, array $layers, bool $inclusive = false): array
    {
        $tax = 0.0;
        $details = [];
        $running = $amount;
        foreach ($layers as $layer) {
            $rate = (float) ($layer['rate'] ?? 0);
            if ($rate <= 0 || $amount <= 0) {
                continue;
            }
            $base = ! empty($layer['is_compound']) ? $running : $amount;
            $line = $inclusive
                ? round($base * $rate / (100 + $rate), 2)
                : round($base * ($rate / 100), 2);
            $tax += $line;
            $running += $line;
            $key = ($layer['name'] ?? 'Tax').'|'.$rate;
            $details[$key] = ($details[$key] ?? 0) + $line;
        }

        return [
            'tax' => round($tax, 2),
            'details' => $details,
            'effective_rate' => $amount > 0 ? round(($tax / $amount) * 100, 4) : 0.0,
        ];
    }

    protected function customerIsExempt(?Customer $customer): bool
    {
        if (! $customer) {
            return false;
        }

        return (bool) CustomerSettings::customerGroup($customer)?->tax_exempt;
    }

    protected function zoneMatches(TaxRule $rule, ?Country $country, ?CountryRegion $region): bool
    {
        // Legacy: rule has country_id but no zone yet
        if (! $rule->tax_zone_id) {
            if (! $rule->country_id) {
                return true;
            }

            return $country && (int) $rule->country_id === (int) $country->id;
        }

        $zone = $rule->zone;
        if (! $zone || ! $zone->is_enabled) {
            return false;
        }

        // Worldwide zone
        if (! $zone->country_id) {
            return true;
        }

        if (! $country || (int) $zone->country_id !== (int) $country->id) {
            return false;
        }

        $regionIds = $zone->regions->pluck('id');
        if ($regionIds->isEmpty()) {
            return true;
        }

        return $region && $regionIds->contains($region->id);
    }
}
