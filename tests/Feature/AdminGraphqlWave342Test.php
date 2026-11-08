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

class AdminGraphqlWave342Test extends MarketplaceTestCase
{
    public function test_subscription_tick_records_reminder_and_does_not_duplicate(): void
    {
        $this->seed();
        MarketplaceSubscriptionPlanSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        MarketplaceSettings::save(['subscription_plans_enabled' => true]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave342 Seller',
            'email' => 'w342-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w342-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave342 Shop '.$suffix,
            'shop_slug' => 'w342-shop-'.$suffix,
            'profile_name' => 'Wave342 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0342',
            'email' => 'w342-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0342',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave342 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w342-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $plan = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketplace/subscription-plans', [
            'name' => 'Remind '.$suffix,
            'code' => 'rem-'.$suffix,
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
            'ends_at' => now()->addDays(7)->endOfDay(),
            'reminder_days_sent' => null,
        ]);

        $tick = app(MarketplaceSubscriptionTickService::class);
        $first = $tick->run(false);
        $this->assertSame(1, $first['reminders']);
        $second = $tick->run(false);
        $this->assertSame(0, $second['reminders']);
        $this->assertContains(7, MarketplaceSellerSubscription::query()->find($subId)?->reminder_days_sent ?? []);

        MarketplaceSettings::save(['subscription_plans_enabled' => false]);
    }
}
