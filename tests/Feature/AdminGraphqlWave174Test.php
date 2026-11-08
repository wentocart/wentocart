<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\Review;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave174Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_customer_reviews_rest_moderation_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $products = Product::query()->where('is_enabled', true)->orderBy('id')->limit(3)->get();
        $this->assertGreaterThanOrEqual(1, $products->count());
        $p1 = $products[0];
        $p2 = $products[1] ?? $products[0];
        $p3 = $products[2] ?? $products[0];
        $customer = Customer::factory()->create();
        $customer2 = Customer::factory()->create();
        $customer3 = Customer::factory()->create();

        $pending = Review::query()->create([
            'product_id' => $p1->id,
            'customer_id' => $customer->id,
            'rating' => 4,
            'title' => 'Wave174 pending',
            'body' => 'Needs moderation',
            'is_approved' => false,
            'status' => Review::STATUS_PENDING,
        ]);
        $other = Review::query()->create([
            'product_id' => $p2->id,
            'customer_id' => $customer2->id,
            'rating' => 2,
            'title' => 'Wave174 other',
            'body' => 'Also pending',
            'is_approved' => false,
            'status' => Review::STATUS_PENDING,
        ]);

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/customers/reviews?status=pending');
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $this->assertArrayHasKey('meta', $list->json());
        $this->assertArrayHasKey('product', $list->json('data.0'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/customers/reviews/'.$pending->id);
        $show->assertOk();
        $this->assertSame('pending', (string) $show->json('status'));
        $this->assertIsArray($show->json('images'));

        $approve = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/customers/reviews/'.$pending->id, [
            'status' => 'approved',
        ]);
        $approve->assertOk();
        $this->assertSame('approved', (string) $approve->json('status'));
        $this->assertTrue(Review::query()->whereKey($pending->id)->value('is_approved'));

        $bad = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/customers/reviews/'.$pending->id, [
            'status' => 'nope',
        ]);
        $bad->assertStatus(422);

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/customers/reviews/bulk-update-status', [
            'ids' => [$other->id],
            'value' => 'disapproved',
        ]);
        $mass->assertOk();
        $this->assertSame(1, (int) $mass->json('updated_count'));
        $this->assertSame(Review::STATUS_REJECTED, (string) Review::query()->whereKey($other->id)->value('status'));

        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/customers/reviews/'.$other->id);
        $del->assertOk();
        $this->assertSame('disapproved', (string) $del->json('status'));
        $this->assertFalse(Review::query()->whereKey($other->id)->exists());

        $victim = Review::query()->create([
            'product_id' => $p3->id,
            'customer_id' => $customer3->id,
            'rating' => 1,
            'title' => 'Wave174 mass',
            'body' => 'delete me',
            'is_approved' => false,
            'status' => Review::STATUS_PENDING,
            'guest_email' => 'w174-mass-'.uniqid().'@example.test',
        ]);
        $massDel = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/customers/reviews/bulk-delete', [
            'ids' => [$victim->id, 999999],
        ]);
        $massDel->assertOk();
        $this->assertContains($victim->id, $massDel->json('deleted'));
        $this->assertFalse(Review::query()->whereKey($victim->id)->exists());

        // GraphQL still works
        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminCustomerReviews(status: "approved", limit: 10) { id status rating } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
    }
}
