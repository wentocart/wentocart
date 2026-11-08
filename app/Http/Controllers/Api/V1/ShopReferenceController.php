<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Localization\Models\Country;
use App\Domain\Localization\Models\CountryRegion;
use App\Domain\Localization\Models\Currency;
use App\Domain\Localization\Models\Language;
use App\Domain\Store\Models\Store;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shop REST reference: stores, countries, locales.
 */
class ShopReferenceController extends Controller
{
    /** @var list<string> */
    private const RTL = ['ar', 'he', 'fa', 'ur', 'ps', 'yi'];

    public function stores(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->query('per_page', 10)));
        $page = max(1, (int) $request->query('page', 1));

        $query = Store::query()->where('is_enabled', true)->with('urls')->orderByDesc('is_default')->orderBy('id');
        $total = (clone $query)->count();
        $rows = $query->forPage($page, $perPage)->get();
        $data = $rows->map(fn (Store $store) => $this->storePayload($store))->values()->all();

        return $this->paginated($data, $total, $page, $perPage);
    }

    public function showStore(int $id): JsonResponse
    {
        $store = Store::query()->where('is_enabled', true)->find($id);
        if (! $store) {
            return response()->json(['message' => 'Store not found.', 'success' => false], 404);
        }

        return response()->json($this->storePayload($store));
    }

    public function countries(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->query('per_page', 10)));
        $page = max(1, (int) $request->query('page', 1));

        $query = Country::query()->where('is_enabled', true)->orderBy('position')->orderBy('name');
        $total = (clone $query)->count();
        $rows = $query->with([
            'regions' => fn ($q) => $q->where('is_enabled', true)->orderBy('position')->orderBy('name'),
        ])->forPage($page, $perPage)->get();

        $data = $rows->map(fn (Country $c) => $this->countryPayload($c))->values()->all();

        return $this->paginated($data, $total, $page, $perPage);
    }

    public function showCountry(int $id): JsonResponse
    {
        $country = Country::query()->where('is_enabled', true)->with([
            'regions' => fn ($q) => $q->where('is_enabled', true)->orderBy('position')->orderBy('name'),
        ])->find($id);
        if (! $country) {
            return response()->json(['message' => 'Country not found.', 'success' => false], 404);
        }

        return response()->json($this->countryPayload($country));
    }

    public function countryStates(int $countryId): JsonResponse
    {
        $country = Country::query()->where('is_enabled', true)->find($countryId);
        if (! $country) {
            return response()->json(['message' => 'Country not found.', 'success' => false], 404);
        }

        $states = CountryRegion::query()
            ->where('country_id', $country->id)
            ->where('is_enabled', true)
            ->orderBy('position')
            ->orderBy('name')
            ->get()
            ->map(fn (CountryRegion $r) => $this->statePayload($r, $country))
            ->values()
            ->all();

        return response()->json($states);
    }

    /**
     */
    public function countriesStates(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $page = max(1, (int) $request->query('page', 1));
        $q = CountryRegion::query()
            ->where('is_enabled', true)
            ->with('country')
            ->orderBy('country_id')
            ->orderBy('position')
            ->orderBy('name');
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->filter(fn (CountryRegion $r) => $r->country !== null)
            ->map(fn (CountryRegion $r) => $this->statePayload($r, $r->country))
            ->values()
            ->all();

        return $this->paginated($rows, $total, $page, $perPage);
    }

    /**
     */
    public function countryStateGroups(): JsonResponse
    {
        $countries = Country::query()
            ->where('is_enabled', true)
            ->with(['regions' => fn ($q) => $q->where('is_enabled', true)->orderBy('position')->orderBy('name')])
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        $groups = [];
        foreach ($countries as $country) {
            $code = strtoupper((string) $country->code);
            $groups[$code] = $country->regions->map(fn (CountryRegion $r) => [
                'id' => (int) $r->id,
                'code' => (string) ($r->code ?? ''),
                'defaultName' => (string) ($r->name ?? ''),
                'countryCode' => $code,
            ])->values()->all();
        }

        return response()->json(['data' => $groups]);
    }

    /**
     */
    public function states(Request $request): JsonResponse
    {
        $countryId = (int) ($request->query('country_id') ?? $request->query('countryId') ?? 0);
        $countryCode = strtoupper(trim((string) ($request->query('country') ?? $request->query('country_code') ?? '')));

        $country = null;
        if ($countryId > 0) {
            $country = Country::query()->where('is_enabled', true)->find($countryId);
        } elseif (strlen($countryCode) === 2) {
            $country = Country::query()->where('is_enabled', true)->where('code', $countryCode)->first();
        }

        if (! $country) {
            if ($countryId < 1 && $countryCode === '') {
                $states = CountryRegion::query()
                    ->where('is_enabled', true)
                    ->with('country')
                    ->orderBy('position')
                    ->orderBy('name')
                    ->limit(500)
                    ->get()
                    ->filter(fn (CountryRegion $r) => $r->country !== null)
                    ->map(fn (CountryRegion $r) => $this->statePayload($r, $r->country))
                    ->values()
                    ->all();

                return response()->json($states);
            }

            return response()->json([]);
        }

        return $this->countryStates((int) $country->id);
    }

    public function locales(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 10)));
        $page = max(1, (int) $request->query('page', 1));

        $query = Language::query()->where('is_enabled', true)->orderBy('position')->orderBy('name');
        $total = (clone $query)->count();
        $rows = $query->forPage($page, $perPage)->get();
        $data = $rows->map(fn (Language $lang) => $this->localePayload($lang))->values()->all();

        return $this->paginated($data, $total, $page, $perPage);
    }

    public function showLocale(int|string $id): JsonResponse
    {
        $lang = Language::query()->where('is_enabled', true)
            ->where(function ($q) use ($id) {
                if (ctype_digit((string) $id)) {
                    $q->whereKey((int) $id);
                } else {
                    $q->where('code', (string) $id);
                }
            })
            ->first();
        if (! $lang) {
            return response()->json(['message' => 'Locale not found.', 'success' => false], 404);
        }

        return response()->json($this->localePayload($lang));
    }

    public function currencies(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 10)));
        $page = max(1, (int) $request->query('page', 1));

        $query = Currency::query()->where('is_enabled', true)->orderBy('position')->orderBy('code');
        $total = (clone $query)->count();
        $rows = $query->forPage($page, $perPage)->get();
        $data = $rows->map(fn (Currency $c) => $this->currencyPayload($c))->values()->all();

        return $this->paginated($data, $total, $page, $perPage);
    }

    public function showCurrency(int|string $id): JsonResponse
    {
        $currency = Currency::query()->where('is_enabled', true)
            ->where(function ($q) use ($id) {
                if (ctype_digit((string) $id)) {
                    $q->whereKey((int) $id);
                } else {
                    $q->where('code', (string) $id);
                }
            })
            ->first();
        if (! $currency) {
            return response()->json(['message' => 'Currency not found.', 'success' => false], 404);
        }

        return response()->json($this->currencyPayload($currency));
    }

    /**
     * @return array<string, mixed>
     */
    private function currencyPayload(Currency $currency): array
    {
        return [
            'id' => (int) $currency->id,
            '_id' => (int) $currency->id,
            'code' => (string) $currency->code,
            'name' => (string) ($currency->name ?? $currency->code),
            'symbol' => (string) ($currency->symbol ?? ''),
            'exchangeRate' => (float) ($currency->exchange_rate ?? 1),
            'decimal' => (int) ($currency->decimal_places ?? 2),
            'isDefault' => (bool) $currency->is_default,
            'createdAt' => $currency->created_at?->toIso8601String(),
            'updatedAt' => $currency->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function storePayload(Store $store): array
    {
        $primary = $store->relationLoaded('urls')
            ? ($store->urls->firstWhere('is_primary', true) ?? $store->urls->first())
            : $store->primaryUrl();
        $hostname = $primary?->root() ?? $primary?->domain ?? request()->getHost();

        $localeCodes = Language::enabledList()->pluck('code')->all();
        $currencyCodes = Currency::query()->where('is_enabled', true)->orderBy('position')->pluck('code')->all();
        $defaultLocale = Language::defaultCode();
        $baseCurrency = Currency::query()->where('is_default', true)->value('code')
            ?? Currency::query()->where('is_enabled', true)->orderBy('position')->value('code');

        return [
            'id' => (int) $store->id,
            'code' => (string) ($store->code ?: 'default'),
            'hostname' => (string) $hostname,
            'theme' => (string) ($store->theme ?: 'default'),
            'timezone' => (string) config('app.timezone', 'UTC'),
            'homeSeo' => [
                'meta_title' => (string) ($store->name ?? ''),
                'meta_keywords' => null,
                'meta_description' => null,
            ],
            'isMaintenanceOn' => 0,
            'allowedIps' => null,
            'logo' => null,
            'favicon' => null,
            'logoUrl' => null,
            'faviconUrl' => null,
            'createdAt' => $store->created_at?->toIso8601String(),
            'updatedAt' => $store->updated_at?->toIso8601String(),
            'locales' => array_map(fn ($c) => '/api/v1/store/locales/'.$c, $localeCodes),
            'currencies' => array_map(fn ($c) => '/api/v1/store/currencies/'.$c, $currencyCodes),
            'defaultLocale' => $defaultLocale !== '' ? '/api/v1/store/locales/'.$defaultLocale : null,
            'baseCurrency' => $baseCurrency ? '/api/v1/store/currencies/'.$baseCurrency : null,
            'translation' => $this->storeTranslationIri($store, $defaultLocale !== '' ? $defaultLocale : (string) ($localeCodes[0] ?? 'en')),
            'translations' => array_values(array_filter(array_map(
                fn ($code) => $this->storeTranslationIri($store, (string) $code),
                $localeCodes,
            ))),
            'name' => (string) $store->name,
            'isDefault' => (bool) $store->is_default,
        ];
    }

    /**
     * Synthetic id: storeId * 100000 + languageId (stable across requests).
     */
    public function storeTranslations(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->query('per_page', 10)));
        $page = max(1, (int) $request->query('page', 1));

        $stores = Store::query()->where('is_enabled', true)->orderBy('id')->get();
        $langs = Language::query()->where('is_enabled', true)->orderBy('position')->orderBy('id')->get();
        $all = [];
        foreach ($stores as $store) {
            foreach ($langs as $lang) {
                $all[] = $this->storeTranslationPayload($store, $lang);
            }
        }
        $total = count($all);
        $slice = array_slice($all, ($page - 1) * $perPage, $perPage);

        return $this->paginated($slice, $total, $page, $perPage);
    }

    public function showStoreTranslation(int $id): JsonResponse
    {
        $storeId = intdiv($id, 100000);
        $langId = $id % 100000;
        $store = Store::query()->where('is_enabled', true)->find($storeId);
        $lang = Language::query()->where('is_enabled', true)->find($langId);
        if (! $store || ! $lang) {
            return response()->json(['message' => 'Store translation not found.', 'success' => false], 404);
        }

        return response()->json($this->storeTranslationPayload($store, $lang));
    }

    private function storeTranslationIri(Store $store, string $localeCode): ?string
    {
        $lang = Language::query()->where('is_enabled', true)->where('code', $localeCode)->first();
        if (! $lang) {
            return null;
        }

        return '/api/v1/store/store_translations/'.$this->storeTranslationId($store, $lang);
    }

    private function storeTranslationId(Store $store, Language $lang): int
    {
        return ((int) $store->id * 100000) + (int) $lang->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function storeTranslationPayload(Store $store, Language $lang): array
    {
        return [
            'id' => $this->storeTranslationId($store, $lang),
            'storeId' => (int) $store->id,
            'locale' => (string) $lang->code,
            'name' => (string) $store->name,
            'description' => null,
            'maintenanceModeText' => null,
            'homeSeo' => [
                'meta_title' => (string) ($store->name ?? ''),
                'meta_keywords' => null,
                'meta_description' => null,
            ],
            'createdAt' => $store->created_at?->toIso8601String(),
            'updatedAt' => $store->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function countryPayload(Country $country): array
    {
        $states = $country->relationLoaded('regions')
            ? $country->regions
            : $country->regions()->where('is_enabled', true)->orderBy('position')->orderBy('name')->get();

        return [
            'id' => (int) $country->id,
            'code' => (string) $country->code,
            'name' => (string) $country->name,
            'states' => $states->map(fn (CountryRegion $r) => $this->statePayload($r, $country))->values()->all(),
            'translations' => [[
                'id' => (int) $country->id,
                'countryId' => (int) $country->id,
                'locale' => Language::defaultCode(),
                'name' => (string) $country->name,
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function statePayload(CountryRegion $region, Country $country): array
    {
        return [
            'id' => (int) $region->id,
            'countryCode' => (string) $country->code,
            'code' => (string) $region->code,
            'defaultName' => (string) $region->name,
            'countryId' => (int) $country->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function localePayload(Language $lang): array
    {
        $code = strtolower((string) $lang->code);
        $base = explode('-', $code)[0] ?? $code;

        return [
            'id' => (int) $lang->id,
            'code' => (string) $lang->code,
            'name' => (string) $lang->name,
            'direction' => in_array($base, self::RTL, true) ? 'rtl' : 'ltr',
            'logoPath' => null,
            'logoUrl' => null,
            'createdAt' => $lang->created_at?->toIso8601String(),
            'updatedAt' => $lang->updated_at?->toIso8601String(),
            'isDefault' => (bool) $lang->is_default,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $data
     */
    private function paginated(array $data, int $total, int $page, int $perPage): JsonResponse
    {
        $pages = max(1, (int) ceil($total / $perPage));

        return response()->json($data)->withHeaders([
            'X-Total-Count' => (string) $total,
            'X-Page' => (string) $page,
            'X-Per-Page' => (string) $perPage,
            'X-Total-Pages' => (string) $pages,
        ]);
    }
}
