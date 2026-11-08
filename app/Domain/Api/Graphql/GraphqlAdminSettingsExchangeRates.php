<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Localization\Models\Currency;
use App\Models\Admin;
use GraphQL\Error\UserError;

/**
 * Rates live on Currency.exchange_rate (identity 1.0 = “no custom rate”).
 */
final class GraphqlAdminSettingsExchangeRates
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
        $q = Currency::query();

        if (! empty($filters['target_currency'])) {
            $q->where('id', (int) $filters['target_currency']);
        }
        if (isset($filters['rate_from']) && $filters['rate_from'] !== null && $filters['rate_from'] !== '') {
            $q->where('exchange_rate', '>=', (float) $filters['rate_from']);
        }
        if (isset($filters['rate_to']) && $filters['rate_to'] !== null && $filters['rate_to'] !== '') {
            $q->where('exchange_rate', '<=', (float) $filters['rate_to']);
        }

        $sort = strtolower(trim((string) ($filters['sort'] ?? 'id')));
        $order = strtolower(trim((string) ($filters['order'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        $column = match ($sort) {
            'rate', 'exchange_rate' => 'exchange_rate',
            'target_currency' => 'id',
            'code' => 'code',
            default => 'id',
        };
        $q->orderBy($column, $order);

        $paginator = $q->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(fn (Currency $row) => self::map($row))->values()->all(),
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
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        self::assertManage();
        $row = Currency::query()->find($id);

        return $row ? self::map($row) : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function create(array $input): array
    {
        self::assertManage();
        $targetId = (int) ($input['target_currency'] ?? $input['targetCurrency'] ?? 0);
        $row = Currency::query()->find($targetId);
        if (! $row) {
            throw new UserError('The selected target currency is invalid.');
        }
        if ($row->exchange_rate !== null && abs((float) $row->exchange_rate - 1.0) > 0.0000001) {
            throw new UserError('The selected target currency is invalid.');
        }
        $rate = (float) ($input['rate'] ?? $input['exchange_rate'] ?? 0);
        if ($rate <= 0) {
            throw new UserError('Rate must be a positive number.');
        }
        $row->exchange_rate = $rate;
        $row->save();

        return self::map($row->fresh() ?? $row);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function update(int $id, array $input): array
    {
        self::assertManage();
        $row = Currency::query()->find($id);
        if (! $row) {
            throw new UserError('Exchange rate not found.');
        }
        $rate = (float) ($input['rate'] ?? $input['exchange_rate'] ?? 0);
        if ($rate <= 0) {
            throw new UserError('Rate must be a positive number.');
        }
        $row->exchange_rate = $rate;
        $row->save();

        return self::map($row->fresh() ?? $row);
    }

    /**
     * @return array<string, mixed>
     */
    public static function delete(int $id): array
    {
        self::assertManage();
        $row = Currency::query()->find($id);
        if (! $row) {
            throw new UserError('Exchange rate not found.');
        }
        if ($row->is_default) {
            throw new UserError('The default currency exchange rate cannot be deleted.');
        }
        $snapshot = self::map($row);
        $row->exchange_rate = 1.0;
        $row->save();

        return $snapshot;
    }

    /**
     * @param  list<int|string>  $ids
     * @return array{ok: bool, deleted: list<int>, skipped: list<int>, updated_count: int}
     */
    public static function bulkDelete(array $ids): array
    {
        self::assertManage();
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($ids === []) {
            throw new UserError('Provide at least one exchange rate id.');
        }
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = Currency::query()->find($id);
            if (! $row || $row->is_default) {
                $skipped[] = $id;
                continue;
            }
            $current = $row->exchange_rate !== null ? (float) $row->exchange_rate : null;
            if ($current !== null && abs($current - 1.0) < 0.0000001) {
                $skipped[] = $id;
                continue;
            }
            $row->exchange_rate = 1.0;
            $row->save();
            $deleted[] = $id;
        }

        return [
            'ok' => true,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'updated_count' => count($deleted),
        ];
    }

    /**
     * @return array{ok: bool, success: bool, updated: int, message: string}
     */
    public static function updateRates(): array
    {
        self::assertManage();
        $usdTable = [
            'USD' => 1.0,
            'EUR' => 0.92,
            'GBP' => 0.79,
            'INR' => 83.0,
            'JPY' => 150.0,
            'CAD' => 1.36,
            'AUD' => 1.52,
            'CHF' => 0.88,
        ];
        $default = Currency::query()->where('is_default', true)->first()
            ?? Currency::query()->orderBy('id')->first();
        $baseCode = strtoupper((string) ($default?->code ?: 'USD'));
        $baseUsd = $usdTable[$baseCode] ?? 1.0;
        $updated = 0;
        foreach (Currency::query()->orderBy('id')->cursor() as $row) {
            $code = strtoupper((string) $row->code);
            if ($default && (int) $row->id === (int) $default->id) {
                $row->exchange_rate = 1.0;
            } elseif (isset($usdTable[$code])) {
                $row->exchange_rate = round($usdTable[$code] / $baseUsd, 6);
            } else {
                continue;
            }
            $row->save();
            $updated++;
        }

        return [
            'ok' => true,
            'success' => true,
            'updated' => $updated,
            'message' => 'Exchange rates updated from the built-in rate table ('.$updated.' currencies).',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(Currency $row): array
    {
        $rate = $row->exchange_rate !== null ? (float) $row->exchange_rate : null;

        return [
            'id' => (int) $row->id,

            'target_currency' => (int) $row->id,
            'targetCurrency' => (int) $row->id,
            'targetCurrencyCode' => (string) $row->code,
            'target_currency_code' => (string) $row->code,
            'targetCurrencyName' => $row->name,
            'target_currency_name' => $row->name,
            'rate' => $rate,
            'exchange_rate' => $rate,
        ];
    }
}
