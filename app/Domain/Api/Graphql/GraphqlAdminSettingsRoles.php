<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Auth\Models\Permission;
use App\Domain\Auth\Models\Role;
use App\Domain\Auth\PageCatalog;
use App\Domain\Auth\UniqueSlug;
use App\Domain\Database\DbGrammar;
use App\Models\Admin;
use GraphQL\Error\UserError;

/**
 */
final class GraphqlAdminSettingsRoles
{
    public static function assertRead(): Admin
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! $admin->hasPermission('settings.manage')) {
            // Super admins always pass settings.manage; fall back for seeded SU.
            if (! $admin instanceof Admin || ! $admin->isSuperAdmin()) {
                throw new UserError('Forbidden.');
            }
        }

        return $admin;
    }

    public static function assertWrite(): Admin
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! $admin->isSuperAdmin()) {
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
        self::assertRead();
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? $filters['limit'] ?? 10)));
        $q = Role::query()->with('permissions');
        $name = trim((string) ($filters['name'] ?? ''));
        if ($name !== '') {
            $op = DbGrammar::likeOperator();
            $q->where('name', $op, '%'.$name.'%');
        }
        $sort = strtolower(trim((string) ($filters['sort'] ?? 'id')));
        $order = strtolower(trim((string) ($filters['order'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        $column = in_array($sort, ['id', 'name', 'created_at'], true) ? $sort : 'id';
        $q->orderBy($column, $order);

        $paginator = $q->paginate($perPage, ['*'], 'page', $page);
        $permissionType = strtolower(trim((string) ($filters['permission_type'] ?? '')));
        $data = collect($paginator->items())->map(fn (Role $r) => self::map($r));
        if (in_array($permissionType, ['all', 'custom'], true)) {
            $data = $data->filter(fn (array $row) => ($row['permission_type'] ?? '') === $permissionType)->values();
        }

        return [
            'data' => $data->values()->all(),
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
        self::assertRead();
        $role = Role::query()->with('permissions')->find($id);

        return $role ? self::map($role) : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function create(array $input): array
    {
        self::assertWrite();
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new UserError('name is required.');
        }
        $permissionType = strtolower(trim((string) ($input['permission_type'] ?? $input['permissionType'] ?? 'custom')));
        if (! in_array($permissionType, ['all', 'custom'], true)) {
            $permissionType = 'custom';
        }
        $role = Role::query()->create([
            'name' => $name,
            'slug' => UniqueSlug::make($name, Role::class),
        ]);
        if ($permissionType === 'all') {
            $role->permissions()->sync(Permission::query()->pluck('id')->all());
        } else {
            $allowed = PageCatalog::slugs();
            $permissions = collect($input['permissions'] ?? [])
                ->map(fn ($slug) => trim((string) $slug))
                ->filter()
                ->unique()
                ->values()
                ->intersect($allowed)
                ->values()
                ->all();
            if ($permissions === []) {
                throw new UserError('Choose at least one valid permission slug.');
            }
            $role->permissions()->sync(Permission::query()->whereIn('slug', $permissions)->pluck('id')->all());
        }

        return self::map($role->fresh(['permissions']) ?? $role->load('permissions'));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function update(int $id, array $input): array
    {
        self::assertWrite();
        $role = Role::query()->with('permissions')->find($id);
        if (! $role) {
            throw new UserError('Role not found.');
        }
        if ($role->isSystem() && $role->isSuperAdminRole()) {
            throw new UserError('The super admin role cannot be edited via API.');
        }
        if (array_key_exists('name', $input) && $input['name'] !== null) {
            $name = trim((string) $input['name']);
            if ($name === '') {
                throw new UserError('name cannot be empty.');
            }
            $role->name = $name;
            $role->save();
        }
        $permissionType = array_key_exists('permission_type', $input) || array_key_exists('permissionType', $input)
            ? strtolower(trim((string) ($input['permission_type'] ?? $input['permissionType'])))
            : null;
        if ($permissionType === 'all') {
            $role->permissions()->sync(Permission::query()->pluck('id')->all());
        } elseif (array_key_exists('permissions', $input) && is_array($input['permissions'])) {
            $allowed = PageCatalog::slugs();
            $permissions = collect($input['permissions'])
                ->map(fn ($slug) => trim((string) $slug))
                ->filter()
                ->unique()
                ->values()
                ->intersect($allowed)
                ->values()
                ->all();
            if ($permissions === []) {
                throw new UserError('Choose at least one valid permission slug.');
            }
            $role->permissions()->sync(Permission::query()->whereIn('slug', $permissions)->pluck('id')->all());
        }

        return self::map($role->fresh(['permissions']) ?? $role->load('permissions'));
    }

    /**
     * @return array<string, mixed>
     */
    public static function delete(int $id): array
    {
        self::assertWrite();
        $role = Role::query()->with('permissions')->find($id);
        if (! $role) {
            throw new UserError('Role not found.');
        }
        if ($role->isSystem()) {
            throw new UserError('System roles cannot be deleted.');
        }
        if ($role->admins()->exists()) {
            throw new UserError('This role is assigned to admins and cannot be deleted.');
        }
        $snapshot = self::map($role);
        $role->permissions()->detach();
        $role->delete();

        return $snapshot;
    }

    /**
     * @param  list<int|string>  $ids
     * @return array{ok: bool, deleted: list<int>, skipped: list<int>, updated_count: int}
     */
    public static function bulkDelete(array $ids): array
    {
        self::assertWrite();
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($ids === []) {
            throw new UserError('Provide at least one role id.');
        }
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $role = Role::query()->find($id);
            if (! $role || $role->isSystem() || $role->admins()->exists() || Role::query()->count() <= 1) {
                $skipped[] = $id;
                continue;
            }
            $role->permissions()->detach();
            $role->delete();
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
    public static function map(Role $role): array
    {
        $role->loadMissing('permissions');
        $permissions = $role->permissions->pluck('slug')->values()->all();
        $totalPermissions = (int) Permission::query()->count();
        $permissionType = $role->isSuperAdminRole()
            || in_array('*', $permissions, true)
            || ($totalPermissions > 0 && count($permissions) >= $totalPermissions)
            ? 'all'
            : 'custom';

        return [
            'id' => (int) $role->id,

            'slug' => $role->slug,
            'name' => $role->name,
            'description' => $role->name,
            'permission_type' => $permissionType,
            'permissionType' => $permissionType,
            'permissions' => $permissionType === 'all' ? null : $permissions,
            'created_at' => optional($role->created_at)?->toIso8601String(),
            'updated_at' => optional($role->updated_at)?->toIso8601String(),
        ];
    }
}
