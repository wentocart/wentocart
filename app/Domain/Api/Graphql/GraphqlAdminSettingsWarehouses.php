<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Catalog\Models\Warehouse;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Database\DbGrammar;
use App\Domain\Localization\Models\Language;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Support\Facades\DB;

/**
 */
final class GraphqlAdminSettingsWarehouses
{
    public static function assertManage(): Admin
    {
        $admin = auth('admin')->user();
        if (
            ! $admin instanceof Admin
            || (! $admin->hasPermission('catalog.products') && ! $admin->hasPermission('catalog.manage') && ! $admin->hasPermission('settings.manage'))
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
        $q = Warehouse::query()->with('translations');
        $op = DbGrammar::likeOperator();

        $code = trim((string) ($filters['code'] ?? ''));
        if ($code !== '') {
            $q->where('code', $op, '%'.$code.'%');
        }
        $name = trim((string) ($filters['name'] ?? ''));
        if ($name !== '') {
            $q->where(function ($inner) use ($name, $op) {
                $inner->where('name', $op, '%'.$name.'%')
                    ->orWhereHas('translations', fn ($t) => $t->where('name', $op, '%'.$name.'%'));
            });
        }
        if (array_key_exists('status', $filters) && $filters['status'] !== null && $filters['status'] !== '') {
            $q->where('is_enabled', (int) $filters['status'] === 1);
        }
        $country = strtoupper(trim((string) ($filters['country'] ?? '')));
        if ($country !== '') {
            $q->where('country', $country);
        }

        $sort = strtolower(trim((string) ($filters['sort'] ?? 'id')));
        $order = strtolower(trim((string) ($filters['order'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        $column = match ($sort) {
            'code' => 'code',
            'name' => 'name',
            'status' => 'is_enabled',
            'priority' => 'id',
            default => 'id',
        };
        $q->orderBy($column, $order);

        $paginator = $q->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(fn (Warehouse $row) => self::map($row))->values()->all(),
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
        $row = Warehouse::query()->with('translations')->find($id);

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
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $country = strtoupper(trim((string) ($input['country'] ?? '')));
        $address = trim((string) ($input['street'] ?? $input['address'] ?? ''));
        $city = trim((string) ($input['city'] ?? ''));
        $postcode = trim((string) ($input['postcode'] ?? $input['zipcode'] ?? ''));
        if ($code === '' || $name === '' || $email === '' || strlen($country) !== 2) {
            throw new UserError('code, name, email, and 2-letter country are required.');
        }
        if ($address === '' || $city === '' || $postcode === '') {
            throw new UserError('street/address, city, and postcode are required.');
        }
        if (Warehouse::query()->where('code', $code)->exists()) {
            throw new UserError('A warehouse with this code already exists.');
        }
        $enabled = true;
        if (array_key_exists('status', $input) && $input['status'] !== null) {
            $enabled = (int) $input['status'] === 1;
        } elseif (array_key_exists('enabled', $input)) {
            $enabled = (bool) $input['enabled'];
        }
        $isDefault = (bool) ($input['is_default'] ?? $input['isDefault'] ?? false);
        $locale = Language::defaultCode();

        try {
            $row = DB::transaction(function () use ($input, $code, $name, $email, $country, $address, $city, $postcode, $enabled, $isDefault, $locale) {
                $warehouse = Warehouse::query()->create([
                    'name' => $name,
                    'code' => $code,
                    'email' => $email,
                    'phone' => isset($input['phone']) ? trim((string) $input['phone']) : null,
                    'address' => $address,
                    'city' => $city,
                    'state' => isset($input['state']) ? trim((string) $input['state']) : null,
                    'postcode' => $postcode,
                    'country' => $country,
                    'latitude' => isset($input['latitude']) ? (float) $input['latitude'] : null,
                    'longitude' => isset($input['longitude']) ? (float) $input['longitude'] : null,
                    'is_enabled' => $enabled,
                    'is_default' => $isDefault,
                ]);
                $warehouse->translations()->updateOrCreate(
                    ['language_code' => $locale],
                    ['name' => $name, 'description' => null],
                );
                app(InventoryService::class)->ensureExactlyOneDefault(
                    $warehouse->is_default ? $warehouse : null
                );

                return $warehouse->fresh(['translations']);
            });
        } catch (\Throwable $e) {
            throw new UserError($e->getMessage());
        }

        return self::map($row);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function update(int $id, array $input): array
    {
        self::assertManage();
        $row = Warehouse::query()->with('translations')->find($id);
        if (! $row) {
            throw new UserError('Warehouse not found.');
        }
        $locale = Language::defaultCode();
        try {
            $row = DB::transaction(function () use ($input, $row, $locale) {
                $updates = [];
                foreach (['email', 'phone', 'city', 'state'] as $field) {
                    if (array_key_exists($field, $input) && $input[$field] !== null) {
                        $updates[$field] = $field === 'email'
                            ? strtolower(trim((string) $input[$field]))
                            : trim((string) $input[$field]);
                    }
                }
                if (array_key_exists('street', $input) || array_key_exists('address', $input)) {
                    $updates['address'] = trim((string) ($input['street'] ?? $input['address'] ?? ''));
                }
                if (array_key_exists('postcode', $input) || array_key_exists('zipcode', $input)) {
                    $updates['postcode'] = trim((string) ($input['postcode'] ?? $input['zipcode'] ?? ''));
                }
                if (isset($input['country'])) {
                    $country = strtoupper(trim((string) $input['country']));
                    if (strlen($country) !== 2) {
                        throw new UserError('Country must be a 2-letter ISO code.');
                    }
                    $updates['country'] = $country;
                }
                if (array_key_exists('status', $input) && $input['status'] !== null) {
                    $updates['is_enabled'] = (int) $input['status'] === 1;
                } elseif (array_key_exists('enabled', $input)) {
                    $updates['is_enabled'] = (bool) $input['enabled'];
                }
                if (array_key_exists('is_default', $input) || array_key_exists('isDefault', $input)) {
                    $updates['is_default'] = (bool) ($input['is_default'] ?? $input['isDefault']);
                }
                if (isset($input['latitude'])) {
                    $updates['latitude'] = (float) $input['latitude'];
                }
                if (isset($input['longitude'])) {
                    $updates['longitude'] = (float) $input['longitude'];
                }
                if (isset($input['name'])) {
                    $name = trim((string) $input['name']);
                    if ($name === '') {
                        throw new UserError('Name cannot be empty.');
                    }
                    $updates['name'] = $name;
                    $row->translations()->updateOrCreate(
                        ['language_code' => $locale],
                        ['name' => $name, 'description' => $row->translation($locale)?->description],
                    );
                }
                if ($updates !== []) {
                    $row->update($updates);
                }
                app(InventoryService::class)->ensureExactlyOneDefault(
                    ! empty($updates['is_default']) ? $row->fresh() : null
                );

                return $row->fresh(['translations']);
            });
        } catch (UserError $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new UserError($e->getMessage());
        }

        return self::map($row);
    }

    /**
     * @return array<string, mixed>
     */
    public static function delete(int $id): array
    {
        self::assertManage();
        $row = Warehouse::query()->with('translations')->find($id);
        if (! $row) {
            throw new UserError('Warehouse not found.');
        }
        if ($row->is_default) {
            throw new UserError('The default warehouse cannot be deleted.');
        }
        if (Warehouse::query()->count() <= 1) {
            throw new UserError('Cannot delete the last warehouse.');
        }
        if ($row->stocks()->where('qty', '>', 0)->exists()) {
            throw new UserError('Warehouse still has stock and cannot be deleted.');
        }
        $snapshot = self::map($row);
        $row->stocks()->delete();
        $row->translations()->delete();
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
            throw new UserError('Provide at least one warehouse id.');
        }
        $remaining = Warehouse::query()->count();
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = Warehouse::query()->find($id);
            if (! $row) {
                $skipped[] = $id;
                continue;
            }
            if ($row->is_default || ($remaining - count($deleted) <= 1) || $row->stocks()->where('qty', '>', 0)->exists()) {
                $skipped[] = $id;
                continue;
            }
            $row->stocks()->delete();
            $row->translations()->delete();
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
    public static function map(Warehouse $row): array
    {
        $locale = app()->getLocale();

        return [
            'id' => (int) $row->id,

            'code' => (string) $row->code,
            'name' => $row->displayName($locale),
            'email' => $row->email,
            'contact_name' => $row->displayName($locale),
            'contact_email' => $row->email,
            'contact_number' => $row->phone,
            'phone' => $row->phone,
            'street' => $row->address,
            'address' => $row->address,
            'city' => $row->city,
            'state' => $row->state,
            'postcode' => $row->postcode,
            'country' => $row->country,
            'latitude' => $row->latitude !== null ? (float) $row->latitude : null,
            'longitude' => $row->longitude !== null ? (float) $row->longitude : null,
            'priority' => 0,
            'is_default' => (bool) $row->is_default,
            'is_enabled' => (bool) $row->is_enabled,
            'status' => $row->is_enabled ? 1 : 0,
            'created_at' => optional($row->created_at)?->toIso8601String(),
            'updated_at' => optional($row->updated_at)?->toIso8601String(),
        ];
    }
}
