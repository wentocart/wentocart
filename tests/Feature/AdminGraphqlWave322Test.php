<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\MarketplaceSubscriptionPlanSchema;
use App\Domain\Marketplace\Models\MarketplaceSubscriptionPlan;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Models\Admin;

class AdminGraphqlWave322Test extends MarketplaceTestCase
{
    public function test_plan_assign_disables_excess_products_over_cap(): void
    {
        $this->seed();
        MarketplaceSubscriptionPlanSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        MarketplaceSettings::save(['subscription_plans_enabled' => false, 'auto_approve_products' => true]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave322 Seller',
            'email' => 'w322-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w322-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave322 Shop '.$suffix,
            'shop_slug' => 'w322-shop-'.$suffix,
            'profile_name' => 'Wave322 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0322',
            'email' => 'w322-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0322',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave322 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w322-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $this->flushSession();
        auth('admin')->logout();
        $this->actingAs($owner, 'customer');
        foreach (['a', 'b', 'c'] as $i) {
            $this->postJson('/api/v1/account/seller/products', [
                'name' => 'Prod '.$i.' '.$suffix,
                'sku' => 'w322-'.$i.'-'.$suffix,
                'price' => 10,
                'stock_qty' => 2,
                'type' => 'simple',
                'is_enabled' => true,
            ])->assertCreated();
        }
        $this->assertSame(3, Product::query()->where('seller_id', $seller->id)->count());

        $this->flushSession();
        auth('customer')->logout();
        MarketplaceSettings::save(['subscription_plans_enabled' => true]);
        $plan = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketplace/subscription-plans', [
            'name' => 'Tiny Cap '.$suffix,
            'code' => 'tiny-cap-'.$suffix,
            'price' => 0,
            'billing_period' => MarketplaceSubscriptionPlan::PERIOD_MONTH,
            'product_limit' => 1,
            'is_enabled' => true,
        ])->assertCreated();
        $planId = (int) $plan->json('data.id');

        $assign = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/subscription-plans/'.$planId.'/assign',
            ['seller_id' => $seller->id]
        );
        $assign->assertCreated();
        $this->assertSame(2, (int) ($assign->json('data.productsDisabled') ?? 0));

        $pending = SellerProduct::query()
            ->where('seller_id', $seller->id)
            ->where('approval_status', SellerProduct::STATUS_PENDING)
            ->count();
        $this->assertSame(2, $pending);
        $enabled = Product::query()->where('seller_id', $seller->id)->where('is_enabled', true)->count();
        $this->assertSame(1, $enabled);

        MarketplaceSettings::save(['subscription_plans_enabled' => false]);
    }
}
