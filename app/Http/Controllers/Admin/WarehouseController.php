<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\Warehouse;
use App\Domain\Catalog\Models\WarehouseStock;
use App\Domain\Catalog\Models\WarehouseTranslation;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Catalog\Support\CatalogMediaUrl;
use App\Domain\Catalog\Support\DefaultImage;
use App\Domain\Catalog\Support\WarehouseCsvSchema;
use App\Domain\Localization\Models\Country;
use App\Domain\Localization\Models\Language;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform-owned stock locations. Sellers do not manage warehouses.
 */
class WarehouseController extends Controller
{
    public function __construct(protected InventoryService $inventory) {}

    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: 'all';
        $q = trim($request->string('q')->toString());
        $name = trim($request->string('name')->toString());
        $code = trim($request->string('code')->toString());
        $idMin = $request->input('id_min');
        $idMax = $request->input('id_max');
        $stocksMin = $request->input('stocks_min');
        $stocksMax = $request->input('stocks_max');
        $isDefault = $request->string('is_default')->toString();
        $sort = $request->string('sort')->toString() ?: 'id';
        $dir = $request->string('dir')->toString() === 'asc' ? 'asc' : 'desc';
        $allowedSort = ['id', 'name', 'code', 'stocks_count', 'is_enabled', 'is_default'];
        if (! in_array($sort, $allowedSort, true)) {
            $sort = 'id';
        }

        $base = Warehouse::query();
        $counts = [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('is_enabled', true)->count(),
            'inactive' => (clone $base)->where('is_enabled', false)->count(),
        ];

        $totalWarehouses = $counts['all'];

