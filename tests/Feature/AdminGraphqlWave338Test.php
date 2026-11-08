<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Models\Admin;

class AdminGraphqlWave338Test extends MarketplaceTestCase
{
    public function test_seller_barcode_set_generate_and_print(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        MarketplaceSettings::save(['auto_approve_products' => true]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave338 Seller',
            'email' => 'w338-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w338-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave338 Shop '.$suffix,
            'shop_slug' => 'w338-shop-'.$suffix,
            'profile_name' => 'Wave338 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0338',
            'email' => 'w338-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0338',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave338 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w338-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $this->flushSession();
        auth('admin')->logout();
        $this->actingAs($owner, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Barcoded '.$suffix,
            'sku' => 'w338-'.$suffix,
            'price' => 11,
            'stock_qty' => 5,
            'type' => 'simple',
            'is_enabled' => true,
        ])->assertCreated();
        $product = Product::query()->where('seller_id', $seller->id)->firstOrFail();

        $set = $this->putJson('/api/v1/account/seller/products/'.$product->id.'/barcode', [
            'barcode' => 'BC'.$suffix,
        ]);
        $set->assertOk();
        $this->assertSame('BC'.$suffix, (string) ($set->json('data.barcode') ?? ''));

        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Auto BC '.$suffix,
            'sku' => 'w338b-'.$suffix,
            'price' => 9,
            'stock_qty' => 2,
            'type' => 'simple',
            'is_enabled' => true,
        ])->assertCreated();
        $productB = Product::query()->where('seller_id', $seller->id)->where('sku', 'LIKE', '%w338b%')->firstOrFail();

        $gen = $this->postJson('/api/v1/account/seller/products/barcodes/generate', [
            'product_ids' => [$product->id, $productB->id],
            'format' => 'code128',
        ]);
        $gen->assertOk();
        $this->assertSame(1, (int) ($gen->json('data.created') ?? 0));
        $this->assertSame(1, (int) ($gen->json('data.skipped') ?? 0));
        $this->assertNotEmpty(Product::query()->find($productB->id)?->barcode);

        $print = $this->postJson('/api/v1/account/seller/products/barcodes/print', [
            'product_ids' => [$product->id, $productB->id],
        ]);
        $print->assertOk();
        $this->assertStringContainsString('BC'.$suffix, $print->getContent());
    }
}
