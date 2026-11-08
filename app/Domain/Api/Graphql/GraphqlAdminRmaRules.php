<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Database\DbGrammar;
use App\Domain\Order\Models\ReturnRule;
use App\Models\Admin;
use GraphQL\Error\UserError;

/**
 */
final class GraphqlAdminRmaRules
{
    public static function assertManage(): Admin
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! $admin->hasPermission('orders.manage')) {
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
        $q = ReturnRule::query();
        $term = trim((string) ($filters['q'] ?? $filters['name'] ?? ''));
        if ($term !== '') {
            $op = DbGrammar::likeOperator();
            $q->where(function ($inner) use ($term, $op) {
                $inner->where('name', $op, '%'.$term.'%')
                    ->orWhere('description', $op, '%'.$term.'%');
            });
        }
        if (array_key_exists('status', $filters) && $filters['status'] !== null && $filters['status'] !== '') {
            $q->where('is_enabled', ((int) $filters['status']) === 1);
        }
        $sort = strtolower(trim((string) ($filters['sort'] ?? 'id')));
        $order = strtolower(trim((string) ($filters['order'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        $column = in_array($sort, ['id', 'name', 'return_period', 'created_at'], true) ? $sort : 'id';
        $q->orderByDesc('is_default')->orderBy($column, $order);
        $paginator = $q->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(fn (ReturnRule $row) => self::map($row))->values()->all(),
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
    public static function find(int $id): ?array
    {
        self::assertManage();
        $row = ReturnRule::query()->find($id);

        return $row ? self::map($row) : null;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function create(array $args): array
    {
        self::assertManage();
        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '') {
            throw new UserError('name is required.');
        }
        $period = max(1, (int) ($args['returnPeriod'] ?? $args['return_period'] ?? 30));
        $enabled = true;
        if (array_key_exists('status', $args) && $args['status'] !== null && $args['status'] !== '') {
            $enabled = ((int) $args['status']) === 1;
        }
        $isDefault = array_key_exists('default', $args) && $args['default'] !== null
            ? ((int) $args['default']) === 1
            : false;
        if ($isDefault) {
            ReturnRule::query()->where('is_default', true)->update(['is_default' => false]);
        }
        $row = ReturnRule::query()->create([
            'name' => $name,
            'description' => array_key_exists('description', $args) ? trim((string) $args['description']) : null,
            'return_period' => $period,
            'is_enabled' => $enabled,
            'is_default' => $isDefault,
        ]);

        return self::map($row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function update(int $id, array $args): array
    {
        self::assertManage();
        $row = ReturnRule::query()->find($id);
        if (! $row) {
            throw new UserError('Return rule not found.');
        }
        if (array_key_exists('name', $args) && $args['name'] !== null) {
            $name = trim((string) $args['name']);
            if ($name === '') {
                throw new UserError('name cannot be empty.');
            }
            $row->name = $name;
        }
        if (array_key_exists('description', $args)) {
            $row->description = $args['description'] !== null ? trim((string) $args['description']) : null;
        }
        if (array_key_exists('returnPeriod', $args) && $args['returnPeriod'] !== null) {
            $row->return_period = max(1, (int) $args['returnPeriod']);
        } elseif (array_key_exists('return_period', $args) && $args['return_period'] !== null) {
            $row->return_period = max(1, (int) $args['return_period']);
        }
        if (array_key_exists('status', $args) && $args['status'] !== null && $args['status'] !== '') {
            $row->is_enabled = ((int) $args['status']) === 1;
        }
        if (array_key_exists('default', $args) && $args['default'] !== null && $args['default'] !== '') {
            $isDefault = ((int) $args['default']) === 1;
            if ($isDefault) {
                ReturnRule::query()->where('id', '!=', $row->id)->where('is_default', true)->update(['is_default' => false]);
            }
            $row->is_default = $isDefault;
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
        $row = ReturnRule::query()->find($id);
        if (! $row) {
            throw new UserError('Return rule not found.');
        }
        if ($row->is_default && ReturnRule::query()->where('is_default', true)->count() <= 1) {
            throw new UserError('The store must keep at least one default return rule.');
        }
        $mapped = self::map($row, 'Return rule deleted.');
        $row->delete();

        return $mapped;
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
            throw new UserError('Provide at least one rule id.');
        }
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = ReturnRule::query()->find($id);
            if (! $row || ($row->is_default && ReturnRule::query()->where('is_default', true)->count() <= 1)) {
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
            'message' => count($deleted).' return rule(s) deleted.',
        ];
    }

    /**
     * @param  list<int|string>  $ids
     * @return array{ok: bool, updated_count: int}
     */
    public static function bulkUpdateStatus(array $ids, int $status): array
    {
        self::assertManage();
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($ids === []) {
            throw new UserError('Provide at least one rule id.');
        }
        $updated = ReturnRule::query()->whereIn('id', $ids)->update(['is_enabled' => $status === 1]);

        return ['ok' => true, 'updated_count' => (int) $updated];
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(ReturnRule $row, ?string $message = null): array
    {
        return [
            'id' => (int) $row->id,

            'name' => (string) $row->name,
            'description' => $row->description,
            'returnPeriod' => (int) $row->return_period,
            'return_period' => (int) $row->return_period,
            'status' => $row->is_enabled ? 1 : 0,
            'is_enabled' => (bool) $row->is_enabled,
            'default' => $row->is_default ? 1 : 0,
            'is_default' => (bool) $row->is_default,
            'message' => $message,
        ];
    }
}
