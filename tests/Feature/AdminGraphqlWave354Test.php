<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\MarketplaceDiscountJoin;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\SellerDiscountJoinSchema;
use App\Domain\Promo\Models\DiscountRule;
use App\Models\Admin;

class AdminGraphqlWave354Test extends MarketplaceTestCase
{
    public function test_seller_enrolls_in_admin_catalog_discount(): void
    {
        $this->seed();
        SellerDiscountJoinSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        MarketplaceSettings::save([
            'enable_product_discount_join' => true,
            'auto_approve_discount_join' => true,
        ]);

        $rule = DiscountRule::query()->create([
            'name' => 'Store Sale '.$suffix,
            'apply_as' => 'catalog',
            'action' => 'percent',
            'value' => 10,
            'priority' => 10,
            'is_enabled' => true,
            'allow_sellers_to_join' => true,
            'seller_id' => null,
        ]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave354 Seller',
            'email' => 'w354-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w354-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave354 Shop '.$suffix,
            'shop_slug' => 'w354-shop-'.$suffix,
            'profile_name' => 'Wave354 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0354',
            'email' => 'w354-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0354',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave354 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w354-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $settings = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketplace/settings');
        $settings->assertOk();
        $this->assertTrue((bool) $settings->json('data.enableProductDiscountJoin'));

        $this->flushSession();
        auth('admin')->logout();
        $list = $this->actingAs($owner, 'customer')->getJson('/api/v1/account/seller/join-discounts');
        $list->assertOk();
        $this->assertGreaterThanOrEqual(1, (int) ($list->json('meta.total') ?? 0));

        $enroll = $this->actingAs($owner, 'customer')->postJson(
            '/api/v1/account/seller/join-discounts/'.$rule->id.'/enroll'
        );
        $enroll->assertCreated();
        $this->assertSame(MarketplaceDiscountJoin::STATUS_APPROVED, (string) ($enroll->json('data.status') ?? ''));

        $this->flushSession();
        auth('customer')->logout();
        $adminList = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketplace/discount-joins?status=approved');
        $adminList->assertOk();
        $this->assertGreaterThanOrEqual(1, (int) ($adminList->json('meta.total') ?? 0));
    }
}
