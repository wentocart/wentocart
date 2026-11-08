<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Database\DbGrammar;
use App\Domain\Order\Models\ReturnReason;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Support\Str;

/**
 */
final class GraphqlAdminRmaReasons
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
        $q = ReturnReason::query();
        $term = trim((string) ($filters['q'] ?? $filters['name'] ?? ''));
        if ($term !== '') {
            $op = DbGrammar::likeOperator();
            $q->where(function ($inner) use ($term, $op) {
                $inner->where('code', $op, '%'.$term.'%')
                    ->orWhere('name', $op, '%'.$term.'%');
            });
        }
        if (array_key_exists('status', $filters) && $filters['status'] !== null && $filters['status'] !== '') {
            $q->where('is_enabled', ((int) $filters['status']) === 1);
        }
        $sort = strtolower(trim((string) ($filters['sort'] ?? 'position')));
        $order = strtolower(trim((string) ($filters['order'] ?? 'asc'))) === 'desc' ? 'desc' : 'asc';
        $column = in_array($sort, ['id', 'code', 'name', 'position', 'created_at'], true) ? $sort : 'position';
        $q->orderBy($column, $order)->orderBy('id');
        $paginator = $q->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(fn (ReturnReason $row) => self::map($row))->values()->all(),
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
        $row = ReturnReason::query()->find($id);

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
        $code = trim((string) ($args['code'] ?? ''));
        if ($code === '') {
            $code = Str::slug($name);
        }
        $code = Str::limit($code !== '' ? $code : 'reason-'.uniqid(), 80, '');
        if (ReturnReason::query()->where('code', $code)->exists()) {
            $code = $code.'-'.substr(uniqid(), -4);
        }
        $enabled = true;
        if (array_key_exists('status', $args) && $args['status'] !== null && $args['status'] !== '') {
            $enabled = ((int) $args['status']) === 1;
        } elseif (array_key_exists('enabled', $args) && $args['enabled'] !== null) {
            $enabled = (bool) $args['enabled'];
        }
        $row = ReturnReason::query()->create([
            'code' => $code,
            'name' => $name,
            'is_enabled' => $enabled,
            'position' => (int) ($args['position'] ?? ((int) (ReturnReason::query()->max('position') ?? 0) + 1)),
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
        $row = ReturnReason::query()->find($id);
        if (! $row) {
            throw new UserError('Return reason not found.');
        }
        if (array_key_exists('name', $args) && $args['name'] !== null) {
            $name = trim((string) $args['name']);
            if ($name === '') {
                throw new UserError('name cannot be empty.');
            }
            $row->name = $name;
        }
        if (array_key_exists('position', $args) && $args['position'] !== null) {
            $row->position = (int) $args['position'];
        }
        if (array_key_exists('status', $args) && $args['status'] !== null && $args['status'] !== '') {
            $row->is_enabled = ((int) $args['status']) === 1;
        } elseif (array_key_exists('enabled', $args) && $args['enabled'] !== null) {
            $row->is_enabled = (bool) $args['enabled'];
        }
        $row->save();

        return self::map($row->fresh() ?? $row);
    }

    /**
     * @return array{ok: bool, id: string, _id: int, message: string}
     */
    public static function delete(int $id): array
    {
        self::assertManage();
        $row = ReturnReason::query()->find($id);
        if (! $row) {
            throw new UserError('Return reason not found.');
        }
        if (ReturnReason::query()->count() <= 1) {
            throw new UserError('The store must keep at least one return reason.');
        }
        $snapshot = [
            'ok' => true,
            'id' => (string) $row->id,

            'message' => 'Return reason deleted successfully.',
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
            throw new UserError('Provide at least one reason id.');
        }
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = ReturnReason::query()->find($id);
            if (! $row || ReturnReason::query()->count() <= 1) {
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
            'message' => count($deleted).' return reason(s) deleted.',
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
            throw new UserError('Provide at least one reason id.');
        }
        $updated = ReturnReason::query()->whereIn('id', $ids)->update(['is_enabled' => $status === 1]);

        return ['ok' => true, 'updated_count' => (int) $updated];
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(ReturnReason $row): array
    {
        return [
            'id' => (int) $row->id,

            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'title' => (string) $row->name,
            'is_enabled' => (bool) $row->is_enabled,
            'status' => $row->is_enabled ? 1 : 0,
            'position' => (int) ($row->position ?? 0),
        ];
    }
}
