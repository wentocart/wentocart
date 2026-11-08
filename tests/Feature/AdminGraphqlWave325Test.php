<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\MarketplaceSubscriptionPlanSchema;
use App\Domain\Marketplace\Models\MarketplaceSubscriptionPlan;
use App\Domain\Marketplace\Models\Seller;
use App\Models\Admin;

class AdminGraphqlWave325Test extends MarketplaceTestCase
{
    public function test_plan_permissions_gate_seller_panel_routes(): void
    {
        $this->seed();
        MarketplaceSubscriptionPlanSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        MarketplaceSettings::save(['subscription_plans_enabled' => true]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave325 Seller',
            'email' => 'w325-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w325-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave325 Shop '.$suffix,
            'shop_slug' => 'w325-shop-'.$suffix,
            'profile_name' => 'Wave325 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0325',
            'email' => 'w325-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0325',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave325 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w325-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $plan = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketplace/subscription-plans', [
            'name' => 'Catalog Only '.$suffix,
            'code' => 'cat-only-'.$suffix,
            'price' => 0,
            'billing_period' => MarketplaceSubscriptionPlan::PERIOD_MONTH,
            'permissions' => ['catalog', 'dashboard'],
            'commission_value' => 12.5,
            'is_featured' => true,
            'is_enabled' => true,
        ])->assertCreated();
        $this->assertSame(['catalog', 'dashboard'], $plan->json('data.permissions'));
        $this->assertSame(12.5, (float) $plan->json('data.commissionValue'));
        $this->assertSame(1, (int) $plan->json('data.isFeatured'));

        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/subscription-plans/'.(int) $plan->json('data.id').'/assign',
            ['seller_id' => $seller->id]
        )->assertCreated();

        $this->flushSession();
        auth('admin')->logout();
        $this->actingAs($owner, 'customer');

        // Owner hits staff panel routes with session — middleware uses customer seller + plan ACL.
        $this->getJson('/api/v1/seller/panel/products')->assertOk();
        $this->getJson('/api/v1/seller/panel/orders')->assertForbidden();
        $this->getJson('/api/v1/seller/panel/dashboard')->assertOk();

        MarketplaceSettings::save(['subscription_plans_enabled' => false]);
    }
}
