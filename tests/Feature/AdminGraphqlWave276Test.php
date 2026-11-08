<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Settings\ShopSetting;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminGraphqlWave276Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_store_setting_fields_reporting_stats_cart_clear(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $fields = $this->getJson('/api/v1/store-setting-fields');
        $fields->assertOk();
        $this->assertNotEmpty($fields->json());
        $code = (string) ($fields->json('0.code') ?? '');
        $this->assertNotSame('', $code);

        $one = $this->getJson('/api/v1/store/store-setting-fields/'.$code);
        $one->assertOk();
        $this->assertSame($code, (string) $one->json('code'));

        $theme = $this->getJson('/api/v1/home-modules');
        $theme->assertOk();
        $this->assertIsArray($theme->json());

        $admin = Admin::query()->firstOrFail();

        $sales = $this->actingAs($admin, 'admin')->getJson('/api/v1/metrics/sales/stats');
        $sales->assertOk();
        $customers = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/metrics/customers/stats');
        $customers->assertOk();
        $products = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/metrics/products/stats');
        $products->assertOk();

        $customer = Customer::query()->create([
            'name' => 'Wave276 Shopper',
            'email' => 'w276@example.com',
            'password' => Hash::make('Password1!'),
            'is_enabled' => true,
        ]);
        $login = $this->postJson('/api/v1/customer/login', [
            'email' => 'w276@example.com',
            'password' => 'Password1!',
        ]);
        $login->assertCreated();
        $headers = ['Authorization' => 'Bearer '.(string) $login->json('token')];

        $product = Product::query()->where('is_enabled', true)->where('type', 'simple')->firstOrFail();
        $this->withHeaders($headers)
            ->postJson('/api/v1/customer/cart/add/'.$product->id, ['quantity' => 1])
            ->assertOk();

        $clear = $this->withHeaders($headers)->deleteJson('/api/v1/customer/cart/remove');
        $clear->assertOk();
        $this->assertTrue((bool) $clear->json('success'));
        $this->assertSame(0, (int) ($clear->json('itemsCount') ?? -1));
    }
}
