<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Localization\Models\Country;
use App\Domain\Localization\Models\CountryRegion;
use App\Domain\Localization\Support\StateCsvSchema;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CountryRegionController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: 'all';
        $countryCode = strtoupper(trim($request->string('country')->toString()));
        $q = trim($request->string('q')->toString());

        $base = CountryRegion::query();

        $counts = [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('is_enabled', true)->count(),
            'inactive' => (clone $base)->where('is_enabled', false)->count(),
        ];

        $states = CountryRegion::query()
            ->with(['country:id,code,name'])
            ->when($status === 'active', fn ($query) => $query->where('is_enabled', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_enabled', false))
            ->when($countryCode !== '', fn ($query) => $query->whereHas('country', fn ($inner) => $inner->where('code', $countryCode)))
            ->when($q !== '', fn ($query) => $query->where(function ($inner) use ($q) {
                $inner->where('name', 'like', "%{$q}%")
                    ->orWhere('code', 'like', "%{$q}%")
                    ->orWhereHas('country', fn ($country) => $country
                        ->where('name', 'like', "%{$q}%")
                        ->orWhere('code', 'like', "%{$q}%"));
            }))
            ->orderBy('country_id')
            ->orderBy('position')
            ->orderBy('name')
            ->adminPaginate()
            ->through(fn (CountryRegion $region) => [
                'id' => $region->id,
                'code' => $region->code,
                'name' => $region->name,
                'is_enabled' => $region->is_enabled,
                'country' => $region->country ? [
                    'id' => $region->country->id,
                    'code' => $region->country->code,
                    'name' => $region->country->name,
                ] : null,
            ]);

        return Inertia::render('Localization/States/Index', [
            'states' => $states,
            'counts' => $counts,
            'filters' => [
                'status' => $status,
                'country' => $countryCode,
                'q' => $q,
            ],
            'countries' => Country::query()
                ->whereHas('regions')
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
        ]);
    }

    public function export()
    {
        return $this->csvDownload('states.csv', StateCsvSchema::exportCsv());
    }

    public function importSample()
    {
        return $this->csvDownload('states-sample.csv', StateCsvSchema::sampleCsv());
    }

    public function import(Request $request): RedirectResponse
    {
        return $this->csvImport($request, StateCsvSchema::import(...), 'states');
    }

    public function toggle(CountryRegion $countryRegion): RedirectResponse
    {
        $countryRegion->update(['is_enabled' => ! $countryRegion->is_enabled]);

        return back()->with('success', $countryRegion->is_enabled ? 'State activated.' : 'State deactivated.');
    }
}
