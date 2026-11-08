<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\MarketplaceSubscriptionPlanSchema;
use App\Domain\Marketplace\Models\MarketplaceSellerSubscription;
use App\Domain\Marketplace\Models\MarketplaceSubscriptionPlan;
use App\Domain\Marketplace\Models\Seller;
use App\Models\Admin;

class AdminGraphqlWave328Test extends MarketplaceTestCase
{
    public function test_default_free_plan_blocked_after_first_active_subscription(): void
    {
        $this->seed();
        MarketplaceSubscriptionPlanSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        MarketplaceSettings::save(['subscription_plans_enabled' => true]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave328 Seller',
            'email' => 'w328-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w328-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave328 Shop '.$suffix,
            'shop_slug' => 'w328-shop-'.$suffix,
            'profile_name' => 'Wave328 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0328',
            'email' => 'w328-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0328',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave328 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w328-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $free = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketplace/subscription-plans', [
            'name' => 'Default Free '.$suffix,
            'code' => 'def-free-'.$suffix,
            'price' => 0,
            'billing_period' => MarketplaceSubscriptionPlan::PERIOD_MONTH,
            'is_default' => true,
            'is_enabled' => true,
        ])->assertCreated();
        $freeId = (int) $free->json('data.id');
        $this->assertSame(1, (int) $free->json('data.isDefault'));

        $paid = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketplace/subscription-plans', [
            'name' => 'Paid Again '.$suffix,
            'code' => 'paid-again-'.$suffix,
            'price' => 19,
            'billing_period' => MarketplaceSubscriptionPlan::PERIOD_MONTH,
            'is_enabled' => true,
        ])->assertCreated();
        $paidId = (int) $paid->json('data.id');

        $this->flushSession();
        auth('admin')->logout();
        $this->actingAs($owner, 'customer');

        $this->postJson('/api/v1/account/seller/plans/purchase', [
            'plan_id' => $freeId,
            'payment_method' => 'free',
            'accept_terms' => true,
        ])->assertCreated();

        // Simulate prior paid plan that became active then expired.
        MarketplaceSellerSubscription::query()
            ->where('seller_id', $seller->id)
            ->where('status', MarketplaceSellerSubscription::STATUS_ACTIVE)
            ->update(['status' => MarketplaceSellerSubscription::STATUS_EXPIRED, 'ends_at' => now()]);

        $blocked = $this->postJson('/api/v1/account/seller/plans/purchase', [
            'plan_id' => $freeId,
            'payment_method' => 'free',
            'accept_terms' => true,
        ]);
        $blocked->assertStatus(422);
        $this->assertSame('free_plan_used', (string) ($blocked->json('error') ?? ''));

        $this->postJson('/api/v1/account/seller/plans/purchase', [
            'plan_id' => $paidId,
            'payment_method' => 'money_transfer',
            'accept_terms' => true,
        ])->assertCreated();

        MarketplaceSettings::save(['subscription_plans_enabled' => false]);
    }
}
