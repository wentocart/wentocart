<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Models\Admin;

class AdminGraphqlWave363Test extends MarketplaceTestCase
{
    public function test_low_stock_threshold_and_stepped_form_settings(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $settings = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/marketplace/settings', [
            'lowStockThreshold' => 5,
            'enableSteppedProductForm' => true,
            'steppedFormNavigation' => 'rail',
            'showProgressBar' => true,
        ]);
        $settings->assertOk();
        $this->assertSame(5, (int) ($settings->json('data.lowStockThreshold') ?? 0));
        $this->assertTrue((bool) $settings->json('data.enableSteppedProductForm'));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave363 Seller',
            'email' => 'w363-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w363-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave363 Shop '.$suffix,
            'shop_slug' => 'w363-shop-'.$suffix,
            'profile_name' => 'Wave363 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0363',
            'email' => 'w363-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0363',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave363 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w363-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        Product::query()->create([
            'type' => 'simple',
            'sku' => 'LOW-'.$suffix,
            'price' => 9,
            'stock_qty' => 3,
            'is_enabled' => true,
            'seller_id' => $seller->id,
        ]);
        Product::query()->create([
            'type' => 'simple',
            'sku' => 'OK-'.$suffix,
            'price' => 9,
            'stock_qty' => 20,
            'is_enabled' => true,
            'seller_id' => $seller->id,
        ]);

        $this->flushSession();
        auth('admin')->logout();
        $dash = $this->actingAs($owner, 'customer')->getJson('/api/v1/account/seller/dashboard');
        $dash->assertOk();
        $this->assertSame(1, (int) ($dash->json('stats.lowStockProducts') ?? 0));
        $this->assertSame(5, (int) ($dash->json('stats.lowStockThreshold') ?? 0));
        $this->assertTrue((bool) $dash->json('productForm.enableSteppedProductForm'));
        $this->assertSame('rail', (string) ($dash->json('productForm.steppedFormNavigation') ?? ''));
    }
}
