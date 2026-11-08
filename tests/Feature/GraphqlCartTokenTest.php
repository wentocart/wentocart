<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GraphqlCartTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_cart_token_and_header_binds_guest_cart(): void
    {
        $this->seed();
        $product = Product::query()->where('is_enabled', true)->where('type', 'simple')->first()
            ?? Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);

        $tokenResponse = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation { createCartToken { cart_token cart { id item_count } } }',
        ]);
        $tokenResponse->assertOk();
        $this->assertArrayNotHasKey('errors', $tokenResponse->json() ?? []);
        $token = (string) $tokenResponse->json('data.createCartToken.cart_token');
        $this->assertNotEmpty($token);

        $add = $this->withHeader('X-Cart-Token', $token)->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) { cartAdd(productId: $id, qty: 1) { item_count itemsList { product_id } } }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $add->assertOk();
        $this->assertArrayNotHasKey('errors', $add->json() ?? []);
        $this->assertGreaterThanOrEqual(1, (int) $add->json('data.cartAdd.item_count'));

        $cart = $this->withHeader('X-Cart-Token', $token)->postJson('/api/v1/graphql', [
            'query' => '{ cart { item_count itemsList { product_id } } }',
        ]);
        $cart->assertOk();
        $this->assertArrayNotHasKey('errors', $cart->json() ?? []);
        $this->assertGreaterThanOrEqual(1, (int) $cart->json('data.cart.item_count'));
        $ids = collect($cart->json('data.cart.itemsList') ?? [])->pluck('product_id')->map(fn ($id) => (string) $id)->all();
        $this->assertContains((string) $product->id, $ids);
    }
}
