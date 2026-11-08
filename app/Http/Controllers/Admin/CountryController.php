<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Localization\Models\City;
use App\Domain\Localization\Models\Country;
use App\Domain\Shipping\Models\ShippingZone;
use App\Domain\Localization\Support\CountryCsvSchema;
use App\Domain\Localization\Models\CountryRegion;
use App\Domain\Localization\Models\Currency;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CountryController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: 'all';
        $q = trim($request->string('q')->toString());
        $name = trim($request->string('name')->toString());
        $code = trim($request->string('code')->toString());
        $currency = trim($request->string('currency')->toString());
        $calling = trim($request->string('calling')->toString());
        $idMin = $request->input('id_min');
        $idMax = $request->input('id_max');
        $regionsMin = $request->input('regions_min');
        $regionsMax = $request->input('regions_max');
        $sort = $request->string('sort')->toString() ?: 'position';
        $dir = $request->string('dir')->toString() === 'desc' ? 'desc' : 'asc';
        $sortColumns = [
            'id' => 'id',
            'name' => 'name',
            'code' => 'code',
            'currency' => 'default_currency_code',
            'calling' => 'calling_code',
            'regions' => 'regions_count',
            'status' => 'is_enabled',
            'position' => 'position',
        ];
        if (! isset($sortColumns[$sort])) {
            $sort = 'position';
        }

        $base = Country::query();

        $counts = [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('is_enabled', true)->count(),
            'inactive' => (clone $base)->where('is_enabled', false)->count(),
        ];

        $countries = Country::query()
            ->withCount('regions')
            ->when($status === 'active', fn ($query) => $query->where('is_enabled', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_enabled', false))
            ->when($q !== '', fn ($query) => $query->where(function ($inner) use ($q) {
                $inner->where('name', 'like', "%{$q}%")
                    ->orWhere('code', 'like', "%{$q}%")
                    ->orWhere('iso3', 'like', "%{$q}%");
            }))
            ->when($name !== '', fn ($query) => $query->where('name', 'like', "%{$name}%"))
            ->when($code !== '', fn ($query) => $query->where(function ($inner) use ($code) {
                $inner->where('code', 'like', "%{$code}%")->orWhere('iso3', 'like', "%{$code}%");
            }))
            ->when($currency !== '', fn ($query) => $query->where('default_currency_code', 'like', "%{$currency}%"))
            ->when($calling !== '', fn ($query) => $query->where('calling_code', 'like', "%{$calling}%"))
            ->when($idMin !== null && $idMin !== '', fn ($query) => $query->where('id', '>=', (int) $idMin))
            ->when($idMax !== null && $idMax !== '', fn ($query) => $query->where('id', '<=', (int) $idMax))
            ->when($regionsMin !== null && $regionsMin !== '', fn ($query) => $query->has('regions', '>=', (int) $regionsMin))
            ->when($regionsMax !== null && $regionsMax !== '', fn ($query) => $query->has('regions', '<=', (int) $regionsMax))
            ->orderBy($sortColumns[$sort], $dir)
            ->when($sort !== 'id', fn ($query) => $query->orderBy('id', $dir))
            ->adminPaginate()
            ->through(fn (Country $country) => [
                'id' => $country->id,
                'code' => $country->code,
                'iso3' => $country->iso3,
                'name' => $country->name,
                'calling_code' => $country->calling_code,
                'default_currency_code' => $country->default_currency_code,
                'timezone' => $country->timezone,
                'has_regions' => $country->has_regions,
                'regions_count' => $country->regions_count,
                'is_enabled' => $country->is_enabled,
                'is_default' => $country->is_default,
                'actions' => ['edit' => true, 'delete' => false],
            ]);

        return Inertia::render('Localization/Countries/Index', [
            'countries' => $countries,
            'counts' => $counts,
            'filters' => [
                'status' => $status,
                'q' => $q,
                'name' => $name,
                'code' => $code,
                'currency' => $currency,
                'calling' => $calling,
                'id_min' => $idMin !== null && $idMin !== '' ? (string) $idMin : '',
                'id_max' => $idMax !== null && $idMax !== '' ? (string) $idMax : '',
                'regions_min' => $regionsMin !== null && $regionsMin !== '' ? (string) $regionsMin : '',
                'regions_max' => $regionsMax !== null && $regionsMax !== '' ? (string) $regionsMax : '',
                'sort' => $sort,
                'dir' => $dir,
            ],
            'default_code' => Country::defaultCode(),
        ]);
    }

    public function export()
    {
        return $this->csvDownload('countries.csv', CountryCsvSchema::exportCsv());
    }

    public function importSample()
    {
        return $this->csvDownload('countries-sample.csv', CountryCsvSchema::sampleCsv());
    }

    public function import(Request $request): RedirectResponse
    {
        return $this->csvImport($request, CountryCsvSchema::import(...), 'countries');
    }

    public function create(): Response
    {
        return Inertia::render('Localization/Countries/Form', $this->formProps());
    }

    public function store(Request $request): RedirectResponse
    {
        $regions = $request->input('regions', []);
        $cities = $request->input('cities', []);
        $data = $this->validated($request);
        unset($data['regions'], $data['cities']);

        $country = Country::query()->create($data + [
            'position' => (int) Country::query()->max('position') + 1,
        ]);

        if ($country->is_default) {
            $this->clearOtherDefaults($country);
        }

        $this->syncRegions($country, is_array($regions) ? $regions : []);
        $this->syncCities($country, is_array($cities) ? $cities : []);

        return redirect()->route('admin.localization.countries.edit', $country)
            ->with('success', 'Country created.');
    }

    public function edit(Country $country): Response
    {
        $country->load(['regions', 'cities.region']);

        return Inertia::render('Localization/Countries/Form', $this->formProps($country));
    }

    public function update(Request $request, Country $country): RedirectResponse
    {
        $regions = $request->input('regions', []);
        $cities = $request->input('cities', []);
        $data = $this->validated($request, $country);
        unset($data['regions'], $data['cities']);

        $country->update($data);
        if ($country->is_default) {
            $this->clearOtherDefaults($country);
        }
        $this->syncRegions($country, is_array($regions) ? $regions : []);
        $this->syncCities($country, is_array($cities) ? $cities : []);

        return back()->with('success', 'Country saved.');
    }

    public function toggle(Country $country): RedirectResponse
    {
        if ($country->is_default && $country->is_enabled) {
            return back()->with('error', 'Cannot deactivate the default country.');
        }
        $country->update(['is_enabled' => ! $country->is_enabled]);

        return back()->with('success', $country->is_enabled ? 'Country activated.' : 'Country deactivated.');
    }

    public function makeDefault(Country $country): RedirectResponse
    {
        $country->update(['is_enabled' => true, 'is_default' => true]);
        $this->clearOtherDefaults($country);

        return back()->with('success', $country->name.' is now the default country.');
    }

    private function formProps(?Country $country = null): array
    {
        return [
            'country' => $country ? [
                'id' => $country->id,
                'code' => $country->code,
                'iso3' => $country->iso3 ?? '',
                'name' => $country->name,
                'calling_code' => $country->calling_code ?? '',
                'default_currency_code' => $country->default_currency_code ?? '',
                'timezone' => $country->timezone ?? '',
                'address_format' => $country->address_format ?? '',
                'postal_code_label' => $country->postal_code_label ?? '',
                'tax_id_label' => $country->tax_id_label ?? '',
                'has_regions' => $country->has_regions,
                'is_enabled' => $country->is_enabled,
                'is_default' => $country->is_default,
                'zone_id' => $country->zone_id,
                'regions' => $country->regions->map(fn (CountryRegion $r) => [
                    'id' => $r->id,
                    'code' => $r->code,
                    'name' => $r->name,
                    'is_enabled' => $r->is_enabled,
                ])->values(),
                'cities' => $country->cities->map(fn (City $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'region_code' => $c->region?->code ?? '',
                    'is_enabled' => $c->is_enabled,
                ])->values(),
            ] : null,
            'options' => [
                'currencies' => Currency::query()->orderBy('code')->get(['code', 'name', 'symbol']),
                'zones' => ShippingZone::query()->orderBy('name')->get(['id', 'name']),
                'address_formats' => [
                    ['value' => 'in', 'label' => 'Indian format'],
                    ['value' => 'us', 'label' => 'US format'],
                    ['value' => 'gb', 'label' => 'UK format'],
                    ['value' => 'eu', 'label' => 'European format'],
                    ['value' => 'ae', 'label' => 'UAE format'],
                    ['value' => 'au', 'label' => 'Australian format'],
                    ['value' => 'ca', 'label' => 'Canadian format'],
                    ['value' => 'sg', 'label' => 'Singapore format'],
                    ['value' => 'jp', 'label' => 'Japanese format'],
                    ['value' => 'nz', 'label' => 'New Zealand format'],
                ],
            ],
        ];
    }

    private function validated(Request $request, ?Country $country = null): array
    {
        $data = $request->validate([
            'code' => [
                'required',
                'string',
                'size:2',
                'regex:/^[A-Z]{2}$/',
                Rule::unique('countries', 'code')->ignore($country?->id),
            ],
            'iso3' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'name' => ['required', 'string', 'max:120'],
            'calling_code' => ['nullable', 'string', 'max:8'],
            'default_currency_code' => ['nullable', 'string', 'max:8'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'address_format' => ['nullable', 'string', 'max:32'],
            'postal_code_label' => ['nullable', 'string', 'max:64'],
            'tax_id_label' => ['nullable', 'string', 'max:64'],
            'has_regions' => ['sometimes', 'boolean'],
            'is_enabled' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'zone_id' => ['nullable', 'integer', 'exists:shipping_zones,id'],
            'regions' => ['nullable', 'array'],
            'regions.*.code' => ['required_with:regions', 'string', 'max:16'],
            'regions.*.name' => ['required_with:regions', 'string', 'max:120'],
            'regions.*.is_enabled' => ['sometimes', 'boolean'],
            'cities' => ['nullable', 'array'],
            'cities.*.name' => ['required_with:cities', 'string', 'max:120'],
            'cities.*.region_code' => ['nullable', 'string', 'max:16'],
            'cities.*.is_enabled' => ['sometimes', 'boolean'],
        ]);

        $data['code'] = strtoupper($data['code']);
        $data['iso3'] = isset($data['iso3']) ? strtoupper($data['iso3']) : null;
        $data['has_regions'] = (bool) ($data['has_regions'] ?? false);
        $data['is_enabled'] = (bool) ($data['is_enabled'] ?? true);
        $data['is_default'] = (bool) ($data['is_default'] ?? false);
        $data['zone_id'] = ! empty($data['zone_id']) ? (int) $data['zone_id'] : null;
        if ($data['is_default']) {
            $data['is_enabled'] = true;
        }

        return $data;
    }

    private function clearOtherDefaults(Country $country): void
    {
        Country::query()->where('id', '!=', $country->id)->update(['is_default' => false]);
    }

    private function syncRegions(Country $country, array $regions): void
    {
        $keepIds = [];
        $position = 1;

        foreach ($regions as $row) {
            $code = strtoupper(trim((string) ($row['code'] ?? '')));
            $name = trim((string) ($row['name'] ?? ''));
            if ($code === '' || $name === '') {
                continue;
            }

            $region = CountryRegion::query()->updateOrCreate(
                ['country_id' => $country->id, 'code' => $code],
                [
                    'name' => $name,
                    'is_enabled' => (bool) ($row['is_enabled'] ?? true),
                    'position' => $position++,
                ]
            );
            $keepIds[] = $region->id;
        }

        CountryRegion::query()
            ->where('country_id', $country->id)
            ->when($keepIds !== [], fn ($q) => $q->whereNotIn('id', $keepIds))
            ->delete();

        $country->update(['has_regions' => $country->regions()->exists()]);
    }

    private function syncCities(Country $country, array $cities): void
    {
        $keepIds = [];
        $position = 1;
        $country->loadMissing('regions');

        foreach ($cities as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $regionCode = strtoupper(trim((string) ($row['region_code'] ?? '')));
            $regionId = null;
            if ($regionCode !== '') {
                $regionId = $country->regions->firstWhere('code', $regionCode)?->id;
            }
            $city = City::query()->updateOrCreate(
                ['country_id' => $country->id, 'name' => $name, 'region_id' => $regionId],
                [
                    'is_enabled' => (bool) ($row['is_enabled'] ?? true),
                    'position' => $position++,
                ]
            );
            $keepIds[] = $city->id;
        }

        City::query()
            ->where('country_id', $country->id)
            ->when($keepIds !== [], fn ($q) => $q->whereNotIn('id', $keepIds))
            ->delete();
    }
}
