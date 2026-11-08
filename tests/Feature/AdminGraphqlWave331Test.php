<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Models\Admin;

class AdminGraphqlWave331Test extends MarketplaceTestCase
{
    public function test_approval_required_off_auto_approves_and_show_publicly_gates_shops(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        MarketplaceSettings::save(['approval_required' => false, 'show_publicly' => true]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave331 Seller',
            'email' => 'w331-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w331-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave331 Shop '.$suffix,
            'shop_slug' => 'w331-shop-'.$suffix,
            'profile_name' => 'Wave331 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0331',
            'email' => 'w331-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0331',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave331 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w331-shop-'.$suffix)->firstOrFail();
        $this->assertSame(Seller::STATUS_APPROVED, $seller->status);

        $this->flushSession();
        auth('customer')->logout();
        $settings = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/marketplace/settings', [
            'showPublicly' => false,
            'approvalRequired' => true,
        ]);
        $settings->assertOk();
        $this->assertFalse((bool) $settings->json('data.showPublicly'));
        $this->assertTrue((bool) $settings->json('data.approvalRequired'));

        $this->flushSession();
        auth('admin')->logout();
        $this->getJson('/api/v1/catalog/seller/w331-shop-'.$suffix)->assertNotFound();
        $this->getJson('/api/v1/catalog/sellers/featured')->assertOk()->assertJson(['data' => []]);

        MarketplaceSettings::save(['approval_required' => true, 'show_publicly' => true]);
    }
}
