<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Review;
use App\Domain\Settings\SearchCatalogSettings;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\Models\ProductStore;
use App\Domain\Store\Models\Store;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSortOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_sort_options_cover_admin_and_storefront_keys(): void
    {
        $values = array_column(SearchCatalogSettings::sortOptionDefinitions(), 'value');
        $this->assertSame(SearchCatalogSettings::SORT_OPTIONS, $values);
        $this->assertSame(
            ['relevance', 'popularity', 'newest', 'price_asc', 'price_desc', 'rating', 'discount'],
            SearchCatalogSettings::SORT_OPTIONS
        );
        $this->assertSame(count($values), count(array_unique($values)));

        $admin = (string) file_get_contents(base_path('resources/js/admin/Pages/Settings/SearchCatalog.jsx'));
        $this->assertStringContainsString('sort_options', $admin);
        $this->assertStringContainsString("value: 'rating'", $admin);
        $this->assertStringContainsString("value: 'discount'", $admin);
        $this->assertStringContainsString("value: 'relevance'", $admin);

        $commerce = (string) file_get_contents(base_path('storefront/src/lib/commerce.js'));
        $this->assertStringContainsString('export const CATALOG_SORT_OPTIONS', $commerce);
        $this->assertStringContainsString("value: 'relevance'", $commerce);
        $this->assertStringContainsString("value: 'rating'", $commerce);
        $this->assertStringContainsString("value: 'discount'", $commerce);

        $category = (string) file_get_contents(base_path('storefront/src/pages/Category.jsx'));
        $this->assertStringContainsString('catalogSortOptions', $category);
        $this->assertStringNotContainsString("value: 'popularity', labelKey: 'popularity'", $category);
    }

    public function test_shop_bootstrap_exposes_sort_options_and_default(): void
    {
        $this->seed();
        ShopSetting::put('catalog.search.default_sort', 'rating');

        $this->getJson('/api/v1/store')
            ->assertOk()
            ->assertJsonPath('commerce.search.default_sort', 'rating')
            ->assertJsonPath('commerce.search.sort_options.0.value', 'relevance')
            ->assertJsonPath('commerce.search.sort_options.5.value', 'rating')
            ->assertJsonPath('commerce.search.sort_options.6.value', 'discount');
    }

    public function test_admin_can_save_rating_and_discount_default_sort(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        foreach (['rating', 'discount', 'relevance'] as $sort) {
            $this->actingAs($admin, 'admin')->put('/admin/search-settings', [
                'default_sort' => $sort,
                'per_page' => 24,
                'suggestions' => 1,
                'typo_tolerance' => 0,
                'recently_viewed' => 1,
                'wishlist_enabled' => 1,
            ])->assertRedirect();
            $this->assertSame($sort, SearchCatalogSettings::defaultSort());
        }

        $this->actingAs($admin, 'admin')->put('/admin/search-settings', [
            'default_sort' => 'not-a-real-sort',
            'per_page' => 24,
            'suggestions' => 1,
            'typo_tolerance' => 0,
            'recently_viewed' => 1,
            'wishlist_enabled' => 1,
        ])->assertSessionHasErrors('default_sort');
    }

    public function test_invalid_sort_normalizes_and_listing_uses_default_when_omitted(): void
    {
        $this->seed();
        ShopSetting::put('catalog.search.default_sort', 'newest');

        $this->assertSame('popularity', SearchCatalogSettings::normalizeSort('nope'));
        $this->assertSame('price_asc', SearchCatalogSettings::normalizeSort('price-low'));
        $this->assertSame('price_desc', SearchCatalogSettings::normalizeSort('price-high'));

        $this->getJson('/api/v1/catalog/category/catalog')
            ->assertOk()
            ->assertJsonPath('sort', 'newest');

        $this->getJson('/api/v1/catalog/category/catalog?sort=bogus')
            ->assertOk()
            ->assertJsonPath('sort', 'popularity');
    }

    public function test_price_rating_and_discount_sorts_order_products(): void
    {
        $this->seed();

        Product::query()->update(['is_best_seller' => false]);

        $storeId = (int) Store::query()->value('id');
        $this->assertGreaterThan(0, $storeId);
        $catalogCategoryId = (int) (\App\Domain\Catalog\Models\Category::query()
            ->whereHas('translations', fn ($t) => $t->where('slug', 'catalog'))
            ->value('id') ?: \App\Domain\Catalog\Models\Category::query()->value('id'));
        $this->assertGreaterThan(0, $catalogCategoryId);

        $make = function (string $sku, float $price, ?float $compare, bool $best) use ($storeId, $catalogCategoryId) {
            $product = Product::query()->create([
                'type' => 'simple',
                'sku' => $sku,
                'price' => $price,
                'compare_price' => $compare,
                'stock_qty' => 20,
                'stock_status' => 'in_stock',
                'manage_stock' => true,
                'is_enabled' => true,
                'is_best_seller' => $best,
                'visibility' => 'both',
                'available_for_order' => true,
                'show_price' => true,
            ]);
            $product->translations()->create([
                'language_code' => 'en',
                'name' => $sku,
                'slug' => strtolower($sku),
            ]);
            ProductStore::query()->firstOrCreate(
                ['product_id' => $product->id, 'store_id' => $storeId],
                ['is_visible' => true],
            );
            // /catalog resolves to a real category when one exists; keep test products on it.
            $product->categories()->syncWithoutDetaching([$catalogCategoryId]);

            return $product->fresh();
        };

        $cheap = $make('SORT-CHEAP', 10, 100, false);
        $mid = $make('SORT-MID', 50, 55, true);
        $pricey = $make('SORT-PRICEY', 200, null, false);

        Review::query()->create([
            'product_id' => $mid->id,
            'rating' => 5,
            'title' => 'Great',
            'body' => 'Excellent product for sorting tests.',
            'status' => Review::STATUS_APPROVED,
            'is_approved' => true,
        ]);
        Review::query()->create([
            'product_id' => $cheap->id,
            'rating' => 2,
            'title' => 'Ok',
            'body' => 'Lower rating for sorting tests.',
            'status' => Review::STATUS_APPROVED,
            'is_approved' => true,
        ]);

        $priceAsc = $this->getJson('/api/v1/catalog/category/catalog?sort=price_asc&per_page=48')
            ->assertOk()
            ->assertJsonPath('sort', 'price_asc')
            ->json('products.data');
        $ascIds = collect($priceAsc)->pluck('id')->all();
        $this->assertContains($cheap->id, $ascIds);
        $this->assertTrue(
            array_search($cheap->id, $ascIds, true) < array_search($pricey->id, $ascIds, true),
            'price_asc should list cheaper products before expensive ones'
        );

        $priceDesc = $this->getJson('/api/v1/catalog/category/catalog?sort=price_desc&per_page=48')
            ->assertOk()
            ->json('products.data');
        $descIds = collect($priceDesc)->pluck('id')->all();
        $this->assertTrue(
            array_search($pricey->id, $descIds, true) < array_search($cheap->id, $descIds, true),
            'price_desc should list expensive products first'
        );

        $byRating = $this->getJson('/api/v1/catalog/category/catalog?sort=rating&per_page=48')
            ->assertOk()
            ->assertJsonPath('sort', 'rating')
            ->json('products.data');
        $ratingIds = collect($byRating)->pluck('id')->all();
        $this->assertTrue(
            array_search($mid->id, $ratingIds, true) < array_search($cheap->id, $ratingIds, true),
            'rating sort should prefer higher average ratings'
        );

        $byDiscount = $this->getJson('/api/v1/catalog/category/catalog?sort=discount&per_page=48')
            ->assertOk()
            ->assertJsonPath('sort', 'discount')
            ->json('products.data');
        $discountIds = collect($byDiscount)->pluck('id')->all();
        $cheapPos = array_search($cheap->id, $discountIds, true);
        $midPos = array_search($mid->id, $discountIds, true);
        $this->assertNotFalse($cheapPos, 'discount sort should include the high-discount product');
        $this->assertNotFalse($midPos, 'discount sort should include the low-discount product');
        $this->assertLessThan(
            $midPos,
            $cheapPos,
            'discount sort should prefer larger compare_price gaps'
        );

        $popular = $this->getJson('/api/v1/catalog/category/catalog?sort=popularity&per_page=48')
            ->assertOk()
            ->json('products.data');
        $this->assertSame($mid->id, (int) ($popular[0]['id'] ?? 0));
    }
}
