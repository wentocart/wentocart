<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductTranslation;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Localization\Models\Language;
use App\Domain\Search\CatalogSearchIndex;
use App\Domain\Settings\ShopSetting;
use App\Jobs\ReindexCatalogSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ElasticsearchWriterTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_save_upserts_elasticsearch_document(): void
    {
        $this->seed();
        ShopSetting::put('search.elasticsearch', true);
        ShopSetting::put('search.elasticsearch_host', 'http://es.test:9200');
        ShopSetting::put('search.elasticsearch_index', 'catalog');
        ShopSetting::put('search.meilisearch', false);

        Http::fake([
            'http://es.test:9200/catalog' => Http::sequence()
                ->push('', 200) // HEAD exists
                ->push(['acknowledged' => true], 200),
            'http://es.test:9200/_bulk' => Http::response(['errors' => false], 200),
            'http://es.test:9200/catalog/_doc/*' => Http::response(['result' => 'deleted'], 200),
        ]);

        $product = app(ProductService::class)->save([
            'product' => [
                'type' => 'simple',
                'sku' => 'ES-SVC-1',
                'price' => 19.5,
                'is_enabled' => true,
            ],
            'translations' => [
                Language::defaultCode() => [
                    'name' => 'Alpine Jacket',
                    'slug' => 'alpine-jacket-es',
                ],
            ],
        ]);

        Http::assertSent(function ($request) use ($product) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/_bulk')
                && str_contains((string) $request->body(), 'Alpine Jacket')
                && str_contains((string) $request->body(), '"_id":"'.$product->id.'"');
        });

        app(ProductService::class)->delete($product);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/catalog/_doc/'.$product->id));
    }

    public function test_reindex_job_writes_enabled_engines(): void
    {
        $this->seed();
        ShopSetting::put('search.elasticsearch', true);
        ShopSetting::put('search.elasticsearch_host', 'http://es.test:9200');
        ShopSetting::put('search.elasticsearch_index', 'products');

        $product = Product::query()->create([
            'type' => 'simple',
            'sku' => 'ES-REINDEX-1',
            'price' => 5,
            'is_enabled' => true,
        ]);
        ProductTranslation::query()->create([
            'product_id' => $product->id,
            'language_code' => Language::defaultCode(),
            'name' => 'Reindex Hat',
            'slug' => 'reindex-hat',
        ]);

        Http::fake([
            'http://es.test:9200/products' => Http::response('', 200),
            'http://es.test:9200/_bulk' => Http::response(['errors' => false], 200),
        ]);

        $this->assertTrue(app(CatalogSearchIndex::class)->anyEnabled());
        (new ReindexCatalogSearch)->handle(app(CatalogSearchIndex::class));

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/_bulk')
            && str_contains((string) $request->body(), 'Reindex Hat'));
    }
}