        $warehouses = Warehouse::query()
            ->with('translations')
            ->withCount('stocks')
            ->when($status === 'active', fn ($query) => $query->where('is_enabled', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_enabled', false))
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('code', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%")
                        ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', "%{$q}%"));
                });
            })
            ->when($name !== '', function ($query) use ($name) {
                $query->where(function ($inner) use ($name) {
                    $inner->where('name', 'like', "%{$name}%")
                        ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', "%{$name}%"));
                });
            })
            ->when($code !== '', fn ($query) => $query->where('code', 'like', '%'.strtoupper($code).'%'))
            ->when($idMin !== null && $idMin !== '', fn ($query) => $query->where('warehouses.id', '>=', (int) $idMin))
            ->when($idMax !== null && $idMax !== '', fn ($query) => $query->where('warehouses.id', '<=', (int) $idMax))
            ->when($stocksMin !== null && $stocksMin !== '', fn ($query) => $query->has('stocks', '>=', (int) $stocksMin))
            ->when($stocksMax !== null && $stocksMax !== '', fn ($query) => $query->has('stocks', '<=', (int) $stocksMax))
            ->when($isDefault === '1' || $isDefault === 'true', fn ($query) => $query->where('is_default', true))
            ->when($isDefault === '0' || $isDefault === 'false', fn ($query) => $query->where('is_default', false))
            ->when($sort === 'stocks_count', fn ($query) => $query->orderBy('stocks_count', $dir)->orderBy('id'))
            ->when($sort !== 'stocks_count', fn ($query) => $query->orderBy($sort, $dir)->orderBy('id'))
            ->adminPaginate()
            ->through(fn (Warehouse $row) => $this->presentRow($row, $totalWarehouses));

        return Inertia::render('Catalog/Warehouses/Index', [
            'warehouses' => $warehouses,
            'counts' => $counts,
            'filters' => [
                'status' => $status,
                'q' => $q,
                'name' => $name,
                'code' => $code,
                'id_min' => $idMin !== null && $idMin !== '' ? (string) $idMin : '',
                'id_max' => $idMax !== null && $idMax !== '' ? (string) $idMax : '',
                'stocks_min' => $stocksMin !== null && $stocksMin !== '' ? (string) $stocksMin : '',
                'stocks_max' => $stocksMax !== null && $stocksMax !== '' ? (string) $stocksMax : '',
                'is_default' => $isDefault,
                'sort' => $sort,
                'dir' => $dir,
            ],
            'can' => [
                'create' => true,
                'update' => true,
                'delete' => true,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Catalog/Warehouses/Form', [
            'warehouse' => null,
            'countries' => Country::optionsForAddresses(),
        ]);
    }

    public function edit(Warehouse $warehouse): Response
    {
        $warehouse->load(['translations'])->loadCount('stocks');

        return Inertia::render('Catalog/Warehouses/Form', [
            'warehouse' => $this->presentRow($warehouse, Warehouse::query()->count()),
            'countries' => Country::optionsForAddresses(),
        ]);
    }

    public function export()
    {
        return $this->csvDownload('warehouses.csv', WarehouseCsvSchema::exportCsv());
    }

    public function importSample()
    {
        return $this->csvDownload('warehouses-sample.csv', WarehouseCsvSchema::sampleCsv());
    }

    public function import(Request $request): RedirectResponse
    {
        return $this->csvImport($request, WarehouseCsvSchema::import(...), 'warehouses');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($data, $request) {
            $translations = $data['translations'];
            $removeLogo = $data['remove_logo'] ?? false;
            unset($data['translations'], $data['language_code'], $data['remove_logo']);
            $warehouse = Warehouse::query()->create($data);
            $this->syncTranslations($warehouse, $translations);
            $this->syncLogo($warehouse, $request, ['remove_logo' => $removeLogo]);
            $this->inventory->ensureExactlyOneDefault($warehouse->is_default ? $warehouse : null);
        });

        return redirect()->route('admin.warehouses.index')->with('success', 'Warehouse saved.');
    }

    public function update(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $data = $this->validated($request, $warehouse);
        if ($warehouse->is_default && $request->exists('is_enabled') && ! $request->boolean('is_enabled')) {
            throw ValidationException::withMessages([
                'is_enabled' => 'The default warehouse cannot be disabled.',
            ]);
        }
        if ($warehouse->is_default && empty($data['is_default'])) {
            if (Warehouse::query()->where('id', '!=', $warehouse->id)->doesntExist()) {
                $data['is_default'] = true;
                $data['is_enabled'] = true;
            } else {
                throw ValidationException::withMessages([
                    'is_default' => 'Set another warehouse as default before unsetting this one.',
                ]);
            }
        }

        DB::transaction(function () use ($warehouse, $data, $request) {
            $translations = $data['translations'];
            $removeLogo = $data['remove_logo'] ?? false;
            unset($data['translations'], $data['language_code'], $data['remove_logo']);
            $warehouse->update($data);
            $this->syncTranslations($warehouse, $translations);
            $this->syncLogo($warehouse, $request, ['remove_logo' => $removeLogo]);
            $this->inventory->ensureExactlyOneDefault($warehouse->fresh()->is_default ? $warehouse->fresh() : null);
        });

        return redirect()->route('admin.warehouses.index')->with('success', 'Warehouse updated.');
    }

    public function toggle(Warehouse $warehouse): RedirectResponse
    {
        if ($warehouse->is_default) {
            if (! $warehouse->is_enabled) {
                $warehouse->update(['is_enabled' => true]);
            }

            return back()->with('error', 'The default warehouse cannot be disabled.');
        }

        $warehouse->update(['is_enabled' => ! $warehouse->is_enabled]);

        return back()->with('success', $warehouse->is_enabled ? 'Warehouse enabled.' : 'Warehouse disabled.');
    }

    public function bulkStatus(Request $request): RedirectResponse
    {
        $request->merge([
            'ids' => array_values(array_filter(array_map('intval', (array) $request->input('ids', [])))),
            'enabled' => filter_var($request->input('enabled'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        ]);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'enabled' => ['required', 'boolean'],
        ]);

        $updated = 0;
        $skipped = 0;

        foreach (Warehouse::query()->whereIn('id', $data['ids'])->get() as $warehouse) {
            if (! $data['enabled'] && $warehouse->is_default) {
                $skipped++;
                continue;
            }
            $warehouse->update(['is_enabled' => (bool) $data['enabled']]);
            $updated++;
        }

        if ($updated && $skipped) {
            return back()->with('warning', $updated.' warehouse(s) updated. '.$skipped.' default warehouse(s) were left unchanged.');
        }
        if ($skipped && ! $updated) {
            return back()->with('error', 'Default warehouses cannot be disabled.');
        }
        if ($updated === 0) {
            return back()->with('error', 'No warehouses were updated.');
        }

        return back()->with('success', $data['enabled']
            ? ($updated === 1 ? 'Warehouse enabled.' : $updated.' warehouses enabled.')
            : ($updated === 1 ? 'Warehouse disabled.' : $updated.' warehouses disabled.'));
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $deleted = 0;
        $blocked = 0;

        foreach (Warehouse::query()->whereIn('id', $data['ids'])->get() as $warehouse) {
            if (! $this->canDelete($warehouse)) {
                $blocked++;
                continue;
            }

            DB::transaction(function () use ($warehouse) {
                WarehouseStock::query()->where('warehouse_id', $warehouse->id)->delete();
                $warehouse->delete();
                $this->inventory->ensureExactlyOneDefault();
            });
            $deleted++;
        }

        if ($deleted && $blocked) {
            return back()->with('warning', $deleted.' warehouse(s) deleted. '.$blocked.' could not be deleted (default, last remaining, or still stocked).');
        }
        if ($blocked) {
            return back()->with('error', 'None of the selected warehouses could be deleted. Defaults, the last warehouse, and stocked locations are protected.');
        }

        return back()->with('success', $deleted === 1 ? 'Warehouse deleted.' : $deleted.' warehouses deleted.');
    }

    public function destroy(Warehouse $warehouse): RedirectResponse
    {
        if ($warehouse->is_default) {
            return back()->withErrors(['warehouse' => 'Set another warehouse as default before deleting this one.']);
        }
        if (Warehouse::query()->count() <= 1) {
            return back()->withErrors(['warehouse' => 'At least one warehouse is required.']);
        }

        if ($this->hasStock($warehouse)) {
            return back()->withErrors([
                'warehouse' => 'This warehouse still has stock. Move or clear stock before deleting it.',
            ]);
        }

        DB::transaction(function () use ($warehouse) {
            WarehouseStock::query()->where('warehouse_id', $warehouse->id)->delete();
            $warehouse->delete();
            $this->inventory->ensureExactlyOneDefault();
        });

        return redirect()->route('admin.warehouses.index')->with('success', 'Warehouse deleted.');
    }

    /** @return array<string, mixed> */
    private function presentRow(Warehouse $row, int $totalWarehouses): array
    {
        $hasStock = $this->hasStock($row);
        if (! $row->relationLoaded('translations')) {
            $row->load('translations');
        }

        return [
            'id' => $row->id,
            'name' => $row->displayName(),
            'code' => $row->code,
            'is_default' => (bool) $row->is_default,
            'is_enabled' => (bool) $row->is_enabled,
            'email' => $row->email,
            'phone' => $row->phone,
            'fax' => $row->fax,
            'website' => $row->website,
            'address' => $row->address,
            'address_line2' => $row->address_line2,
            'city' => $row->city,
            'state' => $this->normalizeStateCode($row->country, $row->state),
            'postcode' => $row->postcode,
            'country' => $row->country,
            'latitude' => $row->latitude,
            'longitude' => $row->longitude,
            'logo_url' => $row->logo_path
                ? CatalogMediaUrl::url($row->logo_path, DefaultImage::SM)
                : null,
            'stocks_count' => (int) ($row->stocks_count ?? 0),
            'translations' => $row->translations->map(fn (WarehouseTranslation $translation) => [
                'language_code' => $translation->language_code,
                'name' => $translation->name,
                'description' => $translation->description,
            ])->values()->all(),
            'actions' => [
                'edit' => true,
                'delete' => $this->canDelete($row, $totalWarehouses, $hasStock),
            ],
        ];
    }

    private function canDelete(Warehouse $warehouse, ?int $totalWarehouses = null, ?bool $hasStock = null): bool
    {
        $total = $totalWarehouses ?? Warehouse::query()->count();
        $stocked = $hasStock ?? $this->hasStock($warehouse);

        return ! $warehouse->is_default && $total > 1 && ! $stocked;
    }

    private function hasStock(Warehouse $warehouse): bool
    {
        return WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where(function ($q) {
                $q->where('qty', '>', 0)->orWhere('reserved_qty', '>', 0);
            })
            ->exists();
    }

    /**
     * @param  array<string, array<string, mixed>>  $translations
     */
    private function syncTranslations(Warehouse $warehouse, array $translations): void
    {
        $default = Language::defaultCode();

        foreach ($translations as $key => $row) {
            $row = (array) $row;
            $language = (string) ($row['language_code'] ?? $key);
            if ($language === '') {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            $description = $this->nullableString($row['description'] ?? null);
            if ($language !== $default && $name === '') {
                WarehouseTranslation::query()
                    ->where('warehouse_id', $warehouse->id)
                    ->where('language_code', $language)
                    ->delete();

                continue;
            }

            $warehouse->translations()->updateOrCreate(
                ['language_code' => $language],
                [
                    'name' => $name,
                    'description' => $description,
                ],
            );
        }
    }

    /** @param  array<string, mixed>  $data */
    private function syncLogo(Warehouse $warehouse, Request $request, array $data): void
    {
        if (! empty($data['remove_logo'])) {
            $this->deletePublicFile($warehouse->logo_path);
            $warehouse->logo_path = null;
            $warehouse->save();
        }
        if ($request->file('logo') instanceof UploadedFile) {
            $this->deletePublicFile($warehouse->logo_path);
            $warehouse->logo_path = $this->storeLogo($warehouse, $request->file('logo'));
            $warehouse->save();
        }
    }

    private function storeLogo(Warehouse $warehouse, UploadedFile $file): string
    {
        $dir = 'warehouses/'.$warehouse->id;
        $name = 'logo-'.Str::lower(Str::random(8)).'.'.$file->getClientOriginalExtension();

        return $file->storeAs($dir, $name, 'public');
    }

    private function deletePublicFile(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Warehouse $warehouse = null): array
    {
        foreach (['phone', 'fax', 'website', 'address_line2', 'latitude', 'longitude'] as $field) {
            if ($request->input($field) === '') {
                $request->merge([$field => null]);
            }
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'code' => [
                'required',
                'string',
                'max:32',
                Rule::unique('warehouses', 'code')->ignore($warehouse?->id),
            ],
            'translations' => ['nullable', 'array'],
            'translations.*.language_code' => ['required_with:translations', 'string', Rule::exists('languages', 'code')->where('is_enabled', true)],
            'translations.*.name' => ['nullable', 'string', 'max:120'],
            'translations.*.description' => ['nullable', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'fax' => ['nullable', 'string', 'max:40'],
            'website' => ['nullable', 'string', 'max:255', 'url'],
            'address' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postcode' => ['required', 'string', 'max:32'],
            'country' => ['required', 'string', 'max:120', Rule::exists('countries', 'code')->where('is_enabled', true)],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'logo' => ['nullable', 'file', 'image', 'max:'.(int) config('wento.upload.max_image_kb', 2048)],
            'remove_logo' => ['nullable', 'boolean'],
            'is_enabled' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $data['translations'] = $this->normalizeTranslations($data);
        $default = Language::defaultCode();
        $data['name'] = $data['translations'][$default]['name'] ?? '';
        $data['language_code'] = $default;
        $data['code'] = strtoupper(trim((string) $data['code']));
        $data['is_enabled'] = (bool) ($data['is_enabled'] ?? true);
        $data['is_default'] = (bool) ($data['is_default'] ?? false);
        $data['remove_logo'] = $request->boolean('remove_logo');
        if ($data['is_default']) {
            $data['is_enabled'] = true;
        }

        $data['phone'] = ($data['phone'] ?? '') ?: null;
        $data['fax'] = ($data['fax'] ?? '') ?: null;
        $data['website'] = ($data['website'] ?? '') ?: null;
        $data['address_line2'] = ($data['address_line2'] ?? '') ?: null;
        $data['latitude'] = ($data['latitude'] ?? '') !== '' && $data['latitude'] !== null ? $data['latitude'] : null;
        $data['longitude'] = ($data['longitude'] ?? '') !== '' && $data['longitude'] !== null ? $data['longitude'] : null;

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

        return $data;
    }

    private function normalizeStateCode(?string $countryCode, ?string $state): ?string
    {
        $state = trim((string) $state);
        if ($state === '' || ! $countryCode) {
            return $state !== '' ? $state : null;
        }

        $country = Country::query()
            ->where('code', $countryCode)
            ->with(['regions' => fn ($query) => $query->where('is_enabled', true)])
            ->first();

        if (! $country || ! $country->has_regions || $country->regions->isEmpty()) {
            return null;
        }

        $region = $country->regions->first(
            fn ($row) => $row->code === $state
                || strcasecmp((string) $row->name, $state) === 0
        );

        return $region?->code;
    }

    /** @return array<string, array<string, mixed>> */
    private function normalizeTranslations(array $data): array
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
                'description' => $this->nullableString($row['description'] ?? null),
            ];
        }

        if ($rows === []) {
            $rows[$default] = [
                'language_code' => $default,
                'name' => trim((string) ($data['name'] ?? '')),
                'description' => null,
            ];
        }

        if (($rows[$default]['name'] ?? '') === '') {
            throw ValidationException::withMessages([
                "translations.$default.name" => 'Warehouse name is required for the default language.',
            ]);
        }

        return $rows;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === '' || $value === null) ? null : (string) $value;
    }
}
