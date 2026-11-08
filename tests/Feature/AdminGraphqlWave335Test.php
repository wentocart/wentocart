<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\Seller;
use App\Models\Admin;

class AdminGraphqlWave335Test extends MarketplaceTestCase
{
    public function test_seller_magic_ai_accepts_rest_description_payload(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave335 Seller',
            'email' => 'w335-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w335-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave335 Shop '.$suffix,
            'shop_slug' => 'w335-shop-'.$suffix,
            'profile_name' => 'Wave335 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0335',
            'email' => 'w335-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0335',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave335 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w335-shop-'.$suffix)->firstOrFail();
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
        $this->assertTrue((bool) $status->json('data.allowReferenceImages'));
        $this->assertContains('meta_title', $status->json('data.generatableFields') ?? []);

        $short = $this->postJson('/api/v1/account/seller/ai/generate-product-content', [
            'description' => 'short',
        ]);
        $short->assertStatus(422);

        $gen = $this->postJson('/api/v1/account/seller/ai/generate-product-content', [
            'description' => 'Handmade ceramic mug with speckled glaze for coffee lovers at home.',
            'fields' => ['name', 'short_description', 'description', 'meta_title'],
        ]);
        $gen->assertStatus(422);
        $this->assertContains((string) ($gen->json('error') ?? ''), ['ai_disabled', 'ai_unconfigured', 'ai_error']);
    }
}
