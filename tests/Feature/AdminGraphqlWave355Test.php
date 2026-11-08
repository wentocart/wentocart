<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\MarketplaceDiscountJoin;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\SellerDiscountJoinSchema;
use App\Domain\Promo\Models\DiscountRule;
use App\Models\Admin;

class AdminGraphqlWave355Test extends MarketplaceTestCase
{
    public function test_discount_join_pending_until_admin_approves(): void
    {
        $this->seed();
        SellerDiscountJoinSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        MarketplaceSettings::save([
            'enable_product_discount_join' => true,
            'auto_approve_discount_join' => false,
        ]);

        $rule = DiscountRule::query()->create([
            'name' => 'Pending Sale '.$suffix,
            'apply_as' => 'catalog',
            'action' => 'percent',
            'value' => 15,
            'priority' => 5,
            'is_enabled' => true,
            'allow_sellers_to_join' => true,
        ]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave355 Seller',
            'email' => 'w355-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w355-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave355 Shop '.$suffix,
            'shop_slug' => 'w355-shop-'.$suffix,
            'profile_name' => 'Wave355 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0355',
            'email' => 'w355-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0355',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave355 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w355-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $this->flushSession();
        auth('admin')->logout();
        $enroll = $this->actingAs($owner, 'customer')->postJson(
            '/api/v1/account/seller/join-discounts/'.$rule->id.'/enroll'
        );
        $enroll->assertCreated();
        $joinId = (int) $enroll->json('data.id');
        $this->assertSame(MarketplaceDiscountJoin::STATUS_PENDING, (string) ($enroll->json('data.status') ?? ''));

        $this->flushSession();
        auth('customer')->logout();
        $approve = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/discount-joins/'.$joinId.'/approve'
        );
        $approve->assertOk();
        $this->assertSame(MarketplaceDiscountJoin::STATUS_APPROVED, MarketplaceDiscountJoin::query()->find($joinId)?->status);

        $this->flushSession();
        auth('admin')->logout();
        $withdraw = $this->actingAs($owner, 'customer')->postJson(
            '/api/v1/account/seller/join-discounts/'.$rule->id.'/withdraw'
        );
        $withdraw->assertOk();
        $this->assertNull(MarketplaceDiscountJoin::query()->find($joinId));
    }
}
