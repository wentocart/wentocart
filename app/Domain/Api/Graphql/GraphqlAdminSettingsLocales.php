<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Database\DbGrammar;
use App\Domain\Localization\Models\Language;
use App\Models\Admin;
use GraphQL\Error\UserError;

/**
 */
final class GraphqlAdminSettingsLocales
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
        $q = Language::query();
        $op = DbGrammar::likeOperator();
        $code = trim((string) ($filters['code'] ?? ''));
        if ($code !== '') {
            $q->where('code', $op, '%'.$code.'%');
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
            'data' => collect($paginator->items())->map(fn (Language $row) => self::map($row))->values()->all(),
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
        $row = Language::query()->find($id);

        return $row ? self::map($row) : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function create(array $input): array
    {
        self::assertManage();
        $code = strtolower(trim((string) ($input['code'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));
        if ($code === '' || $name === '') {
            throw new UserError('code and name are required.');
        }
        if (! preg_match('/^[a-z]{2}(-[a-z0-9]+)?$/i', $code)) {
            throw new UserError('code must be a locale like en or pt-br.');
        }
        if (Language::query()->where('code', $code)->exists()) {
            throw new UserError('A locale with this code already exists.');
        }
        $enabled = true;
        if (array_key_exists('status', $input) && $input['status'] !== null) {
            $enabled = ((int) $input['status']) === 1;
        }
        $direction = strtolower(trim((string) ($input['direction'] ?? $input['dir'] ?? 'ltr')));
        if (! in_array($direction, ['ltr', 'rtl'], true)) {
            $direction = 'ltr';
        }
        $row = Language::query()->create([
            'code' => $code,
            'name' => $name,
            'is_enabled' => $enabled,
            'is_default' => false,
            'position' => (int) (Language::query()->max('position') ?? 0) + 1,
        ]);
        $mapped = self::map($row);
        $mapped['direction'] = $direction;

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function update(int $id, array $input): array
    {
        self::assertManage();
        $row = Language::query()->find($id);
        if (! $row) {
            throw new UserError('Locale not found.');
        }
        if (array_key_exists('name', $input) && $input['name'] !== null) {
            $name = trim((string) $input['name']);
            if ($name === '') {
                throw new UserError('name cannot be empty.');
            }
            $row->name = $name;
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
        $row = Language::query()->find($id);
        if (! $row) {
            throw new UserError('Locale not found.');
        }
        if ($row->is_default) {
            throw new UserError('The default locale cannot be deleted.');
        }
        if (Language::query()->count() <= 1) {
            throw new UserError('The store must keep at least one locale.');
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
            throw new UserError('Provide at least one locale id.');
        }
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = Language::query()->find($id);
            if (! $row || $row->is_default || Language::query()->count() <= 1) {
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
    public static function map(Language $row): array
    {
        return [
            'id' => (int) $row->id,

            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'direction' => 'ltr',
            'logo_path' => null,
            'is_default' => (bool) $row->is_default,
            'is_enabled' => (bool) $row->is_enabled,
            'status' => $row->is_enabled ? 1 : 0,
            'position' => (int) $row->position,
            'created_at' => optional($row->created_at)?->toIso8601String(),
            'updated_at' => optional($row->updated_at)?->toIso8601String(),
        ];
    }
}
