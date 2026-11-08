<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\MarketplaceSubscriptionPlanSchema;
use App\Domain\Marketplace\Models\MarketplaceSellerSubscription;
use App\Domain\Marketplace\Models\MarketplaceSubscriptionPlan;
use App\Domain\Marketplace\Models\Seller;
use App\Models\Admin;

class AdminGraphqlWave324Test extends MarketplaceTestCase
{
    public function test_marketplace_subscription_tick_expires_past_due_plans(): void
    {
        $this->seed();
        MarketplaceSubscriptionPlanSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        MarketplaceSettings::save(['subscription_plans_enabled' => true]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave324 Seller',
            'email' => 'w324-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w324-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave324 Shop '.$suffix,
            'shop_slug' => 'w324-shop-'.$suffix,
            'profile_name' => 'Wave324 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0324',
            'email' => 'w324-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0324',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave324 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w324-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $plan = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketplace/subscription-plans', [
            'name' => 'Expire Me '.$suffix,
            'code' => 'exp-'.$suffix,
            'price' => 0,
            'billing_period' => MarketplaceSubscriptionPlan::PERIOD_MONTH,
            'is_enabled' => true,
        ])->assertCreated();
        $assign = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/subscription-plans/'.(int) $plan->json('data.id').'/assign',
            ['seller_id' => $seller->id, 'days' => 30]
        )->assertCreated();
        $subId = (int) $assign->json('data.id');
        MarketplaceSellerSubscription::query()->whereKey($subId)->update([
            'ends_at' => now()->subMinute(),
        ]);

        $this->artisan('wento:marketplace-subscription-tick', ['--no-health' => true])
            ->assertSuccessful();

        $this->assertSame(
            MarketplaceSellerSubscription::STATUS_EXPIRED,
            MarketplaceSellerSubscription::query()->find($subId)?->status
        );

        MarketplaceSettings::save(['subscription_plans_enabled' => false]);
    }
}
