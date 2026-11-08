<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerRole;
use App\Domain\Marketplace\SellerStaffTokenSchema;
use App\Domain\Marketplace\SellerTeamSchema;
use App\Models\Admin;

class AdminGraphqlWave321Test extends MarketplaceTestCase
{
    public function test_staff_panel_acl_matrix_enforces_route_permissions(): void
    {
        $this->seed();
        SellerTeamSchema::ensure();
        SellerStaffTokenSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave321 Seller',
            'email' => 'w321-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w321-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave321 Shop '.$suffix,
            'shop_slug' => 'w321-shop-'.$suffix,
            'profile_name' => 'Wave321 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0321',
            'email' => 'w321-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0321',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave321 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w321-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $this->flushSession();
        auth('admin')->logout();
        $this->actingAs($owner, 'customer');
        $prodRole = (int) $this->postJson('/api/v1/account/seller/roles', [
            'name' => 'Catalog',
            'permission_type' => SellerRole::TYPE_CUSTOM,
            'permissions' => ['catalog'],
        ])->assertCreated()->json('data.id');
        $orderRole = (int) $this->postJson('/api/v1/account/seller/roles', [
            'name' => 'Sales',
            'permission_type' => SellerRole::TYPE_CUSTOM,
            'permissions' => ['sales.orders'],
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/account/seller/users', [
            'name' => 'Catalog Staff',
            'email' => 'cat-'.$suffix.'@example.test',
            'password' => 'ClerkPass1',
            'role_id' => $prodRole,
        ])->assertCreated();
        $this->postJson('/api/v1/account/seller/users', [
            'name' => 'Sales Staff',
            'email' => 'sale-'.$suffix.'@example.test',
            'password' => 'ClerkPass1',
            'role_id' => $orderRole,
        ])->assertCreated();

        $this->flushSession();
        auth('customer')->logout();

        $catToken = (string) $this->postJson('/api/v1/seller/login', [
            'email' => 'cat-'.$suffix.'@example.test',
            'password' => 'ClerkPass1',
        ])->assertOk()->json('token');

        $this->withHeader('Authorization', 'Bearer '.$catToken)
            ->getJson('/api/v1/seller/panel/products')
            ->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$catToken)
            ->getJson('/api/v1/seller/panel/orders')
            ->assertForbidden();

        $saleToken = (string) $this->postJson('/api/v1/seller/login', [
            'email' => 'sale-'.$suffix.'@example.test',
            'password' => 'ClerkPass1',
        ])->assertOk()->json('token');

        $this->withHeader('Authorization', 'Bearer '.$saleToken)
            ->getJson('/api/v1/seller/panel/orders')
            ->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$saleToken)
            ->getJson('/api/v1/seller/panel/products')
            ->assertForbidden();
        $this->withHeader('Authorization', 'Bearer '.$saleToken)
            ->getJson('/api/v1/seller/panel/returns')
            ->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$saleToken)
            ->getJson('/api/v1/seller/panel/earnings')
            ->assertForbidden();
    }
}
