<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Models\Admin;

class AdminGraphqlWave353Test extends MarketplaceTestCase
{
    public function test_bulk_catalog_offers_assign_many_products(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));
        MarketplaceSettings::save([
            'seller_can_assign_product' => true,
            'auto_approve_products' => true,
            'subscription_plans_enabled' => false,
        ]);

        $ids = [];
        foreach (range(1, 3) as $n) {
            $ids[] = Product::query()->create([
                'type' => 'simple',
                'sku' => 'BULK'.$n.'-'.$suffix,
                'price' => 10 + $n,
                'stock_qty' => 5,
                'is_enabled' => true,
            ])->id;
        }

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave353 Seller',
            'email' => 'w353-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w353-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave353 Shop '.$suffix,
            'shop_slug' => 'w353-shop-'.$suffix,
            'profile_name' => 'Wave353 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0353',
            'email' => 'w353-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0353',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave353 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w353-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $this->flushSession();
        auth('admin')->logout();
        $bulk = $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/catalog-offers/bulk', [
            'product_ids' => $ids,
            'price' => 12.5,
            'stock_qty' => 2,
            'condition' => 'new',
        ]);
        $bulk->assertOk();
        $this->assertSame(3, (int) ($bulk->json('data.created') ?? 0));
        $this->assertSame(3, SellerProduct::query()->where('seller_id', $seller->id)->where('is_catalog_offer', true)->count());

        $again = $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/catalog-offers/bulk', [
            'product_ids' => [$ids[0]],
            'price' => 11,
        ]);
        $again->assertOk();
        $this->assertSame(0, (int) ($again->json('data.created') ?? -1));
        $this->assertSame(1, (int) ($again->json('data.skipped') ?? 0));
    }
}
