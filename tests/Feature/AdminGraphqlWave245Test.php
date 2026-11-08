<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\Review;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave245Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_shop_patch_and_delete_reviews(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $storeId = app(StoreContext::class)->requireId();
        $customer = Customer::factory()->create(['store_id' => $storeId]);
        $other = Customer::factory()->create(['store_id' => $storeId]);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();

        $review = Review::query()->create([
            'product_id' => $product->id,
            'customer_id' => $customer->id,
            'rating' => 5,
            'title' => 'Original',
            'body' => 'Original body',
            'is_approved' => true,
            'status' => Review::STATUS_APPROVED,
        ]);

        $patch = $this->actingAs($customer, 'customer')->call(
            'PATCH',
            '/api/v1/store/reviews/'.$review->id,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/merge-patch+json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            json_encode(['title' => 'Updated title', 'rating' => 4], JSON_THROW_ON_ERROR)
        );
        $patch->assertOk();
        $this->assertSame('Updated title', (string) $patch->json('title'));
        $this->assertSame(4, (int) $patch->json('rating'));
        $this->assertSame('approved', (string) $patch->json('status'));

        // Wento also accepts application/json (API rejects with 415)
        $jsonOk = $this->actingAs($customer, 'customer')
            ->patchJson('/api/v1/store/reviews/'.$review->id, ['comment' => 'New comment']);
        $jsonOk->assertOk();
        $this->assertSame('New comment', (string) $jsonOk->json('comment'));

        $this->actingAs($other, 'customer')
            ->patchJson('/api/v1/store/reviews/'.$review->id, ['title' => 'Stolen'])
            ->assertStatus(403);

        $delForeign = $this->actingAs($other, 'customer')
            ->deleteJson('/api/v1/store/reviews/'.$review->id);
        $delForeign->assertStatus(403);

        $del = $this->actingAs($customer, 'customer')
            ->deleteJson('/api/v1/store/reviews/'.$review->id);
        $del->assertNoContent();
        $this->assertNull(Review::query()->find($review->id));
    }
}
