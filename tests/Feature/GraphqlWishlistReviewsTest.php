<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GraphqlWishlistReviewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_wishlist_requires_authentication(): void
    {
        $this->seed();

        $response = $this->postJson('/api/v1/graphql', [
            'query' => '{ wishlist { id name } }',
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('errors'));
        $this->assertNull($response->json('data.wishlist'));
    }

    public function test_wishlist_toggle_and_query(): void
    {
        $this->seed();
        $customer = Customer::factory()->create();
        $product = Product::query()->where('is_enabled', true)->where('type', 'simple')->first()
            ?? Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);

        $toggleOn = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) { wishlistToggle(productId: $id) { wishlisted product_id } }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $toggleOn->assertOk();
        $this->assertArrayNotHasKey('errors', $toggleOn->json() ?? []);
        $this->assertTrue((bool) $toggleOn->json('data.wishlistToggle.wishlisted'));
        $this->assertSame((string) $product->id, (string) $toggleOn->json('data.wishlistToggle.product_id'));

        $list = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => '{ wishlist { id sku } }',
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? []);
        $ids = collect($list->json('data.wishlist') ?? [])->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->assertContains((string) $product->id, $ids);

        $toggleOff = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) { wishlistToggle(productId: $id) { wishlisted } }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $toggleOff->assertOk();
        $this->assertFalse((bool) $toggleOff->json('data.wishlistToggle.wishlisted'));

        $empty = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => '{ wishlist { id } }',
        ]);
        $empty->assertOk();
        $this->assertSame([], $empty->json('data.wishlist'));
    }

    public function test_wishlist_move_to_cart_and_clear(): void
    {
        $this->seed();
        $customer = Customer::factory()->create();
        $product = Product::query()->where('is_enabled', true)->where('type', 'simple')->first()
            ?? Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);
        $customer->wishlist()->attach($product->id);

        $move = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) { wishlistMoveToCart(productId: $id, qty: 1) { item_count itemsList { product_id } } }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $move->assertOk();
        $this->assertArrayNotHasKey('errors', $move->json() ?? []);
        $this->assertGreaterThanOrEqual(1, (int) $move->json('data.wishlistMoveToCart.item_count'));
        $this->assertFalse($customer->fresh()->wishlist()->where('product_id', $product->id)->exists());

        $customer->wishlist()->attach($product->id);
        $clear = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation { wishlistClear { id } }',
        ]);
        $clear->assertOk();
        $this->assertArrayNotHasKey('errors', $clear->json() ?? []);
        $this->assertSame([], $clear->json('data.wishlistClear'));
        $this->assertSame(0, $customer->fresh()->wishlist()->count());
    }

    public function test_product_reviews_returns_approved_only(): void
    {
        $this->seed();
        $product = Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);
        $approvedCustomer = Customer::factory()->create(['name' => 'Reviewer One']);
        $pendingCustomer = Customer::factory()->create(['name' => 'Reviewer Two']);

        Review::query()->create([
            'product_id' => $product->id,
            'customer_id' => $approvedCustomer->id,
            'rating' => 5,
            'title' => 'Great',
            'body' => 'Loved it',
            'is_approved' => true,
            'status' => Review::STATUS_APPROVED,
        ]);
        Review::query()->create([
            'product_id' => $product->id,
            'customer_id' => $pendingCustomer->id,
            'rating' => 1,
            'title' => 'Hidden',
            'body' => 'Pending',
            'is_approved' => false,
            'status' => Review::STATUS_PENDING,
        ]);

        $response = $this->postJson('/api/v1/graphql', [
            'query' => 'query ($id: ID!) {
                productReviews(productId: $id, first: 10) {
                    totalCount
                    edges { node { id rating title body comment customer_name status } }
                }
            }',
            'variables' => ['id' => (string) $product->id],
        ]);

        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? [], json_encode($response->json()));
        $this->assertSame(1, (int) $response->json('data.productReviews.totalCount'));
        $node = $response->json('data.productReviews.edges.0.node');
        $this->assertSame('Great', (string) ($node['title'] ?? ''));
        $this->assertSame(5, (int) ($node['rating'] ?? 0));
        $this->assertSame('Loved it', (string) ($node['body'] ?? ''));
        $this->assertSame('approved', (string) ($node['status'] ?? ''));
    }
}
