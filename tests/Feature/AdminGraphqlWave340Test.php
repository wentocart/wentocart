<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Models\Admin;

class AdminGraphqlWave340Test extends MarketplaceTestCase
{
    public function test_seller_catalog_product_search_for_sell_as_yours(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        MarketplaceSettings::save(['seller_can_assign_product' => true]);

        $platform = Product::query()->whereNull('seller_id')->where('is_enabled', true)->first();
        $this->assertNotNull($platform);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave340 Seller',
            'email' => 'w340-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w340-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave340 Shop '.$suffix,
            'shop_slug' => 'w340-shop-'.$suffix,
            'profile_name' => 'Wave340 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0340',
            'email' => 'w340-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0340',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave340 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w340-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $this->flushSession();
        auth('admin')->logout();
        $this->actingAs($owner, 'customer');

        $short = $this->getJson('/api/v1/account/seller/catalog-products/search?q=ab');
        $short->assertStatus(422);

        $needle = substr((string) $platform->sku, 0, 3);
        if (strlen($needle) < 3) {
            $needle = (string) $platform->sku;
        }
        $search = $this->getJson('/api/v1/account/seller/catalog-products/search?q='.urlencode($needle));
        $search->assertOk();
        $this->assertIsArray($search->json('data'));
    }
}
