<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GraphqlEstimateShippingTest extends TestCase
{
    use RefreshDatabase;

    public function test_estimate_shipping_returns_quotes(): void
    {
        $this->seed();

        $product = \App\Domain\Catalog\Models\Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();

        $response = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                estimateShipping(country: "US", state: "TX", postalCode: "78701") {
                    shippingMethods {
                        totalCount
                        edges { node { id title price basePrice is_pickup } }
                    }
                }
            }',
        ]);
        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? [], json_encode($response->json()));
        $this->assertGreaterThanOrEqual(1, (int) $response->json('data.estimateShipping.shippingMethods.totalCount'));
        $this->assertArrayHasKey('price', $response->json('data.estimateShipping.shippingMethods.edges.0.node') ?? []);
    }
}
