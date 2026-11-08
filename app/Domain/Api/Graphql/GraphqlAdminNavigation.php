<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Auth\PageCatalog;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Admin REST Navigation + Profile: menu tree, effective permissions, readAdminProfile.
 */
final class GraphqlAdminNavigation
{
    public static function assertAdmin(): Admin
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin) {
            throw new UserError('Unauthenticated.');
        }

        return $admin;
    }

    /**
     * @return array{id: string, _id: string, permissionType: string, permissions: list<string>|string}
     */
    public static function permissions(): array
    {
        $admin = self::assertAdmin();
        $token = $admin->currentAccessToken();
        $abilities = $token instanceof PersonalAccessToken && is_array($token->abilities)
            ? $token->abilities
            : [];

        $mode = $token instanceof PersonalAccessToken
            ? IntegrationTokenPermissions::mode($abilities)
            : 'same_as_web';

        if ($mode === 'all') {
            $perms = ['*'];
        } elseif ($mode === 'custom') {
            $frozen = IntegrationTokenPermissions::frozenSlugs($abilities) ?? [];
            $perms = $frozen === [] ? [] : array_values($frozen);
            if (in_array('*', $perms, true)) {
                $perms = ['*'];
            }
        } else {
            $owned = $admin->permissionSlugs();
            $perms = in_array('*', $owned, true) ? ['*'] : array_values($owned);
            if (in_array('*', $perms, true)) {
                // Session / same_as_web super-admin: expose wildcard like typical headless REST "all".
                $mode = $admin->isSuperAdmin() ? 'all' : $mode;
                $perms = ['*'];
            }
        }

        return [
            'id' => '/api/v1/console/admin_permissions/permissions',

            'permissionType' => $mode,
            'permissions' => $perms,
        ];
    }

    /**
     * @return array{id: string, _id: string, tree: list<array<string, mixed>>|string}
     */
    public static function menu(bool $treeAsJson = false): array
    {
        $admin = self::assertAdmin();
        $permPayload = self::permissions();
        $allowed = $permPayload['permissions'];
        $all = in_array('*', $allowed, true);

        $tree = self::filterTree(self::rawTree(), $allowed, $all);

        return [
            'id' => '/api/v1/console/admin_menus/menu',

            'tree' => $treeAsJson ? json_encode($tree, JSON_UNESCAPED_UNICODE) : $tree,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function profile(): array
    {
        $admin = self::assertAdmin();
        $admin->loadMissing('roles');
        $role = $admin->roles->first();

        return [
            'id' => '/api/v1/console/admin_profiles/'.$admin->id,

            'name' => (string) $admin->name,
            'email' => (string) $admin->email,
            'image' => $admin->avatar_path ? (string) $admin->avatar_path : null,
            'status' => $admin->is_enabled ? '1' : '0',
            'roleId' => $role ? (int) $role->id : null,
            'roleName' => $role ? (string) $role->name : null,
            'success' => true,
            'message' => null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function rawTree(): array
    {
        $pages = PageCatalog::pages();
        $byGroup = [];
        foreach ($pages as $i => $page) {
            $group = (string) ($page['group'] ?? 'Other');
            $byGroup[$group][] = [
                'key' => (string) $page['slug'],
                'label' => (string) $page['label'],
                'icon' => null,
                'sort' => $i + 1,
                'permission' => (string) $page['slug'],
                'apiResource' => self::apiResourceFor((string) $page['slug']),
                'children' => [],
            ];
        }

        $tree = [[
            'key' => 'dashboard',
            'label' => 'Dashboard',
            'icon' => 'icon-dashboard',
            'sort' => 1,
            'permission' => 'dashboard',
            'apiResource' => [
                'rest' => '/api/v1/console/dashboard/stats',
                'graphql' => 'statsAdminDashboard',
            ],
            'children' => [],
        ]];

        $sort = 2;
        foreach ($byGroup as $group => $children) {
            $key = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $group) ?: 'group');
            $tree[] = [
                'key' => $key,
                'label' => $group,
                'icon' => null,
                'sort' => $sort++,
                'permission' => $key,
                'apiResource' => null,
                'children' => array_values($children),
            ];
        }

        return $tree;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<string>  $allowed
     * @return list<array<string, mixed>>
     */
    protected static function filterTree(array $nodes, array $allowed, bool $all): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $hadChildren = ($node['children'] ?? []) !== [];
            $children = self::filterTree($node['children'] ?? [], $allowed, $all);
            $key = (string) ($node['permission'] ?? $node['key'] ?? '');
            if ($all || $key === 'dashboard' || in_array($key, $allowed, true)) {
                $node['children'] = $children;
                $out[] = $node;

                continue;
            }
            // Group headers stay when at least one child remains visible.
            if ($hadChildren && $children !== []) {
                $node['children'] = $children;
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * @return array{rest: string, graphql: string}|null
     */
    protected static function apiResourceFor(string $slug): ?array
    {
        return match (true) {
            str_starts_with($slug, 'catalog.products') => [
                'rest' => '/api/v1/console/products/export',
                'graphql' => 'adminCatalogProducts',
            ],
            str_starts_with($slug, 'orders.manage') => [
                'rest' => '/api/v1/console/orders/export',
                'graphql' => 'adminOrders',
            ],
            str_starts_with($slug, 'customers.customers') => [
                'rest' => '/api/v1/console/customers/export',
                'graphql' => 'adminCustomers',
            ],
            str_starts_with($slug, 'customers.gdpr') => [
                'rest' => '/api/v1/console/customers/gdpr-requests',
                'graphql' => 'adminCustomerGdprRequests',
            ],
            str_starts_with($slug, 'customers.reviews') => [
                'rest' => '/api/v1/console/customers/export',
                'graphql' => 'adminCustomerReviews',
            ],
            str_starts_with($slug, 'cms.pages') => [
                'rest' => '/api/v1/console/cms/pages/export',
                'graphql' => 'adminCmsPages',
            ],
            str_starts_with($slug, 'marketing') => [
                'rest' => '/api/v1/console/marketing/discount-rules/export',
                'graphql' => 'adminMarketingCampaigns',
            ],
            default => null,
        };
    }
}
