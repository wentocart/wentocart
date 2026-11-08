<?php

namespace App\Domain\Catalog\Support;

use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\FeatureValue;
use App\Domain\Catalog\Services\FeatureService;
use App\Domain\Localization\Models\Language;
use App\Domain\Settings\AdminCsv;

final class FeatureValueCsvSchema
{
    /** @return list<string> */
    public static function columns(): array
    {
        return ['id', 'feature_code', 'code', 'value', 'is_enabled', 'position'];
    }

    /** @return list<string> */
    public static function required(): array
    {
        return ['feature_code', 'value'];
    }

    /** @return list<array<string, mixed>> */
    public static function samples(): array
    {
        return [
            [
                'feature_code' => 'color',
                'code' => 'csv-teal',
                'value' => 'Teal',
                'is_enabled' => '1',
                'position' => '20',
            ],
            [
                'feature_code' => 'color',
                'code' => 'csv-sand',
                'value' => 'Sand',
                'is_enabled' => '1',
                'position' => '21',
            ],
        ];
    }

    public static function sampleCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        return AdminCsv::stringify(self::columns(), self::samples(), $delimiter);
    }

    public static function exportCsv(string $delimiter = AdminCsv::DELIMITER, ?int $featureId = null): string
    {
        $locale = Language::defaultCode();
        $rows = FeatureValue::query()
            ->with(['translations', 'feature'])
            ->when($featureId, fn ($query) => $query->where('feature_id', $featureId))
            ->orderBy('feature_id')
            ->orderBy('position')
            ->limit(10000)
            ->get()
            ->map(fn (FeatureValue $value) => [
                'id' => $value->id,
                'feature_code' => $value->feature?->code,
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
        $parsed = AdminCsv::parse($contents, self::columns(), 'Feature value', $delimiter, self::required());
        $features = app(FeatureService::class);
        $locale = Language::defaultCode();
        $errors = [];
        $count = 0;

        foreach ($parsed['rows'] as $index => $row) {
            $featureCode = AdminCsv::get($row, 'feature_code');
            $label = AdminCsv::get($row, 'value');
            $feature = Feature::query()->where('code', $featureCode)->first();
            $line = CatalogCsvImport::rowNumber($index);
            if (! $feature) {
                $errors[] = "Row {$line}: unknown feature code \"{$featureCode}\".";

                continue;
            }
            if ($label === '') {
                $errors[] = "Row {$line}: value is required.";

                continue;
            }
            $existing = FeatureValue::query()
                ->where('feature_id', $feature->id)
                ->where(function ($query) use ($row, $label, $locale) {
                    $code = AdminCsv::get($row, 'code');
                    if ($code !== '') {
                        $query->where('code', $code);
                    } else {
                        $query->whereHas('translations', fn ($t) => $t->where('language_code', $locale)->where('value', $label));
                    }
                })
                ->first();
            $features->saveValue([
                'feature_id' => $feature->id,
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
