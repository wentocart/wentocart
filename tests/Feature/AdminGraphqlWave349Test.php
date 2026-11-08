<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Models\Admin;

class AdminGraphqlWave349Test extends MarketplaceTestCase
{
    public function test_catalog_offer_draft_and_delete_leave_product_intact(): void
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
            'sku' => 'DRAFT-'.$suffix,
            'price' => 40,
            'stock_qty' => 10,
            'is_enabled' => true,
        ]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave349 Seller',
            'email' => 'w349-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w349-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave349 Shop '.$suffix,
            'shop_slug' => 'w349-shop-'.$suffix,
            'profile_name' => 'Wave349 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0349',
            'email' => 'w349-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0349',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave349 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w349-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $this->flushSession();
        auth('admin')->logout();
        $this->actingAs($owner, 'customer');

        $draft = $this->postJson('/api/v1/account/seller/catalog-offers', [
            'product_id' => $platform->id,
            'save_as_draft' => true,
            'description' => 'Draft only',
        ]);
        $draft->assertCreated();
        $this->assertTrue((bool) $draft->json('data.isDraft'));

        $public = $this->getJson('/api/v1/store/products/'.$platform->id.'/seller-offers');
        $public->assertOk();
        $this->assertSame(0, (int) ($public->json('meta.total') ?? -1));

        $this->deleteJson('/api/v1/account/seller/catalog-offers/'.$platform->id)->assertOk();
        $this->assertNotNull(Product::query()->find($platform->id));

        $live = $this->postJson('/api/v1/account/seller/catalog-offers', [
            'product_id' => $platform->id,
            'price' => 35,
            'stock_qty' => 2,
            'condition' => 'old',
            'description' => 'Used unit',
        ]);
        $live->assertCreated();
        $this->assertSame('old', (string) ($live->json('data.condition') ?? ''));

        $public2 = $this->getJson('/api/v1/catalog/products/'.$platform->id.'/seller-offers');
        $public2->assertOk();
        $this->assertSame(1, (int) ($public2->json('meta.total') ?? 0));
        $this->assertSame('old', (string) ($public2->json('data.0.condition') ?? ''));
    }
}
