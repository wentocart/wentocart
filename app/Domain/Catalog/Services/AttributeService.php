<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\AttributeTranslation;
use App\Domain\Catalog\Models\AttributeValue;
use App\Domain\Catalog\Models\AttributeValueTranslation;
use App\Domain\Catalog\Models\CatalogAttribute;
use App\Domain\Catalog\Support\AttributeCsvSchema;
use App\Domain\Catalog\Support\CatalogCsvImport;
use App\Domain\Localization\Models\Language;
use App\Domain\Security\CsvSafe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AttributeService
{
    public function presentAttribute(CatalogAttribute $attribute): array
    {
        $translation = $attribute->translation();

        return [
            'id' => $attribute->id,
            'name' => $translation?->name ?: 'Untitled attribute',
            'description' => $translation?->description ?: '',
            'code' => $attribute->code,
            'type' => $attribute->type ?: 'select',
            'type_label' => CatalogAttribute::TYPES[$attribute->type] ?? ($attribute->type ?: 'Select'),
            'supports_swatches' => (bool) $attribute->supports_swatches,
            'is_enabled' => (bool) $attribute->is_enabled,
            'position' => (int) $attribute->position,
            'values_count' => $attribute->values_count ?? $attribute->values()->count(),
            'products_count' => $attribute->products_count ?? $this->attributeProductsCount($attribute->id),
            'translations' => $attribute->relationLoaded('translations')
                ? $attribute->translations->map(fn (AttributeTranslation $row) => [
                    'language_code' => $row->language_code,
                    'name' => $row->name,
                    'description' => $row->description,
                ])->values()->all()
                : [],
        ];
    }

    public function presentValue(AttributeValue $value): array
    {
        $attribute = $value->relationLoaded('attribute') ? $value->attribute : $value->attribute()->with('translations')->first();

        return [
            'id' => $value->id,
            'attribute_id' => $value->attribute_id,
            'attribute_name' => $attribute?->translation()?->name ?: 'Untitled attribute',
            'value' => $value->translation()?->value ?: '',
            'code' => $value->code,
            'swatch_type' => $value->swatch_type ?: 'none',
            'color_code' => $value->color_code,
            'swatch_image' => $value->swatch_image,
            'is_enabled' => (bool) $value->is_enabled,
            'position' => (int) $value->position,
            'products_count' => $value->products_count ?? $this->valueProductsCount($value->id),
            'translations' => $value->relationLoaded('translations')
                ? $value->translations->map(fn (AttributeValueTranslation $row) => [
                    'language_code' => $row->language_code,
                    'value' => $row->value,
                ])->values()->all()
                : [],
        ];
    }

    public function attributeOptions(?int $includeId = null, bool $combinationOnly = false): array
    {
        return CatalogAttribute::query()
            ->with('translations')
            ->when($combinationOnly, fn ($query) => $query->whereIn('type', CatalogAttribute::COMBINATION_TYPES))
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(function (CatalogAttribute $attribute) use ($includeId) {
                $name = $attribute->translation()?->name ?: 'Untitled attribute';
                if (! $attribute->is_enabled && (int) $attribute->id !== (int) $includeId) {
                    $name .= ' (Disabled)';
                }

                return [
                    'id' => $attribute->id,
                    'name' => $name,
                    'code' => $attribute->code,
                    'type' => $attribute->type,
                    'supports_swatches' => (bool) $attribute->supports_swatches,
                    'is_enabled' => (bool) $attribute->is_enabled,
                ];
            })
            ->values()
            ->all();
    }

    public function combinationAttributeCatalog(?int $sellerId = null): array
    {
        return CatalogAttribute::query()
            ->with([
                'translations',
                'values' => fn ($query) => $query->where('is_enabled', true)->orderBy('position')->orderBy('id'),
                'values.translations',
            ])
            ->where('is_enabled', true)
            ->whereIn('type', CatalogAttribute::COMBINATION_TYPES)
            ->when($sellerId !== null, fn ($query) => $query->where(function ($inner) use ($sellerId) {
                $inner->whereNull('seller_id')->orWhere('seller_id', $sellerId);
            }))
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(function (CatalogAttribute $attribute) {
                return [
                    'id' => $attribute->id,
                    'name' => $attribute->translation()?->name ?: $attribute->code,
                    'code' => $attribute->code,
                    'type' => $attribute->type,
                    'supports_swatches' => (bool) $attribute->supports_swatches,
                    'values' => $attribute->values->map(fn (AttributeValue $value) => [
                        'id' => $value->id,
                        'value' => $value->translation()?->value ?: $value->code,
                        'code' => $value->code,
                        'swatch_type' => $value->swatch_type,
                        'color_code' => $value->color_code,
                        'swatch_image' => $value->swatch_image,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    public function saveAttribute(array $data, ?CatalogAttribute $attribute = null): CatalogAttribute
    {
        return DB::transaction(function () use ($data, $attribute) {
            $attribute ??= new CatalogAttribute;
            $default = Language::defaultCode();
            $name = trim((string) (($data['translations'][$default]['name'] ?? null) ?? ($data['name'] ?? '')));
            $type = $data['type'] ?? ($attribute->exists ? $attribute->type : 'select');

            if (! array_key_exists($type, CatalogAttribute::TYPES)) {
                throw ValidationException::withMessages(['type' => 'Invalid attribute type.']);
            }

            $code = $attribute->exists && filled($attribute->code)
                ? $attribute->code
                : $this->uniqueAttributeCode('', $name, $attribute->id);
            if (array_key_exists('code', $data) && trim((string) ($data['code'] ?? '')) !== '') {
                $code = $this->uniqueAttributeCode(trim((string) $data['code']), $name, $attribute->id);
            }

            $attribute->fill([
                'seller_id' => array_key_exists('seller_id', $data)
                    ? (($data['seller_id'] ?? '') === '' || ($data['seller_id'] ?? null) === null ? null : (int) $data['seller_id'])
                    : $attribute->seller_id,
                'code' => $code,
                'type' => $type,
                'supports_swatches' => (bool) ($data['supports_swatches'] ?? false),
                'is_enabled' => (bool) ($data['is_enabled'] ?? true),
                'position' => \App\Domain\Catalog\Support\PositionReorder::resolve(
                    $data['position'] ?? null,
                    $attribute,
                ),
            ]);
            $attribute->save();

            $this->syncAttributeTranslations($attribute, $data, $default);

            return $attribute->fresh(['translations']);
        });
    }

    public function duplicateAttribute(CatalogAttribute $attribute): CatalogAttribute
    {
        $attribute->loadMissing(['translations', 'values.translations']);

        return DB::transaction(function () use ($attribute) {
            $baseName = $attribute->translation()?->name ?: ($attribute->code ?: 'attribute');
            $copy = $attribute->replicate(['code']);
            $copy->code = $this->uniqueAttributeCode('', $baseName.' copy', null);
            $copy->is_enabled = false;
            $copy->position = \App\Domain\Catalog\Support\PositionReorder::next(CatalogAttribute::class);
            $copy->save();

            foreach ($attribute->translations as $translation) {
                $copy->translations()->create([
                    'language_code' => $translation->language_code,
                    'name' => $translation->name.' (copy)',
                    'description' => $translation->description,
                ]);
            }

            foreach ($attribute->values as $value) {
                $newValue = $copy->values()->create([
                    'code' => $value->code,
                    'swatch_type' => $value->swatch_type,
                    'color_code' => $value->color_code,
                    'swatch_image' => $value->swatch_image,
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

    public function saveValue(array $data, ?AttributeValue $value = null): AttributeValue
    {
        return DB::transaction(function () use ($data, $value) {
            $default = Language::defaultCode();
            $label = trim((string) (($data['translations'][$default]['value'] ?? null) ?? ($data['value'] ?? '')));
            $attributeId = (int) $data['attribute_id'];
            $attribute = CatalogAttribute::query()->findOrFail($attributeId);

            if ($this->valueTaken($attributeId, $label, $value?->id, $default)) {
                throw ValidationException::withMessages([
                    'value' => 'This value already exists for the selected attribute.',
                    "translations.$default.value" => 'This value already exists for the selected attribute.',
                ]);
            }

            $swatchType = ($data['swatch_type'] ?? 'none') === '' ? 'none' : ($data['swatch_type'] ?? 'none');
            if (! array_key_exists($swatchType, CatalogAttribute::SWATCH_TYPES)) {
                $swatchType = 'none';
            }

            $value ??= new AttributeValue;
            $position = \App\Domain\Catalog\Support\PositionReorder::resolve(
                $data['position'] ?? null,
                $value,
                'attribute_id',
                $attributeId,
            );

            $code = $value->exists ? $value->code : null;
            if (array_key_exists('code', $data)) {
                $provided = trim((string) ($data['code'] ?? ''));
                $code = $provided !== ''
                    ? $this->uniqueValueCode($attributeId, $provided, $label, $value->id)
                    : ($value->exists ? $value->code : null);
            }

            $value->fill([
                'attribute_id' => $attributeId,
                'code' => $code,
                'swatch_type' => $attribute->supports_swatches ? $swatchType : null,
                'color_code' => $attribute->supports_swatches && $swatchType === 'color' ? ($data['color_code'] ?? null) : null,
                'swatch_image' => $attribute->supports_swatches && $swatchType === 'image' ? ($data['swatch_image'] ?? null) : null,
                'is_enabled' => (bool) ($data['is_enabled'] ?? true),
                'position' => $position,
            ]);
            $value->save();

            $this->syncValueTranslations($value, $data, $default, $attributeId);

            return $value->fresh(['translations', 'attribute.translations']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function syncAttributeTranslations(CatalogAttribute $attribute, array $data, string $default): void
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
                AttributeTranslation::query()
                    ->where('attribute_id', $attribute->id)
                    ->where('language_code', $language)
                    ->delete();

                continue;
            }

            $attribute->translations()->updateOrCreate(
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
    protected function syncValueTranslations(AttributeValue $value, array $data, string $default, int $attributeId): void
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
                AttributeValueTranslation::query()
                    ->where('attribute_value_id', $value->id)
                    ->where('language_code', $language)
                    ->delete();

                continue;
            }

            if ($label !== '' && $this->valueTaken($attributeId, $label, $value->id, $language)) {
                throw ValidationException::withMessages([
                    "translations.$language.value" => 'This value already exists for the selected attribute.',
                ]);
            }

            $value->translations()->updateOrCreate(
                ['language_code' => $language],
                ['value' => $label],
            );
        }
    }

    public function deleteAttribute(CatalogAttribute $attribute): void
    {
        $count = $this->attributeProductsCount($attribute->id);
        if ($count > 0) {
            throw ValidationException::withMessages([
                'attribute' => 'This attribute is currently used by '.$count.' product'.($count === 1 ? '' : 's').'. Please remove its usage before deleting.',
            ]);
        }

        $attribute->delete();
    }

    /** @param  list<int|string>  $orderedIds */
    public function reorderAttributes(array $orderedIds): void
    {
        \App\Domain\Catalog\Support\PositionReorder::among(CatalogAttribute::class, $orderedIds);
    }

    public function deleteValue(AttributeValue $value): void
    {
        $count = $this->valueProductsCount($value->id);
        if ($count > 0) {
            throw ValidationException::withMessages([
                'value' => 'This attribute value is currently used by '.$count.' product'.($count === 1 ? '' : 's').'. Please remove its usage before deleting.',
            ]);
        }

        $value->delete();
    }

    /** @param  list<int|string>  $ids */
    public function bulkDeleteValues(array $ids): int
    {
        $deleted = 0;
        foreach ($ids as $id) {
            $value = AttributeValue::query()->find($id);
            if (! $value) {
                continue;
            }
            try {
                $this->deleteValue($value);
                $deleted++;
            } catch (ValidationException) {
                // skip values still assigned to products
            }
        }

        return $deleted;
    }

    public function bulkDeleteAttributes(array $ids): int
    {
        $deleted = 0;
        foreach ($ids as $id) {
            $attribute = CatalogAttribute::query()->find($id);
            if (! $attribute) {
                continue;
            }
            try {
                $this->deleteAttribute($attribute);
                $deleted++;
            } catch (ValidationException) {
                // skip blocked rows
            }
        }

        return $deleted;
    }

    public function moveValue(AttributeValue $value, string $direction): void
    {
        $siblings = AttributeValue::query()
            ->where('attribute_id', $value->attribute_id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $index = $siblings->search(fn (AttributeValue $row) => (int) $row->id === (int) $value->id);
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
            AttributeValue::class,
            $orderedIds,
            'attribute_id',
        );
    }

    protected function attributeProductsCount(int $attributeId): int
    {
        return (int) DB::table('product_combination_axes')->where('attribute_id', $attributeId)->distinct()->count('product_id');
    }

    protected function valueProductsCount(int $valueId): int
    {
        $axis = (int) DB::table('product_combination_axis_values')->where('attribute_value_id', $valueId)->distinct()->count('product_id');
        $variant = (int) DB::table('product_variant_values')->where('attribute_value_id', $valueId)->count();

        return max($axis, $variant);
    }

    public function csvColumns(): array
    {
        return AttributeCsvSchema::columns();
    }

    public function sampleCsv(
        string $delimiter = AttributeCsvSchema::DEFAULT_DELIMITER,
        string $multiSeparator = AttributeCsvSchema::DEFAULT_MULTI_SEPARATOR,
    ): string {
        return AttributeCsvSchema::sampleCsv($delimiter, $multiSeparator);
    }

    public function importCsv(
        string $contents,
        string $locale = 'en',
        string $delimiter = AttributeCsvSchema::DEFAULT_DELIMITER,
        string $multiSeparator = AttributeCsvSchema::DEFAULT_MULTI_SEPARATOR,
    ): int {
        $delimiter = $this->normalizeCsvDelimiter($delimiter);
        $this->normalizeCsvMultiSeparator($multiSeparator);

        $parsed = CatalogCsvImport::parse($contents, $delimiter);
        CatalogCsvImport::assertHeaders($parsed['header'], AttributeCsvSchema::columns(), 'Attribute');
        CatalogCsvImport::assertHasDataRows($parsed['rows'], 'Attribute');

        $errors = [];
        $prepared = [];
        foreach ($parsed['rows'] as $index => $row) {
            $rowNumber = CatalogCsvImport::rowNumber($index);
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                $errors[] = "Row {$rowNumber}: Attribute name is required.";
                continue;
            }

            $rawType = trim((string) ($row['type'] ?? ''));
            if ($rawType !== '' && ! array_key_exists(strtolower($rawType), CatalogAttribute::TYPES)) {
                $errors[] = "Row {$rowNumber}: Invalid Attribute type.";
                continue;
            }

            $mapped = AttributeCsvSchema::mapRow($row, $locale, $multiSeparator);
            $valueLabel = trim((string) ($mapped['value']['value'] ?? ''));
            if (($row['value'] ?? '') !== '' && $valueLabel === '') {
                $errors[] = "Row {$rowNumber}: Invalid Attribute value.";
                continue;
            }

            $prepared[] = $mapped;
        }

        CatalogCsvImport::throwRowErrors($errors);
        if ($prepared === []) {
            throw ValidationException::withMessages([
                'file' => 'Invalid CSV file. No valid Attribute rows to import.',
            ]);
        }

        return DB::transaction(function () use ($prepared, $locale) {
            $count = 0;
            $seenAttributeKeys = [];

            foreach ($prepared as $mapped) {
                $attributeData = $mapped['attribute'];
                $existing = $this->findAttributeForImport($attributeData['code'] ?? null, $attributeData['name'], $locale);
                $attribute = $this->saveAttribute($attributeData, $existing);

                $attributeKey = (string) $attribute->id;
                if (! isset($seenAttributeKeys[$attributeKey])) {
                    $seenAttributeKeys[$attributeKey] = true;
                    $count++;
                }

                $valueData = $mapped['value'];
                if ($valueData === null) {
                    continue;
                }

                $existingValue = $this->findValueForImport(
                    (int) $attribute->id,
                    $valueData['code'] ?? null,
                    $valueData['value'] ?? '',
                    $locale,
                );
                $this->saveValue([
                    ...$valueData,
                    'attribute_id' => $attribute->id,
                ], $existingValue);
            }

            return $count;
        });
    }

    public function exportCsv(
        string $locale = 'en',
        ?Request $request = null,
        string $delimiter = AttributeCsvSchema::DEFAULT_DELIMITER,
        string $multiSeparator = AttributeCsvSchema::DEFAULT_MULTI_SEPARATOR,
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

        CatalogAttribute::query()
            ->with(['translations', 'values.translations'])
            ->when($name !== '', function ($query) use ($name) {
                $query->where(function ($inner) use ($name) {
                    $inner->where('code', 'like', "%{$name}%")
                        ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', "%{$name}%"));
                });
            })
            ->when($type !== '' && array_key_exists($type, CatalogAttribute::TYPES), fn ($q) => $q->where('type', $type))
            ->when($idMin !== null && $idMin !== '', fn ($q) => $q->where('attributes.id', '>=', (int) $idMin))
            ->when($idMax !== null && $idMax !== '', fn ($q) => $q->where('attributes.id', '<=', (int) $idMax))
            ->when($valuesMin !== null && $valuesMin !== '', fn ($q) => $q->has('values', '>=', (int) $valuesMin))
            ->when($valuesMax !== null && $valuesMax !== '', fn ($q) => $q->has('values', '<=', (int) $valuesMax))
            ->when($positionMin !== null && $positionMin !== '', fn ($q) => $q->where('attributes.position', '>=', (int) $positionMin))
            ->when($positionMax !== null && $positionMax !== '', fn ($q) => $q->where('attributes.position', '<=', (int) $positionMax))
            ->when($status === 'active', fn ($q) => $q->where('attributes.is_enabled', true))
            ->when($status === 'inactive', fn ($q) => $q->where('attributes.is_enabled', false))
            ->orderBy('attributes.position')
            ->orderBy('attributes.id')
            ->chunk(100, function ($attributes) use ($out, $locale, $delimiter) {
                foreach ($attributes as $attribute) {
                    foreach (AttributeCsvSchema::exportAttributeRows($attribute, $locale) as $row) {
                        CsvSafe::put($out, $row, $delimiter);
                    }
                }
            });

        rewind($out);

        return stream_get_contents($out) ?: '';
    }

    protected function findAttributeForImport(?string $code, string $name, string $locale): ?CatalogAttribute
    {
        if ($code) {
            $byCode = CatalogAttribute::query()->where('code', $code)->first();
            if ($byCode) {
                return $byCode;
            }
        }

        $normalized = mb_strtolower(trim($name));
        if ($normalized === '') {
            return null;
        }

        return CatalogAttribute::query()
            ->whereHas('translations', function ($query) use ($locale, $normalized) {
                $query->where('language_code', $locale)
                    ->whereRaw('LOWER(TRIM(name)) = ?', [$normalized]);
            })
            ->first();
    }

    protected function findValueForImport(int $attributeId, ?string $code, string $label, string $locale): ?AttributeValue
    {
        if ($code) {
            $byCode = AttributeValue::query()
                ->where('attribute_id', $attributeId)
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

        return AttributeValue::query()
            ->where('attribute_id', $attributeId)
            ->whereHas('translations', function ($query) use ($locale, $normalized) {
                $query->where('language_code', $locale)
                    ->whereRaw('LOWER(TRIM(value)) = ?', [$normalized]);
            })
            ->first();
    }

    protected function uniqueAttributeCode(string $provided, string $name, ?int $ignoreId): string
    {
        $taken = function (string $code) use ($ignoreId): bool {
            return CatalogAttribute::query()
                ->where('code', $code)
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists();
        };

        $code = Str::slug($name) ?: 'attribute';
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

    protected function uniqueValueCode(int $attributeId, string $provided, string $label, ?int $ignoreId): string
    {
        $taken = function (string $code) use ($attributeId, $ignoreId): bool {
            return AttributeValue::query()
                ->where('attribute_id', $attributeId)
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

    protected function valueTaken(int $attributeId, string $value, ?int $ignoreId, string $locale): bool
    {
        $normalized = mb_strtolower(trim($value));

        return AttributeValueTranslation::query()
            ->where('language_code', $locale)
            ->whereRaw('LOWER(TRIM(value)) = ?', [$normalized])
            ->whereHas('attributeValue', function ($query) use ($attributeId, $ignoreId) {
                $query->where('attribute_id', $attributeId)
                    ->when($ignoreId, fn ($inner) => $inner->where('id', '!=', $ignoreId));
            })
            ->exists();
    }

    protected function normalizeCsvDelimiter(string $delimiter): string
    {
        $delimiter = $delimiter === '' ? AttributeCsvSchema::DEFAULT_DELIMITER : $delimiter;

        return strlen($delimiter) === 1 ? $delimiter : AttributeCsvSchema::DEFAULT_DELIMITER;
    }

    protected function normalizeCsvMultiSeparator(string $separator): string
    {
        return $separator === '' ? AttributeCsvSchema::DEFAULT_MULTI_SEPARATOR : $separator;
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === '' || $value === null ? null : (string) $value;
    }
}
