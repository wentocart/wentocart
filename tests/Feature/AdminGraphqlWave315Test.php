<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\MarketplaceSubscriptionPlanSchema;
use App\Domain\Marketplace\Models\MarketplaceSubscriptionPlan;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerRole;
use App\Domain\Marketplace\SellerTeamSchema;
use App\Models\Admin;

class AdminGraphqlWave315Test extends MarketplaceTestCase
{
    public function test_subscription_plan_max_staff_blocks_extra_users(): void
    {
        $this->seed();
        SellerTeamSchema::ensure();
        MarketplaceSubscriptionPlanSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        MarketplaceSettings::save(['subscription_plans_enabled' => true]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave315 Seller',
            'email' => 'w315-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w315-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave315 Shop '.$suffix,
            'shop_slug' => 'w315-shop-'.$suffix,
            'profile_name' => 'Wave315 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0315',
            'email' => 'w315-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0315',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave315 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w315-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $plan = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketplace/subscription-plans', [
            'name' => 'Tiny '.$suffix,
            'code' => 'tiny-'.$suffix,
            'price' => 1,
            'billing_period' => MarketplaceSubscriptionPlan::PERIOD_MONTH,
            'max_staff' => 1,
            'is_enabled' => true,
        ])->assertCreated();
        $planId = (int) $plan->json('data.id');
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/subscription-plans/'.$planId.'/assign',
            ['seller_id' => $seller->id]
        )->assertCreated();

        $this->flushSession();
        auth('admin')->logout();
        $this->actingAs($owner, 'customer');
        $roleId = (int) $this->postJson('/api/v1/account/seller/roles', [
            'name' => 'Staff',
            'permission_type' => SellerRole::TYPE_ALL,
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/account/seller/users', [
            'name' => 'One',
            'email' => 'one-'.$suffix.'@example.test',
            'password' => 'Secret99',
            'role_id' => $roleId,
        ])->assertCreated();

        $blocked = $this->postJson('/api/v1/account/seller/users', [
            'name' => 'Two',
            'email' => 'two-'.$suffix.'@example.test',
            'password' => 'Secret99',
            'role_id' => $roleId,
        ]);
        $blocked->assertStatus(422);
        $this->assertSame('staff_limit', (string) ($blocked->json('error') ?? ''));

        MarketplaceSettings::save(['subscription_plans_enabled' => false]);
    }
}
