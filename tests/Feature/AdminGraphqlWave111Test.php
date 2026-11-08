<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminGraphqlWave111Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_product_related_upsell_crosssell_connections(): void
    {
        $this->seed();

        $main = Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($main);

        $related = Product::query()->create([
            'type' => 'simple',
            'sku' => 'W111-REL-'.uniqid(),
            'price' => 19.99,
            'stock_qty' => 10,
            'stock_status' => 'in_stock',
            'is_enabled' => true,
        ]);
        $related->translations()->create([
            'language_code' => 'en',
            'name' => 'Wave111 Related',
            'slug' => 'wave111-related-'.uniqid(),
        ]);

        $upsell = Product::query()->create([
            'type' => 'simple',
            'sku' => 'W111-UP-'.uniqid(),
            'price' => 49.99,
            'stock_qty' => 10,
            'stock_status' => 'in_stock',
            'is_enabled' => true,
        ]);
        $upsell->translations()->create([
            'language_code' => 'en',
            'name' => 'Wave111 Upsell',
            'slug' => 'wave111-upsell-'.uniqid(),
        ]);

        $cross = Product::query()->create([
            'type' => 'simple',
            'sku' => 'W111-X-'.uniqid(),
            'price' => 9.99,
            'stock_qty' => 10,
            'stock_status' => 'in_stock',
            'is_enabled' => true,
        ]);
        $cross->translations()->create([
            'language_code' => 'en',
            'name' => 'Wave111 Cross',
            'slug' => 'wave111-cross-'.uniqid(),
        ]);

        DB::table('product_relations')->insert([
            ['product_id' => $main->id, 'related_product_id' => $related->id, 'type' => 'related'],
            ['product_id' => $main->id, 'related_product_id' => $upsell->id, 'type' => 'upsell'],
            ['product_id' => $main->id, 'related_product_id' => $cross->id, 'type' => 'crosssell'],
        ]);

        $response = $this->postJson('/api/v1/graphql', [
            'query' => 'query ($id: ID!) {
                product(id: $id) {
                    id sku
                    relatedProducts(first: 10) {
                        totalCount
                        edges { node { id sku } }
                    }
                    upSells(first: 10) {
                        totalCount
                        edges { node { id sku } }
                    }
                    crossSells(first: 10) {
                        totalCount
                        edges { node { id sku } }
                    }
                }
            }',
            'variables' => ['id' => (string) $main->id],
        ]);
        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? [], json_encode($response->json()));

        $relatedIds = collect($response->json('data.product.relatedProducts.edges') ?? [])
            ->pluck('node.id')->map(fn ($id) => (string) $id)->all();
        $upsellIds = collect($response->json('data.product.upSells.edges') ?? [])
            ->pluck('node.id')->map(fn ($id) => (string) $id)->all();
        $crossIds = collect($response->json('data.product.crossSells.edges') ?? [])
            ->pluck('node.id')->map(fn ($id) => (string) $id)->all();

        $this->assertContains((string) $related->id, $relatedIds);
        $this->assertContains((string) $upsell->id, $upsellIds);
        $this->assertContains((string) $cross->id, $crossIds);
        $this->assertGreaterThanOrEqual(1, (int) $response->json('data.product.relatedProducts.totalCount'));
        $this->assertGreaterThanOrEqual(1, (int) $response->json('data.product.upSells.totalCount'));
        $this->assertGreaterThanOrEqual(1, (int) $response->json('data.product.crossSells.totalCount'));
    }
}
