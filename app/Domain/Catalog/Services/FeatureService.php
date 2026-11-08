<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\FeatureTranslation;
use App\Domain\Catalog\Models\FeatureValue;
use App\Domain\Catalog\Models\FeatureValueTranslation;
use App\Domain\Catalog\Support\CatalogCsvImport;
use App\Domain\Catalog\Support\FeatureCsvSchema;
use App\Domain\Localization\Models\Language;
use App\Domain\Security\CsvSafe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FeatureService
{
    public function presentFeature(Feature $feature): array
    {
        $translation = $feature->translation();

        return [
            'id' => $feature->id,
            'name' => $translation?->name ?: 'Untitled feature',
            'description' => $translation?->description ?: '',
            'code' => $feature->code,
            'type' => $feature->type ?: 'select',
            'type_label' => Feature::TYPES[$feature->type] ?? ($feature->type ?: 'Select'),
            'is_enabled' => (bool) $feature->is_enabled,
            'is_filterable' => (bool) $feature->is_filterable,
            'used_for_combinations' => (bool) $feature->used_for_combinations,
            'position' => (int) $feature->position,
            'values_count' => $feature->values_count ?? $feature->values()->count(),
            'products_count' => $feature->products_count ?? (int) DB::table('product_feature_values')->where('feature_id', $feature->id)->distinct()->count('product_id'),
            'translations' => $feature->relationLoaded('translations')
                ? $feature->translations->map(fn (FeatureTranslation $row) => [
                    'language_code' => $row->language_code,
                    'name' => $row->name,
                    'description' => $row->description,
                ])->values()->all()
                : [],
        ];
    }

    public function presentValue(FeatureValue $value): array
    {
        $feature = $value->relationLoaded('feature') ? $value->feature : $value->feature()->with('translations')->first();

        return [
            'id' => $value->id,
            'feature_id' => $value->feature_id,
            'feature_name' => $feature?->translation()?->name ?: 'Untitled feature',
            'value' => $value->translation()?->value ?: '',
            'code' => $value->code,
            'is_enabled' => (bool) $value->is_enabled,
            'position' => (int) $value->position,
            'products_count' => $value->products_count ?? (int) DB::table('product_feature_values')->where('feature_value_id', $value->id)->count(),
            'translations' => $value->relationLoaded('translations')
                ? $value->translations->map(fn (FeatureValueTranslation $row) => [
                    'language_code' => $row->language_code,
                    'value' => $row->value,
                ])->values()->all()
                : [],
        ];
    }

    public function featureOptions(?int $includeId = null): array
    {
        return Feature::query()
            ->with('translations')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(function (Feature $feature) use ($includeId) {
                $name = $feature->translation()?->name ?: 'Untitled feature';
                if (! $feature->is_enabled && (int) $feature->id !== (int) $includeId) {
                    $name .= ' (Disabled)';
                }

                return [
                    'id' => $feature->id,
                    'name' => $name,
                    'is_enabled' => (bool) $feature->is_enabled,
                ];
            })
            ->values()
            ->all();
    }

    public function saveFeature(array $data, ?Feature $feature = null): Feature
    {
        return DB::transaction(function () use ($data, $feature) {
            $feature ??= new Feature;
            $default = Language::defaultCode();
            $name = trim((string) (($data['translations'][$default]['name'] ?? null) ?? ($data['name'] ?? '')));

            $code = $feature->exists && filled($feature->code)
                ? $feature->code
                : $this->uniqueFeatureCode('', $name, $feature->id);
            if (array_key_exists('code', $data) && trim((string) ($data['code'] ?? '')) !== '') {
                $code = $this->uniqueFeatureCode(trim((string) $data['code']), $name, $feature->id);
            }

            $feature->fill([
                'seller_id' => ($data['seller_id'] ?? '') === '' || ($data['seller_id'] ?? null) === null
                    ? ($feature->seller_id ?? null)
                    : (int) $data['seller_id'],
                'code' => $code,
                'type' => $feature->exists ? $feature->type : ($data['type'] ?? 'select'),
                'is_filterable' => array_key_exists('is_filterable', $data) ? (bool) $data['is_filterable'] : ($feature->exists ? $feature->is_filterable : true),
                'used_for_combinations' => array_key_exists('used_for_combinations', $data) ? (bool) $data['used_for_combinations'] : ($feature->exists ? $feature->used_for_combinations : true),
                'is_enabled' => (bool) ($data['is_enabled'] ?? true),
                'position' => \App\Domain\Catalog\Support\PositionReorder::resolve(
                    $data['position'] ?? null,
                    $feature,
                ),
            ]);
            $feature->save();

            $this->syncFeatureTranslations($feature, $data, $default);

            return $feature->fresh(['translations']);
        });
    }

    public function duplicateFeature(Feature $feature): Feature
    {
        $feature->loadMissing(['translations', 'values.translations']);

        return DB::transaction(function () use ($feature) {
            $baseName = $feature->translation()?->name ?: ($feature->code ?: 'feature');
            $copy = $feature->replicate(['code']);
            $copy->code = $this->uniqueFeatureCode('', $baseName.' copy', null);
            $copy->is_enabled = false;
            $copy->position = \App\Domain\Catalog\Support\PositionReorder::next(Feature::class);
            $copy->save();

            foreach ($feature->translations as $translation) {
                $copy->translations()->create([
                    'language_code' => $translation->language_code,
                    'name' => $translation->name.' (copy)',
                    'description' => $translation->description,
                ]);
            }

            foreach ($feature->values as $value) {
                $newValue = $copy->values()->create([
                    'code' => $value->code,
                    'is_enabled' => (bool) $value->is_enabled,
                    'position' => (int) $value->position,
                ]);
                foreach ($value->translations as $valueTranslation) {
                    $newValue->translations()->create([
                        'language_code' => $valueTranslation->language_code,
                        'value' => $valueTranslation->value,
                    ]);
                }
            }

            return $copy->fresh(['translations', 'values.translations']);
        });
    }

    public function saveValue(array $data, ?FeatureValue $value = null): FeatureValue
    {
        return DB::transaction(function () use ($data, $value) {
            $default = Language::defaultCode();
            $label = trim((string) (($data['translations'][$default]['value'] ?? null) ?? ($data['value'] ?? '')));
            $featureId = (int) $data['feature_id'];

            if ($this->valueTaken($featureId, $label, $value?->id, $default)) {
                throw ValidationException::withMessages([
                    'value' => 'This value already exists for the selected feature.',
                    "translations.$default.value" => 'This value already exists for the selected feature.',
                ]);
            }

            $value ??= new FeatureValue;
            $position = \App\Domain\Catalog\Support\PositionReorder::resolve(
                $data['position'] ?? null,
                $value,
                'feature_id',
                $featureId,
            );

            $code = $value->exists ? $value->code : null;
            if (array_key_exists('code', $data)) {
                $provided = trim((string) ($data['code'] ?? ''));
                $code = $provided !== ''
                    ? $this->uniqueValueCode($featureId, $provided, $label, $value->id)
                    : ($value->exists ? $value->code : null);
            }

            $value->fill([
                'feature_id' => $featureId,
                'code' => $code,
                'is_enabled' => (bool) ($data['is_enabled'] ?? true),
                'position' => $position,
            ]);
            $value->save();

            $this->syncValueTranslations($value, $data, $default, $featureId);

            return $value->fresh(['translations', 'feature.translations']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function syncFeatureTranslations(Feature $feature, array $data, string $default): void
    {
        $rows = $data['translations'] ?? [];
        if ($rows === []) {
            $language = (string) ($data['language_code'] ?? $default);
            $rows = [$language => [
                'language_code' => $language,
                'name' => $data['name'] ?? '',
                'description' => $data['description'] ?? '',
            ]];
        }

        foreach ($rows as $key => $row) {
            $row = (array) $row;
            $language = (string) ($row['language_code'] ?? $key);
            if ($language === '') {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            if ($language !== $default && $name === '') {
                FeatureTranslation::query()
                    ->where('feature_id', $feature->id)
                    ->where('language_code', $language)
                    ->delete();

                continue;
            }

            $feature->translations()->updateOrCreate(
                ['language_code' => $language],
                [
                    'name' => $name,
                    'description' => $this->nullableString($row['description'] ?? null),
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function syncValueTranslations(FeatureValue $value, array $data, string $default, int $featureId): void
    {
        $rows = $data['translations'] ?? [];
        if ($rows === []) {
            $language = (string) ($data['language_code'] ?? $default);
            $rows = [$language => [
                'language_code' => $language,
                'value' => $data['value'] ?? '',
            ]];
        }

        foreach ($rows as $key => $row) {
            $row = (array) $row;
            $language = (string) ($row['language_code'] ?? $key);
            if ($language === '') {
                continue;
            }

            $label = trim((string) ($row['value'] ?? ''));
            if ($language !== $default && $label === '') {
                FeatureValueTranslation::query()
                    ->where('feature_value_id', $value->id)
                    ->where('language_code', $language)
                    ->delete();

                continue;
            }

            if ($label !== '' && $this->valueTaken($featureId, $label, $value->id, $language)) {
                throw ValidationException::withMessages([
                    "translations.$language.value" => 'This value already exists for the selected feature.',
                ]);
            }

            $value->translations()->updateOrCreate(
                ['language_code' => $language],
                ['value' => $label],
            );
        }
    }

    public function deleteFeature(Feature $feature): void
    {
        $count = (int) DB::table('product_feature_values')->where('feature_id', $feature->id)->distinct()->count('product_id');
        if ($count > 0) {
            throw ValidationException::withMessages([
                'feature' => 'This feature is currently assigned to '.$count.' product'.($count === 1 ? '' : 's').'. Please remove it from those products before deleting.',
            ]);
        }

        $feature->delete();
    }

    public function deleteValue(FeatureValue $value): void
    {
        $count = (int) DB::table('product_feature_values')->where('feature_value_id', $value->id)->count();
        if ($count > 0) {
            throw ValidationException::withMessages([
                'value' => 'This feature value is currently assigned to '.$count.' product'.($count === 1 ? '' : 's').'. Please remove it from those products before deleting.',
            ]);
        }

        $value->delete();
    }

    public function moveValue(FeatureValue $value, string $direction): void
    {
        $siblings = FeatureValue::query()
            ->where('feature_id', $value->feature_id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $index = $siblings->search(fn (FeatureValue $row) => (int) $row->id === (int) $value->id);
        if ($index === false) {
            return;
        }

        $swapWith = $direction === 'up' ? $index - 1 : $index + 1;
        if (! isset($siblings[$swapWith])) {
            return;
        }

        $other = $siblings[$swapWith];
        $currentPosition = $value->position;
        $value->update(['position' => $other->position]);
        $other->update(['position' => $currentPosition]);
    }

    /** @param  list<int|string>  $orderedIds */
    public function reorderValues(array $orderedIds): void
    {
        \App\Domain\Catalog\Support\PositionReorder::among(
            FeatureValue::class,
            $orderedIds,
            'feature_id',
        );
    }

    /** @param  list<int|string>  $orderedIds */
    public function reorderFeatures(array $orderedIds): void
    {
        \App\Domain\Catalog\Support\PositionReorder::among(Feature::class, $orderedIds);
    }

    public function csvColumns(): array
    {
        return FeatureCsvSchema::columns();
    }

    public function sampleCsv(
        string $delimiter = FeatureCsvSchema::DEFAULT_DELIMITER,
        string $multiSeparator = FeatureCsvSchema::DEFAULT_MULTI_SEPARATOR,
    ): string {
        return FeatureCsvSchema::sampleCsv($delimiter, $multiSeparator);
    }

    public function importCsv(
        string $contents,
        string $locale = 'en',
        string $delimiter = FeatureCsvSchema::DEFAULT_DELIMITER,
        string $multiSeparator = FeatureCsvSchema::DEFAULT_MULTI_SEPARATOR,
    ): int {
        $delimiter = $this->normalizeCsvDelimiter($delimiter);
        $this->normalizeCsvMultiSeparator($multiSeparator);

        $parsed = CatalogCsvImport::parse($contents, $delimiter);
        CatalogCsvImport::assertHeaders($parsed['header'], FeatureCsvSchema::columns(), 'Feature');
        CatalogCsvImport::assertHasDataRows($parsed['rows'], 'Feature');

        $errors = [];
        $prepared = [];
        foreach ($parsed['rows'] as $index => $row) {
            $rowNumber = CatalogCsvImport::rowNumber($index);
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                $errors[] = "Row {$rowNumber}: Feature name is required.";
                continue;
            }

            $rawType = trim((string) ($row['type'] ?? ''));
            if ($rawType !== '' && ! array_key_exists(strtolower($rawType), Feature::TYPES)) {
                $errors[] = "Row {$rowNumber}: Invalid Feature type.";
                continue;
            }

            $mapped = FeatureCsvSchema::mapRow($row, $locale, $multiSeparator);
            $valueLabel = trim((string) ($mapped['value']['value'] ?? ''));
            if (($row['value'] ?? '') !== '' && $valueLabel === '') {
                $errors[] = "Row {$rowNumber}: Invalid Feature value.";
                continue;
            }

            $prepared[] = $mapped;
        }

        CatalogCsvImport::throwRowErrors($errors);
        if ($prepared === []) {
            throw ValidationException::withMessages([
                'file' => 'Invalid CSV file. No valid Feature rows to import.',
            ]);
        }

        return DB::transaction(function () use ($prepared, $locale) {
            $count = 0;
            $seenFeatureKeys = [];

            foreach ($prepared as $mapped) {
                $featureData = $mapped['feature'];
                $existing = $this->findFeatureForImport($featureData['code'] ?? null, $featureData['name'], $locale);
                $feature = $this->saveFeature($featureData, $existing);

                $featureKey = (string) $feature->id;
                if (! isset($seenFeatureKeys[$featureKey])) {
                    $seenFeatureKeys[$featureKey] = true;
                    $count++;
                }

                $valueData = $mapped['value'];
                if ($valueData === null) {
                    continue;
                }

                $existingValue = $this->findValueForImport(
                    (int) $feature->id,
                    $valueData['code'] ?? null,
                    $valueData['value'] ?? '',
                    $locale,
                );
                $this->saveValue([
                    ...$valueData,
                    'feature_id' => $feature->id,
                ], $existingValue);
            }

            return $count;
        });
    }

    public function exportCsv(
        string $locale = 'en',
        ?Request $request = null,
        string $delimiter = FeatureCsvSchema::DEFAULT_DELIMITER,
        string $multiSeparator = FeatureCsvSchema::DEFAULT_MULTI_SEPARATOR,
    ): string {
        $delimiter = $this->normalizeCsvDelimiter($delimiter);
        $this->normalizeCsvMultiSeparator($multiSeparator);

        $out = fopen('php://temp', 'r+');
        CsvSafe::put($out, $this->csvColumns(), $delimiter);

        $status = $request?->string('status')->toString() ?: 'all';
        $name = trim((string) ($request?->string('name')->toString() ?: $request?->string('q')->toString() ?: ''));
        $type = trim((string) ($request?->string('type')->toString() ?: ''));
        $idMin = $request?->input('id_min');
        $idMax = $request?->input('id_max');
        $valuesMin = $request?->input('values_min');
        $valuesMax = $request?->input('values_max');
        $positionMin = $request?->input('position_min');
        $positionMax = $request?->input('position_max');

        Feature::query()
            ->with(['translations', 'values.translations'])
            ->when($name !== '', function ($query) use ($name) {
                $query->where(function ($inner) use ($name) {
                    $inner->where('code', 'like', "%{$name}%")
                        ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', "%{$name}%"));
                });
            })
            ->when($type !== '' && array_key_exists($type, Feature::TYPES), fn ($q) => $q->where('type', $type))
            ->when($idMin !== null && $idMin !== '', fn ($q) => $q->where('features.id', '>=', (int) $idMin))
            ->when($idMax !== null && $idMax !== '', fn ($q) => $q->where('features.id', '<=', (int) $idMax))
            ->when($valuesMin !== null && $valuesMin !== '', fn ($q) => $q->has('values', '>=', (int) $valuesMin))
            ->when($valuesMax !== null && $valuesMax !== '', fn ($q) => $q->has('values', '<=', (int) $valuesMax))
            ->when($positionMin !== null && $positionMin !== '', fn ($q) => $q->where('features.position', '>=', (int) $positionMin))
            ->when($positionMax !== null && $positionMax !== '', fn ($q) => $q->where('features.position', '<=', (int) $positionMax))
            ->when($status === 'active', fn ($q) => $q->where('features.is_enabled', true))
            ->when($status === 'inactive', fn ($q) => $q->where('features.is_enabled', false))
            ->orderBy('features.position')
            ->orderBy('features.id')
            ->chunk(100, function ($features) use ($out, $locale, $delimiter) {
                foreach ($features as $feature) {
                    foreach (FeatureCsvSchema::exportFeatureRows($feature, $locale) as $row) {
                        CsvSafe::put($out, $row, $delimiter);
                    }
                }
            });

        rewind($out);

        return stream_get_contents($out) ?: '';
    }

    protected function findFeatureForImport(?string $code, string $name, string $locale): ?Feature
    {
        if ($code) {
            $byCode = Feature::query()->where('code', $code)->first();
            if ($byCode) {
                return $byCode;
            }
        }

        $normalized = mb_strtolower(trim($name));
        if ($normalized === '') {
            return null;
        }

        return Feature::query()
            ->whereHas('translations', function ($query) use ($locale, $normalized) {
                $query->where('language_code', $locale)
                    ->whereRaw('LOWER(TRIM(name)) = ?', [$normalized]);
            })
            ->first();
    }

    protected function findValueForImport(int $featureId, ?string $code, string $label, string $locale): ?FeatureValue
    {
        if ($code) {
            $byCode = FeatureValue::query()
                ->where('feature_id', $featureId)
                ->where('code', $code)
                ->first();
            if ($byCode) {
                return $byCode;
            }
        }

        $normalized = mb_strtolower(trim($label));
        if ($normalized === '') {
            return null;
        }

        return FeatureValue::query()
            ->where('feature_id', $featureId)
            ->whereHas('translations', function ($query) use ($locale, $normalized) {
                $query->where('language_code', $locale)
                    ->whereRaw('LOWER(TRIM(value)) = ?', [$normalized]);
            })
            ->first();
    }

    protected function uniqueFeatureCode(string $provided, string $name, ?int $ignoreId): string
    {
        $taken = function (string $code) use ($ignoreId): bool {
            return Feature::query()
                ->where('code', $code)
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists();
        };

        $code = Str::slug($name) ?: 'feature';
        if ($provided !== '') {
            $code = Str::slug($provided) ?: $provided;
        }
        $base = $code;
        $i = 1;
        while ($taken($code)) {
            $code = $base.'-'.$i++;
        }

        return $code;
    }

    protected function uniqueValueCode(int $featureId, string $provided, string $label, ?int $ignoreId): string
    {
        $taken = function (string $code) use ($featureId, $ignoreId): bool {
            return FeatureValue::query()
                ->where('feature_id', $featureId)
                ->where('code', $code)
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists();
        };

        $code = Str::slug($provided !== '' ? $provided : $label) ?: 'value';
        $base = $code;
        $i = 1;
        while ($taken($code)) {
            $code = $base.'-'.$i++;
        }

        return $code;
    }

    protected function valueTaken(int $featureId, string $value, ?int $ignoreId, string $locale): bool
    {
        $normalized = mb_strtolower(trim($value));

        return FeatureValueTranslation::query()
            ->where('language_code', $locale)
            ->whereRaw('LOWER(TRIM(value)) = ?', [$normalized])
            ->whereHas('featureValue', function ($query) use ($featureId, $ignoreId) {
                $query->where('feature_id', $featureId)
                    ->when($ignoreId, fn ($inner) => $inner->where('id', '!=', $ignoreId));
            })
            ->exists();
    }

    protected function normalizeCsvDelimiter(string $delimiter): string
    {
        $delimiter = $delimiter === '' ? FeatureCsvSchema::DEFAULT_DELIMITER : $delimiter;

        return strlen($delimiter) === 1 ? $delimiter : FeatureCsvSchema::DEFAULT_DELIMITER;
    }

    protected function normalizeCsvMultiSeparator(string $separator): string
    {
        return $separator === '' ? FeatureCsvSchema::DEFAULT_MULTI_SEPARATOR : $separator;
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === '' || $value === null ? null : (string) $value;
    }
}
