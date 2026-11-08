<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\Seller;
use App\Models\Admin;

class AdminGraphqlWave296Test extends MarketplaceTestCase
{
    public function test_rest_admin_marketplace_sellers_rest(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave296 Seller',
            'email' => 'w296-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $customer = Customer::query()->where('email', 'w296-'.$suffix.'@example.test')->firstOrFail();
        $apply = $this->actingAs($customer, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave296 Shop '.$suffix,
            'shop_slug' => 'w296-shop-'.$suffix,
            'profile_name' => 'Wave296 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0196',
            'email' => 'w296-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0196',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave296 marketplace seller',
        ]);
        $apply->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w296-shop-'.$suffix)->firstOrFail();

        $this->flushSession();
        auth('customer')->logout();

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketplace/sellers');
        $list->assertOk();
        $this->assertIsArray($list->json('data'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketplace/sellers/'.$seller->id);
        $show->assertOk();
        $this->assertSame('pending', (string) ($show->json('data.status') ?? ''));

        $approve = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 15]
        );
        $approve->assertOk();
        $this->assertSame(Seller::STATUS_APPROVED, $seller->fresh()->status);
        $this->assertEquals(15.0, (float) $seller->fresh()->commission_rate);

        $upd = $this->actingAs($admin, 'admin')->putJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id,
            ['commission_rate' => 18, 'description' => 'Updated '.$suffix]
        );
        $upd->assertOk();
        $this->assertEquals(18.0, (float) ($upd->json('data.commissionPercentage') ?? 0));

        $suspend = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'suspend', 'note' => 'policy']
        );
        $suspend->assertOk();
        $this->assertSame(Seller::STATUS_BLOCKED, $seller->fresh()->status);
    }
}
