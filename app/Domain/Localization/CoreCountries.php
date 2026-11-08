<?php

namespace App\Domain\Localization;

/**
 * Built-in core countries shipped with a clean install.
 * Packs (IN.json, US.json, …) add regions, tax, and richer meta on top.
 */
final class CoreCountries
{
    public static function definitions(): array
    {
        return [
            ['code' => 'IN', 'iso3' => 'IND', 'name' => 'India', 'calling_code' => '+91', 'default_currency_code' => 'INR', 'timezone' => 'Asia/Kolkata', 'address_format' => 'in', 'postal_code_label' => 'PIN Code', 'tax_id_label' => 'GSTIN', 'has_regions' => true, 'position' => 1],
            ['code' => 'US', 'iso3' => 'USA', 'name' => 'United States', 'calling_code' => '+1', 'default_currency_code' => 'USD', 'timezone' => 'America/New_York', 'address_format' => 'us', 'postal_code_label' => 'ZIP Code', 'tax_id_label' => 'EIN', 'has_regions' => true, 'position' => 2],
            ['code' => 'GB', 'iso3' => 'GBR', 'name' => 'United Kingdom', 'calling_code' => '+44', 'default_currency_code' => 'GBP', 'timezone' => 'Europe/London', 'address_format' => 'gb', 'postal_code_label' => 'Postcode', 'tax_id_label' => 'VAT Number', 'has_regions' => false, 'position' => 3],
            ['code' => 'FR', 'iso3' => 'FRA', 'name' => 'France', 'calling_code' => '+33', 'default_currency_code' => 'EUR', 'timezone' => 'Europe/Paris', 'address_format' => 'eu', 'postal_code_label' => 'Postal code', 'tax_id_label' => 'VAT Number', 'has_regions' => false, 'position' => 4],
            ['code' => 'DE', 'iso3' => 'DEU', 'name' => 'Germany', 'calling_code' => '+49', 'default_currency_code' => 'EUR', 'timezone' => 'Europe/Berlin', 'address_format' => 'eu', 'postal_code_label' => 'Postal code', 'tax_id_label' => 'VAT Number', 'has_regions' => false, 'position' => 5],
            ['code' => 'AE', 'iso3' => 'ARE', 'name' => 'United Arab Emirates', 'calling_code' => '+971', 'default_currency_code' => 'AED', 'timezone' => 'Asia/Dubai', 'address_format' => 'ae', 'postal_code_label' => 'PO Box', 'tax_id_label' => 'TRN', 'has_regions' => true, 'position' => 6],
            ['code' => 'AU', 'iso3' => 'AUS', 'name' => 'Australia', 'calling_code' => '+61', 'default_currency_code' => 'AUD', 'timezone' => 'Australia/Sydney', 'address_format' => 'au', 'postal_code_label' => 'Postcode', 'tax_id_label' => 'ABN', 'has_regions' => true, 'position' => 7],
            ['code' => 'CA', 'iso3' => 'CAN', 'name' => 'Canada', 'calling_code' => '+1', 'default_currency_code' => 'CAD', 'timezone' => 'America/Toronto', 'address_format' => 'ca', 'postal_code_label' => 'Postal code', 'tax_id_label' => 'BN', 'has_regions' => true, 'position' => 8],
            ['code' => 'SG', 'iso3' => 'SGP', 'name' => 'Singapore', 'calling_code' => '+65', 'default_currency_code' => 'SGD', 'timezone' => 'Asia/Singapore', 'address_format' => 'sg', 'postal_code_label' => 'Postal code', 'tax_id_label' => 'UEN', 'has_regions' => false, 'position' => 9],
            ['code' => 'NL', 'iso3' => 'NLD', 'name' => 'Netherlands', 'calling_code' => '+31', 'default_currency_code' => 'EUR', 'timezone' => 'Europe/Amsterdam', 'address_format' => 'eu', 'postal_code_label' => 'Postal code', 'tax_id_label' => 'VAT Number', 'has_regions' => false, 'position' => 10],
            ['code' => 'JP', 'iso3' => 'JPN', 'name' => 'Japan', 'calling_code' => '+81', 'default_currency_code' => 'JPY', 'timezone' => 'Asia/Tokyo', 'address_format' => 'jp', 'postal_code_label' => 'Postal code', 'tax_id_label' => 'Corporate Number', 'has_regions' => false, 'position' => 11],
            ['code' => 'NZ', 'iso3' => 'NZL', 'name' => 'New Zealand', 'calling_code' => '+64', 'default_currency_code' => 'NZD', 'timezone' => 'Pacific/Auckland', 'address_format' => 'nz', 'postal_code_label' => 'Postcode', 'tax_id_label' => 'GST Number', 'has_regions' => false, 'position' => 12],
        ];
    }

    public static function seed(): void
    {
        foreach (self::definitions() as $row) {
            Models\Country::query()->updateOrCreate(
                ['code' => $row['code']],
                $row + ['is_enabled' => true]
            );
        }

        // Install states/regions from JSON packs (IN, US, …) so address forms can use them.
        self::seedRegionsFromPacks();
        GeographicZones::ensure();
    }

    public static function seedRegionsFromPacks(): void
    {
        $dir = database_path('localization');
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir.DIRECTORY_SEPARATOR.'*.json') ?: [] as $path) {
            if (basename($path) === 'world-countries.json') {
                continue;
            }

            $data = json_decode((string) file_get_contents($path), true) ?: [];
            $code = strtoupper((string) ($data['country']['code'] ?? pathinfo($path, PATHINFO_FILENAME)));
            $regions = $data['regions'] ?? null;
            if (! is_array($regions) || $regions === []) {
                continue;
            }

            $country = Models\Country::query()->where('code', $code)->first();
            if (! $country) {
                continue;
            }

            $position = 1;
            $rows = [];
            $now = now();
            foreach ($regions as $region) {
                $regionCode = strtoupper(trim((string) ($region['code'] ?? '')));
                $name = trim((string) ($region['name'] ?? ''));
                if ($regionCode === '' || $name === '') {
                    continue;
                }

                $rows[] = [
                    'country_id' => $country->id,
                    'code' => $regionCode,
                    'name' => $name,
                    'is_enabled' => true,
                    'position' => $position++,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 100) as $chunk) {
                Models\CountryRegion::query()->upsert(
                    $chunk,
                    ['country_id', 'code'],
                    ['name', 'is_enabled', 'position', 'updated_at']
                );
            }

            $country->update(['has_regions' => true]);
        }
    }
}
