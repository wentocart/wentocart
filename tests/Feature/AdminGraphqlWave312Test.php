<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\MarketplaceSubscriptionPlanSchema;
use App\Domain\Marketplace\Models\MarketplaceSubscriptionPlan;
use App\Domain\Marketplace\Models\Seller;
use App\Models\Admin;

class AdminGraphqlWave312Test extends MarketplaceTestCase
{
    public function test_subscription_plans_gate_hides_public_shop_until_assigned(): void
    {
        $this->seed();
        MarketplaceSubscriptionPlanSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        MarketplaceSettings::save(['subscription_plans_enabled' => true]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave312 Seller',
            'email' => 'w312-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w312-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave312 Shop '.$suffix,
            'shop_slug' => 'w312-shop-'.$suffix,
            'profile_name' => 'Wave312 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0312',
            'email' => 'w312-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0312',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave312 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w312-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $this->getJson('/api/v1/catalog/seller/'.$seller->shop_slug)->assertNotFound();

        $plan = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketplace/subscription-plans', [
            'name' => 'Basic '.$suffix,
            'code' => 'basic-'.$suffix,
            'price' => 9.99,
            'billing_period' => MarketplaceSubscriptionPlan::PERIOD_MONTH,
            'is_enabled' => true,
        ]);
        $plan->assertCreated();
        $planId = (int) $plan->json('data.id');

        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/subscription-plans/'.$planId.'/assign',
            ['seller_id' => $seller->id, 'days' => 30]
        )->assertCreated();

        $this->getJson('/api/v1/catalog/seller/'.$seller->shop_slug)->assertOk();

        MarketplaceSettings::save(['subscription_plans_enabled' => false]);
    }
}
