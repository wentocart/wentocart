<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\SellerPaymentRequestSchema;
use App\Models\Admin;

class AdminGraphqlWave337Test extends MarketplaceTestCase
{
    public function test_seller_transactions_rest_returns_rest_cards(): void
    {
        $this->seed();
        SellerPaymentRequestSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave337 Seller',
            'email' => 'w337-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w337-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave337 Shop '.$suffix,
            'shop_slug' => 'w337-shop-'.$suffix,
            'profile_name' => 'Wave337 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0337',
            'email' => 'w337-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0337',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave337 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w337-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $this->flushSession();
        auth('admin')->logout();
        $this->actingAs($owner, 'customer');
        $tx = $this->getJson('/api/v1/account/seller/transactions');
        $tx->assertOk();
        $this->assertArrayHasKey('cards', $tx->json());
        $this->assertArrayHasKey('totalSaleSeller', $tx->json('cards'));
        $this->assertArrayHasKey('totalCommissionAdmin', $tx->json('cards'));
        $this->assertArrayHasKey('totalPayout', $tx->json('cards'));
        $this->assertArrayHasKey('remainingPayout', $tx->json('cards'));
        $this->assertIsArray($tx->json('transactions'));
    }
}
