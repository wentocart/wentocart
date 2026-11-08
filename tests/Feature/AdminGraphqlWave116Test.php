<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCustomizationField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave116Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_product_customizable_options(): void
    {
        $this->seed();

        $product = Product::query()->create([
            'type' => 'simple',
            'sku' => 'W116-CUST-'.uniqid(),
            'price' => 40,
            'stock_qty' => 10,
            'stock_status' => 'in_stock',
            'is_enabled' => true,
            'customization_enabled' => true,
        ]);
        $product->translations()->create([
            'language_code' => 'en',
            'name' => 'Wave116 Custom',
            'slug' => 'wave116-custom-'.uniqid(),
        ]);

        ProductCustomizationField::query()->create([
            'product_id' => $product->id,
            'type' => 'text',
            'label' => 'Engraving',
            'is_required' => true,
            'position' => 1,
            'config' => [
                'max_length' => 40,
                'price_type' => 'fixed',
                'price_amount' => 5,
            ],
        ]);

        $response = $this->postJson('/api/v1/graphql', [
            'query' => 'query ($id: ID!) {
                product(id: $id) {
                    customizableOptions(first: 10) {
                        totalCount
                        edges {
                            node {
                                id type label isRequired maxCharacters price priceType
                            }
                        }
                    }
                }
            }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? [], json_encode($response->json()));
        $this->assertSame(1, (int) $response->json('data.product.customizableOptions.totalCount'));
        $this->assertSame('Engraving', (string) $response->json('data.product.customizableOptions.edges.0.node.label'));
        $this->assertSame('text', (string) $response->json('data.product.customizableOptions.edges.0.node.type'));
        $this->assertTrue((bool) $response->json('data.product.customizableOptions.edges.0.node.isRequired'));
        $this->assertSame(40, (int) $response->json('data.product.customizableOptions.edges.0.node.maxCharacters'));
        $this->assertSame(5.0, (float) $response->json('data.product.customizableOptions.edges.0.node.price'));
    }
}
