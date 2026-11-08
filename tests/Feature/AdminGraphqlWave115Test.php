<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave115Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_grouped_products_and_bundle_options(): void
    {
        $this->seed();

        $childA = Product::query()->create([
            'type' => 'simple',
            'sku' => 'W115-A-'.uniqid(),
            'price' => 10,
            'stock_qty' => 5,
            'stock_status' => 'in_stock',
            'is_enabled' => true,
        ]);
        $childA->translations()->create([
            'language_code' => 'en',
            'name' => 'Wave115 Child A',
            'slug' => 'wave115-a-'.uniqid(),
        ]);

        $childB = Product::query()->create([
            'type' => 'simple',
            'sku' => 'W115-B-'.uniqid(),
            'price' => 15,
            'stock_qty' => 5,
            'stock_status' => 'in_stock',
            'is_enabled' => true,
        ]);
        $childB->translations()->create([
            'language_code' => 'en',
            'name' => 'Wave115 Child B',
            'slug' => 'wave115-b-'.uniqid(),
        ]);

        $group = Product::query()->create([
            'type' => 'group',
            'sku' => 'W115-GROUP-'.uniqid(),
            'price' => 0,
            'is_enabled' => true,
            'manage_stock' => false,
        ]);
        $group->translations()->create([
            'language_code' => 'en',
            'name' => 'Wave115 Group',
            'slug' => 'wave115-group-'.uniqid(),
        ]);

        ProductComponent::query()->create([
            'parent_product_id' => $group->id,
            'child_product_id' => $childA->id,
            'quantity' => 2,
            'position' => 1,
            'is_required' => true,
        ]);
        ProductComponent::query()->create([
            'parent_product_id' => $group->id,
            'child_product_id' => $childB->id,
            'quantity' => 1,
            'position' => 2,
            'is_required' => false,
        ]);

        $bundle = Product::query()->create([
            'type' => 'bundle',
            'sku' => 'W115-BUNDLE-'.uniqid(),
            'price' => 0,
            'is_enabled' => true,
            'manage_stock' => false,
        ]);
        $bundle->translations()->create([
            'language_code' => 'en',
            'name' => 'Wave115 Bundle',
            'slug' => 'wave115-bundle-'.uniqid(),
        ]);
        ProductComponent::query()->create([
            'parent_product_id' => $bundle->id,
            'child_product_id' => $childA->id,
            'quantity' => 1,
            'position' => 1,
            'is_required' => true,
            'option_group' => 'Base pack',
        ]);

        $groupQ = $this->postJson('/api/v1/graphql', [
            'query' => 'query ($id: ID!) {
                product(id: $id) {
                    type
                    groupedProducts(first: 10) {
                        totalCount
                        edges {
                            node {
                                qty sortOrder
                                associatedProduct { id sku }
                            }
                        }
                    }
                }
            }',
            'variables' => ['id' => (string) $group->id],
        ]);
        $groupQ->assertOk();
        $this->assertArrayNotHasKey('errors', $groupQ->json() ?? [], json_encode($groupQ->json()));
        $this->assertSame(2, (int) $groupQ->json('data.product.groupedProducts.totalCount'));
        $skus = collect($groupQ->json('data.product.groupedProducts.edges') ?? [])
            ->pluck('node.associatedProduct.sku')->all();
        $this->assertContains($childA->sku, $skus);
        $this->assertContains($childB->sku, $skus);

        $bundleQ = $this->postJson('/api/v1/graphql', [
            'query' => 'query ($id: ID!) {
                product(id: $id) {
                    type
                    bundleOptions(first: 10) {
                        totalCount
                        edges {
                            node {
                                label type isRequired
                                associatedProduct { sku }
                            }
                        }
                    }
                }
            }',
            'variables' => ['id' => (string) $bundle->id],
        ]);
        $bundleQ->assertOk();
        $this->assertArrayNotHasKey('errors', $bundleQ->json() ?? [], json_encode($bundleQ->json()));
        $this->assertSame(1, (int) $bundleQ->json('data.product.bundleOptions.totalCount'));
        $this->assertSame('Base pack', (string) $bundleQ->json('data.product.bundleOptions.edges.0.node.label'));
        $this->assertSame($childA->sku, (string) $bundleQ->json('data.product.bundleOptions.edges.0.node.associatedProduct.sku'));
    }
}
