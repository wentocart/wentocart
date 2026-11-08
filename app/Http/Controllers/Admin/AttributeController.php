<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\AttributeTranslation;
use App\Domain\Catalog\Models\CatalogAttribute;
use App\Domain\Catalog\Services\AttributeService;
use App\Domain\Catalog\Support\AttributeCsvSchema;
use App\Domain\Localization\Models\Language;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttributeController extends Controller
{
    public function __construct(
        protected AttributeService $attributes,
    ) {}

    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: 'all';
        $q = trim($request->string('q')->toString());
        $name = trim($request->string('name')->toString());
        $type = trim($request->string('type')->toString());
        $idMin = $request->input('id_min');
        $idMax = $request->input('id_max');
        $valuesMin = $request->input('values_min');
        $valuesMax = $request->input('values_max');
        $positionMin = $request->input('position_min');
        $positionMax = $request->input('position_max');
        $sort = $request->string('sort')->toString() ?: 'id';
        $dir = $request->string('dir')->toString() === 'asc' ? 'asc' : 'desc';
        $allowedSort = ['id', 'position', 'name', 'type', 'is_enabled', 'values_count'];
        if (! in_array($sort, $allowedSort, true)) {
            $sort = 'id';
        }

        $base = CatalogAttribute::query();
        $counts = [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('is_enabled', true)->count(),
            'inactive' => (clone $base)->where('is_enabled', false)->count(),
        ];

        $attributes = CatalogAttribute::query()
            ->with('translations')
            ->withCount('values')
            ->when($status === 'active', fn ($query) => $query->where('is_enabled', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_enabled', false))
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('type', 'like', "%{$q}%")
                        ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', "%{$q}%"));
                });
            })
            ->when($name !== '', function ($query) use ($name) {
                $query->whereHas('translations', fn ($t) => $t->where('name', 'like', "%{$name}%"));
            })
            ->when($type !== '' && array_key_exists($type, CatalogAttribute::TYPES), fn ($query) => $query->where('type', $type))
            ->when($idMin !== null && $idMin !== '', fn ($query) => $query->where('attributes.id', '>=', (int) $idMin))
            ->when($idMax !== null && $idMax !== '', fn ($query) => $query->where('attributes.id', '<=', (int) $idMax))
            ->when($valuesMin !== null && $valuesMin !== '', fn ($query) => $query->has('values', '>=', (int) $valuesMin))
            ->when($valuesMax !== null && $valuesMax !== '', fn ($query) => $query->has('values', '<=', (int) $valuesMax))
            ->when($positionMin !== null && $positionMin !== '', fn ($query) => $query->where('attributes.position', '>=', (int) $positionMin))
            ->when($positionMax !== null && $positionMax !== '', fn ($query) => $query->where('attributes.position', '<=', (int) $positionMax))
            ->when($sort === 'name', function ($query) use ($dir) {
                $query->orderBy(
                    AttributeTranslation::query()
                        ->select('name')
                        ->whereColumn('attribute_id', 'attributes.id')
                        ->limit(1),
                    $dir
                )->orderBy('id');
            }, function ($query) use ($sort, $dir) {
                if ($sort === 'id') {
                    $query->orderBy('attributes.id', $dir);
                } elseif ($sort === 'values_count') {
                    $query->orderBy('values_count', $dir)->orderBy('id');
                } else {
                    $query->orderBy($sort, $dir)->orderBy('id');
                }
            })
            ->adminPaginate()
            ->through(function (CatalogAttribute $attribute) {
                $row = $this->attributes->presentAttribute($attribute);
                $row['actions'] = ['edit' => true, 'delete' => $row['products_count'] === 0];

                return $row;
            });

        return Inertia::render('Catalog/Attributes/Index', [
            'attributes' => $attributes,
            'counts' => $counts,
            'typeOptions' => CatalogAttribute::typeOptions(),
            'filters' => [
                'status' => $status,
                'q' => $q,
                'name' => $name,
                'type' => $type,
                'id_min' => $idMin !== null && $idMin !== '' ? (string) $idMin : '',
                'id_max' => $idMax !== null && $idMax !== '' ? (string) $idMax : '',
                'values_min' => $valuesMin !== null && $valuesMin !== '' ? (string) $valuesMin : '',
                'values_max' => $valuesMax !== null && $valuesMax !== '' ? (string) $valuesMax : '',
                'position_min' => $positionMin !== null && $positionMin !== '' ? (string) $positionMin : '',
                'position_max' => $positionMax !== null && $positionMax !== '' ? (string) $positionMax : '',
                'sort' => $sort,
                'dir' => $dir,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Catalog/Attributes/Form', [
            'typeOptions' => CatalogAttribute::typeOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->attributes->saveAttribute($this->validated($request));

        return redirect()->route('admin.attributes.index')->with('success', 'Attribute created.');
    }

    public function edit(CatalogAttribute $attribute): Response
    {
        $attribute->load(['translations', 'values']);

        return Inertia::render('Catalog/Attributes/Form', [
            'attribute' => $this->attributes->presentAttribute($attribute),
            'typeOptions' => CatalogAttribute::typeOptions(),
        ]);
    }

    public function update(Request $request, CatalogAttribute $attribute): RedirectResponse
    {
        $this->attributes->saveAttribute($this->validated($request, $attribute), $attribute);

        return redirect()->route('admin.attributes.index')->with('success', 'Attribute updated.');
    }

    public function destroy(CatalogAttribute $attribute): RedirectResponse
    {
        try {
            $this->attributes->deleteAttribute($attribute);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('admin.attributes.index')->with('success', 'Attribute deleted.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);
        $deleted = $this->attributes->bulkDeleteAttributes($data['ids']);

        return back()->with('success', $deleted > 0 ? "{$deleted} attribute(s) deleted." : 'No attributes were deleted because they are still in use.');
    }

    public function bulkStatus(Request $request): RedirectResponse
    {
        $request->merge([
            'ids' => array_values(array_filter(array_map('intval', (array) $request->input('ids', [])))),
            'enabled' => $this->toBool($request->input('enabled')),
        ]);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'enabled' => ['required', 'boolean'],
        ]);

        $updated = CatalogAttribute::query()
            ->whereIn('id', $data['ids'])
            ->update(['is_enabled' => (bool) $data['enabled']]);

        if ($updated === 0) {
            return back()->with('error', 'No attributes were updated.');
        }

        return back()->with('success', $data['enabled']
            ? ($updated === 1 ? 'Attribute enabled.' : $updated.' attributes enabled.')
            : ($updated === 1 ? 'Attribute disabled.' : $updated.' attributes disabled.'));
    }

    public function bulkDuplicate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $copied = 0;
        foreach (CatalogAttribute::query()->whereIn('id', $data['ids'])->get() as $attribute) {
            $this->attributes->duplicateAttribute($attribute);
            $copied++;
        }

        if ($copied === 0) {
            return back()->with('error', 'No attributes were duplicated.');
        }

        return back()->with('success', $copied === 1 ? 'Attribute duplicated.' : $copied.' attributes duplicated.');
    }

    public function toggle(CatalogAttribute $attribute): RedirectResponse
    {
        $attribute->update(['is_enabled' => ! $attribute->is_enabled]);

        return back()->with('success', $attribute->is_enabled
            ? 'Attribute enabled.'
            : 'Attribute disabled. Existing product combinations were kept.');
    }

    public function reorder(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:2'],
            'ids.*' => ['integer', 'exists:attributes,id'],
        ]);
        $this->attributes->reorderAttributes($data['ids']);

        return back()->with('success', 'Sort order updated.');
    }

    public function export(Request $request): StreamedResponse
    {
        $delimiter = (string) $request->input('delimiter', AttributeCsvSchema::DEFAULT_DELIMITER);
        $multi = (string) $request->input('multivalue_separator', AttributeCsvSchema::DEFAULT_MULTI_SEPARATOR);
        $csv = $this->attributes->exportCsv(Language::defaultCode(), $request, $delimiter, $multi);

        return response()->streamDownload(fn () => print ($csv), 'attributes.csv', [
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

        $count = $this->attributes->importCsv(
            $request->file('file')->getContent(),
            $data['language'] ?? Language::defaultCode(),
            $data['delimiter'] ?? AttributeCsvSchema::DEFAULT_DELIMITER,
            $data['multivalue_separator'] ?? AttributeCsvSchema::DEFAULT_MULTI_SEPARATOR,
        );

        return back()->with('success', "Imported {$count} attributes.");
    }

    public function importSample(Request $request): StreamedResponse
    {
        $delimiter = (string) $request->input('delimiter', AttributeCsvSchema::DEFAULT_DELIMITER);
        $multi = (string) $request->input('multivalue_separator', AttributeCsvSchema::DEFAULT_MULTI_SEPARATOR);
        $csv = $this->attributes->sampleCsv($delimiter, $multi);

        return response()->streamDownload(fn () => print ($csv), 'attributes-sample.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    protected function validated(Request $request, ?CatalogAttribute $attribute = null): array
    {
        $data = $request->validate([
            'name' => ['required_without:translations', 'nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'translations' => ['nullable', 'array'],
            'translations.*.language_code' => ['required_with:translations', 'string', Rule::exists('languages', 'code')->where('is_enabled', true)],
            'translations.*.name' => ['nullable', 'string', 'max:255'],
            'translations.*.description' => ['nullable', 'string'],
            'type' => ['required', Rule::in(array_keys(CatalogAttribute::TYPES))],
            'supports_swatches' => ['nullable', 'boolean'],
            'is_enabled' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        $data['is_enabled'] = $request->boolean('is_enabled');
        $data['supports_swatches'] = $request->boolean('supports_swatches');
        $data['translations'] = $this->normalizeTranslations($data);
        $default = Language::defaultCode();
        $data['name'] = $data['translations'][$default]['name'] ?? '';
        $data['description'] = $data['translations'][$default]['description'] ?? '';
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
                'description' => (string) ($row['description'] ?? ''),
            ];
        }

        if ($rows === []) {
            $rows[$default] = [
                'language_code' => $default,
                'name' => trim((string) ($data['name'] ?? '')),
                'description' => (string) ($data['description'] ?? ''),
            ];
        }

        if (($rows[$default]['name'] ?? '') === '') {
            throw ValidationException::withMessages([
                'name' => 'Attribute name is required.',
                "translations.$default.name" => 'Attribute name is required for the default language.',
            ]);
        }

        return $rows;
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
