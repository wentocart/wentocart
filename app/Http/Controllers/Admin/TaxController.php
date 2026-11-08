<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Localization\Models\Country;
use App\Domain\Localization\Models\CountryRegion;
use App\Domain\Tax\Models\TaxClass;
use App\Domain\Tax\Models\TaxRate;
use App\Domain\Tax\Models\TaxRule;
use App\Domain\Tax\Support\TaxRuleCsvSchema;
use App\Domain\Tax\Models\TaxZone;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaxController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Localization/Taxes/Index', [
            'classes' => TaxClass::query()->withCount('rules')->orderBy('name')->get(),
            'zones' => TaxZone::query()
                ->with(['country:id,code,name', 'regions:id,code,name'])
                ->withCount('rules')
                ->orderBy('position')
                ->orderBy('name')
                ->get(),
            'rates' => TaxRate::query()->withCount('rules')->orderBy('name')->get(),
            'rules' => TaxRule::query()
                ->with(['taxClass:id,name', 'zone:id,name', 'taxRate:id,name,rate'])
                ->orderByDesc('priority')
                ->orderBy('id')
                ->get()
                ->map(fn (TaxRule $r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'priority' => $r->priority,
                    'is_enabled' => $r->is_enabled,
                    'tax_class_id' => $r->tax_class_id,
                    'tax_zone_id' => $r->tax_zone_id,
                    'tax_rate_id' => $r->tax_rate_id,
                    'class_name' => $r->taxClass?->name,
                    'zone_name' => $r->zone?->name,
                    'rate_name' => $r->taxRate?->name,
                    'rate' => $r->resolvedRate(),
                    'is_compound' => (bool) $r->is_compound,
                ]),
            'report' => $this->taxReport(),
            'options' => [
                'countries' => Country::query()->where('is_enabled', true)->orderBy('name')->get(['id', 'code', 'name']),
                'regions' => CountryRegion::query()->where('is_enabled', true)->orderBy('name')->get(['id', 'country_id', 'code', 'name']),
            ],
        ]);
    }

    public function export()
    {
        return $this->csvDownload('tax-rules.csv', TaxRuleCsvSchema::exportCsv());
    }

    public function importSample()
    {
        return $this->csvDownload('tax-rules-sample.csv', TaxRuleCsvSchema::sampleCsv());
    }

    public function import(Request $request): RedirectResponse
    {
        return $this->csvImport($request, TaxRuleCsvSchema::import(...), 'tax rules');
    }

    public function storeClass(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        TaxClass::query()->create($data);

        return back()->with('success', 'Tax class created.');
    }

    public function updateClass(Request $request, TaxClass $taxClass): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $taxClass->update($data);

        return back()->with('success', 'Tax class updated.');
    }

    public function destroyClass(TaxClass $taxClass): RedirectResponse
    {
        if ($taxClass->rules()->exists()) {
            return back()->with('error', 'Remove rules using this class first.');
        }
        $taxClass->delete();

        return back()->with('success', 'Tax class deleted.');
    }

    public function bulkDestroyClasses(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:tax_classes,id'],
        ]);

        $blocked = TaxClass::query()
            ->whereIn('id', $data['ids'])
            ->whereHas('rules')
            ->count();

        $deleted = TaxClass::query()
            ->whereIn('id', $data['ids'])
            ->whereDoesntHave('rules')
            ->delete();

        if ($deleted === 0) {
            return back()->with('error', $blocked > 0
                ? 'Remove rules using these classes first.'
                : 'No tax classes were deleted.');
        }

        $message = $deleted === 1 ? 'Tax class deleted.' : $deleted.' tax classes deleted.';
        if ($blocked > 0) {
            $message .= ' '.$blocked.' skipped (still used by rules).';
        }

        return back()->with('success', $message);
    }

    public function storeZone(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'region_ids' => ['nullable', 'array'],
            'region_ids.*' => ['integer', 'exists:country_regions,id'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);

        $zone = TaxZone::query()->create([
            'name' => $data['name'],
            'country_id' => $data['country_id'] ?? null,
            'is_enabled' => (bool) ($data['is_enabled'] ?? true),
            'position' => (int) TaxZone::query()->max('position') + 1,
        ]);
        $zone->regions()->sync($data['region_ids'] ?? []);

        return back()->with('success', 'Tax zone created.');
    }

    public function updateZone(Request $request, TaxZone $taxZone): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'region_ids' => ['nullable', 'array'],
            'region_ids.*' => ['integer', 'exists:country_regions,id'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);

        $taxZone->update([
            'name' => $data['name'],
            'country_id' => $data['country_id'] ?? null,
            'is_enabled' => (bool) ($data['is_enabled'] ?? true),
        ]);
        $taxZone->regions()->sync($data['region_ids'] ?? []);

        return back()->with('success', 'Tax zone updated.');
    }

    public function destroyZone(TaxZone $taxZone): RedirectResponse
    {
        if ($taxZone->rules()->exists()) {
            return back()->with('error', 'Remove rules using this zone first.');
        }
        $taxZone->delete();

        return back()->with('success', 'Tax zone deleted.');
    }

    public function toggleZone(TaxZone $taxZone): RedirectResponse
    {
        $taxZone->update(['is_enabled' => ! $taxZone->is_enabled]);

        return back()->with('success', 'Tax zone updated.');
    }

    public function bulkDestroyZones(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:tax_zones,id'],
        ]);

        $blocked = TaxZone::query()->whereIn('id', $data['ids'])->whereHas('rules')->count();
        $deleted = TaxZone::query()->whereIn('id', $data['ids'])->whereDoesntHave('rules')->delete();

        if ($deleted === 0) {
            return back()->with('error', $blocked > 0
                ? 'Remove rules using these zones first.'
                : 'No tax zones were deleted.');
        }

        $message = $deleted === 1 ? 'Tax zone deleted.' : $deleted.' tax zones deleted.';
        if ($blocked > 0) {
            $message .= ' '.$blocked.' skipped (still used by rules).';
        }

        return back()->with('success', $message);
    }

    public function bulkStatusZones(Request $request): RedirectResponse
    {
        $request->merge([
            'ids' => array_values(array_filter(array_map('intval', (array) $request->input('ids', [])))),
            'enabled' => filter_var($request->input('enabled'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        ]);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:tax_zones,id'],
            'enabled' => ['required', 'boolean'],
        ]);

        $updated = TaxZone::query()
            ->whereIn('id', $data['ids'])
            ->update(['is_enabled' => (bool) $data['enabled']]);

        if ($updated === 0) {
            return back()->with('error', 'No tax zones were updated.');
        }

        return back()->with('success', $data['enabled']
            ? ($updated === 1 ? 'Tax zone enabled.' : $updated.' tax zones enabled.')
            : ($updated === 1 ? 'Tax zone disabled.' : $updated.' tax zones disabled.'));
    }

    public function storeRate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);
        TaxRate::query()->create([
            'name' => $data['name'],
            'rate' => $data['rate'],
            'is_enabled' => (bool) ($data['is_enabled'] ?? true),
        ]);

        return back()->with('success', 'Tax rate created.');
    }

    public function updateRate(Request $request, TaxRate $taxRate): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);
        $taxRate->update([
            'name' => $data['name'],
            'rate' => $data['rate'],
            'is_enabled' => (bool) ($data['is_enabled'] ?? true),
        ]);

        return back()->with('success', 'Tax rate updated.');
    }

    public function destroyRate(TaxRate $taxRate): RedirectResponse
    {
        if ($taxRate->rules()->exists()) {
            return back()->with('error', 'Remove rules using this rate first.');
        }
        $taxRate->delete();

        return back()->with('success', 'Tax rate deleted.');
    }

    public function toggleRate(TaxRate $taxRate): RedirectResponse
    {
        $taxRate->update(['is_enabled' => ! $taxRate->is_enabled]);

        return back()->with('success', 'Tax rate updated.');
    }

    public function bulkDestroyRates(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:tax_rates,id'],
        ]);

        $blocked = TaxRate::query()->whereIn('id', $data['ids'])->whereHas('rules')->count();
        $deleted = TaxRate::query()->whereIn('id', $data['ids'])->whereDoesntHave('rules')->delete();

        if ($deleted === 0) {
            return back()->with('error', $blocked > 0
                ? 'Remove rules using these rates first.'
                : 'No tax rates were deleted.');
        }

        $message = $deleted === 1 ? 'Tax rate deleted.' : $deleted.' tax rates deleted.';
        if ($blocked > 0) {
            $message .= ' '.$blocked.' skipped (still used by rules).';
        }

        return back()->with('success', $message);
    }

    public function bulkStatusRates(Request $request): RedirectResponse
    {
        $request->merge([
            'ids' => array_values(array_filter(array_map('intval', (array) $request->input('ids', [])))),
            'enabled' => filter_var($request->input('enabled'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        ]);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:tax_rates,id'],
            'enabled' => ['required', 'boolean'],
        ]);

        $updated = TaxRate::query()
            ->whereIn('id', $data['ids'])
            ->update(['is_enabled' => (bool) $data['enabled']]);

        if ($updated === 0) {
            return back()->with('error', 'No tax rates were updated.');
        }

        return back()->with('success', $data['enabled']
            ? ($updated === 1 ? 'Tax rate enabled.' : $updated.' tax rates enabled.')
            : ($updated === 1 ? 'Tax rate disabled.' : $updated.' tax rates disabled.'));
    }

    public function storeRule(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tax_class_id' => ['required', 'integer', 'exists:tax_classes,id'],
            'tax_zone_id' => ['required', 'integer', 'exists:tax_zones,id'],
            'tax_rate_id' => ['required', 'integer', 'exists:tax_rates,id'],
            'name' => ['nullable', 'string', 'max:120'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'is_compound' => ['sometimes', 'boolean'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);

        $rate = TaxRate::query()->findOrFail($data['tax_rate_id']);
        TaxRule::query()->create([
            'tax_class_id' => $data['tax_class_id'],
            'tax_zone_id' => $data['tax_zone_id'],
            'tax_rate_id' => $data['tax_rate_id'],
            'name' => $data['name'] ?: $rate->name,
            'rate' => $rate->rate,
            'priority' => (int) ($data['priority'] ?? 10),
            'is_compound' => (bool) ($data['is_compound'] ?? false),
            'is_enabled' => (bool) ($data['is_enabled'] ?? true),
        ]);

        return back()->with('success', 'Tax rule created.');
    }

    public function updateRule(Request $request, TaxRule $taxRule): RedirectResponse
    {
        $data = $request->validate([
            'tax_class_id' => ['required', 'integer', 'exists:tax_classes,id'],
            'tax_zone_id' => ['required', 'integer', 'exists:tax_zones,id'],
            'tax_rate_id' => ['required', 'integer', 'exists:tax_rates,id'],
            'name' => ['nullable', 'string', 'max:120'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'is_compound' => ['sometimes', 'boolean'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);

        $rate = TaxRate::query()->findOrFail($data['tax_rate_id']);
        $taxRule->update([
            'tax_class_id' => $data['tax_class_id'],
            'tax_zone_id' => $data['tax_zone_id'],
            'tax_rate_id' => $data['tax_rate_id'],
            'name' => $data['name'] ?: $rate->name,
            'rate' => $rate->rate,
            'priority' => (int) ($data['priority'] ?? 10),
            'is_compound' => (bool) ($data['is_compound'] ?? $taxRule->is_compound),
            'is_enabled' => (bool) ($data['is_enabled'] ?? $taxRule->is_enabled),
        ]);

        return back()->with('success', 'Tax rule updated.');
    }

    public function destroyRule(TaxRule $taxRule): RedirectResponse
    {
        $taxRule->delete();

        return back()->with('success', 'Tax rule deleted.');
    }

    public function bulkDestroyRules(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:tax_rules,id'],
        ]);

        $deleted = TaxRule::query()->whereIn('id', $data['ids'])->delete();

        return back()->with('success', $deleted === 1 ? 'Tax rule deleted.' : $deleted.' tax rules deleted.');
    }

    public function bulkStatusRules(Request $request): RedirectResponse
    {
        $request->merge([
            'ids' => array_values(array_filter(array_map('intval', (array) $request->input('ids', [])))),
            'enabled' => filter_var($request->input('enabled'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        ]);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:tax_rules,id'],
            'enabled' => ['required', 'boolean'],
        ]);

        $updated = TaxRule::query()
            ->whereIn('id', $data['ids'])
            ->update(['is_enabled' => (bool) $data['enabled']]);

        if ($updated === 0) {
            return back()->with('error', 'No tax rules were updated.');
        }

        return back()->with('success', $data['enabled']
            ? ($updated === 1 ? 'Tax rule enabled.' : $updated.' tax rules enabled.')
            : ($updated === 1 ? 'Tax rule disabled.' : $updated.' tax rules disabled.'));
    }

    public function toggleRule(TaxRule $taxRule): RedirectResponse
    {
        $taxRule->update(['is_enabled' => ! $taxRule->is_enabled]);

        return back()->with('success', 'Tax rule updated.');
    }

    /** @deprecated Packs moved to LocalizationController */
    public function applyPack(Request $request): RedirectResponse
    {
        return app(LocalizationController::class)->applyPack($request, app(\App\Domain\Localization\LocalizationPackService::class));
    }

    /** @return array{rows: list<array{label: string, amount: float}>, orders: list<array<string, mixed>>, total: float} */
    protected function taxReport(): array
    {
        $orders = \App\Domain\Order\Models\Order::query()
            ->where('tax_total', '>', 0)
            ->latest('id')
            ->limit(200)
            ->get(['number', 'tax_total', 'tax_details', 'created_at', 'currency_code']);
        $byLabel = [];
        foreach ($orders as $order) {
            foreach ((array) $order->tax_details as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $label = (string) ($row['label'] ?? 'Tax');
                $byLabel[$label] = ($byLabel[$label] ?? 0) + (float) ($row['amount'] ?? 0);
            }
        }

        return [
            'total' => round((float) $orders->sum('tax_total'), 2),
            'rows' => collect($byLabel)->map(fn ($amount, $label) => [
                'label' => $label,
                'amount' => round((float) $amount, 2),
            ])->values()->all(),
            'orders' => $orders->map(fn ($order) => [
                'number' => $order->number,
                'tax_total' => (float) $order->tax_total,
                'currency_code' => $order->currency_code,
                'created_at' => $order->created_at?->toDateTimeString(),
            ])->values()->all(),
        ];
    }
}
