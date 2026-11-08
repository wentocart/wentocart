<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave108Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_product_lookup_media_and_product_reviews_connection(): void
    {
        $this->seed();

        $product = Product::query()->where('is_enabled', true)->with(['translations', 'images'])->first();
        $this->assertNotNull($product);

        $slug = (string) ($product->translation('en')?->slug ?? $product->translation()?->slug);
        $this->assertNotSame('', $slug);

        $byId = $this->postJson('/api/v1/graphql', [
            'query' => 'query ($id: ID!) {
                product(id: $id) {
                    id sku name slug type status
                    baseImageUrl shortDescription
                    listing { metaTitle new featured status }
                    images { totalCount edges { node { id url publicPath position } } }
                    variantAxisOptions
                    combinations
                }
            }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $byId->assertOk();
        $this->assertArrayNotHasKey('errors', $byId->json() ?? [], json_encode($byId->json()));
        $this->assertSame((string) $product->id, (string) $byId->json('data.product.id'));
        $this->assertSame($slug, (string) $byId->json('data.product.slug'));
        $this->assertSame(1, (int) $byId->json('data.product.status'));
        $this->assertIsArray($byId->json('data.product.images.edges'));

        $bySku = $this->postJson('/api/v1/graphql', [
            'query' => 'query ($sku: String!) {
                product(sku: $sku) { id sku }
            }',
            'variables' => ['sku' => (string) $product->sku],
        ]);
        $bySku->assertOk();
        $this->assertArrayNotHasKey('errors', $bySku->json() ?? [], json_encode($bySku->json()));
        $this->assertSame((string) $product->id, (string) $bySku->json('data.product.id'));

        $byUrlKey = $this->postJson('/api/v1/graphql', [
            'query' => 'query ($slug: String!) {
                product(slug: $slug) { id slug slug }
            }',
            'variables' => ['slug' => $slug],
        ]);
        $byUrlKey->assertOk();
        $this->assertArrayNotHasKey('errors', $byUrlKey->json() ?? [], json_encode($byUrlKey->json()));
        $this->assertSame((string) $product->id, (string) $byUrlKey->json('data.product.id'));

        $approved = Customer::factory()->create(['name' => 'Wave108 Reviewer']);
        Review::query()->create([
            'product_id' => $product->id,
            'customer_id' => $approved->id,
            'rating' => 4,
            'title' => 'Wave108 approved',
            'body' => 'Looks good',
            'is_approved' => true,
            'status' => Review::STATUS_APPROVED,
        ]);
        Review::query()->create([
            'product_id' => $product->id,
            'customer_id' => Customer::factory()->create()->id,
            'rating' => 2,
            'title' => 'Wave108 pending',
            'body' => 'Hidden',
            'is_approved' => false,
            'status' => Review::STATUS_PENDING,
        ]);

        $reviews = $this->postJson('/api/v1/graphql', [
            'query' => 'query ($pid: Int!) {
                productReviews(product_id: $pid, first: 10) {
                    totalCount
                    pageInfo { hasNextPage hasPreviousPage }
                    edges {
                        cursor
                        node { id title rating status comment }
                    }
                }
            }',
            'variables' => ['pid' => (int) $product->id],
        ]);
        $reviews->assertOk();
        $this->assertArrayNotHasKey('errors', $reviews->json() ?? [], json_encode($reviews->json()));
        $this->assertGreaterThanOrEqual(1, (int) $reviews->json('data.productReviews.totalCount'));
        $titles = collect($reviews->json('data.productReviews.edges') ?? [])
            ->pluck('node.title')
            ->all();
        $this->assertContains('Wave108 approved', $titles);
        $this->assertNotContains('Wave108 pending', $titles);

        $pending = $this->postJson('/api/v1/graphql', [
            'query' => 'query ($pid: Int!) {
                productReviews(product_id: $pid, status: "pending", first: 10) {
                    totalCount
                    edges { node { title status } }
                }
            }',
            'variables' => ['pid' => (int) $product->id],
        ]);
        $pending->assertOk();
        $this->assertArrayNotHasKey('errors', $pending->json() ?? [], json_encode($pending->json()));
        $pendingTitles = collect($pending->json('data.productReviews.edges') ?? [])
            ->pluck('node.title')
            ->all();
        $this->assertContains('Wave108 pending', $pendingTitles);
    }
}
