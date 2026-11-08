<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\Review;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave109Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_update_and_delete_product_review(): void
    {
        $this->seed();
        ShopSetting::put('reviews.enabled', true);
        ShopSetting::put('reviews.who', 'registered');

        $customer = Customer::factory()->create();
        $other = Customer::factory()->create();
        $product = Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);

        $review = Review::query()->create([
            'product_id' => $product->id,
            'customer_id' => $customer->id,
            'rating' => 3,
            'title' => 'Wave109 original',
            'body' => 'Original comment body',
            'is_approved' => true,
            'status' => Review::STATUS_APPROVED,
        ]);

        $update = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation ($input: UpdateProductReviewInput!) {
                updateProductReview(input: $input) {
                    clientMutationId
                    productReview { id title comment rating status updatedAt }
                }
            }',
            'variables' => [
                'input' => [
                    'id' => '/api/v1/store/reviews/'.$review->id,
                    'title' => 'Wave109 edited',
                    'comment' => 'Updated after a month of use.',
                    'rating' => 5,
                    'clientMutationId' => 'w109-edit',
                ],
            ],
        ]);
        $update->assertOk();
        $this->assertArrayNotHasKey('errors', $update->json() ?? [], json_encode($update->json()));
        $this->assertSame('w109-edit', (string) $update->json('data.updateProductReview.clientMutationId'));
        $this->assertSame('Wave109 edited', (string) $update->json('data.updateProductReview.productReview.title'));
        $this->assertSame('Updated after a month of use.', (string) $update->json('data.updateProductReview.productReview.comment'));
        $this->assertSame(5, (int) $update->json('data.updateProductReview.productReview.rating'));
        $this->assertSame('approved', (string) $update->json('data.updateProductReview.productReview.status'));

        $stolen = $this->actingAs($other, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation ($input: UpdateProductReviewInput!) {
                updateProductReview(input: $input) { productReview { id } }
            }',
            'variables' => [
                'input' => [
                    'id' => (string) $review->id,
                    'title' => 'Hijack',
                ],
            ],
        ]);
        $stolen->assertOk();
        $this->assertNotEmpty($stolen->json('errors'));

        $delete = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation ($input: DeleteProductReviewInput!) {
                deleteProductReview(input: $input) {
                    clientMutationId
                    productReview { id }
                }
            }',
            'variables' => [
                'input' => [
                    'id' => '/api/v1/store/reviews/'.$review->id,
                    'clientMutationId' => 'w109-del',
                ],
            ],
        ]);
        $delete->assertOk();
        $this->assertArrayNotHasKey('errors', $delete->json() ?? [], json_encode($delete->json()));
        $this->assertSame('w109-del', (string) $delete->json('data.deleteProductReview.clientMutationId'));
        $this->assertSame(
            '/api/v1/store/reviews/'.$review->id,
            (string) $delete->json('data.deleteProductReview.productReview.id')
        );
        $this->assertFalse(Review::query()->where('id', $review->id)->exists());
    }
}
