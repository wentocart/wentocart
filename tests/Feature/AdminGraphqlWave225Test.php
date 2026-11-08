<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave225Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_shop_cart_rest(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $empty = $this->postJson('/api/v1/store/cart', []);
        $empty->assertNotFound();

        $created = $this->postJson('/api/v1/store/create-cart', []);
        $created->assertCreated();
        $this->assertArrayHasKey('cartToken', $created->json() ?? []);

        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $add = $this->postJson('/api/v1/store/add-product-in-cart', [
            'productId' => $product->id,
            'quantity' => 2,
        ]);
        $add->assertOk();
        $this->assertTrue((bool) $add->json('success'));
        $this->assertGreaterThanOrEqual(1, (int) $add->json('itemsCount'));
        $this->assertArrayHasKey('formattedGrandTotal', $add->json() ?? []);
        $itemId = (int) ($add->json('items.0.id') ?? 0);
        $this->assertGreaterThan(0, $itemId);

        $get = $this->postJson('/api/v1/store/cart', []);
        $get->assertOk();
        $this->assertSame($itemId, (int) $get->json('items.0.id'));

        $upd = $this->putJson('/api/v1/store/cart/items/'.$itemId, ['quantity' => 3]);
        $upd->assertOk();
        $this->assertSame(3, (int) $upd->json('items.0.quantity'));

        $rm = $this->deleteJson('/api/v1/store/cart/items/'.$itemId);
        $rm->assertNotFound(); // empty cart → API 404
    }
}
