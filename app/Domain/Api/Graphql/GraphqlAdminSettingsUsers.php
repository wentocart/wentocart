<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Auth\Models\Role;
use App\Domain\Database\DbGrammar;
use App\Models\Admin;
use GraphQL\Error\UserError;

/**
 */
final class GraphqlAdminSettingsUsers
{
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
        self::assertWrite();
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? $filters['limit'] ?? 10)));
        $q = Admin::query()->with('roles');
        $op = DbGrammar::likeOperator();
        $name = trim((string) ($filters['name'] ?? ''));
        if ($name !== '') {
            $q->where('name', $op, '%'.$name.'%');
        }
        $email = trim((string) ($filters['email'] ?? ''));
        if ($email !== '') {
            $q->where('email', $op, '%'.$email.'%');
        }
        $roleId = (int) ($filters['role_id'] ?? $filters['roleId'] ?? 0);
        if ($roleId > 0) {
            $q->whereHas('roles', fn ($rq) => $rq->where('roles.id', $roleId));
        }
        if (array_key_exists('status', $filters) && $filters['status'] !== null && $filters['status'] !== '') {
            $q->where('is_enabled', ((int) $filters['status']) === 1);
        }
        $sort = strtolower(trim((string) ($filters['sort'] ?? 'id')));
        $order = strtolower(trim((string) ($filters['order'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        $column = in_array($sort, ['id', 'name', 'email', 'created_at'], true) ? $sort : 'id';
        $q->orderBy($column, $order);

        $paginator = $q->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(fn (Admin $a) => self::map($a))->values()->all(),
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
        self::assertWrite();
        $row = Admin::query()->with('roles')->find($id);

        return $row ? self::map($row) : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function create(array $input): array
    {
        self::assertWrite();
        $name = trim((string) ($input['name'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        if ($name === '' || $email === '' || strlen($password) < 8) {
            throw new UserError('Name, email, and password (min 8 chars) are required.');
        }
        if (Admin::query()->where('email', $email)->exists()) {
            throw new UserError('An admin with this email already exists.');
        }
        $roleId = (int) ($input['roleId'] ?? $input['role_id'] ?? 0);
        $super = ! empty($input['super']);
        if (! $super && $roleId <= 0) {
            throw new UserError('roleId is required.');
        }
        $enabled = true;
        if (array_key_exists('status', $input) && $input['status'] !== null && $input['status'] !== '') {
            $enabled = ((int) $input['status']) === 1;
        } elseif (array_key_exists('enabled', $input) && $input['enabled'] !== null) {
            $enabled = (bool) $input['enabled'];
        }
        $row = Admin::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'is_enabled' => $enabled,
        ]);
        if ($super) {
            $row->grantSuperAdmin();
        } else {
            $role = Role::query()->find($roleId);
            if (! $role) {
                $row->delete();
                throw new UserError('Role not found.');
            }
            $row->roles()->sync([(int) $role->id]);
        }

        return self::map($row->fresh(['roles']) ?? $row->load('roles'));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function update(int $id, array $input): array
    {
        self::assertWrite();
        $row = Admin::query()->with('roles')->find($id);
        if (! $row) {
            throw new UserError('Admin user not found.');
        }
        if (array_key_exists('name', $input) && $input['name'] !== null) {
            $name = trim((string) $input['name']);
            if ($name === '') {
                throw new UserError('Name cannot be empty.');
            }
            $row->name = $name;
        }
        if (array_key_exists('email', $input) && $input['email'] !== null) {
            $email = strtolower(trim((string) $input['email']));
            if ($email === '') {
                throw new UserError('Email cannot be empty.');
            }
            if (Admin::query()->where('email', $email)->where('id', '!=', $row->id)->exists()) {
                throw new UserError('An admin with this email already exists.');
            }
            $row->email = $email;
        }
        if (array_key_exists('password', $input) && $input['password'] !== null && $input['password'] !== '') {
            $password = (string) $input['password'];
            if (strlen($password) < 8) {
                throw new UserError('Password must be at least 8 characters.');
            }
            $row->password = $password;
        }
        if (array_key_exists('status', $input) && $input['status'] !== null && $input['status'] !== '') {
            $row->is_enabled = ((int) $input['status']) === 1;
        } elseif (array_key_exists('enabled', $input) && $input['enabled'] !== null) {
            $row->is_enabled = (bool) $input['enabled'];
        }
        $row->save();
        $roleKey = array_key_exists('roleId', $input) ? 'roleId' : (array_key_exists('role_id', $input) ? 'role_id' : null);
        if ($roleKey !== null && $input[$roleKey] !== null && $input[$roleKey] !== '') {
            $role = Role::query()->find((int) $input[$roleKey]);
            if (! $role) {
                throw new UserError('Role not found.');
            }
            if ($row->isLastSuperAdmin() && ! $role->isSuperAdminRole()) {
                throw new UserError('Cannot remove the last super admin role.');
            }
            $row->roles()->sync([(int) $role->id]);
        }

        return self::map($row->fresh(['roles']) ?? $row->load('roles'));
    }

    /**
     * @return array<string, mixed>
     */
    public static function delete(int $id): array
    {
        self::assertWrite();
        $row = Admin::query()->with('roles')->find($id);
        if (! $row) {
            throw new UserError('Admin user not found.');
        }
        if ($row->isLastSuperAdmin()) {
            throw new UserError('Cannot delete the last super admin.');
        }
        $actor = auth('admin')->user();
        if ($actor instanceof Admin && (int) $actor->id === (int) $row->id) {
            throw new UserError('You cannot delete your own admin account.');
        }
        $snapshot = self::map($row);
        $snapshot['message'] = 'Admin user deleted successfully.';
        $row->roles()->detach();
        $row->tokens()->delete();
        $row->delete();

        return $snapshot;
    }

    /**
     * @param  list<int|string>  $ids
     * @return array{ok: bool, deleted: list<int>, skipped: list<int>, updated_count: int, message: string}
     */
    public static function bulkDelete(array $ids): array
    {
        self::assertWrite();
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($ids === []) {
            throw new UserError('Provide at least one user id.');
        }
        $actor = auth('admin')->user();
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = Admin::query()->with('roles')->find($id);
            if (
                ! $row
                || $row->isLastSuperAdmin()
                || ($actor instanceof Admin && (int) $actor->id === (int) $row->id)
            ) {
                $skipped[] = $id;
                continue;
            }
            $row->roles()->detach();
            $row->tokens()->delete();
            $row->delete();
            $deleted[] = $id;
        }

        return [
            'ok' => true,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'updated_count' => count($deleted),
            'message' => count($deleted).' user(s) deleted.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(Admin $admin): array
    {
        $admin->loadMissing('roles');
        $role = $admin->roles->first();

        return [
            'id' => (int) $admin->id,

            'name' => $admin->name,
            'email' => $admin->email,
            'is_enabled' => (bool) $admin->is_enabled,
            'is_super' => $admin->isSuperAdmin(),
            'status' => $admin->is_enabled ? 1 : 0,
            'roleId' => $role ? (int) $role->id : null,
            'role_id' => $role ? (int) $role->id : null,
            'roleName' => $role?->name,
            'role_name' => $role?->name,
            'created_at' => optional($admin->created_at)?->toIso8601String(),
            'updated_at' => optional($admin->updated_at)?->toIso8601String(),
        ];
    }
}
