<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\Review;
use App\Domain\Marketing\Models\NewsletterSubscriber;
use App\Domain\Settings\ShopSetting;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminGraphqlWave95Test extends TestCase
{
    use RefreshDatabase;

    public function test_create_product_review_newsletter_and_bulk_update_products(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        ShopSetting::put('reviews.enabled', true);
        ShopSetting::put('reviews.who', 'registered');
        ShopSetting::put('reviews.moderation', 'required');
        ShopSetting::put('reviews.media', 'images');
        ShopSetting::put('reviews.min_length', 500);

        $customer = Customer::factory()->create();
        $product = Product::query()->where('is_enabled', true)->where('type', 'simple')->first()
            ?? Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);

        $png = base64_encode(base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));
        $attachments = json_encode(['data:image/png;base64,'.$png]);

        $review = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation (
                $pid: ID!, $title: String!, $comment: String!, $rating: Int!, $name: String!, $att: String, $cid: String
            ) {
                createProductReview(
                    productId: $pid
                    title: $title
                    comment: $comment
                    rating: $rating
                    name: $name
                    attachments: $att
                    clientMutationId: $cid
                ) {
                    clientMutationId
                    productReview {
                        id name title rating comment status attachments createdAt
                    }
                }
            }',
            'variables' => [
                'pid' => (string) $product->id,
                'title' => 'Wave95 solid',
                'comment' => 'Great product for wave ninety five regression coverage.',
                'rating' => 5,
                'name' => $customer->displayName(),
                'att' => $attachments,
                'cid' => 'w95-review-1',
            ],
        ]);
        $this->assertSame(200, $review->status(), 'review: '.json_encode($review->json()));
        $this->assertArrayNotHasKey('errors', $review->json() ?? [], json_encode($review->json()));
        $this->assertSame('w95-review-1', (string) $review->json('data.createProductReview.clientMutationId'));
        $this->assertSame(5, (int) $review->json('data.createProductReview.productReview.rating'));
        $this->assertSame('pending', (string) $review->json('data.createProductReview.productReview.status'));
        $this->assertNotEmpty($review->json('data.createProductReview.productReview.attachments'));
        $this->assertTrue(
            Review::query()->where('product_id', $product->id)->where('customer_id', $customer->id)->exists()
        );

        $newsletter = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                createNewsletter(email: "wave95-news@example.com") { ok message }
            }',
        ]);
        $this->assertSame(200, $newsletter->status(), 'newsletter: '.json_encode($newsletter->json()));
        $this->assertArrayNotHasKey('errors', $newsletter->json() ?? [], json_encode($newsletter->json()));
        $this->assertTrue((bool) $newsletter->json('data.createNewsletter.ok'));
        $this->assertTrue(
            NewsletterSubscriber::query()->where('email', 'wave95-news@example.com')->exists()
        );

        $dup = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                createNewsletter(email: "wave95-news@example.com") { ok message }
            }',
        ]);
        $dup->assertOk();
        $this->assertStringContainsString('Already', (string) $dup->json('data.createNewsletter.message'));

        Auth::guard('customer')->logout();
        $this->postJson('/api/v1/auth/logout');

        // Bearer Integration token — immune to storefront session churn after customer logout.
        $plain = $admin->createToken('wave95-bulk', ['*'])->plainTextToken;

        Product::query()->whereKey($product->id)->update(['weight' => 1, 'is_enabled' => true]);
        $bulk = $this->withToken($plain)->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                bulkUpdateProducts(productIds: [$id], status: true, weight: 2.5) {
                    ok status message updatedCount
                }
            }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $this->assertSame(200, $bulk->status(), 'bulk: '.json_encode($bulk->json()));
        $this->assertArrayNotHasKey('errors', $bulk->json() ?? [], json_encode($bulk->json()));
        $this->assertTrue((bool) $bulk->json('data.bulkUpdateProducts.ok'));
        $this->assertSame(1, (int) $bulk->json('data.bulkUpdateProducts.updatedCount'));
        $product->refresh();
        $this->assertTrue((bool) $product->is_enabled);
        $this->assertEqualsWithDelta(2.5, (float) $product->weight, 0.001);
    }
}
