<?php

namespace App\Domain\Catalog\Support;

use App\Domain\Catalog\Models\AttributeValue;
use App\Domain\Catalog\Models\CatalogAttribute;
use App\Domain\Catalog\Services\AttributeService;
use App\Domain\Localization\Models\Language;
use App\Domain\Settings\AdminCsv;

final class AttributeValueCsvSchema
{
    /** @return list<string> */
    public static function columns(): array
    {
        return ['id', 'attribute_code', 'code', 'value', 'is_enabled', 'position'];
    }

    /** @return list<string> */
    public static function required(): array
    {
        return ['attribute_code', 'value'];
    }

    /** @return list<array<string, mixed>> */
    public static function samples(): array
    {
        return [
            [
                'attribute_code' => 'size',
                'code' => 'csv-xl',
                'value' => 'XL',
                'is_enabled' => '1',
                'position' => '20',
            ],
        ];
    }

    public static function sampleCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        return AdminCsv::stringify(self::columns(), self::samples(), $delimiter);
    }

    public static function exportCsv(string $delimiter = AdminCsv::DELIMITER, ?int $attributeId = null): string
    {
        $locale = Language::defaultCode();
        $rows = AttributeValue::query()
            ->with(['translations', 'attribute'])
            ->when($attributeId, fn ($query) => $query->where('attribute_id', $attributeId))
            ->orderBy('attribute_id')
            ->orderBy('position')
            ->limit(10000)
            ->get()
            ->map(fn (AttributeValue $value) => [
                'id' => $value->id,
                'attribute_code' => $value->attribute?->code,
                'code' => $value->code,
                'value' => $value->translation($locale)?->value,
                'is_enabled' => $value->is_enabled ? '1' : '0',
                'position' => $value->position,
            ])
            ->all();

        return AdminCsv::stringify(self::columns(), $rows, $delimiter);
    }

    public static function import(string $contents, ?string $delimiter = null): int
    {
        $parsed = AdminCsv::parse($contents, self::columns(), 'Attribute value', $delimiter, self::required());
        $attributes = app(AttributeService::class);
        $locale = Language::defaultCode();
        $errors = [];
        $count = 0;

        foreach ($parsed['rows'] as $index => $row) {
            $attributeCode = AdminCsv::get($row, 'attribute_code');
            $label = AdminCsv::get($row, 'value');
            $attribute = CatalogAttribute::query()->where('code', $attributeCode)->first();
            $line = CatalogCsvImport::rowNumber($index);
            if (! $attribute) {
                $errors[] = "Row {$line}: unknown attribute code \"{$attributeCode}\".";

                continue;
            }
            if ($label === '') {
                $errors[] = "Row {$line}: value is required.";

                continue;
            }
            $existing = AttributeValue::query()
                ->where('attribute_id', $attribute->id)
                ->where(function ($query) use ($row, $label, $locale) {
                    $code = AdminCsv::get($row, 'code');
                    if ($code !== '') {
                        $query->where('code', $code);
                    } else {
                        $query->whereHas('translations', fn ($t) => $t->where('language_code', $locale)->where('value', $label));
                    }
                })
                ->first();
            $attributes->saveValue([
                'attribute_id' => $attribute->id,
                'code' => AdminCsv::nullable(AdminCsv::get($row, 'code')),
                'value' => $label,
                'is_enabled' => AdminCsv::bool(AdminCsv::get($row, 'is_enabled'), true),
                'position' => AdminCsv::int(AdminCsv::get($row, 'position')),
                'translations' => [
                    $locale => ['language_code' => $locale, 'value' => $label],
                ],
            ], $existing);
            $count++;
        }

        CatalogCsvImport::throwRowErrors($errors);

        return $count;
    }
}
