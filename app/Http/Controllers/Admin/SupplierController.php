<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Catalog\Models\SupplierTranslation;
use App\Domain\Catalog\Models\UrlRewrite;
use App\Domain\Catalog\Services\SupplierService;
use App\Domain\Catalog\Services\UrlRewriteService;
use App\Domain\Catalog\Support\CatalogMediaUrl;
use App\Domain\Catalog\Support\DefaultImage;
use App\Domain\Catalog\Support\SupplierCsvSchema;
use App\Domain\Localization\Models\Country;
use App\Domain\Localization\Models\Language;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierController extends Controller
{
    public function __construct(
        protected SupplierService $suppliers,
    ) {}

    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: 'all';
        $q = trim($request->string('q')->toString());
        $name = trim($request->string('name')->toString());
        $contact = trim($request->string('contact')->toString());
        $country = trim($request->string('country')->toString());
        $idMin = $request->input('id_min');
        $idMax = $request->input('id_max');
        $productsMin = $request->input('products_min');
        $productsMax = $request->input('products_max');
        $sort = $request->string('sort')->toString() ?: 'id';
        $dir = $request->string('dir')->toString() === 'asc' ? 'asc' : 'desc';
        $allowedSort = ['id', 'name', 'contact', 'country', 'products_count', 'is_enabled'];
        if (! in_array($sort, $allowedSort, true)) {
            $sort = 'id';
        }

        $base = Supplier::query();

        $counts = [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('is_enabled', true)->count(),
            'inactive' => (clone $base)->where('is_enabled', false)->count(),
        ];

        $countryNames = Country::query()->pluck('name', 'code');
        $countryOptions = $countryNames->map(fn ($name, $code) => [
            'value' => (string) $code,
            'label' => (string) $name,
        ])->values()->all();

        $suppliers = Supplier::query()
            ->with('translations')
            ->withCount('products')
            ->when($status === 'active', fn ($query) => $query->where('is_enabled', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_enabled', false))
            ->when($q !== '', fn ($query) => $query->where(function ($inner) use ($q) {
                $inner->where('email', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', "%{$q}%"));
            }))
            ->when($name !== '', fn ($query) => $query->whereHas('translations', fn ($t) => $t->where('name', 'like', "%{$name}%")))
            ->when($contact !== '', function ($query) use ($contact) {
                $query->where(function ($inner) use ($contact) {
                    $inner->where('email', 'like', "%{$contact}%")
                        ->orWhere('phone', 'like', "%{$contact}%")
                        ->orWhere('city', 'like', "%{$contact}%")
                        ->orWhere('country', 'like', "%{$contact}%");
                });
            })
            ->when($country !== '', fn ($query) => $query->where('country', $country))
            ->when($idMin !== null && $idMin !== '', fn ($query) => $query->where('suppliers.id', '>=', (int) $idMin))
            ->when($idMax !== null && $idMax !== '', fn ($query) => $query->where('suppliers.id', '<=', (int) $idMax))
            ->when($productsMin !== null && $productsMin !== '', fn ($query) => $query->has('products', '>=', (int) $productsMin))
            ->when($productsMax !== null && $productsMax !== '', fn ($query) => $query->has('products', '<=', (int) $productsMax))
            ->when($sort === 'name', function ($query) use ($dir) {
                $query->orderBy(
                    SupplierTranslation::query()
                        ->select('name')
                        ->whereColumn('supplier_id', 'suppliers.id')
                        ->limit(1),
                    $dir
                )->orderBy('suppliers.id');
            }, function ($query) use ($sort, $dir) {
                if ($sort === 'products_count') {
                    $query->orderBy('products_count', $dir)->orderBy('id');
                } elseif ($sort === 'contact') {
                    $query->orderBy('suppliers.email', $dir)->orderBy('suppliers.id');
                } elseif ($sort === 'country') {
                    $query->orderBy('suppliers.country', $dir)->orderBy('suppliers.id');
                } elseif ($sort === 'id') {
                    $query->orderBy('suppliers.id', $dir);
                } else {
                    $query->orderBy($sort, $dir)->orderBy('id');
                }
            })
            ->adminPaginate()
            ->through(function (Supplier $supplier) use ($countryNames) {
                $countryCode = $supplier->country;

                return [
                    'id' => $supplier->id,
                    'name' => $supplier->displayName(),
                    'email' => $supplier->email,
                    'phone' => $supplier->phone,
                    'contact' => $supplier->contactSummary(),
                    'country' => $countryCode,
                    'country_label' => $countryCode ? (string) ($countryNames[$countryCode] ?? $countryCode) : '',
                    'logo_url' => $supplier->logo_path
                        ? CatalogMediaUrl::url($supplier->logo_path, DefaultImage::SM)
                        : null,
                    'is_enabled' => $supplier->is_enabled,
                    'products_count' => $supplier->products_count,
                    'actions' => [
                        'edit' => true,
                        'delete' => true,
                        'duplicate' => true,
                    ],
                ];
            });

        return Inertia::render('Catalog/Suppliers/Index', [
            'suppliers' => $suppliers,
            'counts' => $counts,
            'filters' => [
                'status' => $status,
                'q' => $q,
                'name' => $name,
                'contact' => $contact,
                'country' => $country,
                'id_min' => $idMin !== null && $idMin !== '' ? (string) $idMin : '',
                'id_max' => $idMax !== null && $idMax !== '' ? (string) $idMax : '',
                'products_min' => $productsMin !== null && $productsMin !== '' ? (string) $productsMin : '',
                'products_max' => $productsMax !== null && $productsMax !== '' ? (string) $productsMax : '',
                'sort' => $sort,
                'dir' => $dir,
            ],
            'countryOptions' => $countryOptions,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Catalog/Suppliers/Form', [
            'countries' => Country::optionsForAddresses(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $this->suppliers->save($data, null, ['logo' => $request->file('logo')]);

        return redirect()->route('admin.suppliers.index')->with('success', 'Supplier created.');
    }

    public function edit(Supplier $supplier): Response
    {
        $supplier->load(['translations']);
        $translation = $supplier->translation();

        return Inertia::render('Catalog/Suppliers/Form', [
            'supplier' => [
                'id' => $supplier->id,
                'name' => $translation?->name ?? '',
                'description' => $translation?->description ?? '',
                'translations' => $supplier->translations->map(fn (SupplierTranslation $row) => [
                    'language_code' => $row->language_code,
                    'name' => $row->name,
                    'slug' => $row->slug,
                    'description' => $row->description,
                    'meta_title' => $row->meta_title,
                    'meta_description' => $row->meta_description,
                    'meta_keywords' => $row->meta_keywords,
                ])->values()->all(),
                'slug' => $translation?->slug ?? '',
                'email' => $supplier->email ?? '',
                'phone' => $supplier->phone ?? '',
                'website' => $supplier->website ?? '',
                'facebook_url' => $supplier->facebook_url ?? '',
                'instagram_url' => $supplier->instagram_url ?? '',
                'youtube_url' => $supplier->youtube_url ?? '',
                'twitter_url' => $supplier->twitter_url ?? '',
                'linkedin_url' => $supplier->linkedin_url ?? '',
                'tiktok_url' => $supplier->tiktok_url ?? '',
                'address' => $supplier->address ?? '',
                'address_line2' => $supplier->address_line2 ?? '',
                'city' => $supplier->city ?? '',
                'state' => $this->normalizeStateCode($supplier->country, $supplier->state),
                'postcode' => $supplier->postcode ?? '',
                'country' => $supplier->country ?? '',
                'logo_url' => $supplier->logo_path
                    ? CatalogMediaUrl::url($supplier->logo_path, DefaultImage::SM)
                    : null,
                'is_enabled' => $supplier->is_enabled,
            ],
            'countries' => Country::optionsForAddresses(),
        ]);
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $data = $this->validated($request);
        $this->suppliers->save($data, $supplier, ['logo' => $request->file('logo')]);

        return redirect()->route('admin.suppliers.index')->with('success', 'Supplier updated.');
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        $supplier->delete();

        return redirect()->route('admin.suppliers.index')->with('success', 'Supplier deleted.');
    }

    public function toggle(Supplier $supplier): RedirectResponse
    {
        $supplier->update(['is_enabled' => ! $supplier->is_enabled]);

        return back()->with('success', $supplier->is_enabled ? 'Supplier enabled.' : 'Supplier disabled.');
    }

    public function duplicate(Supplier $supplier): RedirectResponse
    {
        $this->suppliers->duplicate($supplier);

        return redirect()->route('admin.suppliers.index')->with('success', 'Supplier duplicated.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:suppliers,id'],
        ]);

        Supplier::query()->whereIn('id', $data['ids'])->delete();

        return back()->with('success', 'Selected suppliers deleted.');
    }

    public function bulkStatus(Request $request): RedirectResponse
    {
        $request->merge([
            'ids' => array_values(array_filter(array_map('intval', (array) $request->input('ids', [])))),
            'enabled' => $this->toBool($request->input('enabled')),
        ]);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:suppliers,id'],
            'enabled' => ['required', 'boolean'],
        ]);

        $updated = Supplier::query()
            ->whereIn('id', $data['ids'])
            ->update(['is_enabled' => (bool) $data['enabled']]);

        if ($updated === 0) {
            return back()->with('error', 'No suppliers were updated.');
        }

        return back()->with('success', $data['enabled']
            ? ($updated === 1 ? 'Supplier enabled.' : $updated.' suppliers enabled.')
            : ($updated === 1 ? 'Supplier disabled.' : $updated.' suppliers disabled.'));
    }

    public function bulkDuplicate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:suppliers,id'],
        ]);

        $copied = 0;
        foreach (Supplier::query()->whereIn('id', $data['ids'])->get() as $supplier) {
            $this->suppliers->duplicate($supplier);
            $copied++;
        }

        if ($copied === 0) {
            return back()->with('error', 'No suppliers were duplicated.');
        }

        return back()->with('success', $copied === 1 ? 'Supplier duplicated.' : $copied.' suppliers duplicated.');
    }

    public function export(Request $request): StreamedResponse
    {
        $delimiter = (string) $request->input('delimiter', SupplierCsvSchema::DEFAULT_DELIMITER);
        $multi = (string) $request->input('multivalue_separator', SupplierCsvSchema::DEFAULT_MULTI_SEPARATOR);
        $csv = $this->suppliers->exportCsv(Language::defaultCode(), $request, $delimiter, $multi);

        return response()->streamDownload(fn () => print ($csv), 'suppliers.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function import(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:8192'],
            'language' => ['nullable', 'string', 'max:16'],
            'delimiter' => ['nullable', 'string', 'max:8'],
            'multivalue_separator' => ['nullable', 'string', 'max:8'],
        ]);

        $count = $this->suppliers->importCsv(
            $request->file('file')->getContent(),
            $data['language'] ?? Language::defaultCode(),
            $data['delimiter'] ?? SupplierCsvSchema::DEFAULT_DELIMITER,
            $data['multivalue_separator'] ?? SupplierCsvSchema::DEFAULT_MULTI_SEPARATOR,
        );

        return back()->with('success', "Imported {$count} suppliers.");
    }

    public function importSample(Request $request): StreamedResponse
    {
        $delimiter = (string) $request->input('delimiter', SupplierCsvSchema::DEFAULT_DELIMITER);
        $multi = (string) $request->input('multivalue_separator', SupplierCsvSchema::DEFAULT_MULTI_SEPARATOR);
        $csv = $this->suppliers->sampleCsv($delimiter, $multi);

        return response()->streamDownload(fn () => print ($csv), 'suppliers-sample.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function searchProducts(Request $request): JsonResponse
    {
        $q = trim($request->string('q')->toString());

        $results = Product::query()
            ->with('translations')
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('sku', 'like', "%{$q}%")
                        ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', "%{$q}%"));
                });
            })
            ->latest()
            ->limit(25)
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => ($product->translations->first()?->name ?? $product->sku).' ('.$product->sku.')',
            ])
            ->values();

        return response()->json(['results' => $results]);
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'translations' => ['nullable', 'array'],
            'translations.*.language_code' => ['required_with:translations', 'string', Rule::exists('languages', 'code')->where('is_enabled', true)],
            'translations.*.name' => ['nullable', 'string', 'max:255'],
            'translations.*.slug' => ['nullable', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'translations.*.description' => ['nullable', 'string'],
            'translations.*.meta_title' => ['nullable', 'string', 'max:60'],
            'translations.*.meta_description' => ['nullable', 'string', 'max:160'],
            'translations.*.meta_keywords' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'website' => ['nullable', 'string', 'max:255', 'url'],
            'facebook_url' => ['nullable', 'string', 'max:255', 'url'],
            'instagram_url' => ['nullable', 'string', 'max:255', 'url'],
            'youtube_url' => ['nullable', 'string', 'max:255', 'url'],
            'twitter_url' => ['nullable', 'string', 'max:255', 'url'],
            'linkedin_url' => ['nullable', 'string', 'max:255', 'url'],
            'tiktok_url' => ['nullable', 'string', 'max:255', 'url'],
            'address' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postcode' => ['required', 'string', 'max:32'],
            'country' => ['required', 'string', 'max:120', Rule::exists('countries', 'code')->where('is_enabled', true)],
            'logo' => ['nullable', 'file', 'image', 'max:'.(int) config('wento.upload.max_image_kb', 2048)],
            'remove_logo' => ['nullable', 'boolean'],
            'is_enabled' => ['required', 'boolean'],
        ]);

        $country = Country::query()
            ->where('code', $data['country'])
            ->with(['regions' => fn ($query) => $query->where('is_enabled', true)])
            ->first();

        $hasRegions = $country && $country->has_regions && $country->regions->isNotEmpty();
        if ($hasRegions) {
            $state = trim((string) ($data['state'] ?? ''));
            $region = $country->regions->first(
                fn ($row) => $row->code === $state
                    || strcasecmp((string) $row->name, $state) === 0
            );
            if (! $region) {
                throw ValidationException::withMessages([
                    'state' => 'Please select a valid state / province for this country.',
                ]);
            }
            $data['state'] = $region->code;
        } else {
            $data['state'] = null;
        }

        $data['is_enabled'] = (bool) $data['is_enabled'];
        $data['remove_logo'] = $request->boolean('remove_logo');
        $data['email'] = ($data['email'] ?? '') ?: null;
        $data['phone'] = ($data['phone'] ?? '') ?: null;
        $data['website'] = ($data['website'] ?? '') ?: null;
        $data['address_line2'] = ($data['address_line2'] ?? '') ?: null;
        $data['translations'] = $this->normalizeTranslations($data);
        $default = Language::defaultCode();
        $defaultRow = $data['translations'][$default] ?? [];
        $data['name'] = $defaultRow['name'] ?? '';
        $data['slug'] = $defaultRow['slug'] ?? '';
        $data['description'] = $defaultRow['description'] ?? null;
        $data['meta_title'] = $defaultRow['meta_title'] ?? null;
        $data['meta_description'] = $defaultRow['meta_description'] ?? null;
        $data['meta_keywords'] = $defaultRow['meta_keywords'] ?? null;
        $data['language_code'] = $default;

        return $data;
    }

    /** @return array<string, array<string, mixed>> */
    protected function normalizeTranslations(array $data): array
    {
        $default = Language::defaultCode();
        $rows = [];

        foreach ((array) ($data['translations'] ?? []) as $key => $row) {
            if (! is_array($row)) {
                continue;
            }
            $language = (string) ($row['language_code'] ?? $key);
            if ($language === '') {
                continue;
            }
            $rows[$language] = [
                'language_code' => $language,
                'name' => trim((string) ($row['name'] ?? '')),
                'slug' => trim((string) ($row['slug'] ?? '')),
                'description' => ($row['description'] ?? '') ?: null,
                'meta_title' => trim((string) ($row['meta_title'] ?? '')),
                'meta_description' => trim((string) ($row['meta_description'] ?? '')),
                'meta_keywords' => trim((string) ($row['meta_keywords'] ?? '')),
            ];
        }

        if ($rows === []) {
            $rows[$default] = [
                'language_code' => $default,
                'name' => trim((string) ($data['name'] ?? '')),
                'slug' => trim((string) ($data['slug'] ?? '')),
                'description' => ($data['description'] ?? '') ?: null,
                'meta_title' => trim((string) ($data['meta_title'] ?? '')),
                'meta_description' => trim((string) ($data['meta_description'] ?? '')),
                'meta_keywords' => trim((string) ($data['meta_keywords'] ?? '')),
            ];
        }

        if (($rows[$default]['name'] ?? '') === '') {
            throw ValidationException::withMessages([
                "translations.$default.name" => 'Supplier name is required for the default language.',
            ]);
        }

        foreach ($rows as $language => $row) {
            $hasContent = collect($row)
                ->except(['language_code'])
                ->contains(fn ($value) => is_string($value) ? trim($value) !== '' : $value !== null);

            if ($language !== $default && $hasContent && $row['name'] === '') {
                throw ValidationException::withMessages([
                    "translations.$language.name" => 'Supplier name is required when saving a translation.',
                ]);
            }
        }

        return $rows;
    }

    /** @param  array<string, mixed>  $data */
    protected function payload(array $data): array
    {
        $payload = [
            'email' => $data['email'],
            'phone' => $data['phone'],
            'website' => $data['website'],
            'address' => $data['address'],
            'address_line2' => $data['address_line2'],
            'city' => $data['city'],
            'state' => $data['state'],
            'postcode' => $data['postcode'],
            'country' => $data['country'],
            'is_enabled' => $data['is_enabled'],
        ];

        if (array_key_exists('seller_id', $data)) {
            $payload['seller_id'] = ($data['seller_id'] ?? '') === '' || $data['seller_id'] === null
                ? null
                : (int) $data['seller_id'];
        }

        return $payload;
    }

    /** @param  array<string, mixed>  $data */
    protected function syncTranslations(Supplier $supplier, array $data, UrlRewriteService $urls): void
    {
        $default = Language::defaultCode();
        $rows = $data['translations'] ?? [];

        foreach ($rows as $key => $row) {
            $row = (array) $row;
            $language = (string) ($row['language_code'] ?? $key);
            if ($language === '') {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            $description = ($row['description'] ?? '') ?: null;
            $blank = $name === '' && $description === null;

            if ($language !== $default && $blank) {
                UrlRewrite::query()
                    ->where('entity_type', 'supplier')
                    ->where('entity_id', $supplier->id)
                    ->where('language_code', $language)
                    ->delete();
                SupplierTranslation::query()
                    ->where('supplier_id', $supplier->id)
                    ->where('language_code', $language)
                    ->delete();

                continue;
            }

            $slug = $this->uniqueTranslationSlug($name, (int) $supplier->id, $language);
            $supplier->translations()->updateOrCreate(
                ['language_code' => $language],
                [
                    'name' => $name,
                    'slug' => $slug,
                    'description' => $description,
                ]
            );
            $urls->sync('supplier', $supplier->id, $language, $slug, 'supplier');
        }
    }

    protected function uniqueTranslationSlug(string $name, int $supplierId, string $locale): string
    {
        $base = Str::slug($name) ?: 'supplier';
        $slug = $base;
        $i = 2;

        while (SupplierTranslation::query()
            ->where('language_code', $locale)
            ->where('slug', $slug)
            ->where('supplier_id', '!=', $supplierId)
            ->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    protected function normalizeStateCode(?string $countryCode, ?string $state): string
    {
        if ($state === null || $state === '' || $countryCode === null || $countryCode === '') {
            return (string) ($state ?? '');
        }

        $country = Country::query()
            ->where('code', $countryCode)
            ->with(['regions' => fn ($query) => $query->where('is_enabled', true)])
            ->first();

        if (! $country) {
            return $state;
        }

        foreach ($country->regions as $region) {
            if ($region->code === $state) {
                return $region->code;
            }
            if (strcasecmp((string) $region->name, $state) === 0) {
                return $region->code;
            }
        }

        return $state;
    }

    protected function toBool(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || $value === 'true' || $value === 'on') {
            return true;
        }
        if ($value === 0 || $value === '0' || $value === 'false' || $value === 'off') {
            return false;
        }

        return $value;
    }
}
