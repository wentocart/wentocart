<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\FeatureValue;
use App\Domain\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminGraphqlWave113Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_product_attribute_values_connection(): void
    {
        $this->seed();

        $product = Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);

        $feature = Feature::query()->create([
            'code' => 'wave113_material',
            'type' => 'select',
            'is_filterable' => true,
            'is_enabled' => true,
            'position' => 1,
        ]);
        $feature->translations()->create([
            'language_code' => 'en',
            'name' => 'Wave113 Material',
        ]);
        $value = FeatureValue::query()->create([
            'feature_id' => $feature->id,
            'code' => 'cotton',
            'is_enabled' => true,
            'position' => 1,
        ]);
        $value->translations()->create([
            'language_code' => 'en',
            'value' => 'Cotton',
        ]);

        DB::table('product_feature_values')->updateOrInsert(
            [
                'product_id' => $product->id,
                'feature_id' => $feature->id,
                'feature_value_id' => $value->id,
            ],
            []
        );

        $response = $this->postJson('/api/v1/graphql', [
            'query' => 'query ($id: ID!) {
                product(id: $id) {
                    id
                    attributeValues(first: 20) {
                        totalCount
                        edges {
                            node {
                                id value
                                attribute { code adminName }
                            }
                        }
                    }
                }
            }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? [], json_encode($response->json()));
        $this->assertGreaterThanOrEqual(1, (int) $response->json('data.product.attributeValues.totalCount'));

        $codes = collect($response->json('data.product.attributeValues.edges') ?? [])
            ->pluck('node.attribute.code')
            ->all();
        $this->assertContains('wave113_material', $codes);

        $values = collect($response->json('data.product.attributeValues.edges') ?? [])
            ->pluck('node.value')
            ->all();
        $this->assertContains('Cotton', $values);
    }
}
