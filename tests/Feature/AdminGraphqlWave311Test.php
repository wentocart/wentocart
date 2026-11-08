<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Models\Admin;

class AdminGraphqlWave311Test extends MarketplaceTestCase
{
    public function test_rest_admin_marketplace_assign_product_rest(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave311 Seller',
            'email' => 'w311-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w311-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave311 Shop '.$suffix,
            'shop_slug' => 'w311-shop-'.$suffix,
            'profile_name' => 'Wave311 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0311',
            'email' => 'w311-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0311',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave311 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w311-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $catalog = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/products', [
            'sku' => 'w311-'.$suffix,
            'name' => 'Wave311 Product '.$suffix,
            'price' => 22,
            'type' => 'simple',
            'stock_qty' => 5,
            'status' => 1,
        ]);
        $catalog->assertCreated();
        $productId = (int) $catalog->json('data.id');

        $assign = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/assign-product',
            ['product_id' => $productId, 'approve' => true]
        );
        $assign->assertCreated();
        $this->assertSame($productId, (int) ($assign->json('data.productId') ?? 0));
        $this->assertSame(SellerProduct::STATUS_APPROVED, (string) ($assign->json('data.approvalStatus') ?? ''));
        $this->assertSame((int) $seller->id, (int) Product::query()->find($productId)?->seller_id);
        $this->assertNotNull(SellerProduct::query()
            ->where('seller_id', $seller->id)
            ->where('product_id', $productId)
            ->first());
    }
}
