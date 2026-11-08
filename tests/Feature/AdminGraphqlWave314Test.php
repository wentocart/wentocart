<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerRole;
use App\Domain\Marketplace\SellerStaffTokenSchema;
use App\Domain\Marketplace\SellerTeamSchema;
use App\Models\Admin;

class AdminGraphqlWave314Test extends MarketplaceTestCase
{
    public function test_seller_staff_login_me_logout(): void
    {
        $this->seed();
        SellerTeamSchema::ensure();
        SellerStaffTokenSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave314 Seller',
            'email' => 'w314-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w314-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave314 Shop '.$suffix,
            'shop_slug' => 'w314-shop-'.$suffix,
            'profile_name' => 'Wave314 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0314',
            'email' => 'w314-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0314',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave314 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w314-shop-'.$suffix)->firstOrFail();
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
            'name' => 'Staff',
            'permission_type' => SellerRole::TYPE_CUSTOM,
            'permissions' => ['sales.orders'],
        ])->assertCreated();
        $roleId = (int) $role->json('data.id');
        $this->postJson('/api/v1/account/seller/users', [
            'name' => 'Clerk '.$suffix,
            'email' => 'clerk-'.$suffix.'@example.test',
            'password' => 'ClerkPass1',
            'role_id' => $roleId,
        ])->assertCreated();

        $this->flushSession();
        auth('customer')->logout();

        $login = $this->postJson('/api/v1/seller/login', [
            'email' => 'clerk-'.$suffix.'@example.test',
            'password' => 'ClerkPass1',
        ]);
        $login->assertOk();
        $token = (string) ($login->json('token') ?? '');
        $this->assertNotSame('', $token);
        $this->assertSame(['sales.orders'], $login->json('user.permissions'));

        $me = $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/seller/me');
        $me->assertOk();
        $this->assertSame('clerk-'.$suffix.'@example.test', (string) ($me->json('data.email') ?? ''));

        $bad = $this->postJson('/api/v1/seller/login', [
            'email' => 'clerk-'.$suffix.'@example.test',
            'password' => 'wrong',
        ]);
        $bad->assertStatus(401);

        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/seller/logout')->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/seller/me')->assertStatus(401);
    }
}
