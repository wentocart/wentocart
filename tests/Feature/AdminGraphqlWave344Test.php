<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\MarketplaceSubscriptionPlanSchema;
use App\Domain\Marketplace\Models\MarketplaceSellerSubscription;
use App\Domain\Marketplace\Models\MarketplaceSubscriptionPlan;
use App\Domain\Marketplace\Models\Seller;
use App\Models\Admin;

class AdminGraphqlWave344Test extends MarketplaceTestCase
{
    public function test_paypal_plan_purchase_stays_pending_until_confirm(): void
    {
        $this->seed();
        MarketplaceSubscriptionPlanSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        MarketplaceSettings::save(['subscription_plans_enabled' => true]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave344 Seller',
            'email' => 'w344-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w344-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave344 Shop '.$suffix,
            'shop_slug' => 'w344-shop-'.$suffix,
            'profile_name' => 'Wave344 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0344',
            'email' => 'w344-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0344',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave344 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w344-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $paid = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketplace/subscription-plans', [
            'name' => 'PayPal Plan '.$suffix,
            'code' => 'pp-'.$suffix,
            'price' => 19.99,
            'billing_period' => MarketplaceSubscriptionPlan::PERIOD_MONTH,
            'is_enabled' => true,
            'duration_days' => 30,
        ])->assertCreated();
        $paidId = (int) $paid->json('data.id');

        $this->flushSession();
        auth('admin')->logout();
        $this->actingAs($owner, 'customer');

        $buy = $this->postJson('/api/v1/account/seller/plans/purchase', [
            'plan_id' => $paidId,
            'payment_method' => 'paypal',
            'accept_terms' => true,
        ]);
        $buy->assertCreated();
        $this->assertSame(MarketplaceSellerSubscription::STATUS_PENDING, (string) ($buy->json('data.status') ?? ''));
        $token = (string) ($buy->json('data.paypalToken') ?? '');
        $this->assertNotSame('', $token);
        $this->assertStringContainsString('token='.$token, (string) ($buy->json('data.approveUrl') ?? ''));
        $subId = (int) $buy->json('data.id');

        $confirm = $this->postJson('/api/v1/account/seller/plans/paypal/confirm', [
            'token' => $token,
        ]);
        $confirm->assertOk();
        $this->assertSame(MarketplaceSellerSubscription::STATUS_ACTIVE, (string) ($confirm->json('data.status') ?? ''));
        $this->assertSame(
            MarketplaceSellerSubscription::STATUS_ACTIVE,
            MarketplaceSellerSubscription::query()->find($subId)?->status
        );
        $this->assertNull(MarketplaceSellerSubscription::query()->find($subId)?->paypal_token);
    }
}
