<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\MarketplaceSubscriptionPlanSchema;
use App\Domain\Marketplace\Models\MarketplaceSellerSubscription;
use App\Domain\Marketplace\Models\MarketplaceSubscriptionPlan;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Services\MarketplaceSubscriptionTickService;
use App\Domain\Settings\ShopMailer;
use App\Models\Admin;
use Mockery;

class AdminGraphqlWave347Test extends MarketplaceTestCase
{
    public function test_subscription_lifecycle_emails_on_expire_and_cancel(): void
    {
        $this->seed();
        MarketplaceSubscriptionPlanSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        MarketplaceSettings::save(['subscription_plans_enabled' => true]);

        $mailer = Mockery::mock(ShopMailer::class)->makePartial();
        $events = [];
        $mailer->shouldReceive('sendEvent')->andReturnUsing(function ($event, $vars, $to) use (&$events) {
            $events[] = (string) $event;

            return true;
        });
        $this->app->instance(ShopMailer::class, $mailer);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave347 Seller',
            'email' => 'w347-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w347-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave347 Shop '.$suffix,
            'shop_slug' => 'w347-shop-'.$suffix,
            'profile_name' => 'Wave347 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0347',
            'email' => 'w347-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0347',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave347 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w347-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $plan = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketplace/subscription-plans', [
            'name' => 'Mail Plan '.$suffix,
            'code' => 'mail-'.$suffix,
            'price' => 15,
            'billing_period' => MarketplaceSubscriptionPlan::PERIOD_MONTH,
            'is_enabled' => true,
            'duration_days' => 30,
        ])->assertCreated();
        $planId = (int) $plan->json('data.id');

        $sub = MarketplaceSellerSubscription::query()->create([
            'seller_id' => $seller->id,
            'plan_id' => $planId,
            'status' => MarketplaceSellerSubscription::STATUS_ACTIVE,
            'payment_method' => 'money_transfer',
            'price' => 15,
            'starts_at' => now()->subDays(40),
            'ends_at' => now()->subMinute(),
        ]);

        $tick = app(MarketplaceSubscriptionTickService::class)->run(false);
        $this->assertGreaterThanOrEqual(1, (int) ($tick['expired'] ?? 0));
        $this->assertContains('marketplace.subscription.expired', $events);

        $pending = MarketplaceSellerSubscription::query()->create([
            'seller_id' => $seller->id,
            'plan_id' => $planId,
            'status' => MarketplaceSellerSubscription::STATUS_PENDING,
            'payment_method' => 'money_transfer',
            'price' => 15,
        ]);
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/subscriptions/'.$pending->id.'/cancel'
        )->assertOk();
        $this->assertContains('marketplace.subscription.cancelled', $events);
    }
}
