<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerRole;
use App\Domain\Marketplace\SellerStaffTokenSchema;
use App\Domain\Marketplace\SellerTeamSchema;
use App\Models\Admin;

class AdminGraphqlWave316Test extends MarketplaceTestCase
{
    public function test_staff_bearer_can_open_dashboard_with_permission(): void
    {
        $this->seed();
        SellerTeamSchema::ensure();
        SellerStaffTokenSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave316 Seller',
            'email' => 'w316-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w316-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave316 Shop '.$suffix,
            'shop_slug' => 'w316-shop-'.$suffix,
            'profile_name' => 'Wave316 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0316',
            'email' => 'w316-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0316',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave316 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w316-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $this->flushSession();
        auth('admin')->logout();
        $this->actingAs($owner, 'customer');
        $okRole = (int) $this->postJson('/api/v1/account/seller/roles', [
            'name' => 'Dash',
            'permission_type' => SellerRole::TYPE_CUSTOM,
            'permissions' => ['dashboard'],
        ])->assertCreated()->json('data.id');
        $denyRole = (int) $this->postJson('/api/v1/account/seller/roles', [
            'name' => 'OrdersOnly',
            'permission_type' => SellerRole::TYPE_CUSTOM,
            'permissions' => ['sales.orders'],
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/account/seller/users', [
            'name' => 'Dash Staff',
            'email' => 'dash-'.$suffix.'@example.test',
            'password' => 'ClerkPass1',
            'role_id' => $okRole,
        ])->assertCreated();
        $this->postJson('/api/v1/account/seller/users', [
            'name' => 'Orders Staff',
            'email' => 'ord-'.$suffix.'@example.test',
            'password' => 'ClerkPass1',
            'role_id' => $denyRole,
        ])->assertCreated();

        $this->flushSession();
        auth('customer')->logout();

        $okToken = (string) $this->postJson('/api/v1/seller/login', [
            'email' => 'dash-'.$suffix.'@example.test',
            'password' => 'ClerkPass1',
        ])->assertOk()->json('token');

        $this->withHeader('Authorization', 'Bearer '.$okToken)
            ->getJson('/api/v1/seller/panel/dashboard')
            ->assertOk();
        $this->assertIsArray($this->withHeader('Authorization', 'Bearer '.$okToken)
            ->getJson('/api/v1/seller/panel/dashboard')->json('stats'));

        $denyToken = (string) $this->postJson('/api/v1/seller/login', [
            'email' => 'ord-'.$suffix.'@example.test',
            'password' => 'ClerkPass1',
        ])->assertOk()->json('token');

        $this->withHeader('Authorization', 'Bearer '.$denyToken)
            ->getJson('/api/v1/seller/panel/dashboard')
            ->assertForbidden();

        // Orders-only staff can still open the general panel orders list.
        $this->withHeader('Authorization', 'Bearer '.$denyToken)
            ->getJson('/api/v1/seller/panel/orders')
            ->assertOk();
    }
}
