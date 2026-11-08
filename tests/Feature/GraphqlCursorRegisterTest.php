<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Settings\CustomerSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GraphqlCursorRegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_connection_cursor_pagination(): void
    {
        $this->seed();

        $page1 = $this->postJson('/api/v1/graphql', [
            'query' => '{ productsConnection(first: 2) { total edges { cursor node { id sku } } pageInfo { hasNextPage endCursor } } }',
        ]);
        $page1->assertOk();
        $this->assertArrayNotHasKey('errors', $page1->json() ?? []);
        $edges = $page1->json('data.productsConnection.edges');
        $this->assertIsArray($edges);
        $this->assertLessThanOrEqual(2, count($edges));
        $this->assertGreaterThanOrEqual(1, (int) $page1->json('data.productsConnection.total'));

        if ($page1->json('data.productsConnection.pageInfo.hasNextPage')) {
            $cursor = $page1->json('data.productsConnection.pageInfo.endCursor');
            $page2 = $this->postJson('/api/v1/graphql', [
                'query' => 'query ($a: String!) { productsConnection(first: 2, after: $a) { edges { node { id } } pageInfo { hasNextPage } } }',
                'variables' => ['a' => $cursor],
            ]);
            $page2->assertOk();
            $this->assertArrayNotHasKey('errors', $page2->json() ?? []);
            $ids1 = collect($edges)->pluck('node.id')->all();
            $ids2 = collect($page2->json('data.productsConnection.edges'))->pluck('node.id')->all();
            $this->assertSame([], array_intersect($ids1, $ids2));
        }
    }

    public function test_products_connection_supports_price_and_category_filters(): void
    {
        $this->seed();
        $product = Product::query()->where('is_enabled', true)->whereNotNull('price')->orderBy('price')->first();
        $this->assertNotNull($product);
        $price = (float) $product->price;
        $categoryId = $product->default_category_id
            ?: $product->categories()->value('categories.id');

        $byPrice = $this->postJson('/api/v1/graphql', [
            'query' => 'query ($min: Float!, $max: Float!) {
                productsConnection(first: 20, minPrice: $min, maxPrice: $max) {
                    total edges { node { id price } }
                }
            }',
            'variables' => [
                'min' => max(0, $price - 0.01),
                'max' => $price + 0.01,
            ],
        ]);
        $byPrice->assertOk();
        $this->assertArrayNotHasKey('errors', $byPrice->json() ?? []);
        $this->assertGreaterThanOrEqual(1, (int) $byPrice->json('data.productsConnection.total'));
        $ids = collect($byPrice->json('data.productsConnection.edges') ?? [])->pluck('node.id')->map(fn ($id) => (string) $id)->all();
        $this->assertContains((string) $product->id, $ids);

        if ($categoryId) {
            $byCat = $this->postJson('/api/v1/graphql', [
                'query' => 'query ($c: ID!) { productsConnection(first: 20, categoryId: $c) { total edges { node { id } } } }',
                'variables' => ['c' => (string) $categoryId],
            ]);
            $byCat->assertOk();
            $this->assertArrayNotHasKey('errors', $byCat->json() ?? []);
            $this->assertGreaterThanOrEqual(1, (int) $byCat->json('data.productsConnection.total'));
        }
    }

    public function test_register_customer_via_graphql(): void
    {
        $this->seed();
        if (! CustomerSettings::registrationEnabled()) {
            $this->markTestSkipped('Registration disabled in seed.');
        }
        if (CustomerSettings::mobileLoginEnabled()) {
            $this->markTestSkipped('Mobile login registration path is REST-only.');
        }

        $email = 'gql-reg-'.uniqid().'@example.com';
        $response = $this->postJson('/api/v1/graphql', [
            'query' => <<<'GQL'
mutation ($e: String!) {
  registerCustomer(email: $e, password: "password123", firstName: "Graph", lastName: "QL", deviceName: "test") {
    pending access_token token_type customer { id email name }
  }
}
GQL,
            'variables' => ['e' => $email],
        ]);
        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? []);
        $response->assertJsonPath('data.registerCustomer.customer.email', $email);
        $this->assertNotEmpty($response->json('data.registerCustomer.customer.id'));
    }
}
