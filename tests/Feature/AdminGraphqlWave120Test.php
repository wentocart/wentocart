<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\FeatureValue;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductQtyPrice;
use App\Domain\Catalog\Models\Warehouse;
use App\Domain\Catalog\Models\WarehouseStock;
use App\Domain\Customer\Models\Review;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminGraphqlWave120Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_catalog_product_inventories_group_prices_translations(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $product = Product::query()->where('is_enabled', true)->first()
            ?? Product::query()->create([
                'type' => 'simple',
                'sku' => 'W120-ADMIN-'.uniqid(),
                'price' => 42.5,
                'weight' => 1.25,
                'is_enabled' => true,
                'manage_stock' => true,
                'stock_qty' => 10,
            ]);
        if (! $product->translations()->exists()) {
            $product->translations()->create([
                'language_code' => 'en',
                'name' => 'Wave120 Admin Product',
                'slug' => 'wave120-admin-'.uniqid(),
            ]);
        }

        $warehouse = Warehouse::query()->first()
            ?? Warehouse::query()->create([
                'code' => 'W120',
                'name' => 'Wave120 Warehouse',
                'email' => 'w120@example.com',
                'country' => 'US',
                'is_enabled' => true,
                'is_default' => true,
            ]);
        WarehouseStock::query()->updateOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $warehouse->id],
            ['qty' => 17, 'reserved_qty' => 2]
        );
        ProductQtyPrice::query()->create([
            'product_id' => $product->id,
            'min_qty' => 5,
            'max_qty' => 20,
            'price' => 38.0,
            'position' => 1,
        ]);

        $response = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($id: ID!) {
                adminCatalogProduct(id: $id) {
                    id sku formattedPrice weight
                    inventories(first: 10) {
                        totalCount
                        edges { node { id sourceId sourceCode qty } }
                    }
                    customerGroupPrices(first: 10) {
                        totalCount
                        edges { node { id qty valueType value } }
                    }
                    translations(first: 10) {
                        totalCount
                        edges { node { locale name slug } }
                    }
                }
            }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? [], json_encode($response->json()));
        $this->assertSame((int) $product->id, (int) $response->json('data.adminCatalogProduct.id'));
        $this->assertGreaterThanOrEqual(1, (int) $response->json('data.adminCatalogProduct.inventories.totalCount'));
        $this->assertSame(17.0, (float) $response->json('data.adminCatalogProduct.inventories.edges.0.node.qty'));
        $this->assertGreaterThanOrEqual(1, (int) $response->json('data.adminCatalogProduct.customerGroupPrices.totalCount'));
        $this->assertSame(5.0, (float) $response->json('data.adminCatalogProduct.customerGroupPrices.edges.0.node.qty'));
        $this->assertSame('fixed', (string) $response->json('data.adminCatalogProduct.customerGroupPrices.edges.0.node.valueType'));
        $this->assertGreaterThanOrEqual(1, (int) $response->json('data.adminCatalogProduct.translations.totalCount'));
        $this->assertSame('en', (string) $response->json('data.adminCatalogProduct.translations.edges.0.node.locale'));
    }

    public function test_shop_product_cost_weight_rating_attributes_and_sku_alias(): void
    {
        $this->seed();

        $product = Product::query()->create([
            'type' => 'simple',
            'sku' => 'W120-SHOP-'.uniqid(),
            'price' => 55,
            'cost_price' => 22.5,
            'weight' => 0.75,
            'is_enabled' => true,
            'manage_stock' => false,
        ]);
        $product->translations()->create([
            'language_code' => 'en',
            'name' => 'Wave120 Shop Product',
            'slug' => 'wave120-shop-'.uniqid(),
        ]);

        $feature = Feature::query()->create([
            'code' => 'w120_material',
            'type' => 'select',
            'is_enabled' => true,
            'is_filterable' => true,
            'position' => 1,
        ]);
        $feature->translations()->create([
            'language_code' => 'en',
            'name' => 'Material',
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

        Review::query()->create([
            'product_id' => $product->id,
            'rating' => 5,
            'title' => 'Great',
            'body' => 'Solid',
            'is_approved' => true,
            'status' => Review::STATUS_APPROVED,
            'guest_name' => 'Tester',
        ]);
        Review::query()->create([
            'product_id' => $product->id,
            'rating' => 3,
            'title' => 'Ok',
            'body' => 'Fine',
            'is_approved' => true,
            'status' => Review::STATUS_APPROVED,
            'guest_name' => 'Tester2',
        ]);

        $response = $this->postJson('/api/v1/graphql', [
            'query' => 'query ($sku: String!) {
                productBySkU(sku: $sku) {
                    sku cost weight formattedPrice
                    rating { rate count }
                    attributes(first: 10) {
                        totalCount
                        edges { node { code label value } }
                    }
                }
            }',
            'variables' => ['sku' => $product->sku],
        ]);
        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? [], json_encode($response->json()));
        $this->assertSame(22.5, (float) $response->json('data.productBySkU.cost'));
        $this->assertSame(0.75, (float) $response->json('data.productBySkU.weight'));
        $this->assertSame('55.00', (string) $response->json('data.productBySkU.formattedPrice'));
        $this->assertSame(2, (int) $response->json('data.productBySkU.rating.count'));
        $this->assertEqualsWithDelta(4.0, (float) $response->json('data.productBySkU.rating.rate'), 0.01);
        $this->assertGreaterThanOrEqual(1, (int) $response->json('data.productBySkU.attributes.totalCount'));
        $this->assertSame('w120_material', (string) $response->json('data.productBySkU.attributes.edges.0.node.code'));
        $this->assertSame('Cotton', (string) $response->json('data.productBySkU.attributes.edges.0.node.value'));
    }
}
