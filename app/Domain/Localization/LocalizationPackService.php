<?php

namespace App\Domain\Localization;

use App\Domain\Localization\Models\Country;
use App\Domain\Localization\Models\CountryRegion;
use App\Domain\Localization\Models\Currency;
use App\Domain\Localization\Models\Language;
use App\Domain\Localization\Models\LocalizationPack;
use App\Domain\Settings\Models\Setting;
use App\Domain\Tax\Models\TaxClass;
use App\Domain\Tax\Models\TaxRate;
use App\Domain\Tax\Models\TaxRule;
use App\Domain\Tax\Models\TaxZone;
use Illuminate\Support\Facades\File;

class LocalizationPackService
{
    /** @var array<string, array{name: string, symbol: string}> */
    protected array $currencyCatalog = [
        'USD' => ['name' => 'US Dollar', 'symbol' => '$'],
        'INR' => ['name' => 'Indian Rupee', 'symbol' => '₹'],
        'EUR' => ['name' => 'Euro', 'symbol' => '€'],
        'GBP' => ['name' => 'Pound Sterling', 'symbol' => '£'],
        'AED' => ['name' => 'UAE Dirham', 'symbol' => 'د.إ'],
        'AUD' => ['name' => 'Australian Dollar', 'symbol' => 'A$'],
        'CAD' => ['name' => 'Canadian Dollar', 'symbol' => 'C$'],
        'SGD' => ['name' => 'Singapore Dollar', 'symbol' => 'S$'],
        'JPY' => ['name' => 'Japanese Yen', 'symbol' => '¥'],
        'NZD' => ['name' => 'New Zealand Dollar', 'symbol' => 'NZ$'],
    ];

