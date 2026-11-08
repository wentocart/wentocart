<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\Seller;
use App\Models\Admin;

class AdminGraphqlWave319Test extends MarketplaceTestCase
{
    public function test_seller_magic_ai_status_and_disabled_generate(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave319 Seller',
            'email' => 'w319-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w319-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave319 Shop '.$suffix,
            'shop_slug' => 'w319-shop-'.$suffix,
            'profile_name' => 'Wave319 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0319',
            'email' => 'w319-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0319',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave319 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w319-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $this->flushSession();
        auth('admin')->logout();
        $this->actingAs($owner, 'customer');

        $status = $this->getJson('/api/v1/account/seller/ai/status');
        $status->assertOk();
        $this->assertArrayHasKey('enabled', $status->json('data'));

        // Without AI configured, generation must fail cleanly (API Magic AI gate).
        $gen = $this->postJson('/api/v1/account/seller/ai/generate-product-content', [
            'name' => 'Ceramic Mug '.$suffix,
            'keywords' => 'coffee, handmade',
            'tone' => 'friendly',
        ]);
        $gen->assertStatus(422);
        $this->assertContains((string) ($gen->json('error') ?? ''), ['ai_disabled', 'ai_unconfigured', 'ai_error']);
    }
}
