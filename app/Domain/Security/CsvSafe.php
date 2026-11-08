<?php

namespace App\Domain\Security;

/**
 * Neutralize CSV formula injection when staff open exports in Excel / LibreOffice.
 */
final class CsvSafe
{
    public static function cell(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * @param  list<mixed>|array<string, mixed>  $row
     * @return list<mixed>|array<string, mixed>
     */
    public static function row(array $row): array
    {
        foreach ($row as $key => $value) {
            $row[$key] = self::cell($value);
        }

        return $row;
    }

    /**
     * @param  resource  $handle
     * @param  list<mixed>|array<string, mixed>  $row
     */
    public static function put($handle, array $row, string $delimiter = ',', string $enclosure = '"', string $escape = '\\'): int|false
    {
        $row = self::row($row);
        $parts = [];
        foreach ($row as $value) {
            $value = (string) $value;
            if (str_contains($value, $enclosure)) {
                $value = str_replace($enclosure, $enclosure.$enclosure, $value);
            }
            // Always quote so comma inside values (e.g. meta_keywords) does not shift columns
            // when spreadsheets open semicolon-delimited exports with comma as a separator.
            $parts[] = $enclosure.$value.$enclosure;
        }

        return fwrite($handle, implode($delimiter, $parts)."\n");
    }
}
