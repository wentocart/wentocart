<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\Seller;
use App\Models\Admin;

class AdminGraphqlWave306Test extends MarketplaceTestCase
{
    public function test_rest_admin_marketplace_login_as_seller_rest(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave306 Seller',
            'email' => 'w306-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w306-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave306 Shop '.$suffix,
            'shop_slug' => 'w306-shop-'.$suffix,
            'profile_name' => 'Wave306 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0306',
            'email' => 'w306-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0306',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave306 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w306-shop-'.$suffix)->firstOrFail();

        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $login = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/login-as'
        );
        $login->assertOk();
        $token = (string) ($login->json('data.token') ?? '');
        $this->assertNotSame('', $token);
        $this->assertSame((int) $seller->id, (int) ($login->json('data.sellerId') ?? 0));
        $this->assertSame((int) $owner->id, (int) ($login->json('data.customerId') ?? 0));

        // Token must authenticate as the seller owner on account APIs.
        $this->flushSession();
        auth('admin')->logout();
        $me = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/account');
        $me->assertOk();
        $this->assertSame($owner->email, (string) ($me->json('customer.email') ?? $me->json('email') ?? ''));
    }
}
