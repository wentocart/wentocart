<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Promo\Models\DiscountRule;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave263Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_apply_and_remove_coupon_paths(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $product = Product::query()->where('sku', 'DEMO-001')->first()
            ?? Product::query()->where('is_enabled', true)->where('type', 'simple')->firstOrFail();

        $code = 'W263'.strtoupper(substr(uniqid(), -5));
        DiscountRule::query()->create([
            'name' => 'Wave263 Coupon',
            'apply_as' => 'coupon',
            'code' => $code,
            'action' => 'percent',
            'value' => 10,
            'priority' => 10,
            'stop_further' => false,
            'used_count' => 0,
            'is_enabled' => true,
        ]);

        $created = $this->postJson('/api/v1/store/cart-tokens', []);
        $created->assertCreated();
        $headers = ['Authorization' => 'Bearer '.(string) $created->json('cartToken')];

        $this->withHeaders($headers)->postJson('/api/v1/store/add-product-in-cart', [
            'productId' => (int) $product->id,
            'quantity' => 2,
        ])->assertOk();

        $missing = $this->withHeaders($headers)->postJson('/api/v1/store/apply-coupon', []);
        $missing->assertStatus(400);

        $apply = $this->withHeaders($headers)->postJson('/api/v1/store/apply-coupon', [
            'couponCode' => $code,
        ]);
        $apply->assertOk();
        $this->assertTrue((bool) $apply->json('success'));
        $this->assertSame($code, (string) $apply->json('couponCode'));
        $this->assertSame('Coupon applied successfully.', (string) $apply->json('message'));
        $this->assertArrayHasKey('discount', $apply->json() ?? []);
        $this->assertArrayHasKey('total', $apply->json() ?? []);

        $remove = $this->withHeaders($headers)->deleteJson('/api/v1/store/remove-coupon');
        $remove->assertOk();
        $this->assertTrue((bool) $remove->json('success'));
        $this->assertSame('Coupon removed successfully', (string) $remove->json('message'));
        $this->assertNull($remove->json('couponCode'));
        $this->assertArrayHasKey('cartTotal', $remove->json() ?? []);
    }
}
