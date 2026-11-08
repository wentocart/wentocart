<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Api\Models\StorefrontApiKey;
use App\Domain\Database\DbGrammar;
use App\Domain\Settings\ShopSetting;
use App\Models\Admin;
use GraphQL\Error\UserError;

/**
 */
final class GraphqlAdminStorefrontKeys
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
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>, requirement: array<string, mixed>}
     */
    public static function paginate(array $filters = []): array
    {
        self::assertWrite();
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? $filters['limit'] ?? 20)));
        $q = StorefrontApiKey::query()->orderByDesc('id');
        $name = trim((string) ($filters['name'] ?? $filters['q'] ?? ''));
        if ($name !== '') {
            $q->where('name', DbGrammar::likeOperator(), '%'.$name.'%');
        }
        $paginator = $q->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())
                ->map(fn (StorefrontApiKey $row) => self::map($row))
                ->values()
                ->all(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'requirement' => self::requirement(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listAll(): array
    {
        self::assertWrite();

        return StorefrontApiKey::query()
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (StorefrontApiKey $row) => self::map($row))
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        self::assertWrite();
        $row = StorefrontApiKey::query()->find($id);

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
        if ($name === '') {
            throw new UserError('Name is required.');
        }
        $rate = array_key_exists('rateLimit', $input)
            ? (int) $input['rateLimit']
            : (array_key_exists('rate_limit', $input) ? (int) $input['rate_limit'] : 100);
        $issued = StorefrontApiKey::issue($name, null, $rate);

        return self::map($issued['model'], $issued['plain']);
    }

    /**
     * @return array{ok: bool, id: string}
     */
    public static function revoke(int $id): array
    {
        self::assertWrite();
        $row = StorefrontApiKey::query()->find($id);
        if (! $row) {
            throw new UserError('Storefront API key not found.');
        }
        $sid = (string) $row->id;
        $row->delete();

        return ['ok' => true, 'id' => $sid];
    }

    /**
     * Rotate secret — CLI rotate parity via Admin REST.
     *
     * @return array<string, mixed>
     */
    public static function regenerate(int $id): array
    {
        self::assertWrite();
        $row = StorefrontApiKey::query()->find($id);
        if (! $row) {
            throw new UserError('Storefront API key not found.');
        }
        $name = (string) $row->name;
        $rate = $row->rate_limit;
        $retired = mb_substr($name.' (rotated '.now()->format('Y-m-d').')', 0, 120);
        $row->name = $retired;
        $row->is_enabled = false;
        $row->save();

        $issued = StorefrontApiKey::issue($name, $row->store_id ? (int) $row->store_id : null, $rate);

        return self::map($issued['model'], $issued['plain']);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function updateRateLimit(int $id, array $input): array
    {
        self::assertWrite();
        $row = StorefrontApiKey::query()->find($id);
        if (! $row) {
            throw new UserError('Storefront API key not found.');
        }
        $raw = $input['rateLimit'] ?? $input['rate_limit'] ?? null;
        if ($raw === null || $raw === '' || (int) $raw <= 0) {
            $row->rate_limit = null;
        } else {
            $row->rate_limit = min(5000, (int) $raw);
        }
        $row->save();

        return self::map($row);
    }

    /**
     * @return array{ok: bool, id: string, required: bool}
     */
    public static function updateRequirement(bool $required): array
    {
        self::assertWrite();
        ShopSetting::put('api.require_storefront_key', $required);

        return [
            'ok' => true,
            'id' => $required ? '1' : '0',
            'required' => $required,
        ];
    }

    /**
     * @return array{required: bool}
     */
    public static function requirement(): array
    {
        self::assertWrite();

        return [
            'required' => (bool) ShopSetting::get('api.require_storefront_key', false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(StorefrontApiKey $row, ?string $plain = null): array
    {
        return [
            'id' => (string) $row->id,
            'name' => $row->name,
            'key_prefix' => $row->key_prefix,
            'rate_limit' => $row->rate_limit,
            'rateLimit' => $row->rate_limit,
            'is_enabled' => (bool) $row->is_enabled,
            'isEnabled' => (bool) $row->is_enabled,
            'last_used_at' => optional($row->last_used_at)?->toIso8601String(),
            'created_at' => optional($row->created_at)?->toIso8601String(),
            'plain_key' => $plain,
        ];
    }
}
