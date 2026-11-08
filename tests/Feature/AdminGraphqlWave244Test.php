<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\Review;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave244Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_shop_create_product_review_rest(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);
        ShopSetting::put('reviews.enabled', true);
        ShopSetting::put('reviews.who', 'registered');
        ShopSetting::put('reviews.min_length', 500);

        $storeId = app(StoreContext::class)->requireId();
        $customer = Customer::factory()->create(['store_id' => $storeId]);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();

        $guest = $this->postJson('/api/v1/store/reviews', [
            'productId' => $product->id,
            'title' => 'Nope',
            'comment' => 'Guest should be blocked',
            'rating' => 5,
        ]);
        $guest->assertStatus(403);

        $created = $this->actingAs($customer, 'customer')->postJson('/api/v1/store/reviews', [
            'productId' => (int) $product->id,
            'title' => 'Excellent product!',
            'comment' => 'Very satisfied with this purchase.',
            'rating' => 5,
            'name' => 'Tester',
        ]);
        $created->assertCreated();
        $this->assertSame('Excellent product!', (string) $created->json('title'));
        $this->assertSame('pending', (string) $created->json('status'));
        $this->assertSame(5, (int) $created->json('rating'));
        $this->assertGreaterThan(0, (int) $created->json('id'));

        $row = Review::query()->find((int) $created->json('id'));
        $this->assertNotNull($row);
        $this->assertSame((int) $customer->id, (int) $row->customer_id);
        $this->assertSame((int) $product->id, (int) $row->product_id);

        ShopSetting::put('reviews.enabled', false);
        $this->actingAs($customer, 'customer')->postJson('/api/v1/store/reviews', [
            'productId' => (int) $product->id,
            'title' => 'Off',
            'comment' => 'Disabled',
            'rating' => 4,
        ])->assertStatus(403);
    }
}
