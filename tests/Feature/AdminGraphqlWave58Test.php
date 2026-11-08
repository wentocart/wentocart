<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\Review;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave58Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_categories_and_customer_reviews_rest_aliases(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $created = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createAdminCategory(name: "Wave58 Aisle", slug: "wave58-aisle", enabled: true) {
                    id name slug status is_enabled parentId
                }
            }',
        ]);
        $created->assertOk();
        $this->assertArrayNotHasKey('errors', $created->json() ?? [], json_encode($created->json()));
        $categoryId = (string) $created->json('data.createAdminCategory.id');
        $this->assertSame(1, (int) $created->json('data.createAdminCategory.status'));

        $updated = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateAdminCategory(id: $id, name: "Wave58 Aisle Updated") { id name }
            }',
            'variables' => ['id' => $categoryId],
        ]);
        $updated->assertOk();
        $this->assertArrayNotHasKey('errors', $updated->json() ?? []);
        $this->assertSame('Wave58 Aisle Updated', (string) $updated->json('data.updateAdminCategory.name'));

        $list = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($q: String!) {
                adminCategories(limit: 20, q: $q, enabled: true) { id name status }
                adminCategory(id: "'.$categoryId.'") { id name slug }
            }',
            'variables' => ['q' => 'Wave58'],
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? [], json_encode($list->json()));
        $this->assertNotEmpty($list->json('data.adminCategories'));
        $this->assertSame($categoryId, (string) $list->json('data.adminCategory.id'));

        $sku = 'W58-'.strtoupper(substr(uniqid(), -6));
        $productId = (int) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createSimpleProduct(sku: $sku, name: "Wave58 Review Product", price: 22, stockQty: 2, enabled: true) { id }
            }',
            'variables' => ['sku' => $sku],
        ])->json('data.createSimpleProduct.id');
        Product::query()->whereKey($productId)->update(['manage_stock' => true, 'stock_qty' => 2]);

        $customer = Customer::factory()->create([
            'email' => 'w58-'.uniqid().'@example.com',
            'first_name' => 'Wave',
            'last_name' => 'FiftyEight',
        ]);

        $review = Review::query()->create([
            'product_id' => $productId,
            'customer_id' => $customer->id,
            'rating' => 4,
            'title' => 'Solid',
            'body' => 'Wave58 review body',
            'status' => Review::STATUS_PENDING,
            'is_approved' => false,
        ]);

        $reviews = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($pid: ID!) {
                adminCustomerReviews(status: "pending", productId: $pid, limit: 10) {
                    id rating status comment product_name customer_name
                }
                adminCustomerReview(id: "'.$review->id.'") { id status title body }
            }',
            'variables' => ['pid' => (string) $productId],
        ]);
        $reviews->assertOk();
        $this->assertArrayNotHasKey('errors', $reviews->json() ?? [], json_encode($reviews->json()));
        $this->assertNotEmpty($reviews->json('data.adminCustomerReviews'));
        $this->assertSame('pending', (string) $reviews->json('data.adminCustomerReview.status'));

        $approve = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateAdminCustomerReview(id: $id, status: "approved") { id status is_approved }
            }',
            'variables' => ['id' => (string) $review->id],
        ]);
        $approve->assertOk();
        $this->assertArrayNotHasKey('errors', $approve->json() ?? [], json_encode($approve->json()));
        $this->assertSame('approved', (string) $approve->json('data.updateAdminCustomerReview.status'));
        $this->assertTrue((bool) $approve->json('data.updateAdminCustomerReview.is_approved'));

        $disapprove = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateAdminCustomerReview(id: $id, status: "disapproved", rejectedReason: "spam") {
                    id status is_approved
                }
            }',
            'variables' => ['id' => (string) $review->id],
        ]);
        $disapprove->assertOk();
        $this->assertArrayNotHasKey('errors', $disapprove->json() ?? [], json_encode($disapprove->json()));
        $this->assertSame('rejected', (string) $disapprove->json('data.updateAdminCustomerReview.status'));

        $deleted = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                deleteAdminCustomerReview(id: $id) { ok id }
            }',
            'variables' => ['id' => (string) $review->id],
        ]);
        $deleted->assertOk();
        $this->assertArrayNotHasKey('errors', $deleted->json() ?? []);
        $this->assertTrue((bool) $deleted->json('data.deleteAdminCustomerReview.ok'));
        $this->assertNull(Review::query()->find($review->id));

        $catDelete = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                deleteAdminCategory(id: $id) { ok id }
            }',
            'variables' => ['id' => $categoryId],
        ]);
        $catDelete->assertOk();
        $this->assertArrayNotHasKey('errors', $catDelete->json() ?? [], json_encode($catDelete->json()));
        $this->assertTrue((bool) $catDelete->json('data.deleteAdminCategory.ok'));
    }
}
