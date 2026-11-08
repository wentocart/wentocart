<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Store\Models\Store;
use App\Domain\Theme\ThemeRegistry;
use App\Models\Admin;
use GraphQL\Error\UserError;

/**
 */
final class GraphqlAdminAppearanceThemes
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
     * @return list<array<string, mixed>>
     */
    public static function list(): array
    {
        self::assertManage();
        \App\Domain\Store\StoreBootstrap::ensure();
        $registry = app(ThemeRegistry::class);
        $stores = Store::query()->orderBy('id')->get();
        $byTheme = [];
        foreach ($stores as $store) {
            $code = $registry->canonical($store->theme);
            $byTheme[$code][] = [
                'id' => (string) $store->id,
                'code' => (string) $store->code,
                'name' => $store->name,
            ];
        }
        $rows = [];
        foreach ($registry->all() as $theme) {
            $channels = $byTheme[$theme->name] ?? [];
            $active = $channels !== [];
            $rows[] = [
                'code' => $theme->name,
                'name' => $theme->name,
                'displayName' => $theme->displayName,
                'display_name' => $theme->displayName,
                'version' => $theme->version,
                'author' => $theme->author,
                'bundled' => (bool) $theme->bundled,
                'active' => $active,
                'status' => $active ? 'active' : 'installed',
                'stores' => $channels,
            ];
        }
        usort($rows, function (array $a, array $b) {
            if ($a['active'] !== $b['active']) {
                return $a['active'] ? -1 : 1;
            }

            return strcasecmp((string) $a['displayName'], (string) $b['displayName']);
        });

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $code): ?array
    {
        self::assertManage();
        $canonical = app(ThemeRegistry::class)->canonical($code);
        foreach (self::list() as $theme) {
            if ($theme['code'] === $canonical) {
                return $theme;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function impact(array $args): array
    {
        self::assertManage();
        $registry = app(ThemeRegistry::class);
        $code = $registry->canonical((string) ($args['code'] ?? ''));
        if (! $registry->exists($code)) {
            throw new UserError('Theme is not installed.');
        }
        $storeIds = array_values(array_filter(array_map(
            static fn ($id) => (int) $id,
            is_array($args['storeIds'] ?? null)
                ? $args['storeIds']
                : (is_array($args['store_ids'] ?? null) ? $args['store_ids'] : [])
        )));
        $q = Store::query()->orderBy('id');
        if ($storeIds !== []) {
            $q->whereIn('id', $storeIds);
        }
        $would = [];
        $already = [];
        foreach ($q->get() as $store) {
            $channel = [
                'id' => (string) $store->id,
                'code' => (string) $store->code,
                'name' => $store->name,
            ];
            if ($registry->canonical($store->theme) === $code) {
                $already[] = $channel;
            } else {
                $would[] = $channel;
            }
        }

        return [
            'code' => $code,
            'would_switch' => $would,
            'already_active' => $already,
            'message' => count($would).' channel(s) would switch to '.$code.'.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function activate(array $args): array
    {
        self::assertManage();
        $registry = app(ThemeRegistry::class);
        $code = $registry->canonical((string) ($args['code'] ?? ''));
        if (! $registry->exists($code)) {
            throw new UserError('Theme is not installed.');
        }
        $storeIds = array_values(array_filter(array_map(
            static fn ($id) => (int) $id,
            is_array($args['storeIds'] ?? null)
                ? $args['storeIds']
                : (is_array($args['store_ids'] ?? null) ? $args['store_ids'] : [])
        )));
        if ($storeIds === []) {
            \App\Domain\Store\StoreBootstrap::ensure();
            $default = Store::query()->where('is_default', true)->first()
                ?? Store::query()->orderBy('id')->first();
            if (! $default) {
                throw new UserError('No channel found.');
            }
            $storeIds = [(int) $default->id];
        }
        $activated = [];
        foreach (Store::query()->whereIn('id', $storeIds)->get() as $store) {
            $store->update(['theme' => $code]);
            $activated[] = [
                'id' => (string) $store->id,
                'code' => (string) $store->code,
                'name' => $store->name,
            ];
        }
        if ($activated === []) {
            throw new UserError('No matching stores found.');
        }
        $label = $registry->get($code)?->displayName ?: $code;

        return [
            'ok' => true,
            'code' => $code,
            'stores' => $activated,
            'activatedOn' => $activated,
            'message' => $label.' activated on '.count($activated).' channel(s).',
        ];
    }
}
