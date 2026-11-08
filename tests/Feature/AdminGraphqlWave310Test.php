<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\SellerFeaturedSchema;
use App\Models\Admin;

class AdminGraphqlWave310Test extends MarketplaceTestCase
{
    public function test_rest_marketplace_featured_sellers_rest(): void
    {
        $this->seed();
        SellerFeaturedSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave310 Seller',
            'email' => 'w310-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w310-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave310 Shop '.$suffix,
            'shop_slug' => 'w310-shop-'.$suffix,
            'profile_name' => 'Wave310 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0310',
            'email' => 'w310-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0310',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave310 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w310-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $feat = $this->actingAs($admin, 'admin')->putJson(
            '/api/v1/console/marketplace/featured-sellers/'.$seller->id,
            ['isFeatured' => true, 'featuredPosition' => 1]
        );
        $feat->assertOk();
        $this->assertSame(1, (int) ($feat->json('data.isFeatured') ?? 0));

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketplace/featured-sellers');
        $list->assertOk();
        $this->assertGreaterThanOrEqual(1, (int) ($list->json('meta.total') ?? 0));

        $reorder = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/featured-sellers/reorder',
            ['order' => [$seller->id]]
        );
        $reorder->assertOk();

        $shop = $this->getJson('/api/v1/catalog/sellers/featured');
        $shop->assertOk();
        $slugs = collect($shop->json('data') ?? [])->pluck('shopSlug')->all();
        $this->assertContains('w310-shop-'.$suffix, $slugs);
    }
}
