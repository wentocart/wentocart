<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\MarketplaceSubscriptionPlanSchema;
use App\Domain\Marketplace\Models\MarketplaceSellerSubscription;
use App\Domain\Marketplace\Models\MarketplaceSubscriptionPlan;
use App\Domain\Marketplace\Models\Seller;
use App\Models\Admin;

class AdminGraphqlWave318Test extends MarketplaceTestCase
{
    public function test_seller_plan_self_serve_purchase_and_admin_approve(): void
    {
        $this->seed();
        MarketplaceSubscriptionPlanSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        MarketplaceSettings::save(['subscription_plans_enabled' => true]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave318 Seller',
            'email' => 'w318-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w318-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave318 Shop '.$suffix,
            'shop_slug' => 'w318-shop-'.$suffix,
            'profile_name' => 'Wave318 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0318',
            'email' => 'w318-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0318',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave318 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w318-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $free = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketplace/subscription-plans', [
            'name' => 'Free '.$suffix,
            'code' => 'free-'.$suffix,
            'price' => 0,
            'billing_period' => MarketplaceSubscriptionPlan::PERIOD_MONTH,
            'is_enabled' => true,
        ])->assertCreated();
        $freeId = (int) $free->json('data.id');

        $paid = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketplace/subscription-plans', [
            'name' => 'Paid '.$suffix,
            'code' => 'paid-'.$suffix,
            'price' => 29.99,
            'billing_period' => MarketplaceSubscriptionPlan::PERIOD_MONTH,
            'is_enabled' => true,
        ])->assertCreated();
        $paidId = (int) $paid->json('data.id');

        $this->flushSession();
        auth('admin')->logout();
        $this->actingAs($owner, 'customer');

        $available = $this->getJson('/api/v1/account/seller/plans');
        $available->assertOk();
        $this->assertTrue((bool) $available->json('meta.enabled'));
        $this->assertGreaterThanOrEqual(2, count($available->json('data') ?? []));

        $buyFree = $this->postJson('/api/v1/account/seller/plans/purchase', [
            'plan_id' => $freeId,
            'payment_method' => 'free',
            'accept_terms' => true,
        ]);
        $buyFree->assertCreated();
        $this->assertSame(MarketplaceSellerSubscription::STATUS_ACTIVE, (string) ($buyFree->json('data.status') ?? ''));

        $buyPaid = $this->postJson('/api/v1/account/seller/plans/purchase', [
            'plan_id' => $paidId,
            'payment_method' => 'money_transfer',
            'accept_terms' => 1,
        ]);
        $buyPaid->assertCreated();
        $this->assertSame(MarketplaceSellerSubscription::STATUS_PENDING, (string) ($buyPaid->json('data.status') ?? ''));
        $pendingId = (int) $buyPaid->json('data.id');

        $history = $this->getJson('/api/v1/account/seller/plans/history');
        $history->assertOk();
        $this->assertGreaterThanOrEqual(2, (int) ($history->json('meta.total') ?? 0));

        $this->flushSession();
        auth('customer')->logout();
        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketplace/subscriptions?status=pending');
        $list->assertOk();
        $this->assertGreaterThanOrEqual(1, (int) ($list->json('meta.total') ?? 0));

        $approve = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/subscriptions/'.$pendingId.'/approve'
        );
        $approve->assertOk();
        $this->assertSame(MarketplaceSellerSubscription::STATUS_ACTIVE, MarketplaceSellerSubscription::query()->find($pendingId)?->status);

        MarketplaceSettings::save(['subscription_plans_enabled' => false]);
    }
}
