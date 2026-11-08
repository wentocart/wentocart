<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerDiscount;
use App\Models\Admin;

class AdminGraphqlWave359Test extends MarketplaceTestCase
{
    public function test_seller_checkout_deals_crud_and_admin_list(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave359 Seller',
            'email' => 'w359-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w359-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave359 Shop '.$suffix,
            'shop_slug' => 'w359-shop-'.$suffix,
            'profile_name' => 'Wave359 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0359',
            'email' => 'w359-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0359',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave359 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w359-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $this->flushSession();
        auth('admin')->logout();
        $this->actingAs($owner, 'customer');

        $create = $this->postJson('/api/v1/account/seller/checkout-deals', [
            'name' => '10% cart '.$suffix,
            'apply_as' => 'cart',
            'action' => 'percent',
            'value' => 10,
            'min_cart' => 50,
            'is_enabled' => true,
        ]);
        $create->assertCreated();
        $dealId = (int) $create->json('data.id');
        $this->assertSame('cart', (string) ($create->json('data.applyAs') ?? ''));

        $copy = $this->postJson('/api/v1/account/seller/checkout-deals/'.$dealId.'/copy');
        $copy->assertCreated();
        $this->assertFalse((bool) $copy->json('data.isEnabled'));
        $this->assertStringStartsWith('Copy of ', (string) ($copy->json('data.name') ?? ''));

        $list = $this->getJson('/api/v1/account/seller/checkout-deals');
        $list->assertOk();
        $this->assertGreaterThanOrEqual(2, (int) ($list->json('meta.total') ?? 0));

        $this->flushSession();
        auth('customer')->logout();
        $adminList = $this->actingAs($admin, 'admin')->getJson(
            '/api/v1/console/marketplace/checkout-deals?seller_id='.$seller->id
        );
        $adminList->assertOk();
        $this->assertGreaterThanOrEqual(2, (int) ($adminList->json('meta.total') ?? 0));

        $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/marketplace/checkout-deals/'.$dealId)->assertOk();
        $this->assertNull(SellerDiscount::query()->find($dealId));
    }
}
