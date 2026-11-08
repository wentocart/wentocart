<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\ProductFlag;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\ProductFlagSchema;
use App\Models\Admin;

class AdminGraphqlWave309Test extends MarketplaceTestCase
{
    public function test_rest_marketplace_product_flags_rest(): void
    {
        $this->seed();
        ProductFlagSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave309 Seller',
            'email' => 'w309-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w309-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave309 Shop '.$suffix,
            'shop_slug' => 'w309-shop-'.$suffix,
            'profile_name' => 'Wave309 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0309',
            'email' => 'w309-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0309',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave309 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w309-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $catalog = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/products', [
            'sku' => 'w309-'.$suffix,
            'name' => 'Wave309 Product '.$suffix,
            'price' => 12,
            'type' => 'simple',
            'stock_qty' => 3,
            'status' => 1,
        ]);
        $catalog->assertCreated();
        $productId = (int) $catalog->json('data.id');
        Product::query()->whereKey($productId)->update(['seller_id' => $seller->id]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave309 Buyer',
            'email' => 'w309b-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $buyer = Customer::query()->where('email', 'w309b-'.$suffix.'@example.test')->firstOrFail();

        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($buyer, 'customer')->postJson(
            '/api/v1/catalog/products/'.$productId.'/flag',
            ['reason' => 'Counterfeit', 'body' => 'Looks fake']
        )->assertCreated();

        $this->flushSession();
        auth('customer')->logout();
        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketplace/product-flags?status=pending');
        $list->assertOk();
        $this->assertGreaterThanOrEqual(1, (int) ($list->json('meta.total') ?? 0));
        $flagId = (int) ($list->json('data.0.id') ?? 0);

        $mod = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/product-flags/'.$flagId.'/moderate',
            ['action' => 'dismiss']
        );
        $mod->assertOk();
        $this->assertSame(ProductFlag::STATUS_DISMISSED, ProductFlag::query()->find($flagId)?->status);
    }
}
