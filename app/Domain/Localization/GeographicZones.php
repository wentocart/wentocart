<?php

namespace App\Domain\Localization;

use App\Domain\Localization\Models\Country;
use App\Domain\Shipping\Models\ShippingZone;

/**
 * Default geographic zones, one per continent, with every world country placed in exactly one.
 * Countries already given a zone are left alone. Countries that were not installed yet are added
 * switched off, so checkout keeps the countries the merchant already enabled.
 */
final class GeographicZones
{
    /** @var array<string, string> */
    public const ZONES = [
        'AF' => 'Africa',
        'AN' => 'Antarctica',
        'AS' => 'Asia',
        'EU' => 'Europe',
        'NA' => 'North America',
        'OC' => 'Oceania',
        'SA' => 'South America',
    ];

    public static function ensure(): void
    {
        $zoneIds = [];
        $position = 1;
        foreach (self::ZONES as $name) {
            $zone = ShippingZone::query()->firstOrCreate(
                ['name' => $name],
                ['is_enabled' => true, 'position' => $position]
            );
            $zoneIds[$name] = $zone->id;
            $position++;
        }

        $existing = Country::query()->get(['id', 'code', 'zone_id'])->keyBy('code');
        $nextPosition = (int) Country::query()->max('position');
        $now = now();
        $inserts = [];
        $assign = [];

        foreach (WorldCountries::all() as $row) {
            $continent = strtoupper((string) ($row['continent'] ?? ''));
            $zoneName = self::ZONES[$continent] ?? null;
            $zoneId = $zoneName ? ($zoneIds[$zoneName] ?? null) : null;
            $code = $row['code'];
            $current = $existing->get($code);

            if (! $current) {
                $nextPosition++;
                $calling = trim(explode(' and ', (string) ($row['calling_code'] ?? ''))[0]);
                $inserts[] = [
                    'code' => $code,
                    'iso3' => $row['iso3'],
                    'name' => $row['name'],
                    'calling_code' => $calling !== '' ? mb_substr($calling, 0, 8) : null,
                    'default_currency_code' => $row['default_currency_code'],
                    'has_regions' => false,
                    'is_enabled' => false,
                    'is_default' => false,
                    'position' => $nextPosition,
                    'zone_id' => $zoneId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                continue;
            }

            if ($current->zone_id === null && $zoneId) {
                $assign[$zoneId][] = $code;
            }
        }

        foreach (array_chunk($inserts, 100) as $chunk) {
            Country::query()->insert($chunk);
        }

        foreach ($assign as $zoneId => $codes) {
            Country::query()->whereIn('code', $codes)->whereNull('zone_id')->update(['zone_id' => $zoneId]);
        }
    }
}
