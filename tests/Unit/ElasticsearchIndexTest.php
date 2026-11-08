<?php

namespace Tests\Unit;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductTranslation;
use App\Domain\Localization\Models\Language;
use App\Domain\Search\ElasticsearchIndex;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ElasticsearchIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_upsert_ensures_index_and_bulks_documents(): void
    {
        $this->seed();
        ShopSetting::put('search.elasticsearch', true);
        ShopSetting::put('search.elasticsearch_host', 'http://127.0.0.1:9200');
        ShopSetting::put('search.elasticsearch_index', 'wento_products');

        Http::fake([
            'http://127.0.0.1:9200/wento_products' => Http::sequence()
                ->push('', 404) // HEAD missing
                ->push(['acknowledged' => true], 200), // PUT create
            'http://127.0.0.1:9200/_bulk' => Http::response(['errors' => false], 200),
        ]);

        $product = Product::query()->create([
            'type' => 'simple',
            'sku' => 'ES-WRITE-1',
            'price' => 12,
            'is_enabled' => true,
        ]);
        ProductTranslation::query()->create([
            'product_id' => $product->id,
            'language_code' => Language::defaultCode(),
            'name' => 'Trail Boots',
            'slug' => 'trail-boots-es',
            'short_description' => 'Grip sole',
            'description' => 'All weather',
            'meta_keywords' => 'boots,trail',
        ]);

        app(ElasticsearchIndex::class)->upsert($product->fresh(['translations']));

        Http::assertSent(function ($request) {
            return $request->method() === 'HEAD'
                && str_ends_with($request->url(), '/wento_products');
        });
        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && str_ends_with($request->url(), '/wento_products')
                && data_get($request->data(), 'mappings.properties.name.type') === 'text';
        });
        Http::assertSent(function ($request) use ($product) {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/_bulk')) {
                return false;
            }
            $body = (string) $request->body();

            return str_contains($body, '"_id":"'.$product->id.'"')
                && str_contains($body, 'Trail Boots')
                && str_contains($body, 'boots,trail');
        });
    }

    public function test_delete_removes_document(): void
    {
        $this->seed();
        ShopSetting::put('search.elasticsearch', true);
        ShopSetting::put('search.elasticsearch_host', 'http://127.0.0.1:9200');
        ShopSetting::put('search.elasticsearch_index', 'products');

        Http::fake([
            'http://127.0.0.1:9200/products/_doc/99' => Http::response(['result' => 'deleted'], 200),
        ]);

        app(ElasticsearchIndex::class)->delete(99);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/products/_doc/99'));
    }

    public function test_ping_reports_cluster_version(): void
    {
        $this->seed();
        ShopSetting::put('search.elasticsearch', true);
        ShopSetting::put('search.elasticsearch_host', 'http://127.0.0.1:9200');

        Http::fake([
            'http://127.0.0.1:9200' => Http::response([
                'version' => ['number' => '8.15.0'],
            ], 200),
        ]);

        $result = app(ElasticsearchIndex::class)->ping();
        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('8.15.0', $result['message']);
    }
}
