<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Database\DbGrammar;
use App\Domain\Localization\Models\Currency;
use App\Models\Admin;
use GraphQL\Error\UserError;

/**
 */
final class GraphqlAdminSettingsCurrencies
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
        $op = DbGrammar::likeOperator();
        $code = trim((string) ($filters['code'] ?? ''));
        if ($code !== '') {
            $q->where('code', $op, '%'.strtoupper($code).'%');
        }
        $name = trim((string) ($filters['name'] ?? ''));
        if ($name !== '') {
            $q->where('name', $op, '%'.$name.'%');
        }
        $sort = strtolower(trim((string) ($filters['sort'] ?? 'id')));
        $order = strtolower(trim((string) ($filters['order'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        $column = in_array($sort, ['id', 'code', 'name', 'position'], true) ? $sort : 'id';
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
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));
        if ($code === '' || $name === '') {
            throw new UserError('code and name are required.');
        }
        if (! preg_match('/^[A-Z]{3}$/', $code)) {
            throw new UserError('code must be a 3-letter ISO currency code.');
        }
        if (Currency::query()->where('code', $code)->exists()) {
            throw new UserError('A currency with this code already exists.');
        }
        $enabled = true;
        if (array_key_exists('status', $input) && $input['status'] !== null) {
            $enabled = ((int) $input['status']) === 1;
        }
        $position = strtolower(trim((string) ($input['currency_position'] ?? $input['currencyPosition'] ?? 'left')));
        $symbolPosition = in_array($position, ['right', 'after'], true) ? 'after' : 'before';
        $row = Currency::query()->create([
            'code' => $code,
            'name' => $name,
            'symbol' => trim((string) ($input['symbol'] ?? $code)) ?: $code,
            'decimal_places' => max(0, min(6, (int) ($input['decimal'] ?? $input['decimal_places'] ?? 2))),
            'symbol_position' => $symbolPosition,
            'exchange_rate' => isset($input['exchange_rate']) ? (float) $input['exchange_rate'] : 1.0,
            'is_enabled' => $enabled,
            'is_default' => false,
            'position' => (int) (Currency::query()->max('position') ?? 0) + 1,
        ]);

        return self::map($row);
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
            throw new UserError('Currency not found.');
        }
        if (array_key_exists('name', $input) && $input['name'] !== null) {
            $name = trim((string) $input['name']);
            if ($name === '') {
                throw new UserError('name cannot be empty.');
            }
            $row->name = $name;
        }
        if (array_key_exists('symbol', $input) && $input['symbol'] !== null) {
            $row->symbol = trim((string) $input['symbol']);
        }
        if (array_key_exists('decimal', $input) || array_key_exists('decimal_places', $input)) {
            $row->decimal_places = max(0, min(6, (int) ($input['decimal'] ?? $input['decimal_places'])));
        }
        if (array_key_exists('currency_position', $input) || array_key_exists('currencyPosition', $input)) {
            $position = strtolower(trim((string) ($input['currency_position'] ?? $input['currencyPosition'])));
            $row->symbol_position = in_array($position, ['right', 'after'], true) ? 'after' : 'before';
        }
        if (array_key_exists('status', $input) && $input['status'] !== null) {
            $row->is_enabled = ((int) $input['status']) === 1;
        }
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
            throw new UserError('Currency not found.');
        }
        if ($row->is_default) {
            throw new UserError('The default currency cannot be deleted.');
        }
        if (Currency::query()->count() <= 1) {
            throw new UserError('The store must keep at least one currency.');
        }
        $snapshot = self::map($row);
        $row->delete();

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
            throw new UserError('Provide at least one currency id.');
        }
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = Currency::query()->find($id);
            if (! $row || $row->is_default || Currency::query()->count() <= 1) {
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(Currency $row): array
    {
        $position = (string) ($row->symbol_position ?: 'before');

        return [
            'id' => (int) $row->id,

            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'symbol' => (string) $row->symbol,
            'decimal' => (int) ($row->decimal_places ?? 2),
            'decimal_places' => (int) ($row->decimal_places ?? 2),
            'currencyPosition' => $position === 'after' ? 'right' : 'left',
            'currency_position' => $position === 'after' ? 'right' : 'left',
            'symbol_position' => $position,
            'is_default' => (bool) $row->is_default,
            'is_enabled' => (bool) $row->is_enabled,
            'status' => $row->is_enabled ? 1 : 0,
            'created_at' => optional($row->created_at)?->toIso8601String(),
            'updated_at' => optional($row->updated_at)?->toIso8601String(),
        ];
    }
}
