<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\MarketplaceSubscriptionPlanSchema;
use App\Domain\Marketplace\Models\MarketplaceSubscriptionPlan;
use App\Domain\Marketplace\Models\Seller;
use App\Models\Admin;

class AdminGraphqlWave320Test extends MarketplaceTestCase
{
    public function test_subscription_plan_product_limit_blocks_extra_products(): void
    {
        $this->seed();
        MarketplaceSubscriptionPlanSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        MarketplaceSettings::save(['subscription_plans_enabled' => true, 'auto_approve_products' => true]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave320 Seller',
            'email' => 'w320-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w320-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave320 Shop '.$suffix,
            'shop_slug' => 'w320-shop-'.$suffix,
            'profile_name' => 'Wave320 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0320',
            'email' => 'w320-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0320',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave320 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w320-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $plan = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketplace/subscription-plans', [
            'name' => 'Starter '.$suffix,
            'code' => 'starter-'.$suffix,
            'price' => 0,
            'billing_period' => MarketplaceSubscriptionPlan::PERIOD_MONTH,
            'product_limit' => 1,
            'allowed_product_types' => ['simple'],
            'is_enabled' => true,
        ])->assertCreated();
        $planId = (int) $plan->json('data.id');
        $this->assertSame(['simple'], $plan->json('data.allowedProductTypes'));
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/subscription-plans/'.$planId.'/assign',
            ['seller_id' => $seller->id]
        )->assertCreated();

        $this->flushSession();
        auth('admin')->logout();
        $this->actingAs($owner, 'customer');

        $first = $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Only One '.$suffix,
            'sku' => 'w320-a-'.$suffix,
            'price' => 12.5,
            'stock_qty' => 3,
            'type' => 'simple',
            'is_enabled' => true,
        ]);
        $first->assertCreated();
        $this->assertSame(1, Product::query()->where('seller_id', $seller->id)->count());

        $blocked = $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Too Many '.$suffix,
            'sku' => 'w320-b-'.$suffix,
            'price' => 9.99,
            'stock_qty' => 1,
            'type' => 'simple',
            'is_enabled' => true,
        ]);
        $blocked->assertStatus(422);
        $this->assertStringContainsString('max limit', (string) ($blocked->json('message') ?? json_encode($blocked->json('errors') ?? [])));

        MarketplaceSettings::save(['subscription_plans_enabled' => false]);
    }
}
