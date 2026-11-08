<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\SellerDiscountJoinSchema;
use App\Domain\Promo\Models\DiscountRule;
use App\Models\Admin;

class AdminGraphqlWave356Test extends MarketplaceTestCase
{
    public function test_join_discounts_disabled_hides_list(): void
    {
        $this->seed();
        SellerDiscountJoinSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        MarketplaceSettings::save(['enable_product_discount_join' => false]);

        DiscountRule::query()->create([
            'name' => 'Hidden Sale '.$suffix,
            'apply_as' => 'catalog',
            'action' => 'percent',
            'value' => 5,
            'priority' => 1,
            'is_enabled' => true,
            'allow_sellers_to_join' => true,
        ]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave356 Seller',
            'email' => 'w356-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w356-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave356 Shop '.$suffix,
            'shop_slug' => 'w356-shop-'.$suffix,
            'profile_name' => 'Wave356 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0356',
            'email' => 'w356-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0356',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave356 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w356-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $this->flushSession();
        auth('admin')->logout();
        $list = $this->actingAs($owner, 'customer')->getJson('/api/v1/account/seller/join-discounts');
        $list->assertOk();
        $this->assertFalse((bool) $list->json('meta.enabled'));
        $this->assertSame([], $list->json('data') ?? null);
    }
}
