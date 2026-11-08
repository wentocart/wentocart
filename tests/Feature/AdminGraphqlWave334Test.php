<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\MarketplaceSubscriptionPlanSchema;
use App\Domain\Marketplace\Models\MarketplaceSellerSubscription;
use App\Domain\Marketplace\Models\MarketplaceSubscriptionPlan;
use App\Domain\Marketplace\Models\Seller;
use App\Models\Admin;
use Carbon\Carbon;

class AdminGraphqlWave334Test extends MarketplaceTestCase
{
    public function test_plan_custom_duration_days_applied_on_assign(): void
    {
        $this->seed();
        MarketplaceSubscriptionPlanSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        MarketplaceSettings::save(['subscription_plans_enabled' => true]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave334 Seller',
            'email' => 'w334-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w334-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave334 Shop '.$suffix,
            'shop_slug' => 'w334-shop-'.$suffix,
            'profile_name' => 'Wave334 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0334',
            'email' => 'w334-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0334',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave334 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w334-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $plan = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketplace/subscription-plans', [
            'name' => '14 Day '.$suffix,
            'code' => 'd14-'.$suffix,
            'price' => 0,
            'billing_period' => MarketplaceSubscriptionPlan::PERIOD_MONTH,
            'duration_days' => 14,
            'is_enabled' => true,
        ])->assertCreated();
        $this->assertSame(14, (int) $plan->json('data.durationDays'));

        $assign = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/subscription-plans/'.(int) $plan->json('data.id').'/assign',
            ['seller_id' => $seller->id]
        )->assertCreated();
        $sub = MarketplaceSellerSubscription::query()->find((int) $assign->json('data.id'));
        $this->assertNotNull($sub?->ends_at);
        $this->assertSame(
            Carbon::now()->addDays(14)->toDateString(),
            $sub->ends_at->toDateString()
        );

        MarketplaceSettings::save(['subscription_plans_enabled' => false]);
    }
}
