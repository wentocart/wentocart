<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave260Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_remove_cart_item_and_bulk_posts(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $products = Product::query()
            ->where('is_enabled', true)
            ->where('type', 'simple')
            ->orderBy('id')
            ->limit(2)
            ->get();
        $this->assertGreaterThanOrEqual(1, $products->count());
        $productA = $products->first();
        $productB = $products->count() > 1 ? $products->last() : null;
        if (! $productB || (int) $productB->id === (int) $productA->id) {
            $productB = Product::query()->create([
                'sku' => 'WAVE260-B-'.uniqid(),
                'type' => 'simple',
                'is_enabled' => true,
                'price' => 19.99,
                'manage_stock' => true,
                'stock_qty' => 50,
            ]);
        }

        $created = $this->postJson('/api/v1/store/cart-tokens', []);
        $created->assertCreated();
        $token = (string) $created->json('cartToken');
        $this->assertNotSame('', $token);
        $headers = ['Authorization' => 'Bearer '.$token];

        $missing = $this->withHeaders($headers)->postJson('/api/v1/store/remove-cart-item', []);
        $missing->assertStatus(400);
        $this->assertFalse((bool) $missing->json('success'));

        $emptyBulk = $this->withHeaders($headers)->postJson('/api/v1/store/remove-cart-items', ['itemIds' => []]);
        $emptyBulk->assertStatus(400);

        $addA = $this->withHeaders($headers)->postJson('/api/v1/store/add-product-in-cart', [
            'productId' => (int) $productA->id,
            'quantity' => 1,
        ]);
        $addA->assertOk();
        $itemA = (int) ($addA->json('items.0.id') ?? 0);
        $this->assertGreaterThan(0, $itemA);

        $addB = $this->withHeaders($headers)->postJson('/api/v1/store/add-product-in-cart', [
            'productId' => (int) $productB->id,
            'quantity' => 1,
        ]);
        $addB->assertOk();
        $this->assertGreaterThanOrEqual(2, (int) $addB->json('itemsCount'), 'Need two cart lines for singular+bulk coverage');
        $itemB = 0;
        foreach ($addB->json('items') ?? [] as $row) {
            if ((int) ($row['id'] ?? 0) !== $itemA) {
                $itemB = (int) $row['id'];
                break;
            }
        }
        $this->assertGreaterThan(0, $itemB);

        // Alternate cart-line route: /api/v1/store/remove-cart-item
        $rmOne = $this->withHeaders($headers)->postJson('/api/v1/store/remove-cart-item', [
            'cartItemId' => $itemA,
        ]);
        $rmOne->assertCreated();
        $this->assertTrue((bool) $rmOne->json('success'));
        $this->assertSame('Item removed from cart successfully', (string) $rmOne->json('message'));
        $this->assertSame($token, (string) $rmOne->json('cartToken'));
        $remainingIds = collect($rmOne->json('items') ?? [])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertNotContains($itemA, $remainingIds);
        $this->assertContains($itemB, $remainingIds);

        $bulk = $this->withHeaders($headers)->postJson('/api/v1/store/remove-cart-items', [
            'itemIds' => [$itemB],
        ]);
        $bulk->assertCreated();
        $this->assertTrue((bool) $bulk->json('success'));
        $this->assertSame('Items removed from cart successfully', (string) $bulk->json('message'));
        $this->assertSame(0, (int) $bulk->json('itemsCount'));
        $this->assertSame([], $bulk->json('items'));
        $this->assertSame($token, (string) $bulk->json('cartToken'), 'API: empty cart keeps cartToken');

        // Token still works for add after clear
        $again = $this->withHeaders($headers)->postJson('/api/v1/store/add-product-in-cart', [
            'productId' => (int) $productA->id,
            'quantity' => 1,
        ]);
        $again->assertOk();
        $this->assertGreaterThanOrEqual(1, (int) $again->json('itemsCount'));
    }
}
