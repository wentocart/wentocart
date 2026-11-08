<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Models\Admin;

class AdminGraphqlWave339Test extends MarketplaceTestCase
{
    public function test_seller_sell_as_yours_catalog_offer(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        MarketplaceSettings::save([
            'seller_can_assign_product' => true,
            'auto_approve_products' => true,
            'subscription_plans_enabled' => false,
        ]);

        $platform = Product::query()->whereNull('seller_id')->first();
        if (! $platform) {
            $platform = Product::query()->create([
                'type' => 'simple',
                'sku' => 'PLAT-'.$suffix,
                'price' => 20,
                'stock_qty' => 50,
                'is_enabled' => true,
            ]);
        }

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave339 Seller',
            'email' => 'w339-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w339-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave339 Shop '.$suffix,
            'shop_slug' => 'w339-shop-'.$suffix,
            'profile_name' => 'Wave339 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0339',
            'email' => 'w339-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0339',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave339 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w339-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $settings = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketplace/settings');
        $settings->assertOk();
        $this->assertTrue((bool) $settings->json('data.sellerCanAssignProduct'));

        $this->flushSession();
        auth('admin')->logout();
        $this->actingAs($owner, 'customer');
        $offer = $this->postJson('/api/v1/account/seller/catalog-offers', [
            'product_id' => $platform->id,
            'price' => 18.5,
            'stock_qty' => 4,
            'condition' => 'new',
            'description' => 'My offer '.$suffix,
        ]);
        $offer->assertCreated();
        $this->assertSame((int) $platform->id, (int) ($offer->json('data.productId') ?? 0));
        $this->assertSame(18.5, (float) ($offer->json('data.price') ?? 0));
        // Catalog ownership stays on the platform product so multiple sellers can offer it.
        $this->assertTrue((bool) ($offer->json('data.isCatalogOffer') ?? false));
        $this->assertNotSame((int) $seller->id, (int) (Product::query()->find($platform->id)?->seller_id ?? 0));

        $dup = $this->postJson('/api/v1/account/seller/catalog-offers', [
            'product_id' => $platform->id,
            'price' => 17,
        ]);
        $dup->assertStatus(422);
        $this->assertSame('already_selling', (string) ($dup->json('error') ?? ''));
    }
}
