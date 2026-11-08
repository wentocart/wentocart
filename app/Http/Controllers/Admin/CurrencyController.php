<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Localization\Models\Currency;
use App\Domain\Localization\Support\CurrencyCsvSchema;
use App\Domain\Localization\Money;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CurrencyController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: 'all';
        $q = trim($request->string('q')->toString());
        $name = trim($request->string('name')->toString());
        $code = trim($request->string('code')->toString());
        $symbol = trim($request->string('symbol')->toString());
        $idMin = $request->input('id_min');
        $idMax = $request->input('id_max');
        $rateMin = $request->input('rate_min');
        $rateMax = $request->input('rate_max');
        $sort = $request->string('sort')->toString() ?: 'position';
        $dir = $request->string('dir')->toString() === 'desc' ? 'desc' : 'asc';
        $sortColumns = [
            'id' => 'id',
            'name' => 'name',
            'code' => 'code',
            'symbol' => 'symbol',
            'rate' => 'exchange_rate',
            'status' => 'is_enabled',
            'position' => 'position',
        ];
        if (! isset($sortColumns[$sort])) {
            $sort = 'position';
        }

        $base = Currency::query();
        $counts = [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('is_enabled', true)->count(),
            'inactive' => (clone $base)->where('is_enabled', false)->count(),
        ];

        $currencies = Currency::query()
            ->when($status === 'active', fn ($query) => $query->where('is_enabled', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_enabled', false))
            ->when($q !== '', fn ($query) => $query->where(function ($inner) use ($q) {
                $inner->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%");
            }))
            ->when($name !== '', fn ($query) => $query->where('name', 'like', "%{$name}%"))
            ->when($code !== '', fn ($query) => $query->where('code', 'like', "%{$code}%"))
            ->when($symbol !== '', fn ($query) => $query->where('symbol', 'like', "%{$symbol}%"))
            ->when($idMin !== null && $idMin !== '', fn ($query) => $query->where('id', '>=', (int) $idMin))
            ->when($idMax !== null && $idMax !== '', fn ($query) => $query->where('id', '<=', (int) $idMax))
            ->when($rateMin !== null && $rateMin !== '', fn ($query) => $query->where('exchange_rate', '>=', (float) $rateMin))
            ->when($rateMax !== null && $rateMax !== '', fn ($query) => $query->where('exchange_rate', '<=', (float) $rateMax))
            ->orderBy($sortColumns[$sort], $dir)
            ->when($sort !== 'id', fn ($query) => $query->orderBy('id', $dir))
            ->adminPaginate()
            ->through(fn (Currency $c) => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'symbol' => $c->symbol,
                'decimal_places' => $c->decimal_places,
                'symbol_position' => $c->symbol_position,
                'exchange_rate' => $c->exchange_rate,
                'is_enabled' => $c->is_enabled,
                'is_default' => $c->is_default,
                'sample' => Money::format(1234.5, $c->code),
            ]);

        return Inertia::render('Localization/Currencies/Index', [
            'currencies' => $currencies,
            'counts' => $counts,
            'filters' => [
                'status' => $status,
                'q' => $q,
                'name' => $name,
                'code' => $code,
                'symbol' => $symbol,
                'id_min' => $idMin !== null && $idMin !== '' ? (string) $idMin : '',
                'id_max' => $idMax !== null && $idMax !== '' ? (string) $idMax : '',
                'rate_min' => $rateMin !== null && $rateMin !== '' ? (string) $rateMin : '',
                'rate_max' => $rateMax !== null && $rateMax !== '' ? (string) $rateMax : '',
                'sort' => $sort,
                'dir' => $dir,
            ],
            'default_code' => Money::defaultCode(),
        ]);
    }

    public function export()
    {
        return $this->csvDownload('currencies.csv', CurrencyCsvSchema::exportCsv());
    }

    public function importSample()
    {
        return $this->csvDownload('currencies-sample.csv', CurrencyCsvSchema::sampleCsv());
    }

    public function import(Request $request): RedirectResponse
    {
        return $this->csvImport($request, CurrencyCsvSchema::import(...), 'currencies');
    }

    public function create(): Response
    {
        return Inertia::render('Localization/Currencies/Form', ['currency' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $currency = Currency::query()->create($data + [
            'position' => (int) Currency::query()->max('position') + 1,
        ]);
        if ($currency->is_default) {
            $this->clearOtherDefaults($currency);
        }
        Money::forgetCache();

        return redirect()->route('admin.localization.currencies.edit', $currency)
            ->with('success', 'Currency created.');
    }

    public function edit(Currency $currency): Response
    {
        return Inertia::render('Localization/Currencies/Form', [
            'currency' => [
                'id' => $currency->id,
                'code' => $currency->code,
                'name' => $currency->name,
                'symbol' => $currency->symbol,
                'decimal_places' => $currency->decimal_places ?? 2,
                'symbol_position' => $currency->symbol_position ?? 'before',
                'exchange_rate' => $currency->exchange_rate,
                'is_enabled' => $currency->is_enabled,
                'is_default' => $currency->is_default,
            ],
        ]);
    }

    public function update(Request $request, Currency $currency): RedirectResponse
    {
        $data = $this->validated($request, $currency);
        $currency->update($data);
        if ($currency->is_default) {
            $this->clearOtherDefaults($currency);
        }
        Money::forgetCache();

        return back()->with('success', 'Currency saved.');
    }

    public function toggle(Currency $currency): RedirectResponse
    {
        if ($currency->is_default && $currency->is_enabled) {
            return back()->with('error', 'Cannot deactivate the default currency.');
        }
        $currency->update(['is_enabled' => ! $currency->is_enabled]);
        Money::forgetCache();

        return back()->with('success', $currency->is_enabled ? 'Currency activated.' : 'Currency deactivated.');
    }

    public function makeDefault(Currency $currency): RedirectResponse
    {
        \App\Domain\Localization\ShopLocaleDefaults::rebaseRatesToDefault($currency);
        Money::forgetCache();

        return back()->with('success', $currency->code.' is now the default currency.');
    }

    private function validated(Request $request, ?Currency $currency = null): array
    {
        $data = $request->validate([
            'code' => [
                'required', 'string', 'max:8', 'regex:/^[A-Z]{3}$/',
                Rule::unique('currencies', 'code')->ignore($currency?->id),
            ],
            'name' => ['required', 'string', 'max:120'],
            'symbol' => ['required', 'string', 'max:16'],
            'decimal_places' => ['required', 'integer', 'min:0', 'max:4'],
            'symbol_position' => ['required', 'in:before,after'],
            'exchange_rate' => ['required', 'numeric', 'min:0.000001'],
            'is_enabled' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $data['code'] = strtoupper($data['code']);
        $data['is_enabled'] = (bool) ($data['is_enabled'] ?? true);
        $data['is_default'] = (bool) ($data['is_default'] ?? false);
        if ($data['is_default']) {
            $data['exchange_rate'] = 1;
            $data['is_enabled'] = true;
        }

        return $data;
    }

    private function clearOtherDefaults(Currency $currency): void
    {
        Currency::query()->where('id', '!=', $currency->id)->update(['is_default' => false]);
    }
}
