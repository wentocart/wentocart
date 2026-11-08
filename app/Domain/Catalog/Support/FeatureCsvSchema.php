<?php

namespace App\Domain\Catalog\Support;

use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\FeatureValue;
use App\Domain\Security\CsvSafe;

/**
 * Single source of truth for feature CSV import, export, and sample download.
 *
 * One row per feature value (feature columns repeated). Features without values
 * export as a single row with empty value_* columns.
 */
final class FeatureCsvSchema
{
    public const DEFAULT_DELIMITER = ';';

    public const DEFAULT_MULTI_SEPARATOR = ',';

    /** @return list<string> */
    public static function columns(): array
    {
        return [
            'id',
            'code',
            'name',
            'description',
            'type',
            'is_filterable',
            'used_for_combinations',
            'is_enabled',
            'position',
            'value',
            'value_is_enabled',
            'value_position',
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function sampleRows(): array
    {
        return [
            [
                'code' => 'color',
                'name' => 'Color',
                'description' => 'Primary product color for filters and combinations.',
                'type' => 'color',
                'is_filterable' => '1',
                'used_for_combinations' => '1',
                'is_enabled' => '1',
                'position' => '1',
                'value' => 'Black',
                'value_is_enabled' => '1',
                'value_position' => '1',
            ],
            [
                'code' => 'color',
                'name' => 'Color',
                'description' => 'Primary product color for filters and combinations.',
                'type' => 'color',
                'is_filterable' => '1',
                'used_for_combinations' => '1',
                'is_enabled' => '1',
                'position' => '1',
                'value' => 'White',
                'value_is_enabled' => '1',
                'value_position' => '2',
            ],
            [
                'code' => 'color',
                'name' => 'Color',
                'description' => 'Primary product color for filters and combinations.',
                'type' => 'color',
                'is_filterable' => '1',
                'used_for_combinations' => '1',
                'is_enabled' => '1',
                'position' => '1',
                'value' => 'Navy',
                'value_is_enabled' => '1',
                'value_position' => '3',
            ],
            [
                'code' => 'size',
                'name' => 'Size',
                'description' => 'Apparel size options.',
                'type' => 'select',
                'is_filterable' => '1',
                'used_for_combinations' => '1',
                'is_enabled' => '1',
                'position' => '2',
                'value' => 'S',
                'value_is_enabled' => '1',
                'value_position' => '1',
            ],
            [
                'code' => 'size',
                'name' => 'Size',
                'description' => 'Apparel size options.',
                'type' => 'select',
                'is_filterable' => '1',
                'used_for_combinations' => '1',
                'is_enabled' => '1',
                'position' => '2',
                'value' => 'M',
                'value_is_enabled' => '1',
                'value_position' => '2',
            ],
            [
                'code' => 'size',
                'name' => 'Size',
                'description' => 'Apparel size options.',
                'type' => 'select',
                'is_filterable' => '1',
                'used_for_combinations' => '1',
                'is_enabled' => '1',
                'position' => '2',
                'value' => 'L',
                'value_is_enabled' => '1',
                'value_position' => '3',
            ],
            [
                'code' => 'material',
                'name' => 'Material',
                'description' => 'Main fabric or build material.',
                'type' => 'select',
                'is_filterable' => '1',
                'used_for_combinations' => '0',
                'is_enabled' => '1',
                'position' => '3',
                'value' => 'Cotton',
                'value_is_enabled' => '1',
                'value_position' => '1',
            ],
            [
                'code' => 'material',
                'name' => 'Material',
                'description' => 'Main fabric or build material.',
                'type' => 'select',
                'is_filterable' => '1',
                'used_for_combinations' => '0',
                'is_enabled' => '1',
                'position' => '3',
                'value' => 'Wool',
                'value_is_enabled' => '1',
                'value_position' => '2',
            ],
            [
                'code' => 'connectivity',
                'name' => 'Connectivity',
                'description' => 'Wireless and wired connectivity options.',
                'type' => 'select',
                'is_filterable' => '1',
                'used_for_combinations' => '0',
                'is_enabled' => '1',
                'position' => '4',
                'value' => 'Wi-Fi',
                'value_is_enabled' => '1',
                'value_position' => '1',
            ],
            [
                'code' => 'connectivity',
                'name' => 'Connectivity',
                'description' => 'Wireless and wired connectivity options.',
                'type' => 'select',
                'is_filterable' => '1',
                'used_for_combinations' => '0',
                'is_enabled' => '1',
                'position' => '4',
                'value' => 'Bluetooth',
                'value_is_enabled' => '1',
                'value_position' => '2',
            ],
            [
                'code' => 'warranty',
                'name' => 'Warranty',
                'description' => 'Manufacturer warranty duration.',
                'type' => 'text',
                'is_filterable' => '0',
                'used_for_combinations' => '0',
                'is_enabled' => '1',
                'position' => '5',
                'value' => '1 Year',
                'value_is_enabled' => '1',
                'value_position' => '1',
            ],
            [
                'code' => 'warranty',
                'name' => 'Warranty',
                'description' => 'Manufacturer warranty duration.',
                'type' => 'text',
                'is_filterable' => '0',
                'used_for_combinations' => '0',
                'is_enabled' => '1',
                'position' => '5',
                'value' => '2 Years',
                'value_is_enabled' => '1',
                'value_position' => '2',
            ],
            [
                'code' => 'legacy-finish',
                'name' => 'Legacy Finish',
                'description' => 'Retired finish options kept for catalog history.',
                'type' => 'select',
                'is_filterable' => '0',
                'used_for_combinations' => '0',
                'is_enabled' => '0',
                'position' => '10',
                'value' => 'Matte',
                'value_is_enabled' => '0',
                'value_position' => '1',
            ],
            [
                'code' => 'brand-story',
                'name' => 'Brand Story',
                'description' => 'Optional free-text brand story feature with no predefined values.',
                'type' => 'text',
                'is_filterable' => '0',
                'used_for_combinations' => '0',
                'is_enabled' => '1',
                'position' => '6',
                'value' => '',
                'value_is_enabled' => '',
                'value_position' => '',
            ],
        ];
    }

    public static function sampleCsv(
        string $delimiter = self::DEFAULT_DELIMITER,
        string $multiSeparator = self::DEFAULT_MULTI_SEPARATOR,
    ): string {
        $out = fopen('php://temp', 'r+');
        CsvSafe::put($out, self::columns(), $delimiter);
        foreach (self::sampleRows() as $row) {
            CsvSafe::put($out, self::rowToCsv($row, $multiSeparator), $delimiter);
        }
        rewind($out);

        return stream_get_contents($out) ?: '';
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    public static function rowToCsv(array $row, string $multiSeparator = self::DEFAULT_MULTI_SEPARATOR): array
    {
        $cells = [];
        foreach (self::columns() as $key) {
            $value = $row[$key] ?? '';
            if (is_array($value)) {
                $value = implode($multiSeparator, array_map(static fn ($part) => (string) $part, $value));
            }
            $cells[] = (string) $value;
        }

        return $cells;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{feature: array<string, mixed>, value: ?array<string, mixed>}
     */
    public static function mapRow(
        array $row,
        string $locale = 'en',
        string $multiSeparator = self::DEFAULT_MULTI_SEPARATOR,
    ): array {
        unset($multiSeparator);
        $get = static fn (string $key): ?string => array_key_exists($key, $row)
            ? trim((string) $row[$key])
            : null;

        $name = (string) ($get('name') ?? '');
        $type = strtolower((string) ($get('type') ?? 'select'));
        if (! array_key_exists($type, Feature::TYPES)) {
            $type = 'select';
        }

        $feature = [
            'code' => self::nullable($get('code')),
            'name' => $name,
            'description' => self::nullable($get('description')),
            'type' => $type,
            'is_filterable' => self::boolOr($get('is_filterable'), true),
            'used_for_combinations' => self::boolOr($get('used_for_combinations'), true),
            'is_enabled' => self::boolOr($get('is_enabled'), true),
            'position' => self::filled($get('position')) ? (int) $get('position') : null,
            'language_code' => $locale,
        ];

        $valueLabel = (string) ($get('value') ?? '');
        $value = null;
        if ($valueLabel !== '') {
            $value = [
                'value' => $valueLabel,
                'is_enabled' => self::boolOr($get('value_is_enabled'), true),
                'position' => self::filled($get('value_position')) ? (int) $get('value_position') : null,
                'language_code' => $locale,
            ];
        }

        return ['feature' => $feature, 'value' => $value];
    }

    /** @return list<list<string|int|null>> */
    public static function exportFeatureRows(Feature $feature, string $locale = 'en'): array
    {
        $t = $feature->translation($locale);
        $base = [
            'code' => $feature->code,
            'name' => $t?->name,
            'description' => $t?->description,
            'type' => $feature->type,
            'is_filterable' => self::boolToCsv($feature->is_filterable),
            'used_for_combinations' => self::boolToCsv($feature->used_for_combinations),
            'is_enabled' => self::boolToCsv($feature->is_enabled),
            'position' => $feature->position,
        ];

        $values = $feature->relationLoaded('values')
            ? $feature->values
            : $feature->values()->with('translations')->orderBy('position')->orderBy('id')->get();

        if ($values->isEmpty()) {
            return [self::exportValues([
                'id' => $feature->id,
                ...$base,
                'value' => '',
                'value_is_enabled' => '',
                'value_position' => '',
            ])];
        }

        $rows = [];
        foreach ($values as $value) {
            /** @var FeatureValue $value */
            $rows[] = self::exportValues([
                'id' => $value->id,
                ...$base,
                'value' => $value->translation($locale)?->value,
                'value_is_enabled' => self::boolToCsv($value->is_enabled),
                'value_position' => $value->position,
            ]);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $map
     * @return list<string|int|null>
     */
    public static function exportValues(array $map): array
    {
        return array_map(fn (string $key) => $map[$key] ?? '', self::columns());
    }

    protected static function filled(?string $value): bool
    {
        return $value !== null && $value !== '';
    }

    protected static function nullable(?string $value): ?string
    {
        return self::filled($value) ? $value : null;
    }

    protected static function intOr(?string $value, int $default): int
    {
        return self::filled($value) ? (int) $value : $default;
    }

    protected static function boolOr(?string $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            '1', 'true', 'yes', 'y', 'on', 'active', 'enabled' => true,
            '0', 'false', 'no', 'n', 'off', 'inactive', 'disabled' => false,
            default => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default,
        };
    }

    protected static function boolToCsv(mixed $value): string
    {
        return $value ? '1' : '0';
    }
}
