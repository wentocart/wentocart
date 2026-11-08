<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminGraphqlWave110Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_wishlist_item_connection_and_mutations(): void
    {
        $this->seed();
        ShopSetting::put('catalog.wishlist_enabled', true);

        $customer = Customer::factory()->create();
        $product = Product::query()->where('is_enabled', true)->where('type', 'simple')->first()
            ?? Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);

        $create = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation ($pid: ID!, $cid: String) {
                createWishlist(productId: $pid, clientMutationId: $cid) {
                    clientMutationId
                    wishlisted
                    wishlist {
                        id createdAt
                        product { id sku isInWishlist }
                    }
                }
            }',
            'variables' => [
                'pid' => (string) $product->id,
                'cid' => 'w110-add',
            ],
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? [], json_encode($create->json()));
        $this->assertSame('w110-add', (string) $create->json('data.createWishlist.clientMutationId'));
        $wishlistId = (int) $create->json('data.createWishlist.wishlist.id');
        $this->assertGreaterThan(0, $wishlistId);
        $this->assertSame('1', (string) $create->json('data.createWishlist.wishlist.product.isInWishlist'));
        $this->assertTrue(
            DB::table('wishlists')->where('customer_id', $customer->id)->where('product_id', $product->id)->exists()
        );

        $list = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => '{
                wishlists(first: 10, sort: "created_at", order: "desc") {
                    totalCount
                    pageInfo { hasNextPage }
                    edges {
                        cursor
                        node { id product { id sku } }
                    }
                }
            }',
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? [], json_encode($list->json()));
        $this->assertGreaterThanOrEqual(1, (int) $list->json('data.wishlists.totalCount'));
        $ids = collect($list->json('data.wishlists.edges') ?? [])->pluck('node.id')->all();
        $this->assertContains($wishlistId, $ids);

        $move = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation ($wid: Int!) {
                moveWishlistToCart(wishlistItemId: $wid, quantity: 1) {
                    wishlistToCart { message }
                    cart { item_count }
                }
            }',
            'variables' => ['wid' => $wishlistId],
        ]);
        $move->assertOk();
        $this->assertArrayNotHasKey('errors', $move->json() ?? [], json_encode($move->json()));
        $this->assertNotSame('', (string) $move->json('data.moveWishlistToCart.wishlistToCart.message'));
        $this->assertFalse(
            DB::table('wishlists')->where('id', $wishlistId)->exists()
        );

        $create2 = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation ($pid: ID!) {
                createWishlist(productId: $pid) { wishlist { id } }
            }',
            'variables' => ['pid' => (string) $product->id],
        ]);
        $create2->assertOk();
        $this->assertArrayNotHasKey('errors', $create2->json() ?? [], json_encode($create2->json()));
        $wid2 = (int) $create2->json('data.createWishlist.wishlist.id');

        $delete = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) {
                deleteWishlist(id: $id) {
                    wishlist { id }
                    wishlisted
                }
            }',
            'variables' => ['id' => '/api/v1/store/wishlists/'.$wid2],
        ]);
        $delete->assertOk();
        $this->assertArrayNotHasKey('errors', $delete->json() ?? [], json_encode($delete->json()));
        $this->assertFalse(DB::table('wishlists')->where('id', $wid2)->exists());
    }
}
