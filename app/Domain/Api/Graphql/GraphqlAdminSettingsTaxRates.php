<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Database\DbGrammar;
use App\Domain\Tax\Models\TaxRate;
use App\Models\Admin;
use GraphQL\Error\UserError;

/**
 */
final class GraphqlAdminSettingsTaxRates
{
    public static function assertManage(): Admin
    {
        $admin = auth('admin')->user();
        if (
            ! $admin instanceof Admin
            || (! $admin->hasPermission('taxes.manage') && ! $admin->hasPermission('settings.manage'))
        ) {
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
        $q = TaxRate::query();
        $term = trim((string) ($filters['identifier'] ?? $filters['name'] ?? $filters['q'] ?? ''));
        if ($term !== '') {
            $op = DbGrammar::likeOperator();
            $q->where('name', $op, '%'.$term.'%');
        }
        $sort = strtolower(trim((string) ($filters['sort'] ?? 'id')));
        $order = strtolower(trim((string) ($filters['order'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        $column = in_array($sort, ['id', 'name', 'rate', 'created_at'], true) ? $sort : 'id';
        $q->orderBy($column, $order);

        $paginator = $q->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(fn (TaxRate $row) => self::map($row))->values()->all(),
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
        $row = TaxRate::query()->find($id);

        return $row ? self::map($row) : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function create(array $input): array
    {
        self::assertManage();
        $name = trim((string) ($input['identifier'] ?? $input['name'] ?? ''));
        if ($name === '') {
            throw new UserError('identifier or name is required.');
        }
        $rate = array_key_exists('tax_rate', $input)
            ? (float) $input['tax_rate']
            : (array_key_exists('taxRate', $input) ? (float) $input['taxRate'] : (float) ($input['rate'] ?? -1));
        if ($rate < 0 || $rate > 100) {
            throw new UserError('Tax rate must be between 0 and 100.');
        }
        $enabled = true;
        if (array_key_exists('status', $input) && $input['status'] !== null) {
            $enabled = (int) $input['status'] === 1;
        } elseif (array_key_exists('enabled', $input)) {
            $enabled = (bool) $input['enabled'];
        }
        $row = TaxRate::query()->create([
            'name' => $name,
            'rate' => $rate,
            'is_enabled' => $enabled,
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
        $row = TaxRate::query()->find($id);
        if (! $row) {
            throw new UserError('Tax rate not found.');
        }
        $updates = [];
        if (array_key_exists('name', $input) || array_key_exists('identifier', $input)) {
            $name = trim((string) ($input['identifier'] ?? $input['name'] ?? ''));
            if ($name === '') {
                throw new UserError('identifier cannot be empty.');
            }
            $updates['name'] = $name;
        }
        if (array_key_exists('tax_rate', $input) || array_key_exists('taxRate', $input) || array_key_exists('rate', $input)) {
            $rate = array_key_exists('tax_rate', $input)
                ? (float) $input['tax_rate']
                : (array_key_exists('taxRate', $input) ? (float) $input['taxRate'] : (float) $input['rate']);
            if ($rate < 0 || $rate > 100) {
                throw new UserError('Tax rate must be between 0 and 100.');
            }
            $updates['rate'] = $rate;
        }
        if (array_key_exists('status', $input) && $input['status'] !== null) {
            $updates['is_enabled'] = (int) $input['status'] === 1;
        } elseif (array_key_exists('enabled', $input)) {
            $updates['is_enabled'] = (bool) $input['enabled'];
        }
        if ($updates !== []) {
            $row->update($updates);
        }

        return self::map($row->fresh() ?? $row);
    }

    /**
     * @return array<string, mixed>
     */
    public static function delete(int $id): array
    {
        self::assertManage();
        $row = TaxRate::query()->find($id);
        if (! $row) {
            throw new UserError('Tax rate not found.');
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
            throw new UserError('Provide at least one tax rate id.');
        }
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = TaxRate::query()->find($id);
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(TaxRate $row): array
    {
        $rate = (float) $row->rate;

        return [
            'id' => (int) $row->id,

            'identifier' => (string) $row->name,
            'name' => (string) $row->name,
            'rate' => $rate,
            'tax_rate' => $rate,
            'taxRate' => $rate,
            'country' => null,
            'state' => null,
            'zip_code' => null,
            'zip_from' => null,
            'zip_to' => null,
            'is_zip_range' => 0,
            'is_enabled' => (bool) $row->is_enabled,
            'status' => $row->is_enabled ? 1 : 0,
            'created_at' => optional($row->created_at)?->toIso8601String(),
            'updated_at' => optional($row->updated_at)?->toIso8601String(),
        ];
    }
}