    /**
     * Countries an admin can pick for pack install — full worldwide list.
     *
     * @return list<array<string, mixed>>
     */
    public function catalog(): array
    {
        $applied = LocalizationPack::query()->pluck('applied_at', 'country_code');
        $jsonPacks = $this->jsonPackIndex();
        $byCode = [];

        foreach (WorldCountries::all() as $row) {
            $byCode[$row['code']] = $this->buildCatalogEntry(
                $row['code'],
                $row['name'],
                $applied->get($row['code']),
                $jsonPacks[$row['code']] ?? null
            );
        }

        // Prefer richer CoreCountries / DB names when present.
        foreach (CoreCountries::definitions() as $row) {
            if (isset($byCode[$row['code']])) {
                $byCode[$row['code']]['name'] = $row['name'];
            }
        }

        foreach (Country::query()->withCount('regions')->get(['id', 'code', 'name']) as $country) {
            if (! isset($byCode[$country->code])) {
                $byCode[$country->code] = $this->buildCatalogEntry(
                    $country->code,
                    $country->name,
                    $applied->get($country->code),
                    $jsonPacks[$country->code] ?? null
                );
            } else {
                $byCode[$country->code]['name'] = $country->name;
            }
            $byCode[$country->code]['regions_installed'] = (int) $country->regions_count;
        }

        return collect($byCode)->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    /**
     * @deprecated Use catalog()
     */
    public function available(): array
    {
        return $this->catalog();
    }

    /**
     * Components available for a country (drives checkboxes).
     *
     * @return array<string, mixed>
     */
    public function preview(string $countryCode): array
    {
        $code = strtoupper($countryCode);
        $data = $this->resolvePackData($code);
        $installed = LocalizationPack::query()->where('country_code', $code)->whereNotNull('applied_at')->exists();
        $country = Country::query()->where('code', $code)->withCount('regions')->first();

        $components = [
            'country' => [
                'key' => 'country',
                'label' => 'Country profile',
                'hint' => 'ISO codes, calling code, timezone, address labels',
                'available' => true,
                'required' => true,
            ],
            'currency' => [
                'key' => 'currency',
                'label' => 'Currency',
                'hint' => ($data['currency']['code'] ?? $data['country']['default_currency_code'] ?? null)
                    ? 'Will enable '.($data['currency']['code'] ?? $data['country']['default_currency_code'])
                    : 'No currency mapped for this country',
                'available' => ! empty($data['currency']) || ! empty($data['country']['default_currency_code']),
                'required' => false,
            ],
            'language' => [
                'key' => 'language',
                'label' => 'Language',
                'hint' => ($data['language']['name'] ?? 'English').' ('.($data['language']['code'] ?? 'en').')',
                'available' => true,
                'required' => false,
            ],
            'regions' => [
                'key' => 'regions',
                'label' => 'States / regions',
                'hint' => ! empty($data['regions'])
                    ? count($data['regions']).' states/regions in pack'
                    : 'No state/city list in this pack yet',
                'available' => ! empty($data['regions']),
                'required' => false,
            ],
            'tax' => [
                'key' => 'tax',
                'label' => 'Tax rules',
                'hint' => $this->taxHint($data),
                'available' => $this->taxRows($data) !== [],
                'required' => false,
            ],
            'units' => [
                'key' => 'units',
                'label' => 'Units (weight / volume / distance)',
                'hint' => $this->unitsHint($data['units'] ?? $this->defaultUnits($code)),
                'available' => true,
                'required' => false,
            ],
        ];

        return [
            'country_code' => $code,
            'name' => $data['name'] ?? ($data['country']['name'] ?? $code),
            'has_json_pack' => File::exists(database_path('localization/'.$code.'.json')),
            'is_installed' => $installed,
            'regions_installed' => $country?->regions_count ?? 0,
            'components' => array_values($components),
        ];
    }

    /**
     * @param  array{currency?: bool, language?: bool, regions?: bool, tax?: bool, units?: bool}  $options
     */
    public function apply(string $countryCode, array $options = []): LocalizationPack
    {
        $code = strtoupper($countryCode);
        $data = $this->resolvePackData($code);
        abort_unless(! empty($data['country']['code']), 404, 'Country pack not found.');

        $options = [
            'currency' => (bool) ($options['currency'] ?? true),
            'language' => (bool) ($options['language'] ?? true),
            'regions' => (bool) ($options['regions'] ?? true),
            'tax' => (bool) ($options['tax'] ?? true),
            'units' => (bool) ($options['units'] ?? true),
        ];

        $countryData = $data['country'];
        $country = Country::query()->updateOrCreate(
            ['code' => $countryData['code']],
            [
                'name' => $countryData['name'],
                'iso3' => $countryData['iso3'] ?? null,
                'calling_code' => $countryData['calling_code'] ?? null,
                'default_currency_code' => $countryData['default_currency_code'] ?? ($data['currency']['code'] ?? null),
                'timezone' => $countryData['timezone'] ?? null,
                'address_format' => $countryData['address_format'] ?? null,
                'postal_code_label' => $countryData['postal_code_label'] ?? null,
                'tax_id_label' => $countryData['tax_id_label'] ?? null,
                'has_regions' => (bool) ($countryData['has_regions'] ?? ! empty($data['regions'])),
                'is_enabled' => true,
            ]
        );

        $applied = ['country' => true];

        if ($options['language']) {
            $lang = $data['language'] ?? ['code' => 'en', 'name' => 'English'];
            Language::query()->updateOrCreate(
                ['code' => $lang['code']],
                ['name' => $lang['name'], 'is_enabled' => true]
            );
            $applied['language'] = $lang['code'];
        }

        if ($options['currency']) {
            $currency = $data['currency'] ?? null;
            if (! $currency && ! empty($countryData['default_currency_code'])) {
                $cc = strtoupper($countryData['default_currency_code']);
                $meta = $this->currencyCatalog[$cc] ?? ['name' => $cc, 'symbol' => $cc];
                $currency = ['code' => $cc, 'name' => $meta['name'], 'symbol' => $meta['symbol']];
            }
            if ($currency) {
                Currency::query()->updateOrCreate(
                    ['code' => $currency['code']],
                    [
                        'name' => $currency['name'],
                        'symbol' => $currency['symbol'] ?? $currency['code'],
                        'is_enabled' => true,
                        'decimal_places' => ($currency['code'] ?? '') === 'JPY' ? 0 : 2,
                    ]
                );
                $country->update(['default_currency_code' => $currency['code']]);
                Money::forgetCache();
                $applied['currency'] = $currency['code'];
            }
        }

        $needsRegionsForTax = $options['tax'] && $this->taxRowsHaveRegions($data);
        if (($options['regions'] || $needsRegionsForTax) && ! empty($data['regions']) && is_array($data['regions'])) {
            $position = 1;
            foreach ($data['regions'] as $region) {
                CountryRegion::query()->updateOrCreate(
                    ['country_id' => $country->id, 'code' => $region['code']],
                    [
                        'name' => $region['name'],
                        'is_enabled' => true,
                        'position' => $position++,
                    ]
                );
            }
            $country->update(['has_regions' => true]);
            $applied['regions'] = count($data['regions']);
        }

        if ($options['tax']) {
            $taxRows = $this->taxRows($data);
            if ($taxRows !== []) {
                $installed = [];
                $installedRegional = false;

                foreach ($taxRows as $tax) {
                    $regionCode = isset($tax['region']) ? strtoupper(trim((string) $tax['region'])) : '';
                    $zone = $this->resolveTaxZone($country, $regionCode !== '' ? $regionCode : null);
                    if ($zone === null) {
                        continue;
                    }
                    if ($regionCode !== '') {
                        $installedRegional = true;
                    }

                    $className = trim((string) ($tax['class'] ?? 'Standard')) ?: 'Standard';
                    $rateName = trim((string) ($tax['name'] ?? $className)) ?: $className;
                    $rateValue = (float) ($tax['rate'] ?? 0);
                    $priority = (int) ($tax['priority'] ?? 100);

                    $class = TaxClass::query()->firstOrCreate(['name' => $className]);
                    $rate = TaxRate::query()->updateOrCreate(
                        ['name' => $rateName],
                        ['rate' => $rateValue, 'is_enabled' => true]
                    );
                    TaxRule::query()->updateOrCreate(
                        [
                            'tax_class_id' => $class->id,
                            'tax_zone_id' => $zone->id,
                            'tax_rate_id' => $rate->id,
                        ],
                        [
                            'country_id' => $country->id,
                            'name' => $rateName,
                            'rate' => $rateValue,
                            'priority' => $priority,
                            'is_enabled' => true,
                        ]
                    );
                    $installed[] = $rateName;
                }

                if ($installedRegional) {
                    $this->removeLegacyCountryTaxPlaceholder($country);
                }

                $applied['tax'] = count($installed) === 1
                    ? $installed[0]
                    : count($installed).' rates';
            }
        }

        if ($options['units']) {
            $units = $data['units'] ?? $this->defaultUnits($code);
            Setting::setValue('unit_weight', $units['weight'] ?? 'kg');
            Setting::setValue('unit_dimension', $units['dimension'] ?? 'cm');
            Setting::setValue('unit_volume', $units['volume'] ?? 'l');
            $applied['units'] = $units;
        }

        return LocalizationPack::query()->updateOrCreate(
            ['country_code' => $country->code],
            [
                'name' => $data['name'] ?? $country->name,
                'payload' => array_merge($data, ['_applied_options' => $options, '_applied' => $applied]),
                'applied_at' => now(),
            ]
        );
    }

    protected function buildCatalogEntry(string $code, string $name, $appliedAt, ?array $jsonPack = null): array
    {
        $jsonPack ??= $this->jsonPackIndex()[$code] ?? null;
        $hasJson = $jsonPack !== null;
        $regionsCount = is_array($jsonPack['regions'] ?? null) ? count($jsonPack['regions']) : 0;
        $currencyCode = $jsonPack['currency']['code']
            ?? $jsonPack['country']['default_currency_code']
            ?? WorldCountries::find($code)['default_currency_code']
            ?? null;
        $taxPayload = $jsonPack ?? [];
        if ($this->taxRows($taxPayload) === []) {
            $worldTaxes = WorldTaxCatalog::taxesFor($code);
            if ($worldTaxes !== []) {
                $taxPayload = ['taxes' => $worldTaxes];
            }
        }
        $hasTax = $this->taxRows($taxPayload) !== [];
        $units = $jsonPack['units'] ?? $this->defaultUnits($code);

        $components = [
            [
                'key' => 'country',
                'label' => 'Country profile',
                'hint' => 'ISO codes, calling code, address labels',
                'available' => true,
                'required' => true,
            ],
            [
                'key' => 'currency',
                'label' => 'Currency',
                'hint' => $currencyCode ? "Will enable {$currencyCode}" : 'No currency mapped',
                'available' => (bool) $currencyCode,
                'required' => false,
            ],
            [
                'key' => 'language',
                'label' => 'Language',
                'hint' => ($jsonPack['language']['name'] ?? 'English').' ('.($jsonPack['language']['code'] ?? 'en').')',
                'available' => true,
                'required' => false,
            ],
            [
                'key' => 'regions',
                'label' => 'States / regions',
                'hint' => $regionsCount > 0 ? "{$regionsCount} states/regions in pack" : 'No state/city list in this pack yet',
                'available' => $regionsCount > 0,
                'required' => false,
            ],
            [
                'key' => 'tax',
                'label' => 'Tax rules',
                'hint' => $hasTax
                    ? $this->taxHint($taxPayload)
                    : 'No starter tax in this pack',
                'available' => $hasTax,
                'required' => false,
            ],
            [
                'key' => 'units',
                'label' => 'Units (weight / volume / distance)',
                'hint' => $this->unitsHint($units),
                'available' => true,
                'required' => false,
            ],
        ];

        return [
            'country_code' => $code,
            'name' => $name,
            'file' => $hasJson ? $code.'.json' : null,
            'applied_at' => $appliedAt ? (is_string($appliedAt) ? $appliedAt : optional($appliedAt)->toIso8601String()) : null,
            'is_installed' => (bool) $appliedAt,
            'has_json_pack' => $hasJson,
            'regions_count' => $regionsCount,
            'regions_installed' => 0,
            'currency_code' => $currencyCode,
            'components' => $components,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function jsonPackIndex(): array
    {
        static $index = null;
        if ($index !== null) {
            return $index;
        }

        $index = [];
        $dir = database_path('localization');
        if (! File::isDirectory($dir)) {
            return $index;
        }

        foreach (File::files($dir) as $file) {
            if ($file->getExtension() !== 'json' || $file->getFilename() === 'world-countries.json') {
                continue;
            }
            $data = json_decode(File::get($file->getPathname()), true) ?: [];
            $code = strtoupper($data['country']['code'] ?? $file->getFilenameWithoutExtension());
            $index[$code] = $data;
        }

        return $index;
    }

    /**
     * Merge JSON pack (if any) over CoreCountries / world list / DB.
     *
     * @return array<string, mixed>
     */
    protected function resolvePackData(string $code): array
    {
        $code = strtoupper($code);
        $fromJson = $this->jsonPackIndex()[$code] ?? [];

        $core = collect(CoreCountries::definitions())->firstWhere('code', $code);
        $world = WorldCountries::find($code);
        $db = Country::query()->where('code', $code)->first();

        $country = $fromJson['country'] ?? [];
        if ($core) {
            $country = array_merge([
                'code' => $core['code'],
                'iso3' => $core['iso3'],
                'name' => $core['name'],
                'calling_code' => $core['calling_code'],
                'default_currency_code' => $core['default_currency_code'],
                'timezone' => $core['timezone'],
                'address_format' => $core['address_format'],
                'postal_code_label' => $core['postal_code_label'],
                'tax_id_label' => $core['tax_id_label'],
                'has_regions' => $core['has_regions'],
            ], $country);
        } elseif ($world) {
            $country = array_merge([
                'code' => $world['code'],
                'iso3' => $world['iso3'],
                'name' => $world['name'],
                'calling_code' => $world['calling_code'],
                'default_currency_code' => $world['default_currency_code'],
                'timezone' => null,
                'address_format' => null,
                'postal_code_label' => 'Postal code',
                'tax_id_label' => 'Tax ID',
                'has_regions' => false,
            ], $country);
        } elseif ($db) {
            $country = array_merge([
                'code' => $db->code,
                'iso3' => $db->iso3,
                'name' => $db->name,
                'calling_code' => $db->calling_code,
                'default_currency_code' => $db->default_currency_code,
                'timezone' => $db->timezone,
                'address_format' => $db->address_format,
                'postal_code_label' => $db->postal_code_label,
                'tax_id_label' => $db->tax_id_label,
                'has_regions' => $db->has_regions,
            ], $country);
        }

        if (empty($country['code'])) {
            return [];
        }

        $currencyCode = $fromJson['currency']['code'] ?? $country['default_currency_code'] ?? null;
        $currency = $fromJson['currency'] ?? null;
        if (! $currency && $currencyCode) {
            $meta = $this->currencyCatalog[strtoupper($currencyCode)] ?? ['name' => $currencyCode, 'symbol' => $currencyCode];
            $currency = ['code' => strtoupper($currencyCode), 'name' => $meta['name'], 'symbol' => $meta['symbol']];
        }

        $tax = $fromJson['tax'] ?? null;
        $taxes = $fromJson['taxes'] ?? null;
        if ($this->taxRows(['tax' => $tax, 'taxes' => $taxes]) === []) {
            $worldTaxes = WorldTaxCatalog::taxesFor($code);
            if ($worldTaxes !== []) {
                $taxes = $worldTaxes;
                $tax = null;
            }
        }

        return [
            'name' => $fromJson['name'] ?? ($country['name'].' pack'),
            'country' => $country,
            'language' => $fromJson['language'] ?? ['code' => 'en', 'name' => 'English'],
            'currency' => $currency,
            'tax' => $tax,
            'taxes' => $taxes,
            'regions' => $fromJson['regions'] ?? [],
            'units' => $fromJson['units'] ?? $this->defaultUnits($code),
        ];
    }

    /**
     * Normalize pack tax payload: prefer `taxes` list, else legacy single `tax`.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{class?: string, name?: string, rate?: float|int|string, priority?: int, region?: string}>
     */
    protected function taxRows(array $data): array
    {
        if (! empty($data['taxes']) && is_array($data['taxes'])) {
            return array_values(array_filter($data['taxes'], fn ($row) => is_array($row)));
        }

        if (! empty($data['tax']) && is_array($data['tax'])) {
            return [$data['tax']];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function taxRowsHaveRegions(array $data): bool
    {
        foreach ($this->taxRows($data) as $row) {
            if (trim((string) ($row['region'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Country-wide zone, or one zone locked to a state/region code.
     */
    protected function resolveTaxZone(Country $country, ?string $regionCode): ?TaxZone
    {
        if ($regionCode === null || $regionCode === '') {
            return TaxZone::query()->updateOrCreate(
                ['name' => $country->name.' tax zone', 'country_id' => $country->id],
                ['is_enabled' => true]
            );
        }

        $region = CountryRegion::query()
            ->where('country_id', $country->id)
            ->where('code', $regionCode)
            ->first();

        if (! $region) {
            return null;
        }

        $zone = TaxZone::query()->updateOrCreate(
            ['name' => $country->name.' · '.$region->name, 'country_id' => $country->id],
            ['is_enabled' => true]
        );
        $zone->regions()->sync([$region->id]);

        return $zone;
    }

    /**
     * Drop the old single “Example 0%” country-wide placeholder once real state rates exist.
     */
    protected function removeLegacyCountryTaxPlaceholder(Country $country): void
    {
        $legacy = TaxZone::query()
            ->where('country_id', $country->id)
            ->where('name', $country->name.' tax zone')
            ->whereDoesntHave('regions')
            ->first();

        if (! $legacy) {
            return;
        }

        TaxRule::query()->where('tax_zone_id', $legacy->id)->delete();
        if (! $legacy->rules()->exists()) {
            $legacy->delete();
        }

        $example = TaxRate::query()->where('name', 'Example 0%')->first();
        if ($example && ! TaxRule::query()->where('tax_rate_id', $example->id)->exists()) {
            $example->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function taxHint(array $data): string
    {
        $rows = $this->taxRows($data);
        if ($rows === []) {
            return 'No starter tax in this pack';
        }

        $regional = count(array_filter(
            $rows,
            fn (array $row) => trim((string) ($row['region'] ?? '')) !== ''
        ));

        if ($regional > 1) {
            return $regional.' state/regional rates (edit after install)';
        }

        if (count($rows) === 1) {
            $row = $rows[0];

            return ($row['name'] ?? $row['class'] ?? 'Tax').' @ '.($row['rate'] ?? 0).'%';
        }

        $labels = array_map(
            fn (array $row) => (string) ($row['rate'] ?? 0).'%',
            $rows
        );

        return count($rows).' rates ('.implode(', ', $labels).')';
    }

    /**
     * @return array{weight: string, dimension: string, volume: string}
     */
    protected function defaultUnits(string $code): array
    {
        // US customary for US; metric for everyone else (including CA/AU for store simplicity).
        if (strtoupper($code) === 'US') {
            return ['weight' => 'lb', 'dimension' => 'in', 'volume' => 'gal'];
        }

        return ['weight' => 'kg', 'dimension' => 'cm', 'volume' => 'l'];
    }

    protected function unitsHint(array $units): string
    {
        return sprintf(
            'Weight %s · Distance %s · Volume %s',
            $units['weight'] ?? 'kg',
            $units['dimension'] ?? 'cm',
            $units['volume'] ?? 'l'
        );
    }
}
