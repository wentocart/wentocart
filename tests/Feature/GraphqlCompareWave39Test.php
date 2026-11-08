<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GraphqlCompareWave39Test extends TestCase
{
    use RefreshDatabase;

    public function test_compare_toggle_list_clear_and_product_by_url(): void
    {
        $this->seed();
        $product = Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);
        $slug = $product->translation()?->slug;
        $this->assertNotEmpty($slug);

        $toggle = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) {
                compareToggle(productId: $id) { compared product_ids }
            }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $toggle->assertOk();
        $this->assertArrayNotHasKey('errors', $toggle->json() ?? []);
        $this->assertTrue((bool) $toggle->json('data.compareToggle.compared'));
        $this->assertContains((string) $product->id, $toggle->json('data.compareToggle.ids') ?? []);

        $list = $this->postJson('/api/v1/graphql', [
            'query' => '{ compareList { id sku } }',
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? []);
        $ids = collect($list->json('data.compareList') ?? [])->pluck('id')->all();
        $this->assertContains((string) $product->id, $ids);

        $byUrl = $this->postJson('/api/v1/graphql', [
            'query' => 'query ($u: String!) { productByUrl(url: $u) { id slug } }',
            'variables' => ['u' => '/'.$slug],
        ]);
        $byUrl->assertOk();
        $this->assertArrayNotHasKey('errors', $byUrl->json() ?? []);
        $byUrl->assertJsonPath('data.productByUrl.id', (string) $product->id);

        $clear = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation { compareClear { ok message } }',
        ]);
        $clear->assertOk();
        $this->assertTrue((bool) $clear->json('data.compareClear.ok'));

        $empty = $this->postJson('/api/v1/graphql', [
            'query' => '{ compareList { id } }',
        ]);
        $empty->assertOk();
        $this->assertSame([], $empty->json('data.compareList'));
    }
}
