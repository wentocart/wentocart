<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\FeatureTranslation;
use App\Domain\Catalog\Services\FeatureService;
use App\Domain\Catalog\Support\FeatureCsvSchema;
use App\Domain\Localization\Models\Language;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FeatureController extends Controller
{
    public function __construct(
        protected FeatureService $features,
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

        $base = Feature::query();
        $counts = [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('is_enabled', true)->count(),
            'inactive' => (clone $base)->where('is_enabled', false)->count(),
        ];

        $features = Feature::query()
            ->with('translations')
            ->withCount(['values', 'products'])
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
            ->when($type !== '' && array_key_exists($type, Feature::TYPES), fn ($query) => $query->where('type', $type))
            ->when($idMin !== null && $idMin !== '', fn ($query) => $query->where('features.id', '>=', (int) $idMin))
            ->when($idMax !== null && $idMax !== '', fn ($query) => $query->where('features.id', '<=', (int) $idMax))
            ->when($valuesMin !== null && $valuesMin !== '', fn ($query) => $query->has('values', '>=', (int) $valuesMin))
            ->when($valuesMax !== null && $valuesMax !== '', fn ($query) => $query->has('values', '<=', (int) $valuesMax))
            ->when($positionMin !== null && $positionMin !== '', fn ($query) => $query->where('features.position', '>=', (int) $positionMin))
            ->when($positionMax !== null && $positionMax !== '', fn ($query) => $query->where('features.position', '<=', (int) $positionMax))
            ->when($sort === 'name', function ($query) use ($dir) {
                $query->orderBy(
                    FeatureTranslation::query()
                        ->select('name')
                        ->whereColumn('feature_id', 'features.id')
                        ->limit(1),
                    $dir
                )->orderBy('id');
            }, function ($query) use ($sort, $dir) {
                if ($sort === 'id') {
                    $query->orderBy('features.id', $dir);
                } else {
                    $query->orderBy($sort, $dir)->orderBy('id');
                }
            })
            ->adminPaginate()
            ->through(function (Feature $feature) {
                $row = $this->features->presentFeature($feature);
                $row['actions'] = [
                    'edit' => true,
                    'delete' => ((int) ($row['products_count'] ?? 0)) === 0,
                ];

                return $row;
            });

        return Inertia::render('Catalog/Features/Index', [
            'features' => $features,
            'counts' => $counts,
            'typeOptions' => Feature::typeOptions(),
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
        return Inertia::render('Catalog/Features/Form', $this->inertiaCreateProps());
    }

    public function store(Request $request): RedirectResponse
    {
        $this->features->saveFeature($this->validated($request));

        return redirect()->route('admin.features.index')->with('success', 'Feature created.');
    }

    public function edit(Feature $feature): Response
    {
        return Inertia::render('Catalog/Features/Form', $this->inertiaEditProps($feature));
    }

    public function update(Request $request, Feature $feature): RedirectResponse
    {
        $this->features->saveFeature($this->validated($request, $feature), $feature);

        return redirect()->route('admin.features.index')->with('success', 'Feature updated.');
    }

    public function destroy(Feature $feature): RedirectResponse
    {
        try {
            $this->features->deleteFeature($feature);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('admin.features.index')->with('success', 'Feature deleted.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $deleted = 0;
        $blocked = 0;
        foreach (Feature::query()->whereIn('id', $data['ids'])->get() as $feature) {
            try {
                $this->features->deleteFeature($feature);
                $deleted++;
            } catch (ValidationException) {
                $blocked++;
            }
        }

        if ($deleted && $blocked) {
            return back()->with('warning', $deleted.' feature(s) deleted. '.$blocked.' could not be deleted because they are assigned to products.');
        }
        if ($blocked) {
            return back()->with('error', 'None of the selected features could be deleted. Remove them from products first.');
        }

        return back()->with('success', $deleted === 1 ? 'Feature deleted.' : $deleted.' features deleted.');
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

        $updated = Feature::query()
            ->whereIn('id', $data['ids'])
            ->update(['is_enabled' => (bool) $data['enabled']]);

        return back()->with('success', $data['enabled']
            ? ($updated === 1 ? 'Feature enabled.' : $updated.' features enabled.')
            : ($updated === 1 ? 'Feature disabled.' : $updated.' features disabled.'));
    }

    public function bulkDuplicate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $copied = 0;
        foreach (Feature::query()->whereIn('id', $data['ids'])->get() as $feature) {
            $this->features->duplicateFeature($feature);
            $copied++;
        }

        if ($copied === 0) {
            return back()->with('error', 'No features were duplicated.');
        }

        return back()->with('success', $copied === 1 ? 'Feature duplicated.' : $copied.' features duplicated.');
    }

    public function toggle(Feature $feature): RedirectResponse
    {
        $feature->update(['is_enabled' => ! $feature->is_enabled]);

        return back()->with('success', $feature->is_enabled
            ? 'Feature enabled.'
            : 'Feature disabled. Existing product assignments were kept.');
    }

    public function reorder(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:2'],
            'ids.*' => ['integer', 'exists:features,id'],
        ]);
        $this->features->reorderFeatures($data['ids']);

        return back()->with('success', 'Sort order updated.');
    }

    public function export(Request $request): StreamedResponse
    {
        $delimiter = (string) $request->input('delimiter', FeatureCsvSchema::DEFAULT_DELIMITER);
        $multi = (string) $request->input('multivalue_separator', FeatureCsvSchema::DEFAULT_MULTI_SEPARATOR);
        $csv = $this->features->exportCsv(Language::defaultCode(), $request, $delimiter, $multi);

        return response()->streamDownload(fn () => print ($csv), 'features.csv', [
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

        $count = $this->features->importCsv(
            $request->file('file')->getContent(),
            $data['language'] ?? Language::defaultCode(),
            $data['delimiter'] ?? FeatureCsvSchema::DEFAULT_DELIMITER,
            $data['multivalue_separator'] ?? FeatureCsvSchema::DEFAULT_MULTI_SEPARATOR,
        );

        return back()->with('success', "Imported {$count} features.");
    }

    public function importSample(Request $request): StreamedResponse
    {
        $delimiter = (string) $request->input('delimiter', FeatureCsvSchema::DEFAULT_DELIMITER);
        $multi = (string) $request->input('multivalue_separator', FeatureCsvSchema::DEFAULT_MULTI_SEPARATOR);
        $csv = $this->features->sampleCsv($delimiter, $multi);

        return response()->streamDownload(fn () => print ($csv), 'features-sample.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** @return array<string, mixed> */
    public function inertiaCreateProps(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function inertiaEditProps(Feature $feature): array
    {
        $feature->load(['translations', 'values']);

        return [
            'feature' => $this->features->presentFeature($feature),
        ];
    }

    /** @return array<string, mixed> */
    public function validateFeaturePayload(Request $request, ?Feature $feature = null): array
    {
        return $this->validated($request, $feature);
    }

    protected function validated(Request $request, ?Feature $feature = null): array
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'translations' => ['nullable', 'array'],
            'translations.*.language_code' => ['required_with:translations', 'string', Rule::exists('languages', 'code')->where('is_enabled', true)],
            'translations.*.name' => ['nullable', 'string', 'max:255'],
            'translations.*.description' => ['nullable', 'string'],
            'is_enabled' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        $data['is_enabled'] = $request->boolean('is_enabled');
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
                "translations.$default.name" => 'Feature name is required for the default language.',
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
