<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\FeatureTranslation;
use App\Domain\Catalog\Models\FeatureValue;
use App\Domain\Catalog\Models\FeatureValueTranslation;
use App\Domain\Catalog\Services\FeatureService;
use App\Domain\Catalog\Support\FeatureValueCsvSchema;
use App\Domain\Localization\Models\Language;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FeatureValueController extends Controller
{
    public function __construct(
        protected FeatureService $features,
    ) {}

    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: 'all';
        $q = trim($request->string('q')->toString());
        $value = trim($request->string('value')->toString());
        $featureName = trim($request->string('feature')->toString());
        $featureId = $request->integer('feature_id') ?: null;
        $idMin = $request->input('id_min');
        $idMax = $request->input('id_max');
        $positionMin = $request->input('position_min');
        $positionMax = $request->input('position_max');
        $sort = $request->string('sort')->toString() ?: 'id';
        $dir = $request->string('dir')->toString() === 'asc' ? 'asc' : 'desc';
        $allowedSort = ['id', 'position', 'value', 'feature', 'is_enabled'];
        if (! in_array($sort, $allowedSort, true)) {
            $sort = 'id';
        }

        $base = FeatureValue::query()->when($featureId, fn ($query) => $query->where('feature_id', $featureId));
        $counts = [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('is_enabled', true)->count(),
            'inactive' => (clone $base)->where('is_enabled', false)->count(),
        ];

        $values = FeatureValue::query()
            ->with(['translations', 'feature.translations'])
            ->withCount('products')
            ->when($featureId, fn ($query) => $query->where('feature_id', $featureId))
            ->when($status === 'active', fn ($query) => $query->where('is_enabled', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_enabled', false))
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->whereHas('translations', fn ($t) => $t->where('value', 'like', "%{$q}%"))
                        ->orWhereHas('feature.translations', fn ($t) => $t->where('name', 'like', "%{$q}%"));
                });
            })
            ->when($value !== '', function ($query) use ($value) {
                $query->whereHas('translations', fn ($t) => $t->where('value', 'like', "%{$value}%"));
            })
            ->when($featureName !== '', function ($query) use ($featureName) {
                $query->whereHas('feature.translations', fn ($t) => $t->where('name', 'like', "%{$featureName}%"));
            })
            ->when($idMin !== null && $idMin !== '', fn ($query) => $query->where('feature_values.id', '>=', (int) $idMin))
            ->when($idMax !== null && $idMax !== '', fn ($query) => $query->where('feature_values.id', '<=', (int) $idMax))
            ->when($positionMin !== null && $positionMin !== '', fn ($query) => $query->where('feature_values.position', '>=', (int) $positionMin))
            ->when($positionMax !== null && $positionMax !== '', fn ($query) => $query->where('feature_values.position', '<=', (int) $positionMax))
            ->when($sort === 'value', function ($query) use ($dir) {
                $query->orderBy(
                    FeatureValueTranslation::query()
                        ->select('value')
                        ->whereColumn('feature_value_id', 'feature_values.id')
                        ->limit(1),
                    $dir
                )->orderBy('id');
            })
            ->when($sort === 'feature', function ($query) use ($dir) {
                $query->orderBy(
                    FeatureTranslation::query()
                        ->select('name')
                        ->whereColumn('feature_id', 'feature_values.feature_id')
                        ->limit(1),
                    $dir
                )->orderBy('id');
            })
            ->when(! in_array($sort, ['value', 'feature'], true), function ($query) use ($sort, $dir) {
                if ($sort === 'id') {
                    $query->orderBy('feature_values.id', $dir);
                } else {
                    $query->orderBy($sort, $dir)->orderBy('id');
                }
            })
            ->adminPaginate()
            ->through(function (FeatureValue $row) {
                $presented = $this->features->presentValue($row);
                $presented['actions'] = [
                    'edit' => true,
                    'delete' => true,
                ];

                return $presented;
            });

        $feature = null;
        if ($featureId) {
            $model = Feature::query()->with('translations')->find($featureId);
            if ($model) {
                $feature = [
                    'id' => $model->id,
                    'name' => $model->translation()?->name ?? ('#'.$model->id),
                ];
            }
        }

        return Inertia::render('Catalog/FeatureValues/Index', [
            'values' => $values,
            'counts' => $counts,
            'featureOptions' => $this->features->featureOptions(),
            'feature' => $feature,
            'filters' => [
                'status' => $status,
                'q' => $q,
                'value' => $value,
                'feature' => $featureName,
                'feature_id' => $featureId,
                'id_min' => $idMin !== null && $idMin !== '' ? (string) $idMin : '',
                'id_max' => $idMax !== null && $idMax !== '' ? (string) $idMax : '',
                'position_min' => $positionMin !== null && $positionMin !== '' ? (string) $positionMin : '',
                'position_max' => $positionMax !== null && $positionMax !== '' ? (string) $positionMax : '',
                'sort' => $sort,
                'dir' => $dir,
            ],
        ]);
    }

    public function export(Request $request)
    {
        $featureId = $request->integer('feature_id') ?: null;

        return $this->csvDownload('feature-values.csv', FeatureValueCsvSchema::exportCsv(featureId: $featureId));
    }

    public function importSample()
    {
        return $this->csvDownload('feature-values-sample.csv', FeatureValueCsvSchema::sampleCsv());
    }

    public function import(Request $request): RedirectResponse
    {
        return $this->csvImport($request, FeatureValueCsvSchema::import(...), 'feature values');
    }

    public function create(Request $request): Response
    {
        $featureId = $request->integer('feature_id') ?: null;
        $options = $this->features->featureOptions();

        return Inertia::render('Catalog/FeatureValues/Form', [
            'features' => $options,
            'featureId' => $featureId,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $value = $this->features->saveValue($this->validated($request));

        return redirect()
            ->route('admin.feature-values.index', array_filter(['feature_id' => $value->feature_id]))
            ->with('success', 'Feature value created.');
    }

    public function edit(FeatureValue $featureValue): Response
    {
        $featureValue->load(['translations', 'feature.translations']);

        return Inertia::render('Catalog/FeatureValues/Form', [
            'featureValue' => $this->features->presentValue($featureValue),
            'features' => $this->features->featureOptions($featureValue->feature_id),
        ]);
    }

    public function update(Request $request, FeatureValue $featureValue): RedirectResponse
    {
        $value = $this->features->saveValue($this->validated($request, $featureValue), $featureValue);

        return redirect()
            ->route('admin.feature-values.index', array_filter(['feature_id' => $value->feature_id]))
            ->with('success', 'Feature value updated.');
    }

    public function destroy(FeatureValue $featureValue): RedirectResponse
    {
        try {
            $this->features->deleteValue($featureValue);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('admin.feature-values.index')->with('success', 'Feature value deleted.');
    }

    public function toggle(FeatureValue $featureValue): RedirectResponse
    {
        $featureValue->update(['is_enabled' => ! $featureValue->is_enabled]);

        return back()->with('success', $featureValue->is_enabled
            ? 'Feature value enabled.'
            : 'Feature value disabled. Existing product assignments were kept.');
    }

    public function move(Request $request, FeatureValue $featureValue): RedirectResponse
    {
        $data = $request->validate([
            'direction' => ['required', 'in:up,down'],
        ]);
        $this->features->moveValue($featureValue, $data['direction']);

        return back()->with('success', 'Sort order updated.');
    }

    public function reorder(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:2'],
            'ids.*' => ['integer', 'exists:feature_values,id'],
        ]);
        $this->features->reorderValues($data['ids']);

        return back()->with('success', 'Sort order updated.');
    }

    protected function validated(Request $request, ?FeatureValue $featureValue = null): array
    {
        $request->merge([
            'feature_id' => $request->filled('feature_id') ? $request->integer('feature_id') : null,
        ]);

        $data = $request->validate([
            'feature_id' => ['required', 'integer', 'exists:features,id'],
            'value' => ['required_without:translations', 'nullable', 'string', 'max:255'],
            'translations' => ['nullable', 'array'],
            'translations.*.language_code' => ['required_with:translations', 'string', Rule::exists('languages', 'code')->where('is_enabled', true)],
            'translations.*.value' => ['nullable', 'string', 'max:255'],
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
                "translations.$default.value" => 'Feature value is required for the default language.',
            ]);
        }

        return $rows;
    }
}
