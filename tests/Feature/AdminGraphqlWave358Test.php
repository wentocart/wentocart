<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\MarketplaceSubscriptionPlanSchema;
use App\Domain\Marketplace\Models\MarketplaceSellerSubscription;
use App\Domain\Marketplace\Models\MarketplaceSubscriptionPlan;
use App\Domain\Marketplace\Models\Seller;
use App\Models\Admin;

class AdminGraphqlWave358Test extends MarketplaceTestCase
{
    public function test_stripe_and_razorpay_plan_checkout_confirm(): void
    {
        $this->seed();
        MarketplaceSubscriptionPlanSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));
        MarketplaceSettings::save(['subscription_plans_enabled' => true]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave358 Seller',
            'email' => 'w358-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w358-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave358 Shop '.$suffix,
            'shop_slug' => 'w358-shop-'.$suffix,
            'profile_name' => 'Wave358 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0358',
            'email' => 'w358-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0358',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave358 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w358-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $paid = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketplace/subscription-plans', [
            'name' => 'Stripe Plan '.$suffix,
            'code' => 'st-'.$suffix,
            'price' => 25,
            'billing_period' => MarketplaceSubscriptionPlan::PERIOD_MONTH,
            'is_enabled' => true,
            'duration_days' => 30,
        ])->assertCreated();
        $paidId = (int) $paid->json('data.id');

        $this->flushSession();
        auth('admin')->logout();
        $this->actingAs($owner, 'customer');

        $methods = $this->getJson('/api/v1/account/seller/plans');
        $codes = collect($methods->json('meta.paymentMethods') ?? [])->pluck('code')->all();
        $this->assertContains('stripe', $codes);
        $this->assertContains('razorpay', $codes);
        $this->assertContains('payu', $codes);

        $buy = $this->postJson('/api/v1/account/seller/plans/purchase', [
            'plan_id' => $paidId,
            'payment_method' => 'stripe',
            'accept_terms' => true,
        ]);
        $buy->assertCreated();
        $this->assertSame('stripe', (string) ($buy->json('data.gateway') ?? ''));
        $token = (string) ($buy->json('data.paymentToken') ?? '');
        $this->assertStringStartsWith('ST-', $token);

        $confirm = $this->postJson('/api/v1/account/seller/plans/stripe/confirm', ['token' => $token]);
        $confirm->assertOk();
        $this->assertSame(
            MarketplaceSellerSubscription::STATUS_ACTIVE,
            MarketplaceSellerSubscription::query()->find((int) $buy->json('data.id'))?->status
        );
    }
}
