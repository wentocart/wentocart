<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GraphqlShopTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_query_returns_store_name(): void
    {
        $this->seed();

        $response = $this->postJson('/api/v1/graphql', [
            'query' => '{ shop { name currency locale } }',
        ]);

        $response->assertOk();
        $this->assertIsString($response->json('data.shop.name'));
        $this->assertNotSame('', (string) $response->json('data.shop.currency'));
        $this->assertArrayNotHasKey('errors', $response->json() ?? []);
    }

    public function test_products_query_lists_enabled_catalog(): void
    {
        $this->seed();

        $response = $this->postJson('/api/v1/graphql', [
            'query' => '{ products(limit: 5) { total items { id name slug } } }',
        ]);

        $response->assertOk();
        $this->assertGreaterThan(0, (int) $response->json('data.products.total'));
        $this->assertNotEmpty($response->json('data.products.items'));
    }

    public function test_product_query_by_slug(): void
    {
        $this->seed();
        $product = Product::query()->where('is_enabled', true)->with('translations')->first();
        $this->assertNotNull($product);
        $slug = $product->translation('en')?->slug ?? $product->translations->first()?->slug;
        $this->assertNotEmpty($slug);

        $response = $this->postJson('/api/v1/graphql', [
            'query' => 'query ($slug: String!) { product(slug: $slug) { id name slug sku } }',
            'variables' => ['slug' => $slug],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.product.slug', $slug);
        $response->assertJsonPath('data.product.id', (string) $product->id);
    }

    public function test_missing_query_returns_400(): void
    {
        $this->postJson('/api/v1/graphql', [])
            ->assertStatus(400)
            ->assertJsonStructure(['errors']);
    }

    public function test_cart_add_update_and_remove_mutations(): void
    {
        $this->seed();
        $product = Product::query()->where('is_enabled', true)->where('type', 'simple')->first()
            ?? Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);

        $add = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) { cartAdd(productId: $id, qty: 1) { item_count grand_total itemsList { id product_id qty } } }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $add->assertOk();
        $this->assertArrayNotHasKey('errors', $add->json() ?? []);
        $this->assertGreaterThanOrEqual(1, (int) $add->json('data.cartAdd.item_count'));
        $itemId = $add->json('data.cartAdd.itemsList.0.id');
        $this->assertNotEmpty($itemId);

        $update = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) { cartUpdateQty(itemId: $id, qty: 2) { itemsList { id qty } } }',
            'variables' => ['id' => (string) $itemId],
        ]);
        $update->assertOk();
        $this->assertArrayNotHasKey('errors', $update->json() ?? []);
        $this->assertEquals(2.0, (float) $update->json('data.cartUpdateQty.itemsList.0.qty'));

        $remove = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) { cartRemoveItem(itemId: $id) { item_count itemsList { id } } }',
            'variables' => ['id' => (string) $itemId],
        ]);
        $remove->assertOk();
        $this->assertArrayNotHasKey('errors', $remove->json() ?? []);
        $this->assertSame(0, (int) $remove->json('data.cartRemoveItem.item_count'));
    }
}
