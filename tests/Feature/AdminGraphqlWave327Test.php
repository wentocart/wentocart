<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\MarketplaceSubscriptionPlanSchema;
use App\Domain\Marketplace\Models\MarketplaceSellerSubscription;
use App\Domain\Marketplace\Models\MarketplaceSubscriptionPlan;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Services\MarketplaceSubscriptionTickService;
use App\Models\Admin;

class AdminGraphqlWave327Test extends MarketplaceTestCase
{
    public function test_subscription_expiry_countdown_and_reminder_tick(): void
    {
        $this->seed();
        MarketplaceSubscriptionPlanSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        MarketplaceSettings::save(['subscription_plans_enabled' => true]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave327 Seller',
            'email' => 'w327-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w327-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave327 Shop '.$suffix,
            'shop_slug' => 'w327-shop-'.$suffix,
            'profile_name' => 'Wave327 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0327',
            'email' => 'w327-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0327',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave327 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w327-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $plan = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketplace/subscription-plans', [
            'name' => 'Soon '.$suffix,
            'code' => 'soon-'.$suffix,
            'price' => 0,
            'billing_period' => MarketplaceSubscriptionPlan::PERIOD_MONTH,
            'is_enabled' => true,
        ])->assertCreated();
        $assign = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/subscription-plans/'.(int) $plan->json('data.id').'/assign',
            ['seller_id' => $seller->id]
        )->assertCreated();
        $subId = (int) $assign->json('data.id');
        MarketplaceSellerSubscription::query()->whereKey($subId)->update([
            'ends_at' => now()->addDays(3)->endOfDay(),
        ]);

        $result = app(MarketplaceSubscriptionTickService::class)->run(false);
        $this->assertSame(1, $result['reminders']);
        $sub = MarketplaceSellerSubscription::query()->find($subId);
        $this->assertContains(3, $sub?->reminder_days_sent ?? []);

        $this->flushSession();
        auth('admin')->logout();
        $this->actingAs($owner, 'customer');
        $plans = $this->getJson('/api/v1/account/seller/plans');
        $plans->assertOk();
        $this->assertSame(3, (int) ($plans->json('meta.subscriptionExpiresInDays') ?? -1));

        MarketplaceSettings::save(['subscription_plans_enabled' => false]);
    }
}
