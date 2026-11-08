<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductQtyPrice;
use App\Domain\Customer\Models\Customer;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminGraphqlWave132Test extends TestCase
{
    use RefreshDatabase;

    public function test_shop_create_remove_cart_items_bulk(): void
    {
        $this->seed();
        $base = Product::query()->where('is_enabled', true)->where('type', 'simple')->first()
            ?? Product::query()->where('is_enabled', true)->firstOrFail();
        $extra = Product::query()->create([
            'type' => 'simple',
            'sku' => 'W132-EXTRA-'.uniqid(),
            'price' => 19.99,
            'is_enabled' => true,
            'manage_stock' => true,
            'stock_qty' => 50,
        ]);
        $extra->translations()->create([
            'language_code' => 'en',
            'name' => 'Wave132 Extra',
            'slug' => 'wave132-extra-'.uniqid(),
        ]);
        $products = collect([$base, $extra]);
        foreach ($products as $product) {
            Product::query()->whereKey($product->id)->update([
                'manage_stock' => true,
                'stock_qty' => max(40, (int) $product->stock_qty),
                'is_enabled' => true,
            ]);
        }

        $customer = Customer::factory()->create([
            'password' => Hash::make('password'),
            'is_enabled' => true,
        ]);

        $ids = [];
        foreach ($products as $product) {
            $add = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
                'query' => 'mutation ($id: ID!) {
                    createAddProductInCart(productId: $id, quantity: 1) {
                        itemsList { id product_id }
                    }
                }',
                'variables' => ['id' => (string) $product->id],
            ]);
            $add->assertOk();
            $this->assertArrayNotHasKey('errors', $add->json() ?? [], json_encode($add->json()));
            $line = collect($add->json('data.createAddProductInCart.itemsList') ?? [])
                ->firstWhere('product_id', (string) $product->id);
            $this->assertNotNull($line, json_encode($add->json()));
            $ids[] = (string) $line['id'];
        }
        $this->assertCount(2, $ids);

        $bulk = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation ($ids: [ID!]!) {
                createRemoveCartItems(input: { itemIds: $ids }) {
                    item_count
                    itemsList { id }
                }
            }',
            'variables' => ['ids' => $ids],
        ]);
        $bulk->assertOk();
        $this->assertArrayNotHasKey('errors', $bulk->json() ?? [], json_encode($bulk->json()));
        $remaining = collect($bulk->json('data.createRemoveCartItems.itemsList') ?? [])->pluck('id')->all();
        foreach ($ids as $rid) {
            $this->assertNotContains($rid, $remaining);
        }
        $this->assertSame(0, (int) $bulk->json('data.createRemoveCartItems.item_count'));
    }

    public function test_admin_rest_image_and_group_price_aliases(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $product = Product::query()->where('is_enabled', true)->firstOrFail();
        Storage::disk('public')->put('products/w132-a.jpg', 'a');
        Storage::disk('public')->put('products/w132-b.jpg', 'b');

        $imgA = ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'products/w132-a.jpg',
            'alt' => 'A',
            'position' => 1,
        ]);
        $imgB = ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'products/w132-b.jpg',
            'alt' => 'B',
            'position' => 2,
        ]);

        $reorder = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($pid: ID!, $a: ID!, $b: ID!) {
                reorderAdminCatalogProductImage(
                    productId: $pid
                    order: [{ id: $b, position: 1 }, { id: $a, position: 2 }]
                ) { ok updated_count }
            }',
            'variables' => [
                'pid' => (string) $product->id,
                'a' => (string) $imgA->id,
                'b' => (string) $imgB->id,
            ],
        ]);
        $reorder->assertOk();
        $this->assertArrayNotHasKey('errors', $reorder->json() ?? [], json_encode($reorder->json()));
        $this->assertTrue((bool) $reorder->json('data.reorderAdminCatalogProductImage.ok'));
        $this->assertSame(1, (int) ProductImage::query()->find($imgB->id)?->position);
        $this->assertSame(2, (int) ProductImage::query()->find($imgA->id)?->position);

        $iri = '/api/v1/console/catalog/products/'.$product->id.'/images/'.$imgA->id;
        $update = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($iri: ID!, $pid: ID!) {
                updateAdminCatalogProductImage(id: $iri, productId: $pid, altText: "Wave132", position: 3) {
                    id alt position
                }
            }',
            'variables' => ['iri' => $iri, 'pid' => (string) $product->id],
        ]);
        $update->assertOk();
        $this->assertArrayNotHasKey('errors', $update->json() ?? [], json_encode($update->json()));
        $this->assertSame('Wave132', (string) $update->json('data.updateAdminCatalogProductImage.alt'));
        $this->assertSame(3, (int) $update->json('data.updateAdminCatalogProductImage.position'));

        $tier = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($pid: ID!) {
                createCustomerGroupPrice(productId: $pid, qty: 3, valueType: "fixed", value: 9.5) {
                    id qty value
                }
            }',
            'variables' => ['pid' => (string) $product->id],
        ]);
        $tier->assertOk();
        $this->assertArrayNotHasKey('errors', $tier->json() ?? [], json_encode($tier->json()));
        $tierId = (string) $tier->json('data.createCustomerGroupPrice.id');

        $updTier = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateAdminCatalogProductCustomerGroupPrice(id: $id, qty: 6, valueType: "fixed", value: 8) {
                    id qty value price
                }
            }',
            'variables' => ['id' => $tierId],
        ]);
        $updTier->assertOk();
        $this->assertArrayNotHasKey('errors', $updTier->json() ?? [], json_encode($updTier->json()));
        $this->assertSame(6.0, (float) $updTier->json('data.updateAdminCatalogProductCustomerGroupPrice.qty'));
        $this->assertSame(8.0, (float) $updTier->json('data.updateAdminCatalogProductCustomerGroupPrice.price'));

        $list = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($pid: ID!) {
                adminCatalogProductCustomerGroupPrices(productId: $pid) { id qty price }
            }',
            'variables' => ['pid' => (string) $product->id],
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? [], json_encode($list->json()));
        $listed = collect($list->json('data.adminCatalogProductCustomerGroupPrices') ?? [])->pluck('id')->all();
        $this->assertContains($tierId, $listed);

        $del = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($pid: ID!, $iri: ID!) {
                deleteAdminCatalogProductImage(productId: $pid, id: $iri) { ok id }
            }',
            'variables' => [
                'pid' => (string) $product->id,
                'iri' => $iri,
            ],
        ]);
        $del->assertOk();
        $this->assertArrayNotHasKey('errors', $del->json() ?? [], json_encode($del->json()));
        $this->assertTrue((bool) $del->json('data.deleteAdminCatalogProductImage.ok'));
        $this->assertNull(ProductImage::query()->find($imgA->id));
        $this->assertNotNull(ProductQtyPrice::query()->find((int) $tierId));
    }
}
