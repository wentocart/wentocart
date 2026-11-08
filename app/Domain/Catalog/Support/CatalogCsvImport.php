<?php

namespace App\Domain\Catalog\Support;

use Illuminate\Validation\ValidationException;

/**
 * Shared CSV parse + header checks for Catalog (and related) importers.
 *
 * Each entity schema's columns() is the single source of truth for expected headers
 * (sample download, export, and import validation).
 */
final class CatalogCsvImport
{
    /** Hard caps against memory-exhaustion uploads (all callers funnel through here). */
    protected const MAX_BYTES = 16 * 1024 * 1024;

    protected const MAX_ROWS = 5000;

    protected const MAX_COLUMNS = 200;

    protected const MAX_CELL_CHARS = 200000;
    /**
     * @return array{header: list<string>, rows: list<array<string, string>>}
     */
    /**
     * Pick ; or , from the header line. Catalog samples use ; by default.
     */
    public static function detectDelimiter(string $contents, string $fallback = ';'): string
    {
        $first = strtok(str_replace(["\r\n", "\r"], "\n", ltrim($contents)), "\n");
        if (! is_string($first) || $first === '') {
            return $fallback;
        }
        $semi = substr_count($first, ';');
        $comma = substr_count($first, ',');
        if ($semi === 0 && $comma === 0) {
            return $fallback;
        }

        return $semi >= $comma ? ';' : ',';
    }

    public static function parse(string $contents, string $delimiter): array
    {
        if (strlen($contents) > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'file' => 'Invalid CSV file. The file is too large (maximum 8 MB). Split it into smaller files.',
            ]);
        }
        $trimmed = trim($contents);
        if ($trimmed === '') {
            throw ValidationException::withMessages([
                'file' => 'Invalid CSV file. The file is empty.',
            ]);
        }

        $lines = preg_split('/\r\n|\r|\n/', $trimmed) ?: [];
        $headerLine = array_shift($lines);
        if ($headerLine === null || trim($headerLine) === '') {
            throw ValidationException::withMessages([
                'file' => 'Invalid CSV file. The file is empty.',
            ]);
        }

        $rawHeader = str_getcsv($headerLine, $delimiter);
        $header = [];
        foreach ($rawHeader as $index => $column) {
            $name = trim((string) $column);
            if ($name === '') {
                throw ValidationException::withMessages([
                    'file' => 'Invalid CSV file. The header row contains an empty column name.',
                ]);
            }
            if (isset($header[$name])) {
                throw ValidationException::withMessages([
                    'file' => "Invalid CSV file. Duplicate header column \"{$name}\".",
                ]);
            }
            $header[$name] = true;
        }
        /** @var list<string> $headerList */
        $headerList = array_keys($header);
        if (count($headerList) > self::MAX_COLUMNS) {
            throw ValidationException::withMessages([
                'file' => 'Invalid CSV file. Too many columns (maximum '.self::MAX_COLUMNS.').',
            ]);
        }

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                throw ValidationException::withMessages([
                    'file' => 'Invalid CSV file. Too many rows (maximum '.self::MAX_ROWS.'). Split it into smaller files.',
                ]);
            }
            $cells = str_getcsv($line, $delimiter);
            if (count($cells) < count($headerList)) {
                $cells = array_pad($cells, count($headerList), '');
            }
            $combined = @array_combine($headerList, array_slice($cells, 0, count($headerList)));
            if (! is_array($combined)) {
                continue;
            }
            $row = [];
            foreach ($combined as $key => $value) {
                $cell = trim((string) $value);
                if (strlen($cell) > self::MAX_CELL_CHARS) {
                    throw ValidationException::withMessages([
                        'file' => 'Invalid CSV file. A cell exceeds the maximum length of '.self::MAX_CELL_CHARS.' characters.',
                    ]);
                }
                $row[(string) $key] = $cell;
            }
            $rows[] = $row;
        }

        return [
            'header' => $headerList,
            'rows' => $rows,
        ];
    }

    /**
     * Require schema columns to be present (extras are allowed and ignored).
     *
     * @param  list<string>  $header
     * @param  list<string>  $expectedColumns  Full schema (used in the error message)
     * @param  list<string>|null  $requiredColumns  Defaults to all expected columns except optional `id`
     */
    public static function assertHeaders(
        array $header,
        array $expectedColumns,
        string $entityLabel,
        ?array $requiredColumns = null,
    ): void {
        $present = [];
        foreach ($header as $column) {
            $present[trim((string) $column)] = true;
        }

        $required = $requiredColumns ?? array_values(array_filter(
            $expectedColumns,
            static fn (string $column) => $column !== 'id',
        ));
        $missing = [];
        foreach ($required as $column) {
            if (! isset($present[$column])) {
                $missing[] = $column;
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'file' => 'Invalid CSV file. This file is not compatible with '.$entityLabel.' import.'
                    .' Expected '.$entityLabel.' fields: '.implode(', ', $expectedColumns).'.',
            ]);
        }
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    public static function assertHasDataRows(array $rows, string $entityLabel): void
    {
        if ($rows === []) {
            throw ValidationException::withMessages([
                'file' => 'Invalid CSV file. This '.$entityLabel.' CSV has a header but no data rows.',
            ]);
        }
    }

    /**
     * @param  list<string>  $errors
     */
    public static function throwRowErrors(array $errors, int $limit = 25): void
    {
        if ($errors === []) {
            return;
        }

        $slice = array_slice($errors, 0, $limit);
        $message = implode("\n", $slice);
        if (count($errors) > $limit) {
            $message .= "\n… and ".(count($errors) - $limit).' more error(s).';
        }

        throw ValidationException::withMessages([
            'file' => $message,
        ]);
    }

    public static function rowNumber(int $zeroBasedIndex): int
    {
        // Header is row 1; first data row is row 2.
        return $zeroBasedIndex + 2;
    }
}
