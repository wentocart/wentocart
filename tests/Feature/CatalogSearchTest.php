<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Search\CatalogSearch;
use App\Domain\Search\Models\SearchPin;
use App\Domain\Search\Models\SearchRedirect;
use App\Domain\Search\Models\SearchSynonym;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CatalogSearchTest extends TestCase
{
    use RefreshDatabase;

    private function seedShirt(): Product
    {
        $product = Product::query()->create([
            'type' => 'simple',
            'sku' => 'HB-TEE-001',
            'price' => 19.99,
            'stock_qty' => 10,
            'is_enabled' => true,
            'visibility' => 'both',
        ]);
        $product->translations()->create([
            'language_code' => 'en',
            'name' => 'Humming Bird Printed T-Shirt',
            'slug' => 'humming-bird-printed-t-shirt',
        ]);

        return $product;
    }

    public function test_partial_case_insensitive_and_multi_word_name_search(): void
    {
        $this->seed();
        $product = $this->seedShirt();

        foreach (['humming', 'BIRD', 'printed', 'tshirt', 'T-Shirt', 'humming bird', 'bird humming', 'printed shirt'] as $q) {
            $response = $this->getJson('/api/v1/catalog/search?q='.rawurlencode($q));
            $response->assertOk();
            $ids = collect($response->json('data'))->pluck('id')->all();
            $this->assertContains(
                $product->id,
                $ids,
                "Expected product for query [{$q}]"
            );
        }
    }

    public function test_search_survives_corrupt_synonym_cache(): void
    {
        $this->seed();
        $this->seedShirt();

        // Mimic cache.serializable_classes=false turning a Collection into Incomplete_Class.
        $incomplete = unserialize('O:29:"Illuminate\\Support\\Collection":0:{}', ['allowed_classes' => false]);
        Cache::put('search.synonyms', $incomplete, 300);

        $this->getJson('/api/v1/catalog/search?q=serv')
            ->assertOk()
            ->assertJsonStructure(['data', 'query']);

        $this->assertIsArray(Cache::get('search.synonyms'));
    }

    public function test_typo_tolerance_finds_a_one_character_misspelling(): void
    {
        $this->seed();
        \App\Domain\Settings\ShopSetting::put('catalog.search.typo_tolerance', true);
        $product = Product::query()->create([
            'type' => 'simple',
            'sku' => 'RW-CAM-001',
            'price' => 49.99,
            'stock_qty' => 4,
            'is_enabled' => true,
            'visibility' => 'both',
        ]);
        $product->translations()->create([
            'language_code' => 'en',
            'name' => 'RoadWatch HD Dash Cam',
            'slug' => 'roadwatch-hd-dash-cam',
        ]);

        $ids = collect($this->getJson('/api/v1/catalog/search?q=roadewatch')->json('data'))->pluck('id')->all();
        $this->assertContains($product->id, $ids);
    }

    public function test_synonym_expand_uses_plain_array_cache(): void
    {
        $this->seed();
        SearchSynonym::query()->create([
            'term' => 'sofa',
            'aliases' => 'couch',
            'is_enabled' => true,
        ]);
        Cache::forget('search.synonyms');

        $terms = app(CatalogSearch::class)->expand('couch');
        $this->assertContains('sofa', $terms);
        $this->assertContains('couch', $terms);
        $this->assertIsArray(Cache::get('search.synonyms'));
        $this->assertSame('sofa', Cache::get('search.synonyms')[0]['term'] ?? null);
    }

    public function test_synonym_finds_a_product_by_its_alias(): void
    {
        $this->seed();
        $product = Product::query()->create([
            'type' => 'simple',
            'sku' => 'COUCH-001',
            'price' => 299,
            'stock_qty' => 3,
            'is_enabled' => true,
            'visibility' => 'both',
        ]);
        $product->translations()->create([
            'language_code' => 'en',
            'name' => 'Leather Couch',
            'slug' => 'leather-couch',
        ]);
        SearchSynonym::query()->create([
            'term' => 'sofa',
            'aliases' => 'couch, settee',
            'is_enabled' => true,
        ]);
        Cache::forget('search.synonyms');

        $ids = collect($this->getJson('/api/v1/catalog/search?q=sofa')->json('data'))->pluck('id')->all();
        $this->assertContains($product->id, $ids);

        $reverse = collect($this->getJson('/api/v1/catalog/search?q=settee')->json('data'))->pluck('id')->all();
        $this->assertContains($product->id, $reverse);
    }

    public function test_disabled_synonym_does_not_expand_the_search(): void
    {
        $this->seed();
        $product = Product::query()->create([
            'type' => 'simple',
            'sku' => 'COUCH-002',
            'price' => 199,
            'stock_qty' => 2,
            'is_enabled' => true,
            'visibility' => 'both',
        ]);
        $product->translations()->create([
            'language_code' => 'en',
            'name' => 'Leather Couch',
            'slug' => 'leather-couch-off',
        ]);
        SearchSynonym::query()->create([
            'term' => 'sofa',
            'aliases' => 'couch',
            'is_enabled' => false,
        ]);
        Cache::forget('search.synonyms');

        $ids = collect($this->getJson('/api/v1/catalog/search?q=sofa')->json('data'))->pluck('id')->all();
        $this->assertNotContains($product->id, $ids);
    }

    public function test_merchandising_pin_puts_the_chosen_product_first(): void
    {
        $this->seed();
        $first = Product::query()->create([
            'type' => 'simple',
            'sku' => 'LAMP-A',
            'price' => 40,
            'stock_qty' => 5,
            'is_enabled' => true,
            'visibility' => 'both',
        ]);
        $first->translations()->create([
            'language_code' => 'en',
            'name' => 'Amber Desk Lamp',
            'slug' => 'amber-desk-lamp',
        ]);
        $pinned = Product::query()->create([
            'type' => 'simple',
            'sku' => 'LAMP-B',
            'price' => 55,
            'stock_qty' => 5,
            'is_enabled' => true,
            'visibility' => 'both',
        ]);
        $pinned->translations()->create([
            'language_code' => 'en',
            'name' => 'Copper Desk Lamp',
            'slug' => 'copper-desk-lamp',
        ]);
        $extra = Product::query()->create([
            'type' => 'simple',
            'sku' => 'SHELF-1',
            'price' => 30,
            'stock_qty' => 4,
            'is_enabled' => true,
            'visibility' => 'both',
        ]);
        $extra->translations()->create([
            'language_code' => 'en',
            'name' => 'Oak Shelf',
            'slug' => 'oak-shelf',
        ]);
        SearchPin::query()->create([
            'query' => 'lamp',
            'product_id' => $pinned->id,
            'position' => 0,
        ]);
        SearchPin::query()->create([
            'query' => 'lamp',
            'product_id' => $extra->id,
            'position' => 1,
        ]);

        $response = $this->getJson('/api/v1/catalog/search?q=lamp')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$pinned->id, $extra->id], array_slice($ids, 0, 2));
        $this->assertContains($first->id, $ids);
        $this->assertSame(3, $response->json('total'));

        $other = collect($this->getJson('/api/v1/catalog/search?q=desk')->json('data'))->pluck('id')->all();
        $this->assertContains($first->id, $other);
        $this->assertContains($pinned->id, $other);
    }

    public function test_search_redirect_sends_an_exact_query_to_a_path(): void
    {
        $this->seed();
        SearchRedirect::query()->create([
            'query' => 'footwear',
            'target_path' => '/catalog/shoes',
            'is_enabled' => true,
        ]);
        SearchRedirect::query()->create([
            'query' => 'hidden-sale',
            'target_path' => '/catalog/sale',
            'is_enabled' => false,
        ]);

        $this->getJson('/api/v1/catalog/search?q=footwear')
            ->assertOk()
            ->assertJsonPath('redirect', '/catalog/shoes');
        $this->getJson('/api/v1/catalog/search?q=FOOTWEAR')
            ->assertOk()
            ->assertJsonPath('redirect', '/catalog/shoes');
        $this->getJson('/api/v1/catalog/search?q=hidden-sale')
            ->assertOk()
            ->assertJsonMissingPath('redirect');
    }

    public function test_search_payload_includes_query_and_total_for_results_summary(): void
    {
        $this->seed();
        $this->seedShirt();

        $withQuery = $this->getJson('/api/v1/catalog/search?q=humming')->assertOk();
        $withQuery->assertJsonPath('query', 'humming');
        $this->assertIsInt($withQuery->json('total'));
        $this->assertGreaterThanOrEqual(1, $withQuery->json('total'));
        $this->assertNotEmpty($withQuery->json('data'));

        $emptyQuery = $this->getJson('/api/v1/catalog/search')->assertOk();
        $emptyQuery->assertJsonStructure(['data', 'query', 'total']);
        $this->assertIsInt($emptyQuery->json('total'));

        $zero = $this->getJson('/api/v1/catalog/search?q=zzznomatchxyz')->assertOk();
        $zero->assertJsonPath('query', 'zzznomatchxyz');
        $this->assertSame(0, $zero->json('total'));
        $this->assertSame([], $zero->json('data'));
    }
}
