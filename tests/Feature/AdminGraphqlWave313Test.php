<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerRole;
use App\Domain\Marketplace\Models\SellerUser;
use App\Domain\Marketplace\SellerTeamSchema;
use App\Models\Admin;

class AdminGraphqlWave313Test extends MarketplaceTestCase
{
    public function test_seller_team_roles_and_users_rest(): void
    {
        $this->seed();
        SellerTeamSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave313 Seller',
            'email' => 'w313-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w313-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave313 Shop '.$suffix,
            'shop_slug' => 'w313-shop-'.$suffix,
            'profile_name' => 'Wave313 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0313',
            'email' => 'w313-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0313',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave313 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w313-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $this->flushSession();
        auth('admin')->logout();
        $this->actingAs($owner, 'customer');

        $role = $this->postJson('/api/v1/account/seller/roles', [
            'name' => 'Catalog Staff',
            'permission_type' => SellerRole::TYPE_CUSTOM,
            'permissions' => ['catalog', 'sales.orders'],
        ]);
        $role->assertCreated();
        $roleId = (int) $role->json('data.id');

        $allRole = $this->postJson('/api/v1/account/seller/roles', [
            'name' => 'Manager',
            'permission_type' => SellerRole::TYPE_ALL,
        ]);
        $allRole->assertCreated();

        $user = $this->postJson('/api/v1/account/seller/users', [
            'name' => 'Staff '.$suffix,
            'email' => 'staff-'.$suffix.'@example.test',
            'password' => 'Secret99',
            'role_id' => $roleId,
            'is_enabled' => true,
        ]);
        $user->assertCreated();
        $userId = (int) $user->json('data.id');
        $this->assertSame(1, SellerUser::query()->where('seller_id', $seller->id)->count());

        $list = $this->getJson('/api/v1/account/seller/users');
        $list->assertOk();
        $this->assertGreaterThanOrEqual(1, (int) ($list->json('meta.total') ?? 0));

        $this->putJson('/api/v1/account/seller/users/'.$userId, [
            'name' => 'Staff Updated',
            'email' => 'staff-'.$suffix.'@example.test',
            'role_id' => $roleId,
            'is_enabled' => false,
        ])->assertOk();
        $this->assertFalse((bool) SellerUser::query()->find($userId)?->is_enabled);

        $this->deleteJson('/api/v1/account/seller/users/'.$userId)->assertOk();
        $this->assertNull(SellerUser::query()->find($userId));
    }
}
