<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\Review;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave247Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_shop_product_reviews_list(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $storeId = app(StoreContext::class)->requireId();
        $customer = Customer::factory()->create(['store_id' => $storeId]);
        $customer2 = Customer::factory()->create(['store_id' => $storeId]);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $other = Product::query()->create([
            'type' => 'simple',
            'sku' => 'W247-'.uniqid(),
            'price' => 9.99,
            'is_enabled' => true,
        ]);
        $other->translations()->create([
            'language_code' => 'en',
            'name' => 'Wave247 Other',
            'slug' => 'w247-'.uniqid(),
        ]);

        Review::query()->create([
            'product_id' => $product->id,
            'customer_id' => $customer->id,
            'rating' => 5,
            'title' => 'A',
            'body' => 'Approved first',
            'is_approved' => true,
            'status' => Review::STATUS_APPROVED,
        ]);
        Review::query()->create([
            'product_id' => $product->id,
            'customer_id' => $customer2->id,
            'rating' => 3,
            'title' => 'Pending',
            'body' => 'Pending hidden by default',
            'is_approved' => false,
            'status' => Review::STATUS_PENDING,
        ]);

        $list = $this->getJson('/api/v1/store/products/'.$product->id.'/reviews');
        $list->assertOk();
        $rows = $list->json();
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame('approved', (string) $rows[0]['status']);
        $this->assertSame('1', (string) $list->headers->get('X-Total-Count'));

        $pending = $this->getJson('/api/v1/store/products/'.$product->id.'/reviews?status=pending');
        $pending->assertOk();
        $this->assertCount(1, $pending->json());
        $this->assertSame('pending', (string) $pending->json('0.status'));

        $five = $this->getJson('/api/v1/store/products/'.$product->id.'/reviews?rating=5');
        $five->assertOk();
        $this->assertCount(1, $five->json());

        $this->getJson('/api/v1/store/products/'.$other->id.'/reviews')->assertOk()
            ->assertExactJson([]);

        $this->getJson('/api/v1/store/products/999999/reviews')->assertNotFound();
    }
}
