<?php

namespace App\Domain\Auth;

use App\Domain\Auth\Models\Permission;
use App\Domain\Auth\Models\Profile;
use App\Domain\Auth\Models\Role;
use App\Domain\Auth\Models\Team;
use App\Support\HexColor;
use Illuminate\Support\Facades\Schema;

final class StaffAcl
{
    public static function ensure(): void
    {
        $rows = [];
        $now = now();
        foreach (PageCatalog::seedPermissions() as $slug => [$group, $name]) {
            $rows[] = [
                'slug' => $slug,
                'name' => $name,
                'group' => $group,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if ($rows !== []) {
            Permission::query()->upsert($rows, ['slug'], ['name', 'group', 'updated_at']);
        }

        Role::query()->updateOrCreate(['slug' => 'super_admin'], ['name' => 'Super admin']);

        self::ensureSystemRole('catalog_manager', 'Catalog manager', ['catalog.manage']);
        self::ensureSystemRole('order_manager', 'Order manager', [
            'orders.manage',
            'orders.documents',
            'marketing.discounts',
            'shipping.manage',
            'payments.manage',
        ]);
        self::ensureSystemRole('customer_manager', 'Customer manager', ['customers.manage']);
        self::ensureSystemRole('marketplace_manager', 'Marketplace manager', ['multivendor.manage']);

        $operations = Team::query()->updateOrCreate(
            ['slug' => 'operations'],
            ['name' => 'Operations', 'color' => HexColor::TONES['primary'], 'description' => 'Catalog and inventory.', 'is_enabled' => true]
        );
        $fulfilment = Team::query()->updateOrCreate(
            ['slug' => 'fulfilment'],
            ['name' => 'Fulfilment', 'color' => HexColor::TONES['warning'], 'description' => 'Orders, shipping, and payments.', 'is_enabled' => true]
        );
        $sales = Team::query()->updateOrCreate(
            ['slug' => 'sales'],
            ['name' => 'Sales', 'color' => HexColor::TONES['info'], 'description' => 'Customers and order desk.', 'is_enabled' => true]
        );
        $marketing = Team::query()->updateOrCreate(
            ['slug' => 'marketing'],
            ['name' => 'Marketing', 'color' => HexColor::TONES['success'], 'description' => 'Pages, theme, and newsletter.', 'is_enabled' => true]
        );

        $catalogProfile = self::ensureProfile(
            'catalog_editor',
            'Catalog editor',
            'Can open catalog pages only: products, categories, brands, suppliers, and features.',
            ['catalog.manage']
        );
        $orderProfile = self::ensureProfile(
            'order_desk',
            'Order desk',
            'Can open orders, documents, discounts, shipping, and payments.',
            [
                'orders.manage',
                'orders.documents',
                'marketing.discounts',
                'shipping.manage',
                'payments.manage',
            ]
        );
        $careProfile = self::ensureProfile(
            'customer_care',
            'Customer care',
            'Can open customer, group, address, and review pages.',
            ['customers.manage']
        );
        $contentProfile = self::ensureProfile(
            'content_editor',
            'Content editor',
            'Can open CMS pages, blocks, themes, and the newsletter.',
            ['cms.manage', 'marketing.newsletter']
        );

        if (! $operations->default_profile_id) {
            $operations->update(['default_profile_id' => $catalogProfile->id]);
        }
        if (! $fulfilment->default_profile_id) {
            $fulfilment->update(['default_profile_id' => $orderProfile->id]);
        }
        if (! $sales->default_profile_id) {
            $sales->update(['default_profile_id' => $careProfile->id]);
        }
        if (! $marketing->default_profile_id) {
            $marketing->update(['default_profile_id' => $contentProfile->id]);
        }
    }

    /**
     * @param  list<string>  $permissionSlugs
     */
    private static function ensureProfile(string $slug, string $name, string $description, array $permissionSlugs): Profile
    {
        $profile = Profile::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'description' => $description,
                'is_enabled' => true,
            ]
        );

        if (Schema::hasTable('profile_permission')) {
            if ($profile->wasRecentlyCreated || $profile->permissions()->count() === 0) {
                $profile->permissions()->sync(self::permissionIds($permissionSlugs));
            }

            return $profile;
        }

        $role = Role::query()->updateOrCreate(
            ['slug' => $slug],
            ['name' => $name]
        );
        self::ensureRolePermissions($role, $permissionSlugs);

        if ($profile->wasRecentlyCreated || $profile->roles()->count() === 0) {
            $profile->roles()->sync([$role->id]);
        }

        return $profile;
    }

    /** @param  list<string>  $permissionSlugs */
    private static function ensureSystemRole(string $slug, string $name, array $permissionSlugs): Role
    {
        $role = Role::query()->updateOrCreate(['slug' => $slug], ['name' => $name]);
        self::ensureRolePermissions($role, $permissionSlugs);

        return $role;
    }

    /** @param  list<string>  $slugs */
    private static function ensureRolePermissions(Role $role, array $slugs): void
    {
        $role->permissions()->sync(self::permissionIds($slugs));
    }

    /**
     * @param  list<string>  $slugs
     * @return list<int>
     */
    private static function permissionIds(array $slugs): array
    {
        return Permission::query()->whereIn('slug', $slugs)->pluck('id')->all();
    }
}
