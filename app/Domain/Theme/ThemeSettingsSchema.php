<?php

namespace App\Domain\Theme;

/**
 * Generic normalizer for theme-defined settings_schema (lives in theme.json or settings_schema.json).
 */
class ThemeSettingsSchema
{
    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $defaults, array $schema, array $input): array
    {
        $merged = ThemeSettingsCatalog::merge($defaults, $input);
        $groups = is_array($schema['groups'] ?? null) ? $schema['groups'] : [];

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }
            foreach (self::fieldsInGroup($group) as $field) {
                if (! is_array($field)) {
                    continue;
                }
                $path = (string) ($field['path'] ?? '');
                if ($path === '') {
                    continue;
                }
                $value = self::getByPath($merged, $path);
                self::setByPath($merged, $path, self::sanitizeField($field, $value, $defaults, $path));
            }
        }

        if (isset($merged['colors']) && is_array($merged['colors'])) {
            $merged['colors'] = ThemeSettingsCatalog::ensureReadableContrast($merged['colors']);
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $group
     * @return list<array<string, mixed>>
     */
    protected static function fieldsInGroup(array $group): array
    {
        $fields = is_array($group['fields'] ?? null) ? $group['fields'] : [];

        return array_values(array_filter($fields, fn ($f) => is_array($f)));
    }

    /**
     * @param  array<string, mixed>  $field
     */
    protected static function sanitizeField(array $field, mixed $value, array $defaults, string $path): mixed
    {
        $type = (string) ($field['type'] ?? 'text');
        $fallback = self::getByPath($defaults, $path);

        return match ($type) {
            'toggle' => (bool) $value,
            'number' => self::number($value, $field, is_numeric($fallback) ? (float) $fallback : 0),
            'color' => self::hex($value, is_string($fallback) ? $fallback : '#111111'),
            'select' => self::select($value, $field, $fallback),
            'textarea' => self::str($value, (int) ($field['max'] ?? 2000)),
            'image', 'media' => self::mediaPath($value),
            'strings' => self::stringList($value, (int) ($field['max_items'] ?? 20), (int) ($field['max'] ?? 120)),
            'repeater' => self::repeater($value, $field, is_array($fallback) ? $fallback : []),
            default => self::str($value, (int) ($field['max'] ?? 240)),
        };
    }

    /**
     * @param  array<string, mixed>  $field
     */
    protected static function repeater(mixed $value, array $field, array $fallback): array
    {
        if (! is_array($value)) {
            return $fallback;
        }
        $max = max(0, (int) ($field['max_items'] ?? 12));
        $subFields = is_array($field['fields'] ?? null) ? $field['fields'] : [];
        $rows = [];
        foreach (array_slice($value, 0, $max) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $baseRow = is_array($fallback[$index] ?? null) ? $fallback[$index] : [];
            $clean = [];
            foreach ($subFields as $sub) {
                if (! is_array($sub)) {
                    continue;
                }
                $key = (string) ($sub['key'] ?? '');
                if ($key === '') {
                    continue;
                }
                $subPath = $key;
                $clean[$key] = self::sanitizeField($sub, $row[$key] ?? $baseRow[$key] ?? null, $baseRow, $subPath);
            }
            if ($clean !== []) {
                $rows[] = $clean;
            }
        }

        return $rows !== [] ? $rows : $fallback;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    protected static function select(mixed $value, array $field, mixed $fallback): string
    {
        $value = is_string($value) ? trim($value) : '';
        $options = [];
        foreach ((array) ($field['options'] ?? []) as $opt) {
            if (is_string($opt)) {
                $options[] = $opt;
            } elseif (is_array($opt) && isset($opt['value'])) {
                $options[] = (string) $opt['value'];
            }
        }
        if ($options !== [] && ! in_array($value, $options, true)) {
            return is_string($fallback) ? $fallback : (string) ($options[0] ?? '');
        }

        return self::str($value, (int) ($field['max'] ?? 80));
    }

    /**
     * @param  array<string, mixed>  $field
     */
    protected static function number(mixed $value, array $field, float|int $fallback): int|float
    {
        $min = isset($field['min']) ? (float) $field['min'] : null;
        $max = isset($field['max']) ? (float) $field['max'] : null;
        $step = isset($field['step']) ? (float) $field['step'] : null;
        $n = is_numeric($value) ? (float) $value : (float) $fallback;
        if ($min !== null) {
            $n = max($min, $n);
        }
        if ($max !== null) {
            $n = min($max, $n);
        }
        if ($step !== null && $step >= 1) {
            return (int) round($n);
        }

        return $n;
    }

    /**
     * @return list<string>
     */
    protected static function stringList(mixed $value, int $maxItems, int $maxLen): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach (array_slice($value, 0, $maxItems) as $item) {
            $s = self::str($item, $maxLen);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return $out;
    }

    protected static function str(mixed $value, int $max): string
    {
        $value = is_string($value) ? trim(strip_tags($value)) : (is_numeric($value) ? (string) $value : '');

        return mb_substr($value, 0, $max);
    }

    protected static function hex(mixed $value, string $fallback): string
    {
        $value = is_string($value) ? trim($value) : '';
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value)) {
            return strtoupper(strlen($value) === 4
                ? sprintf('#%s%s%s%s%s%s', $value[1], $value[1], $value[2], $value[2], $value[3], $value[3])
                : $value);
        }

        return strtoupper($fallback);
    }

    protected static function mediaPath(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $value)) {
            if (! filter_var($value, FILTER_VALIDATE_URL)) {
                return '';
            }

            return mb_substr($value, 0, 500);
        }
        if (str_starts_with($value, '/')) {
            return mb_substr($value, 0, 500);
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function getByPath(array $data, string $path): mixed
    {
        $parts = explode('.', $path);
        $cur = $data;
        foreach ($parts as $part) {
            if (! is_array($cur) || ! array_key_exists($part, $cur)) {
                return null;
            }
            $cur = $cur[$part];
        }

        return $cur;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function setByPath(array &$data, string $path, mixed $value): void
    {
        $parts = explode('.', $path);
        $cur = &$data;
        $last = array_pop($parts);
        foreach ($parts as $part) {
            if (! isset($cur[$part]) || ! is_array($cur[$part])) {
                $cur[$part] = [];
            }
            $cur = &$cur[$part];
        }
        $cur[$last] = $value;
    }
}
