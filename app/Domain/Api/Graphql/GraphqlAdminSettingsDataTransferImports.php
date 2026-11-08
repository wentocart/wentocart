<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Catalog\Services\CategoryService;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Catalog\Support\CatalogCsvImport;
use App\Domain\Catalog\Support\CategoryCsvSchema;
use App\Domain\Catalog\Support\ProductCsvSchema;
use App\Domain\Customer\Services\CustomerService;
use App\Domain\Customer\Support\CustomerCsvSchema;
use App\Domain\Settings\Models\DataTransferImport;
use App\Domain\Tax\Support\TaxRuleCsvSchema;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Validation\ValidationException;

/**
 */
final class GraphqlAdminSettingsDataTransferImports
{
    public static function assertManage(): Admin
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! $admin->hasPermission('settings.manage')) {
            throw new UserError('Forbidden.');
        }

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public static function paginate(array $filters): array
    {
        self::assertManage();
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? $filters['limit'] ?? 10)));
        $q = DataTransferImport::query();
        $code = trim((string) ($filters['code'] ?? $filters['type'] ?? ''));
        if ($code !== '') {
            $q->where('code', $code);
        }
        $action = trim((string) ($filters['action'] ?? ''));
        if ($action !== '') {
            $q->where('action', $action);
        }
        $state = trim((string) ($filters['state'] ?? ''));
        if ($state !== '') {
            $q->where('state', $state);
        }
        $from = trim((string) ($filters['created_at_from'] ?? ''));
        if ($from !== '') {
            $q->whereDate('created_at', '>=', $from);
        }
        $to = trim((string) ($filters['created_at_to'] ?? ''));
        if ($to !== '') {
            $q->whereDate('created_at', '<=', $to);
        }
        $sort = strtolower(trim((string) ($filters['sort'] ?? 'id')));
        $order = strtolower(trim((string) ($filters['order'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        $column = match ($sort) {
            'state' => 'state',
            'created_at' => 'created_at',
            default => 'id',
        };
        $q->orderBy($column, $order);
        $paginator = $q->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(fn (DataTransferImport $row) => self::map($row, false))->values()->all(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    public static function list(array $args): array
    {
        $page = self::paginate(array_merge($args, [
            'page' => 1,
            'per_page' => max(1, min(200, (int) ($args['limit'] ?? 50))),
        ]));

        return $page['data'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id, bool $withDetails = true): ?array
    {
        self::assertManage();
        $row = DataTransferImport::query()->find($id);

        return $row ? self::map($row, $withDetails) : null;
    }

    /**
     *
     * @return array{content: string, filename: string, mime: string}
     */
    public static function sampleDownload(string $type, string $format): array
    {
        self::assertManage();
        $type = strtolower(trim($type));
        $format = strtolower(trim($format));
        $allowedTypes = ['products', 'customers', 'tax_rates', 'categories'];
        $allowedFormats = ['csv', 'xls', 'xlsx', 'xml'];
        if (! in_array($type, $allowedTypes, true)) {
            throw new UserError('Unknown importer type.');
        }
        if (! in_array($format, $allowedFormats, true)) {
            throw new UserError('Unknown sample format.');
        }

        $csv = match ($type) {
            'products' => ProductCsvSchema::sampleCsv(),
            'customers' => CustomerCsvSchema::sampleCsv(),
            'tax_rates' => TaxRuleCsvSchema::sampleCsv(),
            'categories' => CategoryCsvSchema::sampleCsv(),
            default => '',
        };
        if ($csv === '') {
            throw new UserError('Sample template unavailable.');
        }

        if ($format === 'xml') {
            $content = self::csvToSampleXml($csv, $type);
            $mime = 'application/xml';
            $ext = 'xml';
        } else {
            // csv / xls / xlsx — CSV body (accepts csv; spreadsheet formats share columns)
            $content = $csv;
            $mime = $format === 'csv'
                ? 'text/csv; charset=UTF-8'
                : 'application/octet-stream';
            $ext = $format === 'csv' ? 'csv' : $format;
        }

        return [
            'content' => $content,
            'filename' => 'sample-'.$type.'.'.$ext,
            'mime' => $mime,
        ];
    }

    protected static function csvToSampleXml(string $csv, string $type): string
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($csv)) ?: [];
        $header = str_getcsv((string) array_shift($lines));
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<'.$type.'>'."\n";
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = str_getcsv($line);
            $xml .= "  <row>\n";
            foreach ($header as $i => $key) {
                $key = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string) $key) ?: 'col';
                $val = htmlspecialchars((string) ($cols[$i] ?? ''), ENT_XML1 | ENT_COMPAT, 'UTF-8');
                $xml .= '    <'.$key.'>'.$val.'</'.$key.">\n";
            }
            $xml .= "  </row>\n";
        }
        $xml .= '</'.$type.'>'."\n";

        return $xml;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function create(array $args): array
    {
        self::assertManage();
        $code = strtolower(trim((string) ($args['code'] ?? $args['type'] ?? '')));
        $action = strtolower(trim((string) ($args['action'] ?? 'append')));
        $csv = (string) ($args['csvContent'] ?? $args['csv_content'] ?? '');
        if ($code === '' || $csv === '') {
            throw new UserError('code and csvContent are required.');
        }
        if (! in_array($action, ['append', 'delete'], true)) {
            throw new UserError('action must be append or delete.');
        }
        $allowed = ['products', 'customers', 'tax_rates', 'categories'];
        if (! in_array($code, $allowed, true)) {
            throw new UserError('code must be one of: '.implode(', ', $allowed).'.');
        }
        $row = DataTransferImport::query()->create([
            'code' => $code,
            'action' => $action,
            'state' => 'pending',
            'file_name' => trim((string) ($args['fileName'] ?? $args['file_name'] ?? $code.'-import.csv')) ?: $code.'-import.csv',
            'csv_content' => $csv,
            'processed_rows_count' => 0,
            'invalid_rows_count' => 0,
            'errors_count' => 0,
            'errors_json' => [],
            'summary_json' => null,
        ]);

        return self::map($row, true);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function validate(array $args): array
    {
        self::assertManage();
        $id = (int) ($args['importId'] ?? $args['id'] ?? 0);
        $row = DataTransferImport::query()->find($id);
        if (! $row) {
            throw new UserError('Import not found.');
        }
        if (in_array($row->state, ['cancelled', 'completed'], true)) {
            throw new UserError('Cannot validate a '.$row->state.' import.');
        }
        $errors = [];
        $invalid = 0;
        $rowsCount = 0;
        try {
            $parsed = CatalogCsvImport::parse((string) $row->csv_content, ',');
            $header = $parsed['header'] ?? [];
            $rows = $parsed['rows'] ?? [];
            $rowsCount = count($rows);
            if ($header === []) {
                $errors[] = 'CSV header row is missing.';
            }
            if ($rowsCount < 1) {
                $errors[] = 'CSV has no data rows.';
                $invalid = 1;
            }
            if ($row->code === 'products') {
                $normalized = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
                foreach (['sku', 'name'] as $required) {
                    if (! in_array($required, $normalized, true)) {
                        $errors[] = 'Missing required column: '.$required;
                    }
                }
            }
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
            $invalid = max(1, $invalid);
        }
        $isValid = $errors === [];
        $row->update([
            'state' => $isValid ? 'validated' : 'failed',
            'errors_count' => count($errors),
            'invalid_rows_count' => $invalid,
            'errors_json' => $errors,
            'summary_json' => [
                'rows' => $rowsCount,
                'validated_at' => now()->toIso8601String(),
            ],
        ]);
        $fresh = $row->fresh() ?? $row;

        return [
            'ok' => true,
            'isValid' => $isValid,
            'errorsCount' => count($errors),
            'invalidRowsCount' => $invalid,
            'import' => self::map($fresh, true),
            'message' => $isValid ? 'Import validated successfully.' : 'Import validation failed.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function advance(array $args, string $nextState): array
    {
        self::assertManage();
        $row = DataTransferImport::query()->find((int) ($args['id'] ?? 0));
        if (! $row) {
            throw new UserError('Import not found.');
        }
        $required = match ($nextState) {
            'processing' => 'validated',
            'linked' => 'processing',
            'indexed' => 'linked',
            default => null,
        };
        if ($required !== null && $row->state !== $required) {
            throw new UserError('Import must be in "'.$required.'" state before '.$nextState.'.');
        }
        $processed = (int) $row->processed_rows_count;
        $writeSummary = [];
        if ($nextState === 'processing') {
            try {
                $writeSummary = self::writeCatalogRows($row);
                $processed = (int) ($writeSummary['imported'] ?? 0);
            } catch (ValidationException $e) {
                $messages = collect($e->errors())->flatten()->map(fn ($m) => (string) $m)->values()->all();
                $row->update([
                    'state' => 'failed',
                    'errors_count' => count($messages),
                    'invalid_rows_count' => max(1, count($messages)),
                    'errors_json' => $messages,
                    'summary_json' => array_merge(is_array($row->summary_json) ? $row->summary_json : [], [
                        'processing_failed_at' => now()->toIso8601String(),
                    ]),
                ]);
                throw new UserError($messages[0] ?? 'Import processing failed.');
            } catch (\Throwable $e) {
                $row->update([
                    'state' => 'failed',
                    'errors_count' => 1,
                    'invalid_rows_count' => 1,
                    'errors_json' => [$e->getMessage()],
                    'summary_json' => array_merge(is_array($row->summary_json) ? $row->summary_json : [], [
                        'processing_failed_at' => now()->toIso8601String(),
                    ]),
                ]);
                throw new UserError('Import processing failed: '.$e->getMessage());
            }
        }
        $finalState = $nextState === 'indexed' ? 'completed' : $nextState;
        $row->update([
            'state' => $finalState,
            'processed_rows_count' => $processed,
            'summary_json' => array_merge(is_array($row->summary_json) ? $row->summary_json : [], [
                $nextState.'_at' => now()->toIso8601String(),
                'processed_rows' => $processed,
                ...$writeSummary,
            ]),
        ]);
        $fresh = $row->fresh() ?? $row;

        return [
            'ok' => true,
            'state' => (string) $fresh->state,
            'processedRowsCount' => (int) $fresh->processed_rows_count,
            'import' => self::map($fresh, true),
            'message' => 'Import advanced to '.$fresh->state.'.',
        ];
    }

    /**
     * Persist validated CSV rows through the same writers as admin list imports.
     *
     * @return array{imported: int, code: string}
     */
    protected static function writeCatalogRows(DataTransferImport $row): array
    {
        $csv = (string) $row->csv_content;
        $code = (string) $row->code;
        $delimiter = CatalogCsvImport::detectDelimiter($csv, ',');
        $imported = match ($code) {
            'products' => app(ProductService::class)->importCsv($csv, 'en', $delimiter),
            'categories' => app(CategoryService::class)->importCsv($csv, 'en', $delimiter),
            'customers' => app(CustomerService::class)->importCsv($csv),
            'tax_rates' => TaxRuleCsvSchema::import($csv, $delimiter),
            default => throw new UserError('Unsupported data-transfer code: '.$code),
        };

        return [
            'imported' => (int) $imported,
            'code' => $code,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function cancel(array $args): array
    {
        self::assertManage();
        $row = DataTransferImport::query()->find((int) ($args['id'] ?? 0));
        if (! $row) {
            throw new UserError('Import not found.');
        }
        if (in_array($row->state, ['completed', 'cancelled'], true)) {
            throw new UserError('Cannot cancel a '.$row->state.' import.');
        }
        $row->update(['state' => 'cancelled']);
        $fresh = $row->fresh() ?? $row;

        return [
            'ok' => true,
            'state' => 'cancelled',
            'processedRowsCount' => (int) $fresh->processed_rows_count,
            'import' => self::map($fresh, true),
            'message' => 'Import cancelled.',
        ];
    }

    /**
     * @return array{ok: bool, id: int, _id: int, message: string}
     */
    public static function delete(int $id): array
    {
        self::assertManage();
        $row = DataTransferImport::query()->find($id);
        if (! $row) {
            throw new UserError('Import not found.');
        }
        $snapshot = [
            'ok' => true,
            'id' => (string) $row->id,

            'message' => 'Import deleted successfully.',
        ];
        $row->delete();

        return $snapshot;
    }

    /**
     * @param  list<int|string>  $ids
     * @return array{ok: bool, deleted: list<int>, skipped: list<int>, updated_count: int, message: string}
     */
    public static function bulkDelete(array $ids): array
    {
        self::assertManage();
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($ids === []) {
            throw new UserError('Provide at least one import id.');
        }
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = DataTransferImport::query()->find($id);
            if (! $row) {
                $skipped[] = $id;
                continue;
            }
            $row->delete();
            $deleted[] = $id;
        }

        return [
            'ok' => true,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'updated_count' => count($deleted),
            'message' => count($deleted).' import(s) deleted.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(DataTransferImport $row, bool $withDetails = false): array
    {
        $errors = is_array($row->errors_json) ? array_values(array_map('strval', $row->errors_json)) : [];
        $summary = is_array($row->summary_json) ? json_encode($row->summary_json) : null;

        return [
            'id' => (int) $row->id,

            'code' => (string) $row->code,
            'type' => (string) $row->code,
            'action' => (string) $row->action,
            'state' => (string) $row->state,
            'file_name' => $row->file_name,
            'fileName' => $row->file_name,
            'processedRowsCount' => (int) $row->processed_rows_count,
            'processed_rows_count' => (int) $row->processed_rows_count,
            'invalidRowsCount' => (int) $row->invalid_rows_count,
            'invalid_rows_count' => (int) $row->invalid_rows_count,
            'errorsCount' => (int) $row->errors_count,
            'errors_count' => (int) $row->errors_count,
            'errors' => $withDetails ? $errors : null,
            'summary' => $withDetails ? $summary : null,
            'created_at' => optional($row->created_at)?->toIso8601String(),
            'createdAt' => optional($row->created_at)?->toIso8601String(),
        ];
    }
}
