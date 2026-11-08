<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Localization\LocalizationPackService;
use App\Domain\Localization\Models\Country;
use App\Domain\Localization\Models\Currency;
use App\Domain\Localization\Models\Language;
use App\Domain\Localization\Models\LocalizationPack;
use App\Domain\Localization\Money;
use App\Domain\Tax\Models\TaxRule;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LocalizationController extends Controller
{
    public function index(LocalizationPackService $packs): Response
    {
        $installed = LocalizationPack::query()->whereNotNull('applied_at')->orderByDesc('applied_at')->limit(6)->get()
            ->map(function (LocalizationPack $pack) {
                $country = Country::query()->where('code', $pack->country_code)->withCount('regions')->first();

                return [
                    'country_code' => $pack->country_code,
                    'name' => $pack->name,
                    'country_name' => $country?->name ?? $pack->name,
                    'regions_installed' => $country?->regions_count ?? 0,
                    'currency_code' => $country?->default_currency_code,
                    'applied_at' => $pack->applied_at?->toIso8601String(),
                    'is_installed' => true,
                ];
            });

        return Inertia::render('Localization/Index', [
            'counts' => [
                'countries' => Country::query()->where('is_enabled', true)->count(),
                'currencies' => Currency::query()->where('is_enabled', true)->count(),
                'languages' => Language::query()->where('is_enabled', true)->count(),
                'tax_rules' => TaxRule::query()->where('is_enabled', true)->count(),
                'packs' => LocalizationPack::query()->whereNotNull('applied_at')->count(),
            ],
            'packs' => $installed,
            'default_currency' => Money::defaultCode(),
            'defaults' => [
                'country_code' => Country::defaultCode() ?: '',
                'language_code' => Language::defaultCode(),
                'currency_code' => Money::defaultCode(),
            ],
            'default_options' => [
                'countries' => Country::query()->where('is_enabled', true)->orderBy('position')->orderBy('name')
                    ->get(['code', 'name'])->map(fn (Country $row) => ['code' => $row->code, 'name' => $row->name])->values(),
                'languages' => Language::query()->where('is_enabled', true)->orderBy('name')
                    ->get(['code', 'name'])->map(fn (Language $row) => ['code' => $row->code, 'name' => $row->name])->values(),
                'currencies' => Currency::query()->where('is_enabled', true)->orderBy('position')->orderBy('code')
                    ->get(['code', 'name', 'symbol'])->map(fn (Currency $row) => [
                        'code' => $row->code,
                        'name' => $row->name,
                        'symbol' => $row->symbol,
                    ])->values(),
            ],
        ]);
    }

    public function saveDefaults(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'country_code' => ['required', 'string', 'max:4', 'exists:countries,code'],
            'language_code' => ['required', 'string', 'max:8', 'exists:languages,code'],
            'currency_code' => ['required', 'string', 'max:8', 'exists:currencies,code'],
        ]);

        $country = Country::query()->where('code', $data['country_code'])->firstOrFail();
        $language = Language::query()->where('code', $data['language_code'])->firstOrFail();
        $currency = Currency::query()->where('code', $data['currency_code'])->firstOrFail();

        $country->update(['is_enabled' => true, 'is_default' => true]);
        Country::query()->where('id', '!=', $country->id)->update(['is_default' => false]);

        $language->update(['is_enabled' => true, 'is_default' => true]);
        Language::query()->where('id', '!=', $language->id)->update(['is_default' => false]);

        $currency->update(['is_enabled' => true, 'is_default' => true, 'exchange_rate' => 1]);
        Currency::query()->where('id', '!=', $currency->id)->update(['is_default' => false]);
        Money::forgetCache();

        return back()->with('success', 'Default language, country, and currency saved.');
    }

    public function packs(LocalizationPackService $packs): Response
    {
        return Inertia::render('Localization/Packs', [
            'catalog' => $packs->catalog(),
        ]);
    }

    public function applyPack(Request $request, LocalizationPackService $packs): RedirectResponse
    {
        $data = $request->validate([
            'country_code' => ['required', 'string', 'max:4'],
            'options' => ['nullable', 'array'],
            'options.currency' => ['sometimes', 'boolean'],
            'options.language' => ['sometimes', 'boolean'],
            'options.regions' => ['sometimes', 'boolean'],
            'options.tax' => ['sometimes', 'boolean'],
            'options.units' => ['sometimes', 'boolean'],
        ]);

        $preview = $packs->preview($data['country_code']);
        $options = $data['options'] ?? [];

        // Only apply components that exist for this country (and were checked).
        foreach ($preview['components'] as $component) {
            $key = $component['key'];
            if ($key === 'country') {
                continue;
            }
            if (! ($component['available'] ?? false)) {
                $options[$key] = false;
            } elseif (! array_key_exists($key, $options)) {
                $options[$key] = true;
            }
        }

        $packs->apply($data['country_code'], $options);

        return back()->with('success', 'Localization pack installed for '.$data['country_code'].'.');
    }
}
