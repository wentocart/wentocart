<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Localization\Models\Country;
use App\Domain\Settings\PricingSettings;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Shipping\Models\ShippingZone;
use App\Domain\Shipping\Support\ShippingMethodCsvSchema;
use App\Domain\Shipping\Support\ShippingZoneCsvSchema;
use App\Domain\Tax\Models\TaxClass;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ShippingMethodController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: 'all';
        $q = trim($request->string('q')->toString());
        $name = trim($request->string('name')->toString());
        $type = trim($request->string('type')->toString());
        $description = trim($request->string('description')->toString());
        $condition = trim($request->string('condition')->toString());
        $priceMin = $request->input('price_min');
        $priceMax = $request->input('price_max');
        $idMin = $request->input('id_min');
        $idMax = $request->input('id_max');
        $sort = $request->string('sort')->toString() ?: 'name';
        $dir = $request->string('dir')->toString() === 'desc' ? 'desc' : 'asc';
        $allowedSort = ['id', 'name', 'description', 'type', 'price', 'is_enabled', 'position'];
        if (! in_array($sort, $allowedSort, true)) {
            $sort = 'name';
        }

        $base = ShippingMethod::query();

        $counts = [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('is_enabled', true)->count(),
            'inactive' => (clone $base)->where('is_enabled', false)->count(),
        ];

        $methods = ShippingMethod::query()
            ->when($status === 'active', fn ($query) => $query->where('is_enabled', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_enabled', false))
            ->when($q !== '', fn ($query) => $query->where(function ($inner) use ($q) {
                $inner->where('name', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%")
                    ->orWhere('type', 'like', "%{$q}%");
            }))
            ->when($name !== '', fn ($query) => $query->where('name', 'like', "%{$name}%"))
            ->when($description !== '', fn ($query) => $query->where('description', 'like', "%{$description}%"))
            ->when($condition !== '', fn ($query) => $query->where('conditions', 'like', "%{$condition}%"))
            ->when($type !== '' && $type !== 'all', fn ($query) => $query->where('type', $type))
            ->when($priceMin !== null && $priceMin !== '', fn ($query) => $query->where('price', '>=', (float) $priceMin))
            ->when($priceMax !== null && $priceMax !== '', fn ($query) => $query->where('price', '<=', (float) $priceMax))
            ->when($idMin !== null && $idMin !== '', fn ($query) => $query->where('id', '>=', (int) $idMin))
            ->when($idMax !== null && $idMax !== '', fn ($query) => $query->where('id', '<=', (int) $idMax))
            ->orderBy($sort, $dir)
            ->when($sort !== 'id', fn ($query) => $query->orderBy('id'))
            ->adminPaginate()
            ->through(fn (ShippingMethod $method) => [
                'id' => $method->id,
                'name' => $method->name,
                'description' => $method->description,
                'type' => $method->type,
                'type_label' => $method->typeLabel(),
                'price_summary' => $method->priceSummary(),
                'condition_summary' => $method->conditionSummary(),
                'is_enabled' => $method->is_enabled,
                'actions' => [
                    'edit' => true,
                    'delete' => true,
                ],
            ]);

        return Inertia::render('Shipping/Index', [
            'methods' => $methods,
            'counts' => $counts,
            'types' => ShippingMethod::TYPES,
            'filters' => [
                'status' => $status,
                'q' => $q,
                'name' => $name,
                'description' => $description,
                'condition' => $condition,
                'type' => $type ?: 'all',
                'price_min' => $priceMin !== null && $priceMin !== '' ? (string) $priceMin : '',
                'price_max' => $priceMax !== null && $priceMax !== '' ? (string) $priceMax : '',
                'id_min' => $idMin !== null && $idMin !== '' ? (string) $idMin : '',
                'id_max' => $idMax !== null && $idMax !== '' ? (string) $idMax : '',
                'sort' => $sort,
                'dir' => $dir,
            ],
            'zones' => ShippingZone::query()->with('countries:id,name,zone_id')->orderBy('position')->orderBy('name')->get()->map(fn (ShippingZone $zone) => [
                'id' => $zone->id,
                'name' => $zone->name,
                'is_enabled' => $zone->is_enabled,
                'country_ids' => $zone->countries->pluck('id')->all(),
                'country_names' => $zone->countries->pluck('name')->all(),
            ]),
        ]);
    }

    public function zones(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: 'all';
        $name = trim($request->string('name')->toString());
        $country = trim($request->string('country')->toString());
        $idMin = $request->input('id_min');
        $idMax = $request->input('id_max');
        $sort = $request->string('sort')->toString() ?: 'position';
        $dir = $request->string('dir')->toString() === 'desc' ? 'desc' : 'asc';
        if (! in_array($sort, ['id', 'name', 'countries_count', 'position', 'is_enabled'], true)) {
            $sort = 'position';
        }

        $query = ShippingZone::query()->with('countries:id,name,zone_id')->withCount('countries')
            ->when($status === 'active', fn ($q) => $q->where('is_enabled', true))
            ->when($status === 'inactive', fn ($q) => $q->where('is_enabled', false))
            ->when($name !== '', fn ($q) => $q->where('name', 'like', "%{$name}%"))
            ->when($country !== '', fn ($q) => $q->whereHas('countries', fn ($c) => $c->where('name', 'like', "%{$country}%")))
            ->when($idMin !== null && $idMin !== '', fn ($q) => $q->where('id', '>=', (int) $idMin))
            ->when($idMax !== null && $idMax !== '', fn ($q) => $q->where('id', '<=', (int) $idMax))
            ->orderBy($sort, $dir)
            ->when($sort !== 'id', fn ($q) => $q->orderBy('id'));

        return Inertia::render('Localization/Zones', [
            'zones' => $query->adminPaginate()->through(fn (ShippingZone $zone) => [
                'id' => $zone->id,
                'name' => $zone->name,
                'is_enabled' => $zone->is_enabled,
                'country_ids' => $zone->countries->pluck('id')->all(),
                'countries_count' => (int) $zone->countries_count,
                'position' => (int) $zone->position,
            ]),
            'countries' => $this->zoneCountryOptions(),
            'counts' => [
                'all' => ShippingZone::query()->count(),
                'active' => ShippingZone::query()->where('is_enabled', true)->count(),
                'inactive' => ShippingZone::query()->where('is_enabled', false)->count(),
            ],
            'filters' => [
                'status' => $status,
                'name' => $name,
                'country' => $country,
                'id_min' => $idMin !== null && $idMin !== '' ? (string) $idMin : '',
                'id_max' => $idMax !== null && $idMax !== '' ? (string) $idMax : '',
                'sort' => $sort,
                'dir' => $dir,
            ],
        ]);
    }

    public function storeZone(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'is_enabled' => ['sometimes', 'boolean'],
            'country_ids' => ['nullable', 'array'],
            'country_ids.*' => ['integer', 'exists:countries,id'],
        ]);
        $zone = ShippingZone::query()->create([
            'name' => $data['name'],
            'is_enabled' => (bool) ($data['is_enabled'] ?? true),
        ]);
        $zone->assignCountries($data['country_ids'] ?? []);

        return back()->with('success', 'Shipping zone saved.');
    }

    public function updateZone(Request $request, ShippingZone $shippingZone): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'is_enabled' => ['sometimes', 'boolean'],
            'country_ids' => ['nullable', 'array'],
            'country_ids.*' => ['integer', 'exists:countries,id'],
        ]);
        $shippingZone->update([
            'name' => $data['name'],
            'is_enabled' => (bool) ($data['is_enabled'] ?? $shippingZone->is_enabled),
        ]);
        $shippingZone->assignCountries($data['country_ids'] ?? []);

        return back()->with('success', 'Shipping zone updated.');
    }

    public function destroyZone(ShippingZone $shippingZone): RedirectResponse
    {
        $shippingZone->delete();

        return back()->with('success', 'Shipping zone deleted.');
    }

    public function toggleZone(ShippingZone $shippingZone): RedirectResponse
    {
        $shippingZone->update(['is_enabled' => ! $shippingZone->is_enabled]);

        return back()->with('success', $shippingZone->is_enabled ? 'Shipping zone activated.' : 'Shipping zone deactivated.');
    }

    public function bulkDestroyZones(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:shipping_zones,id'],
        ]);
        $deleted = ShippingZone::query()->whereIn('id', $data['ids'])->delete();

        return back()->with('success', $deleted === 1 ? 'Shipping zone deleted.' : $deleted.' shipping zones deleted.');
    }

    public function bulkStatusZones(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:shipping_zones,id'],
            'enabled' => ['required', 'boolean'],
        ]);
        $updated = ShippingZone::query()->whereIn('id', $data['ids'])->update(['is_enabled' => (bool) $data['enabled']]);

        return back()->with('success', "Updated {$updated} shipping zones.");
    }

    public function exportZones()
    {
        return $this->csvDownload('shipping-zones.csv', ShippingZoneCsvSchema::exportCsv());
    }

    public function importZonesSample()
    {
        return $this->csvDownload('shipping-zones-sample.csv', ShippingZoneCsvSchema::sampleCsv());
    }

    public function importZones(Request $request): RedirectResponse
    {
        return $this->csvImport($request, ShippingZoneCsvSchema::import(...), 'shipping zones');
    }

    public function export()
    {
        return $this->csvDownload('shipping-methods.csv', ShippingMethodCsvSchema::exportCsv());
    }

    public function importSample()
    {
        return $this->csvDownload('shipping-methods-sample.csv', ShippingMethodCsvSchema::sampleCsv());
    }

    public function import(Request $request): RedirectResponse
    {
        return $this->csvImport($request, ShippingMethodCsvSchema::import(...), 'shipping methods');
    }

    public function create(): Response
    {
        return Inertia::render('Shipping/Form', $this->formProps());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        ShippingMethod::query()->create($this->payload($data));

        return redirect()->route('admin.shipping.index')->with('success', 'Shipping method created.');
    }

    public function edit(ShippingMethod $shippingMethod): Response
    {
        $conditions = ShippingMethod::normalizeConditions($shippingMethod->conditions);

        return Inertia::render('Shipping/Form', [
            ...$this->formProps(),
            'method' => [
                'id' => $shippingMethod->id,
                'name' => $shippingMethod->name,
                'description' => $shippingMethod->description ?? '',
                'type' => $shippingMethod->type ?: 'flat',
                'driver' => $shippingMethod->driver ?: 'offline',
                'price' => (float) $shippingMethod->price,
                'tax_class_id' => $shippingMethod->tax_class_id,
                'is_enabled' => $shippingMethod->is_enabled,
                'conditions' => $conditions,
                'min_days' => $shippingMethod->rate_config['min_days'] ?? '',
                'max_days' => $shippingMethod->rate_config['max_days'] ?? '',
                'tiers' => ShippingMethod::normalizeTiers($shippingMethod->rate_config['tiers'] ?? [])
                    ?: [['min' => '', 'max' => '', 'price' => '']],
            ],
            'selected' => [
                'customer_groups' => CustomerGroup::query()
                    ->whereIn('id', $conditions['customer_group_ids'] ?? [])
                    ->get(['id', 'name']),
                'countries' => Country::query()
                    ->whereIn('id', $conditions['country_ids'] ?? [])
                    ->get(['id', 'name']),
                'zones' => \App\Domain\Shipping\Models\ShippingZone::query()
                    ->whereIn('id', $conditions['zone_ids'] ?? [])
                    ->get(['id', 'name']),
            ],
        ]);
    }

    public function update(Request $request, ShippingMethod $shippingMethod): RedirectResponse
    {
        $data = $this->validated($request);
        $shippingMethod->update($this->payload($data, $shippingMethod));

        return redirect()->route('admin.shipping.index')->with('success', 'Shipping method updated.');
    }

    public function destroy(ShippingMethod $shippingMethod): RedirectResponse
    {
        $shippingMethod->delete();

        return redirect()->route('admin.shipping.index')->with('success', 'Shipping method deleted.');
    }

    public function toggle(ShippingMethod $shippingMethod): RedirectResponse
    {
        $shippingMethod->update(['is_enabled' => ! $shippingMethod->is_enabled]);

        return back()->with('success', $shippingMethod->is_enabled ? 'Shipping method activated.' : 'Shipping method deactivated.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:shipping_methods,id'],
        ]);

        $deleted = ShippingMethod::query()->whereIn('id', $data['ids'])->delete();

        return back()->with('success', $deleted === 1 ? 'Shipping method deleted.' : $deleted.' shipping methods deleted.');
    }

    public function bulkStatus(Request $request): RedirectResponse
    {
        $request->merge([
            'ids' => array_values(array_filter(array_map('intval', (array) $request->input('ids', [])))),
            'enabled' => filter_var($request->input('enabled'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        ]);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:shipping_methods,id'],
            'enabled' => ['required', 'boolean'],
        ]);

        $updated = ShippingMethod::query()
            ->whereIn('id', $data['ids'])
            ->update(['is_enabled' => (bool) $data['enabled']]);

        if ($updated === 0) {
            return back()->with('error', 'No shipping methods were updated.');
        }

        return back()->with('success', $data['enabled']
            ? ($updated === 1 ? 'Shipping method enabled.' : $updated.' shipping methods enabled.')
            : ($updated === 1 ? 'Shipping method disabled.' : $updated.' shipping methods disabled.'));
    }

    public function bulkDuplicate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:shipping_methods,id'],
        ]);

        $copied = 0;
        foreach (ShippingMethod::query()->whereIn('id', $data['ids'])->get() as $method) {
            $this->duplicateMethod($method);
            $copied++;
        }

        return back()->with('success', $copied === 1 ? 'Shipping method duplicated.' : $copied.' shipping methods duplicated.');
    }

    public function duplicate(ShippingMethod $shippingMethod): RedirectResponse
    {
        $this->duplicateMethod($shippingMethod);

        return back()->with('success', 'Shipping method duplicated.');
    }

    protected function duplicateMethod(ShippingMethod $method): ShippingMethod
    {
        $copy = $method->replicate();
        $copy->name = $method->name.' (copy)';
        $copy->code = $this->uniqueCodeFromName($copy->name);
        $copy->is_enabled = false;
        $copy->save();

        return $copy;
    }

    /** @return array<string, mixed> */
    protected function formProps(): array
    {
        $shippingTaxEnabled = PricingSettings::shippingTax();

        return [
            'types' => collect(ShippingMethod::TYPES)
                ->map(fn ($label, $value) => [
                    'value' => $value,
                    'label' => $label,
                    'hint' => match ($value) {
                        'flat' => 'One fixed shipping fee',
                        'free' => 'No shipping charge (optionally gated by conditions)',
                        'price_based' => 'Fee depends on order total tiers',
                        'weight_based' => 'Fee depends on cart weight tiers',
                        'local_pickup' => 'Customer collects the order',
                        'carrier' => 'Sandbox / extension carrier (quotes without a live FedEx or DHL account)',
                        default => '',
                    },
                ])
                ->values()
                ->all(),
            'shipping_tax_enabled' => $shippingTaxEnabled,
            'options' => [
                'customer_groups' => CustomerGroup::query()
                    ->where('is_enabled', true)
                    ->orderBy('name')
                    ->get(['id', 'name']),
                'countries' => Country::query()
                    ->where('is_enabled', true)
                    ->orderBy('name')
                    ->get(['id', 'name']),
                'zones' => ShippingZone::query()
                    ->where('is_enabled', true)
                    ->orderBy('name')
                    ->get(['id', 'name']),
                'tax_classes' => $shippingTaxEnabled
                    ? TaxClass::query()->orderBy('name')->get(['id', 'name'])
                    : [],
            ],
            'selected' => [
                'customer_groups' => [],
                'countries' => [],
                'zones' => [],
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['required', Rule::in(array_keys(ShippingMethod::TYPES))],
            'driver' => ['nullable', 'string', 'max:80'],
            'price' => ['nullable', 'numeric', 'min:0'],
            // When charge-tax-on-shipping is off, ignore client tax_class_id (preserve stored value).
            ...(PricingSettings::shippingTax()
                ? ['tax_class_id' => ['nullable', 'integer', 'exists:tax_classes,id']]
                : []),
            'is_enabled' => ['required', 'boolean'],
            'min_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'max_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'tiers' => ['nullable', 'array'],
            'tiers.*.min' => ['nullable', 'numeric', 'min:0'],
            'tiers.*.max' => ['nullable', 'numeric', 'min:0'],
            'tiers.*.price' => ['nullable', 'numeric', 'min:0'],
            'conditions' => ['nullable', 'array'],
            'conditions.min_order' => ['nullable', 'numeric', 'min:0'],
            'conditions.max_order' => ['nullable', 'numeric', 'min:0'],
            'conditions.min_weight' => ['nullable', 'numeric', 'min:0'],
            'conditions.max_weight' => ['nullable', 'numeric', 'min:0'],
            'conditions.customer_group_ids' => ['nullable', 'array'],
            'conditions.customer_group_ids.*' => ['integer'],
            'conditions.country_ids' => ['nullable', 'array'],
            'conditions.country_ids.*' => ['integer'],
            'conditions.zone_ids' => ['nullable', 'array'],
            'conditions.zone_ids.*' => ['integer'],
        ]);

        $data['is_enabled'] = (bool) $data['is_enabled'];
        $data['conditions'] = ShippingMethod::normalizeConditions($data['conditions'] ?? []);
        $data['tiers'] = ShippingMethod::normalizeTiers($data['tiers'] ?? []);

        if (in_array($data['type'], ['price_based', 'weight_based'], true) && $data['tiers'] === []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'tiers' => 'Add at least one rate tier.',
            ]);
        }

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    protected function payload(array $data, ?ShippingMethod $existing = null): array
    {
        $type = $data['type'];
        $tiers = $data['tiers'] ?? [];
        $price = (float) ($data['price'] ?? 0);
        $driver = strtolower(trim((string) ($data['driver'] ?? '')));
        if ($type === 'carrier') {
            $driver = $driver !== '' ? $driver : 'sandbox';
        } else {
            $driver = 'offline';
        }

        if ($type === 'free') {
            $price = 0;
        } elseif (in_array($type, ['price_based', 'weight_based'], true) && $tiers !== []) {
            $price = (float) ($tiers[0]['price'] ?? 0);
        }

        $payload = [
            'code' => $existing?->code ?: $this->uniqueCodeFromName($data['name']),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'type' => $type,
            'driver' => $driver,
            'price' => $price,
            'is_enabled' => $data['is_enabled'],
            'position' => $existing?->position ?? 0,
            'conditions' => $data['conditions'],
            'rate_config' => [
                'tiers' => $tiers,
                'min_days' => isset($data['min_days']) && $data['min_days'] !== '' ? (int) $data['min_days'] : null,
                'max_days' => isset($data['max_days']) && $data['max_days'] !== '' ? (int) $data['max_days'] : null,
            ],
        ];

        // Only accept tax class configuration while charge-tax-on-shipping is enabled.
        // When disabled, preserve any previously saved class (not charged until re-enabled).
        if (PricingSettings::shippingTax()) {
            $taxClassId = $data['tax_class_id'] ?? null;
            $payload['tax_class_id'] = ($taxClassId === '' || $taxClassId === null) ? null : (int) $taxClassId;
        }

        return $payload;
    }

    /**
     * @return list<array{id: int, name: string, zone_id: int|null}>
     */
    protected function zoneCountryOptions(): array
    {
        $zoneNames = ShippingZone::query()->pluck('name', 'id');

        return Country::query()
            ->orderBy('name')
            ->get(['id', 'name', 'zone_id'])
            ->map(function (Country $country) use ($zoneNames) {
                $zoneName = $country->zone_id ? (string) ($zoneNames[$country->zone_id] ?? '') : '';

                return [
                    'id' => $country->id,
                    'name' => $country->name,
                    'zone_id' => $country->zone_id,
                    'zone_name' => $zoneName,
                ];
            })
            ->all();
    }

    protected function uniqueCodeFromName(string $name): string
    {
        $base = Str::slug($name) ?: 'shipping';
        $code = $base;
        $i = 2;
        while (ShippingMethod::query()->where('code', $code)->exists()) {
            $code = "{$base}-{$i}";
            $i++;
        }

        return $code;
    }
}
