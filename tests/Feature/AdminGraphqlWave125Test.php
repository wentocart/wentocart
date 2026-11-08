<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave125Test extends TestCase
{
    use RefreshDatabase;

    public function test_cart_items_relay_and_stores_connection(): void
    {
        $this->seed();

        $stores = $this->postJson('/api/v1/graphql', [
            'query' => '{ stores(first: 5) { totalCount edges { node { id code name } } } }',
        ]);
        $stores->assertOk();
        $this->assertArrayNotHasKey('errors', $stores->json() ?? [], json_encode($stores->json()));
        $this->assertGreaterThanOrEqual(1, (int) $stores->json('data.stores.totalCount'));

        $product = Product::query()->where('is_enabled', true)->where('type', 'simple')->first()
            ?? Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);

        $add = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) {
                addProductsToCart(input: { items: [{ productId: $id, quantity: 2 }] }) {
                    cart {
                        itemsCount
                        items(first: 10) {
                            totalCount
                            edges { node { id productId quantity basePrice totalPrice } }
                        }
                    }
                }
            }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $add->assertOk();
        $this->assertArrayNotHasKey('errors', $add->json() ?? [], json_encode($add->json()));
        $this->assertSame(1, (int) $add->json('data.addProductsToCart.cart.items.totalCount'));
        $this->assertSame((string) $product->id, (string) $add->json('data.addProductsToCart.cart.items.edges.0.node.productId'));
        $this->assertEqualsWithDelta(2.0, (float) $add->json('data.addProductsToCart.cart.items.edges.0.node.quantity'), 0.001);
    }
}
