<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\MarketplaceSubscriptionPlanSchema;
use App\Domain\Marketplace\Models\Seller;
use App\Models\Admin;

class AdminGraphqlWave352Test extends MarketplaceTestCase
{
    public function test_seller_plans_expose_rest_payment_methods(): void
    {
        $this->seed();
        MarketplaceSubscriptionPlanSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));
        MarketplaceSettings::save(['subscription_plans_enabled' => true]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave352 Seller',
            'email' => 'w352-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w352-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave352 Shop '.$suffix,
            'shop_slug' => 'w352-shop-'.$suffix,
            'profile_name' => 'Wave352 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0352',
            'email' => 'w352-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0352',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave352 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w352-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $this->flushSession();
        auth('admin')->logout();
        $plans = $this->actingAs($owner, 'customer')->getJson('/api/v1/account/seller/plans');
        $plans->assertOk();
        $codes = collect($plans->json('meta.paymentMethods') ?? [])->pluck('code')->all();
        $this->assertContains('paypal', $codes);
        $this->assertContains('money_transfer', $codes);
        $this->assertNotContains('cod', $codes);
        $this->assertNotContains('cod', $codes);
    }
}
