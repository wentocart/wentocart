<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave104Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_categories_connection_and_compare_items_relay(): void
    {
        $this->seed();

        $cats = $this->postJson('/api/v1/graphql', [
            'query' => '{
                categoriesConnection(first: 10) {
                    totalCount
                    edges {
                        cursor
                        node {
                            id name slug
                            translation { name slug locale urlPath }
                            status position
                        }
                    }
                    pageInfo { hasNextPage endCursor }
                }
            }',
        ]);
        $cats->assertOk();
        $this->assertArrayNotHasKey('errors', $cats->json() ?? [], json_encode($cats->json()));
        $this->assertGreaterThan(0, (int) $cats->json('data.categoriesConnection.totalCount'));
        $first = $cats->json('data.categoriesConnection.edges.0.node');
        $this->assertNotEmpty($first['id'] ?? null);
        $this->assertNotEmpty($first['translation']['name'] ?? null);

        $register = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                createCustomer(
                    email: "wave104@example.com"
                    password: "Password123"
                    passwordConfirmation: "Password123"
                    firstName: "Wave"
                    lastName: "Compare"
                ) { accessToken }
            }',
        ]);
        $register->assertOk();
        $this->assertArrayNotHasKey('errors', $register->json() ?? [], json_encode($register->json()));
        $token = (string) $register->json('data.createCustomer.accessToken');
        $this->assertNotSame('', $token);

        $product = Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);

        $add = $this->withToken($token)->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) {
                createCompareItem(productId: $id) { compared product_id }
            }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $add->assertOk();
        $this->assertArrayNotHasKey('errors', $add->json() ?? [], json_encode($add->json()));
        $this->assertTrue((bool) $add->json('data.createCompareItem.compared'));

        $compare = $this->withToken($token)->postJson('/api/v1/graphql', [
            'query' => '{
                compareItems(first: 10) {
                    totalCount
                    edges {
                        node {
                            id
                            product { id sku name }
                            customer { id email firstName }
                        }
                    }
                    pageInfo { hasNextPage }
                }
            }',
        ]);
        $compare->assertOk();
        $this->assertArrayNotHasKey('errors', $compare->json() ?? [], json_encode($compare->json()));
        $this->assertGreaterThanOrEqual(1, (int) $compare->json('data.compareItems.totalCount'));
        $productIds = collect($compare->json('data.compareItems.edges') ?? [])
            ->pluck('node.product.id')
            ->map(fn ($id) => (string) $id)
            ->all();
        $this->assertContains((string) $product->id, $productIds);

        // Legacy list alias still works
        $list = $this->withToken($token)->postJson('/api/v1/graphql', [
            'query' => '{ compareList { id } }',
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? [], json_encode($list->json()));
        $listIds = collect($list->json('data.compareList') ?? [])->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->assertContains((string) $product->id, $listIds);
    }
}
