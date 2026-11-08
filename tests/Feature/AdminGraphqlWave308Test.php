<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSubscriptionPlanSchema;
use App\Domain\Marketplace\Models\MarketplaceSellerSubscription;
use App\Domain\Marketplace\Models\MarketplaceSubscriptionPlan;
use App\Domain\Marketplace\Models\Seller;
use App\Models\Admin;

class AdminGraphqlWave308Test extends MarketplaceTestCase
{
    public function test_rest_admin_marketplace_subscription_plans_rest(): void
    {
        $this->seed();
        MarketplaceSubscriptionPlanSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketplace/subscription-plans', [
            'name' => 'Pro '.$suffix,
            'code' => 'pro-'.$suffix,
            'price' => 49.99,
            'billing_period' => MarketplaceSubscriptionPlan::PERIOD_MONTH,
            'product_limit' => 100,
            'allows_communication' => true,
            'is_enabled' => true,
        ]);
        $create->assertCreated();
        $planId = (int) $create->json('data.id');

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketplace/subscription-plans');
        $list->assertOk();
        $this->assertGreaterThanOrEqual(1, (int) ($list->json('meta.total') ?? 0));

        $update = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/marketplace/subscription-plans/'.$planId, [
            'name' => 'Pro '.$suffix.' Plus',
            'price' => 59.99,
            'billing_period' => MarketplaceSubscriptionPlan::PERIOD_MONTH,
            'product_limit' => 200,
        ]);
        $update->assertOk();
        $this->assertSame(59.99, (float) ($update->json('data.price') ?? 0));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave308 Seller',
            'email' => 'w308-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w308-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave308 Shop '.$suffix,
            'shop_slug' => 'w308-shop-'.$suffix,
            'profile_name' => 'Wave308 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0308',
            'email' => 'w308-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0308',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave308 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w308-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $assign = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/subscription-plans/'.$planId.'/assign',
            ['seller_id' => $seller->id, 'days' => 30]
        );
        $assign->assertCreated();
        $this->assertSame(1, MarketplaceSellerSubscription::query()
            ->where('seller_id', $seller->id)
            ->where('status', MarketplaceSellerSubscription::STATUS_ACTIVE)
            ->count());
    }
}
