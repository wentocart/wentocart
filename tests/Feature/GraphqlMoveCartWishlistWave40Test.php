<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Store\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GraphqlMoveCartWishlistWave40Test extends TestCase
{
    use RefreshDatabase;

    public function test_move_cart_item_to_wishlist(): void
    {
        $this->seed();
        $product = Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);

        $customer = Customer::factory()->create([
            'store_id' => app(StoreContext::class)->requireId(),
        ]);

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();

        $move = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) {
                moveCartItemToWishlist(productId: $id) { item_count }
            }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $move->assertOk();
        $this->assertArrayNotHasKey('errors', $move->json() ?? [], json_encode($move->json()));
        $this->assertTrue(
            $customer->fresh()->wishlist()->where('product_id', $product->id)->exists()
        );
    }
}
