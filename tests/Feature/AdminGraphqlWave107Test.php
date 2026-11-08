<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\FeatureValue;
use App\Domain\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminGraphqlWave107Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_attributes_filters_and_products_filter_json(): void
    {
        $this->seed();

        $feature = Feature::query()->where('is_filterable', true)->where('is_enabled', true)->first();
        if (! $feature) {
            $feature = Feature::query()->create([
                'code' => 'wave107_color',
                'type' => 'color',
                'is_filterable' => true,
                'is_enabled' => true,
                'position' => 1,
            ]);
            $feature->translations()->create([
                'language_code' => 'en',
                'name' => 'Wave107 Color',
            ]);
        }

        $value = $feature->values()->where('is_enabled', true)->first();
        if (! $value) {
            $value = FeatureValue::query()->create([
                'feature_id' => $feature->id,
                'code' => '#112233',
                'is_enabled' => true,
                'position' => 1,
            ]);
            $value->translations()->create([
                'language_code' => 'en',
                'value' => 'Navy',
            ]);
        }

        $product = Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);
        DB::table('product_feature_values')->updateOrInsert(
            [
                'product_id' => $product->id,
                'feature_id' => $feature->id,
                'feature_value_id' => $value->id,
            ],
            []
        );

        $attrs = $this->postJson('/api/v1/graphql', [
            'query' => '{
                attributes(first: 20) {
                    totalCount
                    edges {
                        node {
                            id code isFilterable
                            translation { name locale }
                            options { totalCount edges { node { id translation { label } } } }
                        }
                    }
                }
            }',
        ]);
        $attrs->assertOk();
        $this->assertArrayNotHasKey('errors', $attrs->json() ?? [], json_encode($attrs->json()));
        $this->assertGreaterThan(0, (int) $attrs->json('data.attributes.totalCount'));
        $codes = collect($attrs->json('data.attributes.edges') ?? [])->pluck('node.code')->all();
        $this->assertContains($feature->code, $codes);

        $one = $this->postJson('/api/v1/graphql', [
            'query' => 'query ($code: String!) {
                attribute(code: $code) {
                    code adminName isFilterable
                    options { edges { node { id adminName } } }
                }
            }',
            'variables' => ['code' => $feature->code],
        ]);
        $one->assertOk();
        $this->assertArrayNotHasKey('errors', $one->json() ?? [], json_encode($one->json()));
        $this->assertSame($feature->code, (string) $one->json('data.attribute.code'));
        $this->assertSame('1', (string) $one->json('data.attribute.isFilterable'));

        $filters = $this->postJson('/api/v1/graphql', [
            'query' => '{
                categoryAttributeFilters(first: 30) {
                    totalCount
                    edges {
                        node {
                            code isFilterable minPrice maxPrice
                            options { edges { node { id } } }
                        }
                    }
                }
            }',
        ]);
        $filters->assertOk();
        $this->assertArrayNotHasKey('errors', $filters->json() ?? [], json_encode($filters->json()));
        $filterCodes = collect($filters->json('data.categoryAttributeFilters.edges') ?? [])->pluck('node.code')->all();
        $this->assertContains($feature->code, $filterCodes);

        $filterJson = json_encode([
            $feature->code => (string) $value->id,
        ], JSON_THROW_ON_ERROR);

        $products = $this->postJson('/api/v1/graphql', [
            'query' => 'query ($filter: String!) {
                products(first: 20, filter: $filter) {
                    totalCount
                    edges { node { id sku } }
                }
            }',
            'variables' => ['filter' => $filterJson],
        ]);
        $products->assertOk();
        $this->assertArrayNotHasKey('errors', $products->json() ?? [], json_encode($products->json()));
        $ids = collect($products->json('data.products.edges') ?? [])->pluck('node.id')->map(fn ($id) => (string) $id)->all();
        $this->assertContains((string) $product->id, $ids);
    }
}
