<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\Seller;
use App\Models\Admin;

class AdminGraphqlWave362Test extends MarketplaceTestCase
{
    public function test_fulfilment_carriers_self_ship_always_available(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $save = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/marketplace/fulfilment-carriers', [
            'selfShipTitle' => 'Own Courier',
            'carriers' => [
                ['code' => 'dhl', 'title' => 'DHL Express', 'isEnabled' => true],
                ['code' => 'fedex', 'title' => 'FedEx', 'isEnabled' => false],
            ],
        ]);
        $save->assertOk();
        $this->assertTrue((bool) $save->json('data.selfShipAlwaysEnabled'));
        $this->assertSame('Own Courier', (string) ($save->json('data.selfShipTitle') ?? ''));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave362 Seller',
            'email' => 'w362-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w362-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave362 Shop '.$suffix,
            'shop_slug' => 'w362-shop-'.$suffix,
            'profile_name' => 'Wave362 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0362',
            'email' => 'w362-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0362',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave362 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w362-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $this->flushSession();
        auth('admin')->logout();
        $list = $this->actingAs($owner, 'customer')->getJson('/api/v1/account/seller/fulfilment-carriers');
        $list->assertOk();
        $codes = collect($list->json('data') ?? [])->pluck('code')->all();
        $this->assertContains('self_ship', $codes);
        $this->assertContains('dhl', $codes);
        $this->assertNotContains('fedex', $codes);
        $this->assertSame('Own Courier', (string) (collect($list->json('data') ?? [])->firstWhere('code', 'self_ship')['title'] ?? ''));
    }
}
