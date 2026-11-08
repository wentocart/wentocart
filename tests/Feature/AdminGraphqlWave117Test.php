<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave117Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_product_videos_connection(): void
    {
        $this->seed();

        $product = Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);
        $product->update([
            'video_url' => json_encode([
                'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'https://cdn.example.com/demo.mp4',
            ], JSON_UNESCAPED_SLASHES),
        ]);

        $response = $this->postJson('/api/v1/graphql', [
            'query' => 'query ($id: ID!) {
                product(id: $id) {
                    videos(first: 10) {
                        totalCount
                        edges { node { id type url path position } }
                    }
                }
            }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? [], json_encode($response->json()));
        $this->assertSame(2, (int) $response->json('data.product.videos.totalCount'));
        $types = collect($response->json('data.product.videos.edges') ?? [])->pluck('node.type')->all();
        $this->assertContains('youtube', $types);
        $this->assertContains('file', $types);
    }
}
