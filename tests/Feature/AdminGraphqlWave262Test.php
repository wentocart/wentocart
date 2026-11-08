<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave262Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_update_cart_item_post(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $product = Product::query()->where('sku', 'DEMO-001')->first()
            ?? Product::query()->where('is_enabled', true)->where('type', 'simple')->firstOrFail();

        $created = $this->postJson('/api/v1/store/cart-tokens', []);
        $created->assertCreated();
        $token = (string) $created->json('cartToken');
        $headers = ['Authorization' => 'Bearer '.$token];

        $bad = $this->withHeaders($headers)->postJson('/api/v1/store/update-cart-item', [
            'cartItemId' => 1,
        ]);
        $bad->assertStatus(400);
        $this->assertSame('Cart item ID and quantity are required', (string) $bad->json('message'));

        $add = $this->withHeaders($headers)->postJson('/api/v1/store/add-product-in-cart', [
            'productId' => (int) $product->id,
            'quantity' => 1,
        ]);
        $add->assertOk();
        $itemId = (int) ($add->json('items.0.id') ?? 0);
        $this->assertGreaterThan(0, $itemId);

        $upd = $this->withHeaders($headers)->postJson('/api/v1/store/update-cart-item', [
            'cartItemId' => $itemId,
            'quantity' => 4,
        ]);
        $upd->assertCreated();
        $this->assertTrue((bool) $upd->json('success'));
        $this->assertSame('Cart item updated successfully', (string) $upd->json('message'));
        $this->assertSame(4, (int) $upd->json('items.0.quantity'));
        $this->assertSame($token, (string) $upd->json('cartToken'));

        // API: unknown cartItemId → 201 success no-op
        $noop = $this->withHeaders($headers)->postJson('/api/v1/store/update-cart-item', [
            'cartItemId' => 999999,
            'quantity' => 2,
        ]);
        $noop->assertCreated();
        $this->assertTrue((bool) $noop->json('success'));
        $this->assertSame(4, (int) $noop->json('items.0.quantity'));
    }
}
