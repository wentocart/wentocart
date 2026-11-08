<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\BrandTranslation;
use App\Domain\Catalog\Services\BrandService;
use App\Domain\Catalog\Support\BrandCsvSchema;
use App\Domain\Localization\Models\Country;
use App\Domain\Localization\Models\Language;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BrandController extends Controller
{
    public function __construct(
        protected BrandService $brands,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeBrand('view');

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
        $allowedSort = ['id', 'name', 'contact', 'country', 'is_enabled', 'products_count'];
        if (! in_array($sort, $allowedSort, true)) {
            $sort = 'id';
        }

        $base = Brand::query();
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

        $brands = Brand::query()
            ->with('translations')
            ->withCount('products')
            ->when($status === 'active', fn ($query) => $query->where('is_enabled', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_enabled', false))
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->whereHas('translations', fn ($t) => $t->where('name', 'like', "%{$q}%")->orWhere('slug', 'like', "%{$q}%"));
                });
            })
            ->when($name !== '', function ($query) use ($name) {
                $query->whereHas('translations', fn ($t) => $t->where('name', 'like', "%{$name}%"));
            })
            ->when($contact !== '', function ($query) use ($contact) {
                $query->where(function ($inner) use ($contact) {
                    $inner->where('email', 'like', "%{$contact}%")
                        ->orWhere('phone', 'like', "%{$contact}%");
                });
            })
            ->when($country !== '', function ($query) use ($country) {
                $query->where(function ($inner) use ($country) {
                    $inner->where('country', $country)->orWhere('country_code', $country);
                });
            })
            ->when($idMin !== null && $idMin !== '', fn ($query) => $query->where('brands.id', '>=', (int) $idMin))
            ->when($idMax !== null && $idMax !== '', fn ($query) => $query->where('brands.id', '<=', (int) $idMax))
            ->when($productsMin !== null && $productsMin !== '', fn ($query) => $query->has('products', '>=', (int) $productsMin))
            ->when($productsMax !== null && $productsMax !== '', fn ($query) => $query->has('products', '<=', (int) $productsMax))
            ->when($sort === 'name', function ($query) use ($dir) {
                $query->orderBy(
                    BrandTranslation::query()
                        ->select('name')
                        ->whereColumn('brand_id', 'brands.id')
                        ->limit(1),
                    $dir
                )->orderBy('brands.id');
            }, function ($query) use ($sort, $dir) {
                if ($sort === 'products_count') {
                    $query->orderBy('products_count', $dir)->orderBy('id');
                } elseif ($sort === 'contact') {
                    $query->orderBy('brands.email', $dir)->orderBy('brands.id');
                } elseif ($sort === 'country') {
                    $query->orderByRaw('COALESCE(brands.country, brands.country_code) '.$dir)->orderBy('brands.id');
                } elseif ($sort === 'id') {
                    $query->orderBy('brands.id', $dir);
                } else {
                    $query->orderBy($sort, $dir)->orderBy('id');
                }
            })
            ->adminPaginate()
            ->through(function (Brand $brand) use ($countryNames) {
                $row = $this->brands->present($brand);
                $countryCode = $brand->country ?: $brand->country_code;
                $row['country'] = $countryCode;
                $row['country_label'] = $countryCode ? (string) ($countryNames[$countryCode] ?? $countryCode) : '';
                $row['actions'] = [
                    'edit' => $this->admin()?->hasPermission('catalog.manage') || $this->admin()?->hasPermission('catalog.brands.update'),
                    'delete' => $this->admin()?->hasPermission('catalog.manage') || $this->admin()?->hasPermission('catalog.brands.delete'),
                    'duplicate' => $this->admin()?->hasPermission('catalog.manage') || $this->admin()?->hasPermission('catalog.brands.create'),
                ];

                return $row;
            });

        return Inertia::render('Catalog/Brands/Index', [
            'brands' => $brands,
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
            'can' => [
                'create' => $this->admin()?->hasPermission('catalog.manage') || $this->admin()?->hasPermission('catalog.brands.create'),
                'update' => $this->admin()?->hasPermission('catalog.manage') || $this->admin()?->hasPermission('catalog.brands.update'),
                'delete' => $this->admin()?->hasPermission('catalog.manage') || $this->admin()?->hasPermission('catalog.brands.delete'),
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorizeBrand('create');

        return Inertia::render('Catalog/Brands/Form', $this->inertiaCreateProps());
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeBrand('create');
        $this->brands->save($this->validated($request), null, $this->files($request));

        return redirect()->route('admin.brands.index')->with('success', 'Brand created.');
    }

    public function edit(Brand $brand): Response
    {
        $this->authorizeBrand('update');

        return Inertia::render('Catalog/Brands/Form', $this->inertiaEditProps($brand));
    }

    public function update(Request $request, Brand $brand): RedirectResponse
    {
        $this->authorizeBrand('update');
        $this->brands->save($this->validated($request, $brand), $brand, $this->files($request));

        return redirect()->route('admin.brands.index')->with('success', 'Brand updated.');
    }

    public function destroy(Brand $brand): RedirectResponse
    {
        $this->authorizeBrand('delete');
        try {
            $this->brands->delete($brand);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('admin.brands.index')->with('success', 'Brand deleted.');
    }

    public function duplicate(Brand $brand): RedirectResponse
    {
        $this->authorizeBrand('create');
        $copy = $this->brands->duplicate($brand);

        return redirect()->route('admin.brands.edit', $copy)->with('success', 'Brand duplicated.');
    }

    public function toggle(Brand $brand): RedirectResponse
    {
        $this->authorizeBrand('update');
        $brand->update(['is_enabled' => ! $brand->is_enabled]);

        return back()->with('success', $brand->is_enabled ? 'Brand enabled.' : 'Brand disabled. Existing product assignments were kept.');
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorizeBrand('view');
        $delimiter = (string) $request->input('delimiter', BrandCsvSchema::DEFAULT_DELIMITER);
        $multi = (string) $request->input('multivalue_separator', BrandCsvSchema::DEFAULT_MULTI_SEPARATOR);
        $csv = $this->brands->exportCsv(Language::defaultCode(), $request, $delimiter, $multi);

        return response()->streamDownload(fn () => print ($csv), 'brands.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function import(Request $request): RedirectResponse
    {
        $this->authorizeBrand('create');
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:8192'],
            'language' => ['nullable', 'string', 'max:16'],
            'delimiter' => ['nullable', 'string', 'max:8'],
            'multivalue_separator' => ['nullable', 'string', 'max:8'],
        ]);

        $count = $this->brands->importCsv(
            $request->file('file')->getContent(),
            $data['language'] ?? Language::defaultCode(),
            $data['delimiter'] ?? BrandCsvSchema::DEFAULT_DELIMITER,
            $data['multivalue_separator'] ?? BrandCsvSchema::DEFAULT_MULTI_SEPARATOR,
        );

        return back()->with('success', "Imported {$count} brands.");
    }

    public function importSample(Request $request): StreamedResponse
    {
        $this->authorizeBrand('view');
        $delimiter = (string) $request->input('delimiter', BrandCsvSchema::DEFAULT_DELIMITER);
        $multi = (string) $request->input('multivalue_separator', BrandCsvSchema::DEFAULT_MULTI_SEPARATOR);
        $csv = $this->brands->sampleCsv($delimiter, $multi);

        return response()->streamDownload(fn () => print ($csv), 'brands-sample.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->authorizeBrand('delete');
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $blocked = 0;
        $deleted = 0;
        foreach (Brand::query()->whereIn('id', $data['ids'])->get() as $brand) {
            try {
                $this->brands->delete($brand);
                $deleted++;
            } catch (ValidationException) {
                $blocked++;
            }
        }

        if ($deleted && $blocked) {
            return back()->with('warning', $deleted.' brand(s) deleted. '.$blocked.' could not be deleted because they are assigned to products.');
        }
        if ($blocked) {
            return back()->with('error', 'None of the selected brands could be deleted. Reassign their products first.');
        }

        return back()->with('success', 'Selected brands deleted.');
    }

    public function bulkDuplicate(Request $request): RedirectResponse
    {
        $this->authorizeBrand('create');
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $copied = 0;
        foreach (Brand::query()->whereIn('id', $data['ids'])->get() as $brand) {
            $this->brands->duplicate($brand);
            $copied++;
        }

        return back()->with('success', $copied === 1 ? 'Brand duplicated.' : $copied.' brands duplicated.');
    }

    public function bulkStatus(Request $request): RedirectResponse
    {
        $this->authorizeBrand('update');
        $request->merge([
            'ids' => array_values(array_filter(array_map('intval', (array) $request->input('ids', [])))),
            'enabled' => $this->toBool($request->input('enabled')),
        ]);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'enabled' => ['required', 'boolean'],
        ]);

        $updated = Brand::query()
            ->whereIn('id', $data['ids'])
            ->update(['is_enabled' => (bool) $data['enabled']]);

        if ($updated === 0) {
            return back()->with('error', 'No brands were updated.');
        }

        return back()->with('success', $data['enabled']
            ? ($updated === 1 ? 'Brand enabled.' : $updated.' brands enabled.')
            : ($updated === 1 ? 'Brand disabled. Existing product assignments were kept.' : $updated.' brands disabled. Existing product assignments were kept.'));
    }

    /** @return array<string, mixed> */
    public function inertiaCreateProps(): array
    {
        return $this->formProps();
    }

    /** @return array<string, mixed> */
    public function inertiaEditProps(Brand $brand): array
    {
        $brand->load(['translations', 'images']);

        return [
            ...$this->formProps(),
            'brand' => $this->brands->present($brand),
        ];
    }

    /** @return array<string, mixed> */
    public function validateBrandPayload(Request $request, ?Brand $brand = null): array
    {
        return $this->validated($request, $brand);
    }

    /** @return array<string, mixed> */
    public function brandFiles(Request $request): array
    {
        return $this->files($request);
    }

    protected function validated(Request $request, ?Brand $brand = null): array
    {
        $locale = Language::defaultCode();
        $year = (int) date('Y') + 1;
        $url = ['nullable', 'string', 'max:255', 'url'];
        $maxKb = (int) config('wento.upload.max_image_kb');

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:180',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('brand_translations', 'slug')
                    ->where(fn ($query) => $query->where('language_code', $locale))
                    ->ignore($brand?->translations()->where('language_code', $locale)->value('id')),
            ],
            'short_description' => ['nullable', 'string'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'website' => $url,
            'country_code' => ['nullable', 'string', 'max:8', Rule::exists('countries', 'code')],
            'established_year' => ['nullable', 'integer', 'min:1800', 'max:'.$year],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postcode' => ['required', 'string', 'max:32'],
            'country' => ['required', 'string', 'max:120', Rule::exists('countries', 'code')->where('is_enabled', true)],
            'facebook_url' => $url,
            'instagram_url' => $url,
            'youtube_url' => $url,
            'twitter_url' => $url,
            'linkedin_url' => $url,
            'tiktok_url' => $url,
            'meta_title' => ['nullable', 'string', 'max:60'],
            'meta_description' => ['nullable', 'string', 'max:160'],
            'meta_keywords' => ['nullable', 'string', 'max:255'],
            'translations' => ['nullable', 'array'],
            'translations.*.language_code' => ['required_with:translations', 'string', Rule::exists('languages', 'code')->where('is_enabled', true)],
            'translations.*.name' => ['nullable', 'string', 'max:255'],
            'translations.*.slug' => ['nullable', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'translations.*.short_description' => ['nullable', 'string'],
            'translations.*.tagline' => ['nullable', 'string', 'max:255'],
            'translations.*.description' => ['nullable', 'string'],
            'translations.*.meta_title' => ['nullable', 'string', 'max:60'],
            'translations.*.meta_description' => ['nullable', 'string', 'max:160'],
            'translations.*.meta_keywords' => ['nullable', 'string', 'max:255'],
            'is_enabled' => ['nullable', 'boolean'],
            'remove_logo' => ['nullable', 'boolean'],
            'remove_banner' => ['nullable', 'boolean'],
            'gallery_keep' => ['nullable', 'array'],
            'gallery_keep.*' => ['integer'],
            'logo' => ['nullable', 'file', 'max:'.$maxKb],
            'banner' => ['nullable', 'file', 'max:'.$maxKb],
            'gallery' => ['nullable', 'array'],
            'gallery.*' => ['file', 'max:'.$maxKb],
        ], [
            'slug.regex' => 'The slug may only contain lowercase letters, numbers, and hyphens.',
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

        $data['is_enabled'] = $request->boolean('is_enabled');
        $data['remove_logo'] = $request->boolean('remove_logo');
        $data['remove_banner'] = $request->boolean('remove_banner');
        $data['gallery_keep'] = array_values(array_map('intval', $data['gallery_keep'] ?? []));
        $data['address_line2'] = ($data['address_line2'] ?? '') ?: null;
        $data['translations'] = $this->normalizeTranslations($data);
        $default = $data['translations'][$locale] ?? null;
        if ($default) {
            $data['name'] = $default['name'];
            $data['slug'] = $default['slug'];
            $data['short_description'] = $default['short_description'];
            $data['tagline'] = $default['tagline'];
            $data['description'] = $default['description'];
            $data['meta_title'] = $default['meta_title'];
            $data['meta_description'] = $default['meta_description'];
            $data['meta_keywords'] = $default['meta_keywords'];
        }
        $data['language_code'] = $locale;

        if (! $request->has('country_code')) {
            unset($data['country_code']);
        }
        if (! $request->has('established_year')) {
            unset($data['established_year']);
        }

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
                'short_description' => $row['short_description'] ?? null,
                'tagline' => trim((string) ($row['tagline'] ?? '')),
                'description' => $row['description'] ?? null,
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
                'short_description' => $data['short_description'] ?? null,
                'tagline' => trim((string) ($data['tagline'] ?? '')),
                'description' => $data['description'] ?? null,
                'meta_title' => trim((string) ($data['meta_title'] ?? '')),
                'meta_description' => trim((string) ($data['meta_description'] ?? '')),
                'meta_keywords' => trim((string) ($data['meta_keywords'] ?? '')),
            ];
        }

        if (($rows[$default]['name'] ?? '') === '') {
            throw ValidationException::withMessages([
                "translations.$default.name" => 'Brand name is required for the default language.',
            ]);
        }

        foreach ($rows as $language => $row) {
            $hasContent = collect($row)
                ->except(['language_code'])
                ->contains(fn ($value) => is_string($value) ? trim($value) !== '' : $value !== null);

            if ($language !== $default && $hasContent && $row['name'] === '') {
                throw ValidationException::withMessages([
                    "translations.$language.name" => 'Brand name is required when saving a translation.',
                ]);
            }
        }

        return $rows;
    }

    protected function files(Request $request): array
    {
        return [
            'logo' => $request->file('logo'),
            'banner' => $request->file('banner'),
            'gallery' => array_values($request->file('gallery', []) ?: []),
        ];
    }

    protected function formProps(): array
    {
        return [
            'countries' => Country::optionsForAddresses(),
        ];
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

    protected function authorizeBrand(string $ability): void
    {
        $admin = $this->admin();
        $map = [
            'view' => 'catalog.brands.view',
            'create' => 'catalog.brands.create',
            'update' => 'catalog.brands.update',
            'delete' => 'catalog.brands.delete',
        ];
        $slug = $map[$ability] ?? 'catalog.brands.view';
        if ($admin && ($admin->hasPermission('catalog.manage') || $admin->hasPermission($slug))) {
            return;
        }

        abort(403, 'You do not have permission to perform this action.');
    }

    protected function admin(): ?Admin
    {
        return request()->user('admin');
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
