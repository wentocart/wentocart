<?php

namespace App\Domain\Localization;

use App\Domain\Localization\Models\Country;
use App\Domain\Localization\Models\Currency;
use App\Domain\Localization\Models\LocalizationImportBatch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use App\Domain\Security\CsvSafe;

class LocalizationImportService
{
    public function exportCountriesCsv(): string
    {
        $rows = Country::query()->orderBy('position')->orderBy('name')->get();
        $out = fopen('php://temp', 'r+');
        CsvSafe::put($out, ['code', 'iso3', 'name', 'calling_code', 'default_currency_code', 'timezone', 'address_format', 'postal_code_label', 'tax_id_label', 'has_regions', 'is_enabled']);
        foreach ($rows as $row) {
            CsvSafe::put($out, [
                $row->code, $row->iso3, $row->name, $row->calling_code, $row->default_currency_code,
                $row->timezone, $row->address_format, $row->postal_code_label, $row->tax_id_label,
                $row->has_regions ? 1 : 0, $row->is_enabled ? 1 : 0,
            ]);
        }
        rewind($out);

        return stream_get_contents($out) ?: '';
    }

    public function exportCurrenciesCsv(): string
    {
        $rows = Currency::query()->orderBy('position')->orderBy('code')->get();
        $out = fopen('php://temp', 'r+');
        CsvSafe::put($out, ['code', 'name', 'symbol', 'decimal_places', 'symbol_position', 'exchange_rate', 'is_enabled', 'is_default']);
        foreach ($rows as $row) {
            CsvSafe::put($out, [
                $row->code, $row->name, $row->symbol, $row->decimal_places, $row->symbol_position,
                $row->exchange_rate, $row->is_enabled ? 1 : 0, $row->is_default ? 1 : 0,
            ]);
        }
        rewind($out);

        return stream_get_contents($out) ?: '';
    }

    public function stageCsv(string $type, UploadedFile $file): LocalizationImportBatch
    {
        abort_unless(in_array($type, ['countries', 'currencies'], true), 422, 'Unsupported import type.');

        $handle = fopen($file->getRealPath(), 'r');
        abort_unless($handle, 422, 'Could not read upload.');

        $header = fgetcsv($handle) ?: [];
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
        $preview = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $assoc = [];
            foreach ($header as $i => $key) {
                $assoc[$key] = $row[$i] ?? null;
            }
            $preview[] = $assoc;
            if (count($preview) >= 500) {
                break;
            }
        }
        fclose($handle);

        return LocalizationImportBatch::query()->create([
            'type' => $type,
            'status' => 'pending',
            'filename' => $file->getClientOriginalName(),
            'preview' => $preview,
            'row_count' => count($preview),
        ]);
    }

    public function apply(LocalizationImportBatch $batch): LocalizationImportBatch
    {
        abort_unless($batch->status === 'pending', 422, 'Batch already processed.');

        $created = 0;
        $updated = 0;
        $errors = [];

        DB::transaction(function () use ($batch, &$created, &$updated, &$errors) {
            foreach ($batch->preview ?? [] as $i => $row) {
                try {
                    if ($batch->type === 'countries') {
                        [$c, $u] = $this->upsertCountry($row);
                    } else {
                        [$c, $u] = $this->upsertCurrency($row);
                    }
                    $created += $c;
                    $updated += $u;
                } catch (\Throwable $e) {
                    $errors[] = 'Row '.($i + 1).': '.$e->getMessage();
                }
            }
        });

        $batch->update([
            'status' => empty($errors) ? 'applied' : 'applied_with_errors',
            'result' => compact('created', 'updated', 'errors'),
        ]);

        Money::forgetCache();

        return $batch->fresh();
    }

    protected function upsertCountry(array $row): array
    {
        $code = strtoupper(trim((string) ($row['code'] ?? '')));
        abort_if($code === '', 422, 'Missing country code');

        $existing = Country::query()->where('code', $code)->first();
        $payload = [
            'iso3' => isset($row['iso3']) ? strtoupper((string) $row['iso3']) : null,
            'name' => $row['name'] ?? $code,
            'calling_code' => $row['calling_code'] ?? null,
            'default_currency_code' => $row['default_currency_code'] ?? null,
            'timezone' => $row['timezone'] ?? null,
            'address_format' => $row['address_format'] ?? null,
            'postal_code_label' => $row['postal_code_label'] ?? null,
            'tax_id_label' => $row['tax_id_label'] ?? null,
            'has_regions' => filter_var($row['has_regions'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'is_enabled' => filter_var($row['is_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ];

        if ($existing) {
            $existing->update($payload);

            return [0, 1];
        }

        Country::query()->create($payload + ['code' => $code]);

        return [1, 0];
    }

    protected function upsertCurrency(array $row): array
    {
        $code = strtoupper(trim((string) ($row['code'] ?? '')));
        abort_if($code === '', 422, 'Missing currency code');

        $existing = Currency::query()->where('code', $code)->first();
        $payload = [
            'name' => $row['name'] ?? $code,
            'symbol' => $row['symbol'] ?? $code,
            'decimal_places' => (int) ($row['decimal_places'] ?? 2),
            'symbol_position' => in_array($row['symbol_position'] ?? 'before', ['before', 'after'], true)
                ? $row['symbol_position'] : 'before',
            'exchange_rate' => (float) ($row['exchange_rate'] ?? 1),
            'is_enabled' => filter_var($row['is_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'is_default' => filter_var($row['is_default'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];

        if ($payload['is_default']) {
            $payload['exchange_rate'] = 1;
            Currency::query()->update(['is_default' => false]);
        }

        if ($existing) {
            $existing->update($payload);

            return [0, 1];
        }

        Currency::query()->create($payload + ['code' => $code]);

        return [1, 0];
    }
}
