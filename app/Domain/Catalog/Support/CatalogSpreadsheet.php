<?php

namespace App\Domain\Catalog\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Accept CSV/TXT plus Excel uploads (HTML .xls from Excel and real .xlsx).
 */
final class CatalogSpreadsheet
{
    public static function fromUpload(UploadedFile $file): string
    {
        return self::toCsv((string) $file->getContent(), (string) $file->getClientOriginalName());
    }

    public static function toCsv(string $contents, string $filename = ''): string
    {
        if ($contents === '') {
            return $contents;
        }

        if (str_starts_with($contents, 'PK')) {
            return self::xlsxToCsv($contents);
        }

        if (str_contains(strtolower($contents), '<table')) {
            return self::htmlTableToCsv($contents);
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, ['xls', 'xlsx'], true)) {
            throw ValidationException::withMessages([
                'file' => 'This Excel file could not be read. Save it as CSV or XLSX and try again.',
            ]);
        }

        return $contents;
    }

    protected static function htmlTableToCsv(string $html): string
    {
        if (! preg_match('/<table\b[^>]*>(.*)<\/table>/is', $html, $table)) {
            throw ValidationException::withMessages([
                'file' => 'This Excel file has no table to import.',
            ]);
        }

        preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/is', $table[1], $rows);
        if ($rows[1] === []) {
            throw ValidationException::withMessages([
                'file' => 'This Excel file has no rows to import.',
            ]);
        }

        $out = fopen('php://temp', 'r+');
        foreach ($rows[1] as $rowHtml) {
            preg_match_all('/<(?:th|td)\b[^>]*>(.*?)<\/(?:th|td)>/is', $rowHtml, $cells);
            $line = array_map(static function (string $cell): string {
                $text = html_entity_decode(strip_tags($cell), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return trim(str_replace("\xc2\xa0", ' ', $text));
            }, $cells[1]);
            fputcsv($out, $line, ',', '"', '\\');
        }
        rewind($out);

        return stream_get_contents($out) ?: '';
    }

    protected static function xlsxToCsv(string $binary): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'wento-xlsx-');
        if ($tmp === false) {
            throw ValidationException::withMessages(['file' => 'Could not read the Excel file.']);
        }
        file_put_contents($tmp, $binary);

        $zip = new ZipArchive;
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);
            throw ValidationException::withMessages([
                'file' => 'This Excel file could not be opened. Save it as CSV or XLSX and try again.',
            ]);
        }

        $shared = self::xlsxSharedStrings((string) $zip->getFromName('xl/sharedStrings.xml'));
        $sheet = (string) ($zip->getFromName('xl/worksheets/sheet1.xml') ?: $zip->getFromName('xl/worksheets/sheet2.xml'));
        $zip->close();
        @unlink($tmp);

        if ($sheet === '') {
            throw ValidationException::withMessages(['file' => 'This Excel file has no worksheet to import.']);
        }

        preg_match_all('/<c\b([^>]*)>(.*?)<\/c>/is', $sheet, $cells, PREG_SET_ORDER);
        $grid = [];
        $maxCol = 0;
        $maxRow = 0;
        foreach ($cells as $cell) {
            if (! preg_match('/r="([A-Z]+)(\d+)"/', $cell[1], $ref)) {
                continue;
            }
            $col = self::xlsxColumnIndex($ref[1]);
            $row = (int) $ref[2];
            $value = '';
            if (preg_match('/<v>(.*?)<\/v>/is', $cell[2], $v)) {
                $value = html_entity_decode(html_entity_decode($v[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (str_contains($cell[1], 't="s"')) {
                    $value = $shared[(int) $value] ?? $value;
                }
            } elseif (preg_match('/<t[^>]*>(.*?)<\/t>/is', $cell[2], $t)) {
                $value = html_entity_decode($t[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            $grid[$row][$col] = $value;
            $maxCol = max($maxCol, $col);
            $maxRow = max($maxRow, $row);
        }

        if ($maxRow < 1) {
            throw ValidationException::withMessages(['file' => 'This Excel file has no rows to import.']);
        }

        $out = fopen('php://temp', 'r+');
        for ($r = 1; $r <= $maxRow; $r++) {
            $line = [];
            for ($c = 1; $c <= $maxCol; $c++) {
                $line[] = (string) ($grid[$r][$c] ?? '');
            }
            fputcsv($out, $line, ',', '"', '\\');
        }
        rewind($out);

        return stream_get_contents($out) ?: '';
    }

    /** @return array<int, string> */
    protected static function xlsxSharedStrings(string $xml): array
    {
        if ($xml === '') {
            return [];
        }
        preg_match_all('/<si\b[^>]*>(.*?)<\/si>/is', $xml, $items);
        $out = [];
        foreach ($items[1] as $i => $item) {
            preg_match_all('/<t[^>]*>(.*?)<\/t>/is', $item, $texts);
            $out[$i] = html_entity_decode(implode('', $texts[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $out;
    }

    protected static function xlsxColumnIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = ($n * 26) + (ord($ch) - 64);
        }

        return $n;
    }
}
