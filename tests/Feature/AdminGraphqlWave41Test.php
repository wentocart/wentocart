<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Order\Models\Order;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminGraphqlWave41Test extends TestCase
{
    use RefreshDatabase;

    public function test_copy_product_sale_cms_update_comments_and_shipments(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $sku = 'W41-'.strtoupper(substr(uniqid(), -6));
        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createSimpleProduct(sku: $sku, name: "Wave41 Product", price: 40, stockQty: 3, enabled: true) {
                    id sku name
                }
            }',
            'variables' => ['sku' => $sku],
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? []);
        $productId = (string) $create->json('data.createSimpleProduct.id');

        $sale = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateProductSale(id: $id, salePrice: 32, salePriceType: "amount", comparePrice: 45) {
                    id price
                }
            }',
            'variables' => ['id' => $productId],
        ]);
        $sale->assertOk();
        $this->assertArrayNotHasKey('errors', $sale->json() ?? []);
        $fresh = Product::query()->find((int) $productId);
        $this->assertEqualsWithDelta(32.0, (float) $fresh->sale_price, 0.001);
        $this->assertEqualsWithDelta(45.0, (float) $fresh->compare_price, 0.001);

        Storage::disk('public')->put('catalog/products/'.$productId.'/a.png', 'a');
        Storage::disk('public')->put('catalog/products/'.$productId.'/b.png', 'b');
        $imgA = ProductImage::query()->create([
            'product_id' => (int) $productId,
            'path' => 'catalog/products/'.$productId.'/a.png',
            'alt' => 'A',
            'position' => 1,
        ]);
        $imgB = ProductImage::query()->create([
            'product_id' => (int) $productId,
            'path' => 'catalog/products/'.$productId.'/b.png',
            'alt' => 'B',
            'position' => 2,
        ]);
        $reorder = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($pid: ID!, $a: ID!, $b: ID!) {
                reorderProductImages(productId: $pid, imageIds: [$b, $a]) { ok updated_count }
            }',
            'variables' => [
                'pid' => $productId,
                'a' => (string) $imgA->id,
                'b' => (string) $imgB->id,
            ],
        ]);
        $reorder->assertOk();
        $this->assertArrayNotHasKey('errors', $reorder->json() ?? []);
        $this->assertSame(1, (int) ProductImage::query()->find($imgB->id)?->position);
        $this->assertSame(2, (int) ProductImage::query()->find($imgA->id)?->position);

        $copy = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { copyProduct(id: $id) { id sku name } }',
            'variables' => ['id' => $productId],
        ]);
        $copy->assertOk();
        $this->assertArrayNotHasKey('errors', $copy->json() ?? [], json_encode($copy->json()));
        $copyId = (string) $copy->json('data.copyProduct.id');
        $this->assertNotSame($productId, $copyId);

        $bulk = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { bulkDeleteProducts(ids: [$id]) { ok updated_count } }',
            'variables' => ['id' => $copyId],
        ]);
        $bulk->assertOk();
        $this->assertArrayNotHasKey('errors', $bulk->json() ?? []);
        $this->assertGreaterThanOrEqual(1, (int) $bulk->json('data.bulkDeleteProducts.updated_count'));

        $cms = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createCmsPage(title: "Wave41 Page", slug: "wave41-page", body: "x", status: "draft") { id slug }
            }',
        ]);
        $cms->assertOk();
        $cmsId = (string) $cms->json('data.createCmsPage.id');
        $updCms = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateCmsPage(id: $id, title: "Wave41 Updated", status: "published") { id title status }
            }',
            'variables' => ['id' => $cmsId],
        ]);
        $updCms->assertOk();
        $this->assertArrayNotHasKey('errors', $updCms->json() ?? []);
        $updCms->assertJsonPath('data.updateCmsPage.status', 'published');
        $this->assertSame('published', CmsPage::query()->find((int) $cmsId)?->status);

        $order = Order::query()->first();
        if ($order) {
            $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
                'query' => 'mutation ($n: String!) {
                    addOrderComment(number: $n, body: "Wave41 note", visibility: "private") { id body }
                }',
                'variables' => ['n' => $order->number],
            ])->assertOk();

            $comments = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
                'query' => 'query ($n: String!) { orderComments(number: $n) { id body } }',
                'variables' => ['n' => $order->number],
            ]);
            $comments->assertOk();
            $this->assertArrayNotHasKey('errors', $comments->json() ?? []);
            $bodies = collect($comments->json('data.orderComments') ?? [])->pluck('body')->all();
            $this->assertContains('Wave41 note', $bodies);

            $shipments = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
                'query' => '{ shipments(limit: 5) { id order_id status } }',
            ]);
            $shipments->assertOk();
            $this->assertArrayNotHasKey('errors', $shipments->json() ?? []);
            $this->assertIsArray($shipments->json('data.shipments'));
        }
    }
}
