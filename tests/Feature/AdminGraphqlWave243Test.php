<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\Review;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave243Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_shop_customer_reviews_rest(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $storeId = app(StoreContext::class)->requireId();
        $customer = Customer::factory()->create(['store_id' => $storeId]);
        $other = Customer::factory()->create(['store_id' => $storeId]);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $productB = Product::query()->create([
            'type' => 'simple',
            'sku' => 'W243-REV-B-'.uniqid(),
            'price' => 19.99,
            'is_enabled' => true,
        ]);
        $productB->translations()->create([
            'language_code' => 'en',
            'name' => 'Wave243 Review Product B',
            'slug' => 'w243-rev-b-'.uniqid(),
        ]);

        $approved = Review::query()->create([
            'product_id' => $product->id,
            'customer_id' => $customer->id,
            'rating' => 5,
            'title' => 'Great',
            'body' => 'Loved it',
            'is_approved' => true,
            'status' => Review::STATUS_APPROVED,
        ]);
        Review::query()->create([
            'product_id' => $productB->id,
            'customer_id' => $customer->id,
            'rating' => 3,
            'title' => 'Ok',
            'body' => 'Pending review',
            'is_approved' => false,
            'status' => Review::STATUS_PENDING,
        ]);
        Review::query()->create([
            'product_id' => $product->id,
            'customer_id' => $other->id,
            'rating' => 4,
            'title' => 'Other',
            'body' => 'Not mine',
            'is_approved' => true,
            'status' => Review::STATUS_APPROVED,
        ]);

        $list = $this->actingAs($customer, 'customer')->getJson('/api/v1/store/customer-reviews');
        $list->assertOk();
        $rows = $list->json();
        $this->assertIsArray($rows);
        $this->assertCount(2, $rows);
        $this->assertSame('10', (string) $list->headers->get('X-Per-Page'));
        $this->assertSame('2', (string) $list->headers->get('X-Total-Count'));
        $this->assertStringContainsString('/api/v1/store/products/', (string) ($rows[0]['product'] ?? ''));

        $pending = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/store/customer-reviews?status=pending');
        $pending->assertOk();
        $this->assertCount(1, $pending->json());
        $this->assertSame('pending', (string) $pending->json('0.status'));

        $five = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/store/customer-reviews?rating=5');
        $five->assertOk();
        $this->assertCount(1, $five->json());
        $this->assertSame(5, (int) $five->json('0.rating'));

        $show = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/store/customer-reviews/'.$approved->id);
        $show->assertOk();
        $this->assertSame((int) $approved->id, (int) $show->json('id'));
        $this->assertSame('approved', (string) $show->json('status'));

        $this->actingAs($other, 'customer')
            ->getJson('/api/v1/store/customer-reviews/'.$approved->id)
            ->assertNotFound();
    }
}
