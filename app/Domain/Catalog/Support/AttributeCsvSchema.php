<?php

namespace App\Domain\Catalog\Support;

use App\Domain\Catalog\Models\AttributeValue;
use App\Domain\Catalog\Models\CatalogAttribute;
use App\Domain\Security\CsvSafe;

/**
 * Single source of truth for attribute CSV import, export, and sample download.
 *
 * One row per attribute value (attribute columns repeated). Attributes without
 * values export as a single row with empty value_* columns.
 */
final class AttributeCsvSchema
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
            'supports_swatches',
            'is_enabled',
            'position',
            'value',
            'swatch_type',
            'color_code',
            'swatch_image',
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
                'description' => 'Variant color shown as swatches on the product page.',
                'type' => 'select',
                'supports_swatches' => '1',
                'is_enabled' => '1',
                'position' => '1',
                'value' => 'Black',
                'swatch_type' => 'color',
                'color_code' => '#111111',
                'swatch_image' => '',
                'value_is_enabled' => '1',
                'value_position' => '1',
            ],
            [
                'code' => 'color',
                'name' => 'Color',
                'description' => 'Variant color shown as swatches on the product page.',
                'type' => 'select',
                'supports_swatches' => '1',
                'is_enabled' => '1',
                'position' => '1',
                'value' => 'White',
                'swatch_type' => 'color',
                'color_code' => '#ffffff',
                'swatch_image' => '',
                'value_is_enabled' => '1',
                'value_position' => '2',
            ],
            [
                'code' => 'color',
                'name' => 'Color',
                'description' => 'Variant color shown as swatches on the product page.',
                'type' => 'select',
                'supports_swatches' => '1',
                'is_enabled' => '1',
                'position' => '1',
                'value' => 'Red',
                'swatch_type' => 'color',
                'color_code' => '#c62828',
                'swatch_image' => '',
                'value_is_enabled' => '1',
                'value_position' => '3',
            ],
            [
                'code' => 'size',
                'name' => 'Size',
                'description' => 'Apparel size for combinations.',
                'type' => 'select',
                'supports_swatches' => '0',
                'is_enabled' => '1',
                'position' => '2',
                'value' => 'S',
                'swatch_type' => 'none',
                'color_code' => '',
                'swatch_image' => '',
                'value_is_enabled' => '1',
                'value_position' => '1',
            ],
            [
                'code' => 'size',
                'name' => 'Size',
                'description' => 'Apparel size for combinations.',
                'type' => 'select',
                'supports_swatches' => '0',
                'is_enabled' => '1',
                'position' => '2',
                'value' => 'M',
                'swatch_type' => 'none',
                'color_code' => '',
                'swatch_image' => '',
                'value_is_enabled' => '1',
                'value_position' => '2',
            ],
            [
                'code' => 'size',
                'name' => 'Size',
                'description' => 'Apparel size for combinations.',
                'type' => 'select',
                'supports_swatches' => '0',
                'is_enabled' => '1',
                'position' => '2',
                'value' => 'L',
                'swatch_type' => 'none',
                'color_code' => '',
                'swatch_image' => '',
                'value_is_enabled' => '1',
                'value_position' => '3',
            ],
            [
                'code' => 'capacity',
                'name' => 'Capacity',
                'description' => 'Storage capacity options.',
                'type' => 'radio',
                'supports_swatches' => '0',
                'is_enabled' => '1',
                'position' => '3',
                'value' => '64 GB',
                'swatch_type' => 'none',
                'color_code' => '',
                'swatch_image' => '',
                'value_is_enabled' => '1',
                'value_position' => '1',
            ],
            [
                'code' => 'capacity',
                'name' => 'Capacity',
                'description' => 'Storage capacity options.',
                'type' => 'radio',
                'supports_swatches' => '0',
                'is_enabled' => '1',
                'position' => '3',
                'value' => '128 GB',
                'swatch_type' => 'none',
                'color_code' => '',
                'swatch_image' => '',
                'value_is_enabled' => '1',
                'value_position' => '2',
            ],
            [
                'code' => 'extras',
                'name' => 'Extras',
                'description' => 'Optional add-ons sold with the product.',
                'type' => 'checkbox',
                'supports_swatches' => '0',
                'is_enabled' => '1',
                'position' => '4',
                'value' => 'Protective Case',
                'swatch_type' => 'none',
                'color_code' => '',
                'swatch_image' => '',
                'value_is_enabled' => '1',
                'value_position' => '1',
            ],
            [
                'code' => 'extras',
                'name' => 'Extras',
                'description' => 'Optional add-ons sold with the product.',
                'type' => 'checkbox',
                'supports_swatches' => '0',
                'is_enabled' => '1',
                'position' => '4',
                'value' => 'Fast Charger',
                'swatch_type' => 'none',
                'color_code' => '',
                'swatch_image' => '',
                'value_is_enabled' => '1',
                'value_position' => '2',
            ],
            [
                'code' => 'weight',
                'name' => 'Weight',
                'description' => 'Numeric weight value for shipping estimates.',
                'type' => 'number',
                'supports_swatches' => '0',
                'is_enabled' => '1',
                'position' => '5',
                'value' => '250',
                'swatch_type' => 'none',
                'color_code' => '',
                'swatch_image' => '',
                'value_is_enabled' => '1',
                'value_position' => '1',
            ],
            [
                'code' => 'notes',
                'name' => 'Notes',
                'description' => 'Free-text notes attribute with no predefined values.',
                'type' => 'text',
                'supports_swatches' => '0',
                'is_enabled' => '1',
                'position' => '6',
                'value' => '',
                'swatch_type' => '',
                'color_code' => '',
                'swatch_image' => '',
                'value_is_enabled' => '',
                'value_position' => '',
            ],
            [
                'code' => 'retired-finish',
                'name' => 'Retired Finish',
                'description' => 'Disabled finish attribute kept for history.',
                'type' => 'select',
                'supports_swatches' => '1',
                'is_enabled' => '0',
                'position' => '10',
                'value' => 'Chrome',
                'swatch_type' => 'color',
                'color_code' => '#c0c0c0',
                'swatch_image' => '',
                'value_is_enabled' => '0',
                'value_position' => '1',
            ],
            [
                'code' => 'pattern',
                'name' => 'Pattern',
                'description' => 'Pattern options with image swatches.',
                'type' => 'select',
                'supports_swatches' => '1',
                'is_enabled' => '1',
                'position' => '7',
                'value' => 'Stripes',
                'swatch_type' => 'image',
                'color_code' => '',
                'swatch_image' => 'https://example.com/images/swatches/stripes.png',
                'value_is_enabled' => '1',
                'value_position' => '1',
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
     * @return array{attribute: array<string, mixed>, value: ?array<string, mixed>}
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
        if (! array_key_exists($type, CatalogAttribute::TYPES)) {
            $type = 'select';
        }

        $attribute = [
            'code' => self::nullable($get('code')),
            'name' => $name,
            'description' => self::nullable($get('description')),
            'type' => $type,
            'supports_swatches' => self::boolOr($get('supports_swatches'), false),
            'is_enabled' => self::boolOr($get('is_enabled'), true),
            'position' => self::filled($get('position')) ? (int) $get('position') : null,
            'language_code' => $locale,
        ];

        $valueLabel = (string) ($get('value') ?? '');
        $value = null;
        if ($valueLabel !== '') {
            $swatchType = strtolower((string) ($get('swatch_type') ?? 'none'));
            if (! array_key_exists($swatchType, CatalogAttribute::SWATCH_TYPES)) {
                $swatchType = 'none';
            }

            $value = [
                'value' => $valueLabel,
                'swatch_type' => $swatchType,
                'color_code' => self::nullable($get('color_code')),
                'swatch_image' => self::nullable($get('swatch_image')),
                'is_enabled' => self::boolOr($get('value_is_enabled'), true),
                'position' => self::filled($get('value_position')) ? (int) $get('value_position') : null,
                'language_code' => $locale,
            ];
        }

        return ['attribute' => $attribute, 'value' => $value];
    }

    /** @return list<list<string|int|null>> */
    public static function exportAttributeRows(CatalogAttribute $attribute, string $locale = 'en'): array
    {
        $t = $attribute->translation($locale);
        $base = [
            'code' => $attribute->code,
            'name' => $t?->name,
            'description' => $t?->description,
            'type' => $attribute->type,
            'supports_swatches' => self::boolToCsv($attribute->supports_swatches),
            'is_enabled' => self::boolToCsv($attribute->is_enabled),
            'position' => $attribute->position,
        ];

        $values = $attribute->relationLoaded('values')
            ? $attribute->values
            : $attribute->values()->with('translations')->orderBy('position')->orderBy('id')->get();

        if ($values->isEmpty()) {
            return [self::exportValues([
                'id' => $attribute->id,
                ...$base,
                'value' => '',
                'swatch_type' => '',
                'color_code' => '',
                'swatch_image' => '',
                'value_is_enabled' => '',
                'value_position' => '',
            ])];
        }

        $rows = [];
        foreach ($values as $value) {
            /** @var AttributeValue $value */
            $rows[] = self::exportValues([
                'id' => $value->id,
                ...$base,
                'value' => $value->translation($locale)?->value,
                'swatch_type' => $value->swatch_type,
                'color_code' => $value->color_code,
                'swatch_image' => $value->swatch_image,
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
