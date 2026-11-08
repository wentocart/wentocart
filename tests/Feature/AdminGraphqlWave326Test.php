<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\MarketplaceSubscriptionPlanSchema;
use App\Domain\Marketplace\Models\MarketplaceSubscriptionPlan;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Services\CommissionService;
use App\Models\Admin;

class AdminGraphqlWave326Test extends MarketplaceTestCase
{
    public function test_active_plan_commission_value_overrides_seller_rate(): void
    {
        $this->seed();
        MarketplaceSubscriptionPlanSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        MarketplaceSettings::save(['subscription_plans_enabled' => true]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave326 Seller',
            'email' => 'w326-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w326-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave326 Shop '.$suffix,
            'shop_slug' => 'w326-shop-'.$suffix,
            'profile_name' => 'Wave326 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0326',
            'email' => 'w326-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0326',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave326 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w326-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();
        $seller->refresh();
        $this->assertSame(10.0, (float) $seller->commission_rate);

        $plan = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketplace/subscription-plans', [
            'name' => 'Comm '.$suffix,
            'code' => 'comm-'.$suffix,
            'price' => 0,
            'billing_period' => MarketplaceSubscriptionPlan::PERIOD_MONTH,
            'commission_value' => 25,
            'is_enabled' => true,
        ])->assertCreated();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/subscription-plans/'.(int) $plan->json('data.id').'/assign',
            ['seller_id' => $seller->id]
        )->assertCreated();

        $resolved = app(CommissionService::class)->resolveForSeller($seller->fresh());
        $this->assertSame(CommissionService::SOURCE_PLAN, $resolved['source']);
        $this->assertSame(25.0, (float) $resolved['rate']);

        MarketplaceSettings::save(['subscription_plans_enabled' => false]);
    }
}
