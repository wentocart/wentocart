<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Database\DbGrammar;
use App\Domain\Store\StoreBootstrap;
use App\Domain\Store\StoreService;
use App\Domain\Store\Models\Store;
use App\Domain\Theme\ThemeRegistry;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Support\Str;

/**
 */
final class GraphqlAdminSettingsStores
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
        StoreBootstrap::ensure();
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? $filters['limit'] ?? 10)));
        $q = Store::query()->with('urls');
        $op = DbGrammar::likeOperator();

        $code = trim((string) ($filters['code'] ?? ''));
        if ($code !== '') {
            $q->where('code', $op, '%'.$code.'%');
        }
        $name = trim((string) ($filters['name'] ?? ''));
        if ($name !== '') {
            $q->where('name', $op, '%'.$name.'%');
        }
        $hostname = trim((string) ($filters['hostname'] ?? ''));
        if ($hostname !== '') {
            $q->whereHas('urls', function ($uq) use ($hostname, $op) {
                $uq->where('domain', $op, '%'.$hostname.'%');
            });
        }

        $sort = strtolower(trim((string) ($filters['sort'] ?? 'id')));
        $order = strtolower(trim((string) ($filters['order'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        $column = in_array($sort, ['id', 'code', 'name', 'created_at'], true) ? $sort : 'id';
        $q->orderBy($column, $order);

        $paginator = $q->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(fn (Store $row) => self::map($row, false))->values()->all(),
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
        StoreBootstrap::ensure();
        $row = Store::query()->with('urls')->find($id);

        return $row ? self::map($row, true) : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function create(array $input): array
    {
        self::assertManage();
        StoreBootstrap::ensure();
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new UserError('name is required.');
        }
        $locales = collect($input['locales'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        $currencies = collect($input['currencies'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        $sources = collect($input['warehouse_ids'] ?? $input['warehouses'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        $hostname = trim((string) ($input['hostname'] ?? ''));
        $shopUrl = trim((string) ($input['shop_url'] ?? $input['shopUrl'] ?? ''));
        if ($shopUrl === '' && $hostname !== '') {
            $shopUrl = 'https://'.preg_replace('#^https?://#i', '', $hostname);
        }
        if ($shopUrl === '') {
            $shopUrl = rtrim((string) config('app.url'), '/').'/'.Str::slug($name);
        }
        $payload = [
            'name' => $name,
            'email' => isset($input['email']) ? trim((string) $input['email']) : null,
            'theme' => isset($input['theme']) ? trim((string) $input['theme']) : 'default',
            'shop_url' => $shopUrl,
            'import_data' => false,
            'preset' => 'empty',
        ];
        try {
            $store = app(StoreService::class)->create($payload);
        } catch (\Throwable $e) {
            throw new UserError($e->getMessage());
        }
        if (! empty($input['code']) && ! $store->is_default) {
            $store->code = StoreBootstrap::uniqueCode(trim((string) $input['code']), $store->id);
            $store->save();
        }
        $enabled = true;
        if (array_key_exists('status', $input) && $input['status'] !== null) {
            $enabled = ((int) $input['status']) === 1;
        }
        if (! $enabled) {
            $store->is_enabled = false;
            $store->save();
        }

        $mapped = self::map($store->fresh(['urls']) ?? $store->load('urls'), true);
        $mapped['locales'] = $locales;
        $mapped['currencies'] = $currencies;
        $mapped['warehouse_ids'] = $sources;
        $mapped['warehouses'] = $sources;

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function update(int $id, array $input): array
    {
        self::assertManage();
        $store = Store::query()->with('urls')->find($id);
        if (! $store) {
            throw new UserError('Store not found.');
        }
        $data = [];
        if (array_key_exists('name', $input) && $input['name'] !== null) {
            $data['name'] = trim((string) $input['name']);
        }
        if (array_key_exists('email', $input)) {
            $data['email'] = $input['email'] !== null ? trim((string) $input['email']) : null;
        }
        if (array_key_exists('theme', $input) && $input['theme'] !== null) {
            $data['theme'] = trim((string) $input['theme']);
        }
        if (array_key_exists('code', $input) && $input['code'] !== null) {
            $data['code'] = trim((string) $input['code']);
        }
        if (array_key_exists('status', $input) && $input['status'] !== null) {
            $data['is_enabled'] = ((int) $input['status']) === 1;
        }
        $hostname = trim((string) ($input['hostname'] ?? ''));
        $shopUrl = trim((string) ($input['shop_url'] ?? $input['shopUrl'] ?? ''));
        if ($shopUrl === '' && $hostname !== '') {
            $shopUrl = 'https://'.preg_replace('#^https?://#i', '', $hostname);
        }
        if ($shopUrl !== '') {
            $data['shop_url'] = $shopUrl;
        }
        try {
            $store = app(StoreService::class)->update($store, $data);
        } catch (\Throwable $e) {
            throw new UserError($e->getMessage());
        }

        return self::map($store->fresh(['urls']) ?? $store->load('urls'), true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function delete(int $id): array
    {
        self::assertManage();
        $store = Store::query()->with('urls')->find($id);
        if (! $store) {
            throw new UserError('Store not found.');
        }
        if (Store::query()->count() <= 1) {
            throw new UserError('The store must keep at least one store.');
        }
        if ($store->is_default) {
            throw new UserError('The default store cannot be deleted.');
        }
        $snapshot = self::map($store, true);
        try {
            app(StoreService::class)->delete($store);
        } catch (\Throwable $e) {
            throw new UserError($e->getMessage());
        }

        return $snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(Store $store, bool $detail = false): array
    {
        $hostname = null;
        $primary = $store->relationLoaded('urls')
            ? ($store->urls->firstWhere('is_primary', true) ?? $store->urls->first())
            : $store->primaryUrl();
        if ($primary) {
            $hostname = trim((string) ($primary->domain ?? ''));
        }

        $payload = [
            'id' => (int) $store->id,

            'code' => (string) $store->code,
            'name' => $store->name,
            'hostname' => $hostname !== '' ? $hostname : null,
            'theme' => app(ThemeRegistry::class)->canonical($store->theme),
            'email' => $store->email,
            'is_default' => (bool) $store->is_default,
            'is_enabled' => (bool) $store->is_enabled,
            'status' => $store->is_enabled ? 1 : 0,
            'created_at' => optional($store->created_at)?->toIso8601String(),
            'updated_at' => optional($store->updated_at)?->toIso8601String(),
        ];
        if ($detail) {
            $payload['locales'] = [];
            $payload['currencies'] = [];
            $payload['warehouses'] = [];
            $payload['warehouse_ids'] = [];
            $payload['translations'] = [];
            $payload['homeSeo'] = null;
        }

        return $payload;
    }
}
