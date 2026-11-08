<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Models\Admin;

class AdminGraphqlWave348Test extends MarketplaceTestCase
{
    public function test_two_sellers_can_offer_same_catalog_product(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        MarketplaceSettings::save([
            'seller_can_assign_product' => true,
            'auto_approve_products' => true,
            'subscription_plans_enabled' => false,
        ]);

        $platform = Product::query()->create([
            'type' => 'simple',
            'sku' => 'MULTI-'.$suffix,
            'price' => 30,
            'stock_qty' => 100,
            'is_enabled' => true,
            'seller_id' => null,
        ]);

        $sellers = [];
        foreach (['a', 'b'] as $i => $tag) {
            $this->flushSession();
            auth('customer')->logout();
            auth('admin')->logout();
            $this->postJson('/api/v1/auth/register', [
                'name' => 'Wave348 '.$tag,
                'email' => 'w348'.$tag.'-'.$suffix.'@example.test',
                'password' => 'Password123',
                'password_confirmation' => 'Password123',
            ])->assertOk();
            $owner = Customer::query()->where('email', 'w348'.$tag.'-'.$suffix.'@example.test')->firstOrFail();
            $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
                'shop_name' => 'Wave348 '.$tag.' '.$suffix,
                'shop_slug' => 'w348-'.$tag.'-'.$suffix,
                'profile_name' => 'Wave348 '.$tag,
                'first_name' => 'Wave',
                'last_name' => $tag,
                'phone' => '555-0348'.$i,
                'email' => 'w348'.$tag.'-'.$suffix.'@example.test',
                'contact_name' => 'Wave '.$tag,
                'contact_phone' => '555-0348'.$i,
                'address_line1' => '1 Test St',
                'city' => 'Austin',
                'postal_code' => '78701',
                'country_code' => 'US',
                'description' => 'Wave348 '.$tag,
            ])->assertSuccessful();
            $seller = Seller::query()->where('shop_slug', 'w348-'.$tag.'-'.$suffix)->firstOrFail();
            $this->flushSession();
            auth('customer')->logout();
            $this->actingAs($admin, 'admin')->postJson(
                '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
                ['action' => 'approve', 'commission_rate' => 10]
            )->assertOk();
            $sellers[] = [$owner, $seller, 20 + $i];
        }

        foreach ($sellers as [$owner, $seller, $price]) {
            $this->flushSession();
            auth('admin')->logout();
            $this->actingAs($owner, 'customer');
            $res = $this->postJson('/api/v1/account/seller/catalog-offers', [
                'product_id' => $platform->id,
                'price' => $price,
                'stock_qty' => 3,
                'condition' => 'new',
                'description' => 'Offer from '.$seller->shop_slug,
            ]);
            $res->assertCreated();
            $this->assertTrue((bool) $res->json('data.isCatalogOffer'));
        }

        $this->assertNull(Product::query()->find($platform->id)?->seller_id);
        $this->assertSame(2, SellerProduct::query()->where('product_id', $platform->id)->where('is_catalog_offer', true)->count());

        $offers = $this->getJson('/api/v1/catalog/products/'.$platform->id.'/seller-offers');
        $offers->assertOk();
        $this->assertSame(2, (int) ($offers->json('meta.total') ?? 0));
        $this->assertSame(20.0, (float) ($offers->json('data.0.price') ?? 0));
    }
}
