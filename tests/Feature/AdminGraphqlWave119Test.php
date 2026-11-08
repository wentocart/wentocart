<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\AttributeValue;
use App\Domain\Catalog\Models\CatalogAttribute;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave119Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_configurable_variants_variant_axes_family_stores(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->post('/admin/attributes', [
            'name' => 'Color', 'code' => 'color', 'type' => 'select', 'is_enabled' => true, 'position' => 1,
        ])->assertRedirect();
        $this->post('/admin/attributes', [
            'name' => 'Size', 'code' => 'size', 'type' => 'select', 'is_enabled' => true, 'position' => 2,
        ])->assertRedirect();

        $color = CatalogAttribute::query()->where('code', 'color')->firstOrFail();
        $size = CatalogAttribute::query()->where('code', 'size')->firstOrFail();

        foreach (['Black' => 'black', 'White' => 'white'] as $label => $code) {
            $this->post('/admin/attributes/values', [
                'attribute_id' => $color->id, 'value' => $label, 'code' => $code, 'is_enabled' => true, 'position' => 1,
            ])->assertRedirect();
        }
        foreach (['S' => 's', 'M' => 'm'] as $label => $code) {
            $this->post('/admin/attributes/values', [
                'attribute_id' => $size->id, 'value' => $label, 'code' => $code, 'is_enabled' => true, 'position' => 1,
            ])->assertRedirect();
        }

        $black = AttributeValue::query()->where('attribute_id', $color->id)->where('code', 'black')->firstOrFail();
        $white = AttributeValue::query()->where('attribute_id', $color->id)->where('code', 'white')->firstOrFail();
        $small = AttributeValue::query()->where('attribute_id', $size->id)->where('code', 's')->firstOrFail();
        $medium = AttributeValue::query()->where('attribute_id', $size->id)->where('code', 'm')->firstOrFail();

        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'combination',
            'sku' => 'W119-TEE',
            'name' => 'Wave119 Tee',
            'price' => 20,
            'stock_qty' => 0,
            'manage_stock' => true,
            'is_enabled' => true,
            'available_for_order' => true,
            'combination_axes' => [
                ['attribute_id' => $color->id, 'value_ids' => [$black->id, $white->id]],
                ['attribute_id' => $size->id, 'value_ids' => [$small->id, $medium->id]],
            ],
            'combination_variants' => [
                [
                    'attribute_value_ids' => [$black->id, $small->id],
                    'sku' => 'W119-TEE-BS',
                    'price' => 19.99,
                    'stock_qty' => 2,
                    'manage_stock' => true,
                    'stock_status' => 'in_stock',
                    'is_enabled' => true,
                    'is_default' => true,
                ],
                [
                    'attribute_value_ids' => [$black->id, $medium->id],
                    'sku' => 'W119-TEE-BM',
                    'price' => 21.99,
                    'stock_qty' => 3,
                    'manage_stock' => true,
                    'stock_status' => 'in_stock',
                    'is_enabled' => true,
                    'is_default' => false,
                ],
                [
                    'attribute_value_ids' => [$white->id, $small->id],
                    'sku' => 'W119-TEE-WS',
                    'price' => 19.99,
                    'stock_qty' => 5,
                    'manage_stock' => true,
                    'stock_status' => 'in_stock',
                    'is_enabled' => true,
                    'is_default' => false,
                ],
                [
                    'attribute_value_ids' => [$white->id, $medium->id],
                    'sku' => 'W119-TEE-WM',
                    'price' => 21.99,
                    'stock_qty' => 4,
                    'manage_stock' => true,
                    'stock_status' => 'in_stock',
                    'is_enabled' => true,
                    'is_default' => false,
                ],
            ],
        ]))->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'W119-TEE')->firstOrFail();
        $this->assertSame(4, ProductVariant::query()->where('product_id', $product->id)->count());

        $response = $this->postJson('/api/v1/graphql', [
            'query' => 'query ($id: ID!) {
                product(id: $id) {
                    id type sku
                    productTemplate { id code name adminName }
                    variants(first: 10) {
                        totalCount
                        edges { node { id sku price name inStock } }
                    }
                    variantAxes(first: 10) {
                        totalCount
                        edges {
                            node {
                                id code adminName type
                                options { totalCount edges { node { id adminName translation { label } } } }
                            }
                        }
                    }
                    stores(first: 5) {
                        totalCount
                        edges { node { id code name } }
                    }
                }
            }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? [], json_encode($response->json()));
        $this->assertSame('configurable', (string) $response->json('data.product.type'));
        $this->assertSame(4, (int) $response->json('data.product.variants.totalCount'));
        $this->assertGreaterThanOrEqual(2, (int) $response->json('data.product.variantAxes.totalCount'));
        $this->assertNotEmpty($response->json('data.product.productTemplate.code'));
        $this->assertGreaterThanOrEqual(1, (int) $response->json('data.product.stores.totalCount'));

        $skus = collect($response->json('data.product.variants.edges'))
            ->map(fn ($e) => (string) ($e['node']['sku'] ?? ''))
            ->all();
        $this->assertContains('W119-TEE-BS', $skus);
        $this->assertContains('W119-TEE-WM', $skus);
    }

    public function test_rest_tree_categories_children_connection(): void
    {
        $this->seed();

        $parent = Category::query()->whereNull('parent_id')->where('is_enabled', true)->first()
            ?? Category::query()->where('is_enabled', true)->orderBy('id')->first();
        $this->assertNotNull($parent);

        $child = Category::query()->create([
            'parent_id' => $parent->id,
            'position' => 99,
            'is_enabled' => true,
        ]);
        $child->translations()->create([
            'language_code' => 'en',
            'name' => 'Wave119 Child',
            'slug' => 'wave119-child-'.uniqid(),
        ]);

        $response = $this->postJson('/api/v1/graphql', [
            'query' => '{
                treeCategories(depth: 2) {
                    id name
                    children(first: 20) {
                        totalCount
                        edges {
                            node {
                                id name
                                translation { name slug }
                                children(first: 5) { totalCount edges { node { id } } }
                            }
                        }
                    }
                }
            }',
        ]);
        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? [], json_encode($response->json()));
        $trees = $response->json('data.treeCategories') ?? [];
        $this->assertIsArray($trees);
        $this->assertNotEmpty($trees);

        $found = false;
        foreach ($trees as $node) {
            foreach ($node['children']['edges'] ?? [] as $edge) {
                if ((string) ($edge['node']['id'] ?? '') === (string) $child->id) {
                    $found = true;
                    $this->assertSame('Wave119 Child', (string) ($edge['node']['name'] ?? ''));
                }
            }
        }
        $this->assertTrue($found, 'Expected Wave119 child in treeCategories children connection');
    }
}
