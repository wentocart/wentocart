<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\MarketplaceSubscriptionPlanSchema;
use App\Domain\Marketplace\Models\MarketplaceSellerSubscription;
use App\Domain\Marketplace\Models\MarketplaceSubscriptionPlan;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Services\MarketplaceSubscriptionTickService;
use App\Models\Admin;

class AdminGraphqlWave346Test extends MarketplaceTestCase
{
    public function test_paid_to_paid_plan_is_scheduled_until_current_ends(): void
    {
        $this->seed();
        MarketplaceSubscriptionPlanSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        MarketplaceSettings::save(['subscription_plans_enabled' => true]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave346 Seller',
            'email' => 'w346-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w346-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave346 Shop '.$suffix,
            'shop_slug' => 'w346-shop-'.$suffix,
            'profile_name' => 'Wave346 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0346',
            'email' => 'w346-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0346',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave346 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w346-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $planA = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketplace/subscription-plans', [
            'name' => 'Plan A '.$suffix,
            'code' => 'a-'.$suffix,
            'price' => 10,
            'billing_period' => MarketplaceSubscriptionPlan::PERIOD_MONTH,
            'is_enabled' => true,
            'duration_days' => 30,
        ])->assertCreated();
        $planB = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketplace/subscription-plans', [
            'name' => 'Plan B '.$suffix,
            'code' => 'b-'.$suffix,
            'price' => 20,
            'billing_period' => MarketplaceSubscriptionPlan::PERIOD_MONTH,
            'is_enabled' => true,
            'duration_days' => 30,
        ])->assertCreated();
        $aId = (int) $planA->json('data.id');
        $bId = (int) $planB->json('data.id');

        $assign = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/subscription-plans/'.$aId.'/assign',
            ['seller_id' => $seller->id]
        )->assertSuccessful();
        $activeId = (int) ($assign->json('data.id') ?? 0);
        if ($activeId < 1) {
            $active = MarketplaceSellerSubscription::query()->create([
                'seller_id' => $seller->id,
                'plan_id' => $aId,
                'status' => MarketplaceSellerSubscription::STATUS_ACTIVE,
                'payment_method' => 'money_transfer',
                'price' => 10,
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addDays(10),
            ]);
            $activeId = (int) $active->id;
        } else {
            MarketplaceSellerSubscription::query()->whereKey($activeId)->update([
                'ends_at' => now()->addDays(10),
                'price' => 10,
                'payment_method' => 'money_transfer',
            ]);
        }

        $this->flushSession();
        auth('admin')->logout();
        $this->actingAs($owner, 'customer');

        $buy = $this->postJson('/api/v1/account/seller/plans/purchase', [
            'plan_id' => $bId,
            'payment_method' => 'money_transfer',
            'accept_terms' => true,
        ])->assertCreated();
        $pendingId = (int) $buy->json('data.id');

        $this->flushSession();
        auth('customer')->logout();
        $approve = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/subscriptions/'.$pendingId.'/approve'
        );
        $approve->assertOk();
        $this->assertTrue((bool) $approve->json('data.scheduled'));
        $this->assertSame(
            MarketplaceSellerSubscription::STATUS_SCHEDULED,
            MarketplaceSellerSubscription::query()->find($pendingId)?->status
        );
        $this->assertSame(
            MarketplaceSellerSubscription::STATUS_ACTIVE,
            MarketplaceSellerSubscription::query()->find($activeId)?->status
        );

        MarketplaceSellerSubscription::query()->whereKey($pendingId)->update([
            'starts_at' => now()->subMinute(),
        ]);
        $tick = app(MarketplaceSubscriptionTickService::class)->run(false);
        $this->assertGreaterThanOrEqual(1, (int) ($tick['promoted'] ?? 0));
        $this->assertSame(
            MarketplaceSellerSubscription::STATUS_ACTIVE,
            MarketplaceSellerSubscription::query()->find($pendingId)?->status
        );
    }
}
