<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Database\DbGrammar;
use App\Domain\Order\Models\ReturnStatus;
use App\Models\Admin;
use GraphQL\Error\UserError;

/**
 */
final class GraphqlAdminRmaStatuses
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
        $q = ReturnStatus::query();
        $term = trim((string) ($filters['q'] ?? $filters['title'] ?? ''));
        if ($term !== '') {
            $op = DbGrammar::likeOperator();
            $q->where('title', $op, '%'.$term.'%');
        }
        if (array_key_exists('status', $filters) && $filters['status'] !== null && $filters['status'] !== '') {
            $q->where('is_enabled', ((int) $filters['status']) === 1);
        }
        $sort = strtolower(trim((string) ($filters['sort'] ?? 'position')));
        $order = strtolower(trim((string) ($filters['order'] ?? 'asc'))) === 'desc' ? 'desc' : 'asc';
        $column = in_array($sort, ['id', 'title', 'position', 'created_at'], true) ? $sort : 'position';
        $q->orderBy($column, $order)->orderBy('id');
        $paginator = $q->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(fn (ReturnStatus $row) => self::map($row))->values()->all(),
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
        $row = ReturnStatus::query()->find($id);

        return $row ? self::map($row) : null;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function create(array $args): array
    {
        self::assertManage();
        $title = trim((string) ($args['title'] ?? ''));
        if ($title === '') {
            throw new UserError('title is required.');
        }
        $color = trim((string) ($args['color'] ?? '#6B7280')) ?: '#6B7280';
        $enabled = true;
        if (array_key_exists('status', $args) && $args['status'] !== null && $args['status'] !== '') {
            $enabled = ((int) $args['status']) === 1;
        }
        $row = ReturnStatus::query()->create([
            'title' => $title,
            'color' => $color,
            'is_enabled' => $enabled,
            'is_default' => false,
            'position' => (int) ($args['position'] ?? ((int) (ReturnStatus::query()->max('position') ?? 0) + 1)),
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
        $row = ReturnStatus::query()->find($id);
        if (! $row) {
            throw new UserError('Return status not found.');
        }
        if (array_key_exists('title', $args) && $args['title'] !== null) {
            $title = trim((string) $args['title']);
            if ($title === '') {
                throw new UserError('title cannot be empty.');
            }
            $row->title = $title;
        }
        if (array_key_exists('color', $args) && $args['color'] !== null) {
            $row->color = trim((string) $args['color']) ?: $row->color;
        }
        if (array_key_exists('position', $args) && $args['position'] !== null) {
            $row->position = (int) $args['position'];
        }
        if (array_key_exists('status', $args) && $args['status'] !== null && $args['status'] !== '') {
            $row->is_enabled = ((int) $args['status']) === 1;
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
        $row = ReturnStatus::query()->find($id);
        if (! $row) {
            throw new UserError('Return status not found.');
        }
        if ($row->is_default) {
            throw new UserError('Built-in return statuses cannot be deleted.');
        }
        $mapped = self::map($row, 'Return status deleted.');
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
            throw new UserError('Provide at least one status id.');
        }
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = ReturnStatus::query()->find($id);
            if (! $row || $row->is_default) {
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
            'message' => count($deleted).' return status(es) deleted.',
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
            throw new UserError('Provide at least one status id.');
        }
        $enabled = $status === 1;
        $updated = ReturnStatus::query()->whereIn('id', $ids)->update(['is_enabled' => $enabled]);

        return ['ok' => true, 'updated_count' => (int) $updated];
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(ReturnStatus $row, ?string $message = null): array
    {
        return [
            'id' => (int) $row->id,

            'title' => (string) $row->title,
            'color' => (string) ($row->color ?: '#6B7280'),
            'status' => $row->is_enabled ? 1 : 0,
            'is_enabled' => (bool) $row->is_enabled,
            'default' => $row->is_default ? 1 : 0,
            'is_default' => (bool) $row->is_default,
            'position' => (int) ($row->position ?? 0),
            'message' => $message,
        ];
    }
}
