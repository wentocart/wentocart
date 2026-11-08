<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\AttributeTranslation;
use App\Domain\Catalog\Models\AttributeValue;
use App\Domain\Catalog\Models\AttributeValueTranslation;
use App\Domain\Catalog\Models\CatalogAttribute;
use App\Domain\Catalog\Services\AttributeService;
use App\Domain\Catalog\Support\AttributeValueCsvSchema;
use App\Domain\Localization\Models\Language;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AttributeValueController extends Controller
{
    public function __construct(
        protected AttributeService $attributes,
    ) {}

    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: 'all';
        $q = trim($request->string('q')->toString());
        $value = trim($request->string('value')->toString());
        $attributeId = $request->integer('attribute_id') ?: null;
        $positionMin = $request->input('position_min');
        $positionMax = $request->input('position_max');
        $sort = $request->string('sort')->toString() ?: 'id';
        $dir = $request->string('dir')->toString() === 'asc' ? 'asc' : 'desc';
        $allowedSort = ['id', 'position', 'value', 'attribute', 'is_enabled'];
        if (! in_array($sort, $allowedSort, true)) {
            $sort = 'id';
        }

        $base = AttributeValue::query()->when($attributeId, fn ($query) => $query->where('attribute_id', $attributeId));
        $counts = [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('is_enabled', true)->count(),
            'inactive' => (clone $base)->where('is_enabled', false)->count(),
        ];

        $values = AttributeValue::query()
            ->with(['translations', 'attribute.translations'])
            ->when($attributeId, fn ($query) => $query->where('attribute_id', $attributeId))
            ->when($status === 'active', fn ($query) => $query->where('is_enabled', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_enabled', false))
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->whereHas('translations', fn ($t) => $t->where('value', 'like', "%{$q}%"))
                        ->orWhereHas('attribute.translations', fn ($t) => $t->where('name', 'like', "%{$q}%"));
                });
            })
            ->when($value !== '', function ($query) use ($value) {
                $query->whereHas('translations', fn ($t) => $t->where('value', 'like', "%{$value}%"));
            })
            ->when($positionMin !== null && $positionMin !== '', fn ($query) => $query->where('attribute_values.position', '>=', (int) $positionMin))
            ->when($positionMax !== null && $positionMax !== '', fn ($query) => $query->where('attribute_values.position', '<=', (int) $positionMax))
            ->when($sort === 'value', function ($query) use ($dir) {
                $query->orderBy(
                    AttributeValueTranslation::query()
                        ->select('value')
                        ->whereColumn('attribute_value_id', 'attribute_values.id')
                        ->limit(1),
                    $dir
                )->orderBy('id');
            })
            ->when($sort === 'attribute', function ($query) use ($dir) {
                $query->orderBy(
                    AttributeTranslation::query()
                        ->select('name')
                        ->whereColumn('attribute_id', 'attribute_values.attribute_id')
                        ->limit(1),
                    $dir
                )->orderBy('id');
            })
            ->when(! in_array($sort, ['value', 'attribute'], true), function ($query) use ($sort, $dir) {
                $query->orderBy($sort, $dir)->orderBy('id');
            })
            ->adminPaginate()
            ->through(function (AttributeValue $row) {
                $presented = $this->attributes->presentValue($row);
                $presented['actions'] = ['edit' => true, 'delete' => $presented['products_count'] === 0];

                return $presented;
            });

        $attribute = null;
        if ($attributeId) {
            $model = CatalogAttribute::query()->with('translations')->find($attributeId);
            if ($model) {
                $attribute = [
                    'id' => $model->id,
                    'name' => $model->translation()?->name ?? ('#'.$model->id),
                ];
            }
        }

        return Inertia::render('Catalog/AttributeValues/Index', [
            'values' => $values,
            'counts' => $counts,
            'attributeOptions' => $this->attributes->attributeOptions(),
            'attribute' => $attribute,
            'filters' => [
                'status' => $status,
                'q' => $q,
                'value' => $value,
                'attribute_id' => $attributeId,
                'position_min' => $positionMin !== null && $positionMin !== '' ? (string) $positionMin : '',
                'position_max' => $positionMax !== null && $positionMax !== '' ? (string) $positionMax : '',
                'sort' => $sort,
                'dir' => $dir,
            ],
        ]);
    }

    public function export(Request $request)
    {
        $attributeId = $request->integer('attribute_id') ?: null;

        return $this->csvDownload('attribute-values.csv', AttributeValueCsvSchema::exportCsv(attributeId: $attributeId));
    }

    public function importSample()
    {
        return $this->csvDownload('attribute-values-sample.csv', AttributeValueCsvSchema::sampleCsv());
    }

    public function import(Request $request): RedirectResponse
    {
        return $this->csvImport($request, AttributeValueCsvSchema::import(...), 'attribute values');
    }

    public function create(Request $request): Response
    {
        $attributeId = $request->integer('attribute_id') ?: null;

        return Inertia::render('Catalog/AttributeValues/Form', [
            'attributes' => $this->attributes->attributeOptions(),
            'attributeId' => $attributeId,
            'swatchTypes' => CatalogAttribute::SWATCH_TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $value = $this->attributes->saveValue($this->validated($request));

        return redirect()
            ->route('admin.attribute-values.index', array_filter(['attribute_id' => $value->attribute_id]))
            ->with('success', 'Attribute value created.');
    }

    public function edit(AttributeValue $attributeValue): Response
    {
        $attributeValue->load(['translations', 'attribute.translations']);

        return Inertia::render('Catalog/AttributeValues/Form', [
            'attributeValue' => $this->attributes->presentValue($attributeValue),
            'attributes' => $this->attributes->attributeOptions($attributeValue->attribute_id),
            'swatchTypes' => CatalogAttribute::SWATCH_TYPES,
        ]);
    }

    public function update(Request $request, AttributeValue $attributeValue): RedirectResponse
    {
        $value = $this->attributes->saveValue($this->validated($request, $attributeValue), $attributeValue);

        return redirect()
            ->route('admin.attribute-values.index', array_filter(['attribute_id' => $value->attribute_id]))
            ->with('success', 'Attribute value updated.');
    }

    public function destroy(AttributeValue $attributeValue): RedirectResponse
    {
        try {
            $this->attributes->deleteValue($attributeValue);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('admin.attribute-values.index')->with('success', 'Attribute value deleted.');
    }

    public function toggle(AttributeValue $attributeValue): RedirectResponse
    {
        $attributeValue->update(['is_enabled' => ! $attributeValue->is_enabled]);

        return back()->with('success', $attributeValue->is_enabled
            ? 'Attribute value enabled.'
            : 'Attribute value disabled. Existing combinations were kept.');
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

        $updated = AttributeValue::query()
            ->whereIn('id', $data['ids'])
            ->update(['is_enabled' => (bool) $data['enabled']]);

        if ($updated === 0) {
            return back()->with('error', 'No attribute values were updated.');
        }

        return back()->with('success', $data['enabled']
            ? ($updated === 1 ? 'Attribute value enabled.' : $updated.' attribute values enabled.')
            : ($updated === 1 ? 'Attribute value disabled.' : $updated.' attribute values disabled.'));
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $deleted = $this->attributes->bulkDeleteValues($data['ids']);

        return back()->with('success', $deleted > 0
            ? "{$deleted} attribute value(s) deleted."
            : 'No attribute values were deleted because they are still in use.');
    }

    public function move(Request $request, AttributeValue $attributeValue): RedirectResponse
    {
        $data = $request->validate(['direction' => ['required', 'in:up,down']]);
        $this->attributes->moveValue($attributeValue, $data['direction']);

        return back()->with('success', 'Sort order updated.');
    }

    public function reorder(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:2'],
            'ids.*' => ['integer', 'exists:attribute_values,id'],
        ]);
        $this->attributes->reorderValues($data['ids']);

        return back()->with('success', 'Sort order updated.');
    }

    protected function validated(Request $request, ?AttributeValue $attributeValue = null): array
    {
        $request->merge([
            'attribute_id' => $request->filled('attribute_id') ? $request->integer('attribute_id') : null,
        ]);

        $data = $request->validate([
            'attribute_id' => ['required', 'integer', 'exists:attributes,id'],
            'value' => ['required_without:translations', 'nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:64'],
            'translations' => ['nullable', 'array'],
            'translations.*.language_code' => ['required_with:translations', 'string', Rule::exists('languages', 'code')->where('is_enabled', true)],
            'translations.*.value' => ['nullable', 'string', 'max:255'],
            'swatch_type' => ['nullable', Rule::in(array_keys(CatalogAttribute::SWATCH_TYPES))],
            'color_code' => ['nullable', 'string', 'max:32'],
            'swatch_image' => ['nullable', 'string', 'max:255'],
            'is_enabled' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        $data['is_enabled'] = $request->boolean('is_enabled');
        $data['translations'] = $this->normalizeTranslations($data);
        $default = Language::defaultCode();
        $data['value'] = $data['translations'][$default]['value'] ?? '';
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
                'value' => trim((string) ($row['value'] ?? '')),
            ];
        }

        if ($rows === []) {
            $rows[$default] = [
                'language_code' => $default,
                'value' => trim((string) ($data['value'] ?? '')),
            ];
        }

        if (($rows[$default]['value'] ?? '') === '') {
            throw ValidationException::withMessages([
                'value' => 'Attribute value is required.',
                "translations.$default.value" => 'Attribute value is required for the default language.',
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
