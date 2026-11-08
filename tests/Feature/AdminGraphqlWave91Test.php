<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave91Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_wishlist_and_compare_aliases(): void
    {
        $this->seed();
        $customer = Customer::factory()->create();
        $product = Product::query()->where('is_enabled', true)->where('type', 'simple')->first()
            ?? Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);

        $create = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) {
                createWishlist(productId: $id) { wishlisted product_id }
            }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? [], json_encode($create->json()));
        $this->assertTrue((bool) $create->json('data.createWishlist.wishlisted'));

        $wishlists = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => '{ wishlists(first: 20) { totalCount edges { node { id product { id sku } } } } }',
        ]);
        $wishlists->assertOk();
        $this->assertArrayNotHasKey('errors', $wishlists->json() ?? [], json_encode($wishlists->json()));
        $ids = collect($wishlists->json('data.wishlists.edges') ?? [])
            ->pluck('node.product.id')
            ->map(fn ($id) => (string) $id)
            ->all();
        $this->assertContains((string) $product->id, $ids);

        $toggle = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) {
                toggleWishlist(productId: $id) { wishlisted }
            }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $toggle->assertOk();
        $this->assertArrayNotHasKey('errors', $toggle->json() ?? [], json_encode($toggle->json()));
        $this->assertFalse((bool) $toggle->json('data.toggleWishlist.wishlisted'));

        $readd = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) {
                createWishlist(productId: $id) { wishlisted }
            }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $readd->assertOk();
        $clear = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation { createDeleteAllWishlists { ok message } }',
        ]);
        $clear->assertOk();
        $this->assertArrayNotHasKey('errors', $clear->json() ?? [], json_encode($clear->json()));
        $this->assertTrue((bool) $clear->json('data.createDeleteAllWishlists.ok'));
        $this->assertFalse($customer->wishlist()->where('product_id', $product->id)->exists());

        $compareAdd = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) {
                createCompareItem(productId: $id) { compared product_ids }
            }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $compareAdd->assertOk();
        $this->assertArrayNotHasKey('errors', $compareAdd->json() ?? [], json_encode($compareAdd->json()));
        $this->assertTrue((bool) $compareAdd->json('data.createCompareItem.compared'));

        $compareItems = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => '{ compareItems(first: 10) { edges { node { id product { id } } } totalCount } }',
        ]);
        $compareItems->assertOk();
        $this->assertArrayNotHasKey('errors', $compareItems->json() ?? [], json_encode($compareItems->json()));
        $compareIds = collect($compareItems->json('data.compareItems.edges') ?? [])
            ->pluck('node.product.id')
            ->map(fn ($id) => (string) $id)
            ->all();
        $this->assertContains((string) $product->id, $compareIds);

        $compareClear = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation { createDeleteAllCompareItems { ok } }',
        ]);
        $compareClear->assertOk();
        $this->assertArrayNotHasKey('errors', $compareClear->json() ?? [], json_encode($compareClear->json()));
        $this->assertTrue((bool) $compareClear->json('data.createDeleteAllCompareItems.ok'));
    }
}
