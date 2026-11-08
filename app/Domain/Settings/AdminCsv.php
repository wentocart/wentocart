<?php

namespace App\Domain\Settings;

use App\Domain\Catalog\Support\CatalogCsvImport;
use App\Domain\Security\CsvSafe;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared CSV download / parse helpers for admin list import and export.
 */
final class AdminCsv
{
    public const DELIMITER = ';';

    /**
     * @param  list<string>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    public static function stringify(array $columns, array $rows, string $delimiter = self::DELIMITER): string
    {
        $out = fopen('php://temp', 'r+');
        CsvSafe::put($out, $columns, $delimiter);
        foreach ($rows as $row) {
            $cells = [];
            foreach ($columns as $column) {
                $value = $row[$column] ?? '';
                if (is_bool($value)) {
                    $value = $value ? '1' : '0';
                }
                $cells[] = is_scalar($value) || $value === null ? (string) $value : '';
            }
            CsvSafe::put($out, $cells, $delimiter);
        }
        rewind($out);

        return stream_get_contents($out) ?: '';
    }

    public static function download(string $filename, string $csv): StreamedResponse
    {
        return response()->streamDownload(fn () => print ($csv), self::safeDownloadName($filename), [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Strip CRLF (response-header injection) and filesystem-hostile characters
     * from download filenames. All export endpoints funnel through here.
     */
    public static function safeDownloadName(string $filename, string $fallback = 'export.csv'): string
    {
        $base = basename(str_replace('\\', '/', $filename));
        $base = str_replace(["\r", "\n"], '', $base);
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base) ?? '';
        $base = trim($base, '._');
        if ($base === '' || strlen($base) > 120) {
            return $fallback;
        }

        return $base;
    }

    /**
     * @param  list<string>  $expected
     * @param  list<string>|null  $required
     * @return array{header: list<string>, rows: list<array<string, string>>}
     */
    public static function parse(
        string $contents,
        array $expected,
        string $label,
        ?string $delimiter = null,
        ?array $required = null,
    ): array {
        $delimiter = ($delimiter !== null && $delimiter !== '')
            ? $delimiter
            : CatalogCsvImport::detectDelimiter($contents, self::DELIMITER);
        $parsed = CatalogCsvImport::parse($contents, $delimiter);
        CatalogCsvImport::assertHeaders($parsed['header'], $expected, $label, $required);
        CatalogCsvImport::assertHasDataRows($parsed['rows'], $label);

        return $parsed;
    }

    /**
     * @param  list<string>  $expected
     * @param  list<string>|null  $required
     * @return array{header: list<string>, rows: list<array<string, string>>}
     */
    public static function parseRequest(Request $request, array $expected, string $label, ?array $required = null): array
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:8192'],
            'delimiter' => ['nullable', 'string', 'max:8'],
        ]);
        $contents = (string) $request->file('file')->getContent();
        $delimiter = trim((string) ($data['delimiter'] ?? ''));

        return self::parse($contents, $expected, $label, $delimiter !== '' ? $delimiter : null, $required);
    }

    public static function bool(?string $value, bool $default = true): bool
    {
        if ($value === null || trim($value) === '') {
            return $default;
        }
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            '1', 'true', 'yes', 'y', 'on', 'active', 'enabled', 'published' => true,
            '0', 'false', 'no', 'n', 'off', 'inactive', 'disabled' => false,
            default => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default,
        };
    }

    public static function filled(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }

    public static function nullable(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    public static function int(?string $value, ?int $default = null): ?int
    {
        if (! self::filled($value)) {
            return $default;
        }

        return (int) $value;
    }

    public static function float(?string $value, ?float $default = null): ?float
    {
        if (! self::filled($value)) {
            return $default;
        }

        return (float) $value;
    }

    public static function get(array $row, string $key): string
    {
        return trim((string) ($row[$key] ?? ''));
    }
}
