<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\Review;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave59Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_catalog_products_and_review_mass_rest_aliases(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $sku = 'W59-'.strtoupper(substr(uniqid(), -6));
        $created = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createAdminCatalogProduct(sku: $sku, name: "Wave59 Catalog", price: 33, stockQty: 5, enabled: true) {
                    id sku type status quantity is_enabled
                }
            }',
            'variables' => ['sku' => $sku],
        ]);
        $created->assertOk();
        $this->assertArrayNotHasKey('errors', $created->json() ?? [], json_encode($created->json()));
        $productId = (string) $created->json('data.createAdminCatalogProduct.id');
        $this->assertSame(1, (int) $created->json('data.createAdminCatalogProduct.status'));
        $this->assertSame('simple', (string) $created->json('data.createAdminCatalogProduct.type'));

        $updated = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateAdminCatalogProduct(id: $id, name: "Wave59 Catalog Updated", price: 35) {
                    id name price
                }
            }',
            'variables' => ['id' => $productId],
        ]);
        $updated->assertOk();
        $this->assertArrayNotHasKey('errors', $updated->json() ?? []);
        $this->assertSame('Wave59 Catalog Updated', (string) $updated->json('data.updateAdminCatalogProduct.name'));

        $list = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($sku: String!) {
                adminCatalogProducts(sku: $sku, status: 1, limit: 10) { id sku status quantity }
                adminCatalogProduct(id: "'.$productId.'") { id name type }
                adminProducts(q: $sku, limit: 5) { id sku is_enabled }
            }',
            'variables' => ['sku' => $sku],
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? [], json_encode($list->json()));
        $this->assertNotEmpty($list->json('data.adminCatalogProducts'));
        $this->assertSame($productId, (string) $list->json('data.adminCatalogProduct.id'));
        $this->assertNotEmpty($list->json('data.adminProducts'));

        $copy = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                createAdminCatalogProductCopy(id: $id) { id sku }
            }',
            'variables' => ['id' => $productId],
        ]);
        $copy->assertOk();
        $this->assertArrayNotHasKey('errors', $copy->json() ?? [], json_encode($copy->json()));
        $copyId = (string) $copy->json('data.createAdminCatalogProductCopy.id');
        $this->assertNotSame($productId, $copyId);

        $massStatus = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($ids: [ID!]!) {
                bulkUpdateAdminCatalogProductStatus(ids: $ids, value: 0) { ok updated_count }
            }',
            'variables' => ['ids' => [$productId, $copyId]],
        ]);
        $massStatus->assertOk();
        $this->assertArrayNotHasKey('errors', $massStatus->json() ?? [], json_encode($massStatus->json()));
        $this->assertGreaterThanOrEqual(1, (int) $massStatus->json('data.bulkUpdateAdminCatalogProductStatus.updated_count'));
        $this->assertFalse((bool) Product::query()->find((int) $productId)?->is_enabled);

        $customerA = Customer::factory()->create();
        $customerB = Customer::factory()->create();
        $r1 = Review::query()->create([
            'product_id' => (int) $productId,
            'customer_id' => $customerA->id,
            'rating' => 3,
            'title' => 'A',
            'body' => 'Wave59 r1',
            'status' => Review::STATUS_PENDING,
            'is_approved' => false,
        ]);
        $r2 = Review::query()->create([
            'product_id' => (int) $productId,
            'customer_id' => $customerB->id,
            'rating' => 2,
            'title' => 'B',
            'body' => 'Wave59 r2',
            'status' => Review::STATUS_PENDING,
            'is_approved' => false,
        ]);

        $massReviews = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($ids: [ID!]!) {
                bulkUpdateAdminCustomerReviewStatus(ids: $ids, status: "approved") { ok updated_count }
            }',
            'variables' => ['ids' => [(string) $r1->id, (string) $r2->id]],
        ]);
        $massReviews->assertOk();
        $this->assertArrayNotHasKey('errors', $massReviews->json() ?? [], json_encode($massReviews->json()));
        $this->assertSame(2, (int) $massReviews->json('data.bulkUpdateAdminCustomerReviewStatus.updated_count'));
        $this->assertSame(Review::STATUS_APPROVED, (string) $r1->fresh()->status);

        $massDeleteReviews = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($ids: [ID!]!) {
                bulkDeleteAdminCustomerReview(ids: $ids) { ok updated_count }
            }',
            'variables' => ['ids' => [(string) $r1->id, (string) $r2->id]],
        ]);
        $massDeleteReviews->assertOk();
        $this->assertSame(2, (int) $massDeleteReviews->json('data.bulkDeleteAdminCustomerReview.updated_count'));

        $bulkDelete = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($ids: [ID!]!) {
                bulkDeleteAdminCatalogProduct(ids: $ids) { ok updated_count }
            }',
            'variables' => ['ids' => [$productId, $copyId]],
        ]);
        $bulkDelete->assertOk();
        $this->assertArrayNotHasKey('errors', $bulkDelete->json() ?? [], json_encode($bulkDelete->json()));
        $this->assertGreaterThanOrEqual(1, (int) $bulkDelete->json('data.bulkDeleteAdminCatalogProduct.updated_count'));
    }
}
