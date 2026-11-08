<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Services\UrlRewriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogCategoryFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_lists_only_its_children_and_filterable_features(): void
    {
        $this->seed();
        $urls = app(UrlRewriteService::class);
        $parent = Category::query()
            ->whereHas('translations', fn ($t) => $t->where('slug', 'catalog')->where('language_code', 'en'))
            ->firstOrFail();

        $child = Category::query()->create(['parent_id' => $parent->id, 'is_enabled' => true, 'position' => 20]);
        $child->translations()->create(['language_code' => 'en', 'name' => 'Women', 'slug' => 'women-test']);
        $urls->sync('category', $child->id, 'en', 'women-test');

        $other = Category::query()->create(['parent_id' => null, 'is_enabled' => true, 'position' => 90]);
        $other->translations()->create(['language_code' => 'en', 'name' => 'Electronics', 'slug' => 'electronics-test']);
        $urls->sync('category', $other->id, 'en', 'electronics-test');

        $response = $this->getJson('/api/v1/catalog/category/catalog')->assertOk();
        $children = collect($response->json('children'))->pluck('slug');

        $this->assertTrue($children->contains('women-test'));
        $this->assertFalse($children->contains('electronics-test'));
        $this->assertSame('catalog', $response->json('category.slug'));
        $this->assertIsArray($response->json('filters'));
        $this->assertArrayHasKey('min', $response->json('price'));
        $this->assertArrayHasKey('max', $response->json('price'));
    }

    public function test_category_can_filter_by_in_stock_and_feature_values(): void
    {
        $this->seed();

        $inStock = $this->getJson('/api/v1/catalog/category/catalog?availability=in_stock')->assertOk();
        $this->assertIsArray($inStock->json('products.data'));

        $feature = Feature::query()->where('is_filterable', true)->with('values')->first();
        if (! $feature || $feature->values->isEmpty()) {
            $this->markTestSkipped('Seeded shop has no filterable feature values.');
        }

        $this->getJson('/api/v1/catalog/category/catalog?values='.$feature->values->first()->id)->assertOk();
    }

    public function test_catalog_slug_lists_all_products_when_root_category_missing(): void
    {
        $this->seed();

        $catalog = Category::query()
            ->whereHas('translations', fn ($t) => $t->where('slug', 'catalog')->where('language_code', 'en'))
            ->firstOrFail();
        $catalog->translations()->update(['slug' => 'catalog-legacy']);
        app(UrlRewriteService::class)->sync('category', $catalog->id, 'en', 'catalog-legacy');

        $response = $this->getJson('/api/v1/catalog/category/catalog')->assertOk();
        $this->assertSame('catalog', $response->json('category.slug'));
        $this->assertSame(0, $response->json('category.id'));
        $this->assertNotEmpty($response->json('products.data'));
        $this->assertNotEmpty($response->json('children'));
    }
}
