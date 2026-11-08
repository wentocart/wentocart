<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\StorefrontCatalogCache;
use App\Domain\Store\Models\Store;
use App\Domain\Theme\ThemeSettingsCatalog;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HomeCategoryShelvesTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_shelves_admin_page_omits_limit_and_columns_controls(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $this->get('/admin/themes/modules/category_shelves')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Design/ClassicModule', false)
                ->where('code', 'category_shelves')
                ->where('hint', 'Which category blocks appear on the home page.')
                ->missing('settings.home.category_shelves.title')
                ->has('settings.home.category_shelves.category_ids')
                ->where('settings.home.category_shelves.show_view_all', true)
                ->missing('settings.home.category_shelves.limit')
                ->missing('settings.home.category_shelves.columns')
            );

        $source = (string) file_get_contents(base_path('resources/js/admin/Pages/Design/ClassicModule.jsx'));
        $this->assertStringContainsString("code === 'category_shelves'", $source);
        $this->assertMatchesRegularExpression(
            "/code === 'category_shelves'[\s\S]{0,500}withShowViewAll/",
            $source,
            'Category products admin must expose the Show View All button toggle.'
        );
        $this->assertStringContainsString("show_view_all', 'Show View All button'", $source);
        $this->assertMatchesRegularExpression(
            "/<PageHeader[\s\S]{0,500}back_layout', 'Back to layout'/",
            $source,
            'Back to layout must sit in the page header actions.'
        );
        $this->assertDoesNotMatchRegularExpression(
            "/save_module', 'Save module'[\s\S]{0,250}back_layout', 'Back to layout'/",
            $source,
            'Back to layout must not remain next to Save module at the bottom.'
        );
        $this->assertDoesNotMatchRegularExpression(
            "/code === 'category_shelves'[\s\S]{0,500}withTitle/",
            $source,
            'Category products admin module must not expose the Title field.'
        );
        $this->assertDoesNotMatchRegularExpression(
            "/code === 'category_shelves'[\s\S]{0,400}withLimit/",
            $source,
            'Category products admin module must not expose Products in each block.'
        );
        $this->assertDoesNotMatchRegularExpression(
            "/code === 'category_shelves'[\s\S]{0,400}withColumns/",
            $source,
            'Category products admin module must not expose Products per row.'
        );
        $this->assertStringNotContainsString(
            "products_each', 'Products in each block'",
            $source,
            'Obsolete Products in each block label must be removed from ClassicModule.'
        );
    }

    public function test_category_shelves_show_these_uses_two_column_scrollable_picker(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/admin/Pages/Design/ClassicModule.jsx'));
        $classes = (string) file_get_contents(base_path('resources/js/admin/ui/classes.js'));

        $this->assertMatchesRegularExpression(
            "/code === 'category_shelves'[\s\S]{0,400}pickerLayout=\"twoCol\"/",
            $source,
            'Category products Show these must use the two-column picker layout.'
        );
        $this->assertStringContainsString("pickerLayout = 'grid'", $source);
        $this->assertStringContainsString('data-testid="id-picker-two-col"', $source);
        $this->assertStringContainsString('data-testid="id-picker-two-col-header"', $source);
        $this->assertStringContainsString("pick_select', 'Select'", $source);
        $this->assertStringContainsString("pick_category_name', 'Category Name'", $source);
        $this->assertStringContainsString("pick_product_count', 'Product Count'", $source);
        $this->assertStringContainsString('products_count', $source);
        $this->assertStringContainsString('idPickerTwoColWrap', $source);
        $this->assertStringContainsString('idPickerTwoColHeader', $source);
        $this->assertStringContainsString('idPickerTwoColBody', $source);
        $this->assertStringContainsString('idPickerTwoColItem', $source);
        $this->assertStringContainsString('idPickerTwoColName', $source);
        $this->assertStringContainsString('idPickerTwoColCount', $source);
        $this->assertMatchesRegularExpression(
            "/idPickerTwoColWrap:\s*'[^']*max-h-\[350px\][^']*'/",
            $classes,
            'Category products Show these must keep a 350px max height scroll container.'
        );
        $this->assertMatchesRegularExpression(
            "/idPickerTwoColHeader:\s*'[^']*\bsticky\b[^']*\btop-0\b[^']*'/",
            $classes,
            'Category products Show these must keep a sticky table-style header.'
        );
        $this->assertMatchesRegularExpression(
            "/idPickerTwoColBody:\s*'[^']*\bgrid-cols-1\b[^']*\bsm:grid-cols-2\b[^']*'/",
            $classes,
            'Category products Show these body must be a two-column grid on sm+ screens.'
        );
        $this->assertStringNotContainsString('sm:max-w-[50%]', $classes);
        $this->assertStringNotContainsString('max-h-[500px]', $classes);

        // Catalog banner lives in the same card via IdPicker footer (not a second Card).
        $this->assertMatchesRegularExpression(
            "/code === 'category_shelves'[\s\S]{0,900}footer=\{\(/",
            $source,
            'Catalog banner must render inside the Category products card footer.'
        );
        $this->assertMatchesRegularExpression(
            "/code === 'category_shelves'[\s\S]{0,1200}embedded/",
            $source,
            'Catalog banner must be embedded without its own Card wrapper.'
        );
        $this->assertStringContainsString("from '../../lib/catalogMedia'", $source);
        $this->assertStringContainsString('catalogImageUrl(value)', $source);
        $this->assertStringContainsString("adminUrl('/admin/themes/modules/'", $source);
        $this->assertFileExists(public_path('themes/classic/assets/merchant/banners/15.png'));

        // Catalog banner ThemeImageField: upload control, preview below, trash icon — no URL text input.
        $themeImageField = null;
        if (preg_match('/function ThemeImageField\([\s\S]*?\n\}\n\nfunction /', $source, $matches)) {
            $themeImageField = $matches[0];
        }
        $this->assertNotNull($themeImageField, 'ThemeImageField must be present in ClassicModule.');
        $this->assertStringNotContainsString('placeholder="/storage/theme-media/..."', $themeImageField);
        $this->assertStringNotContainsString('paste a public URL', $themeImageField);
        $this->assertStringNotContainsString("admin.common.remove', 'Remove'", $themeImageField);
        $this->assertStringContainsString('icon="trash"', $themeImageField);
        $this->assertStringContainsString("remove_banner', 'Remove banner'", $themeImageField);
        $this->assertStringContainsString('URL.createObjectURL', $themeImageField);
        $this->assertStringContainsString('onError', $themeImageField);
        $this->assertStringContainsString('object-contain', $themeImageField);
        $filePos = strpos($themeImageField, 'type="file"');
        $imgPos = strpos($themeImageField, '<img');
        $this->assertNotFalse($filePos, 'ThemeImageField must include a file upload control.');
        $this->assertNotFalse($imgPos, 'ThemeImageField must include an image preview.');
        $this->assertLessThan($imgPos, $filePos, 'Image preview must render below the file upload control.');

        // Other IdPicker modules keep the checkbox grid unless opted in.
        $this->assertDoesNotMatchRegularExpression(
            "/code === 'categories'[\s\S]{0,220}pickerLayout=\"twoCol\"/",
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            "/code === 'brands'[\s\S]{0,220}pickerLayout=\"twoCol\"/",
            $source
        );
    }

    public function test_category_shelves_banner_image_url_saves_and_loads(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $banner = '/themes/classic/assets/merchant/banners/15.png';
        $this->assertFileExists(public_path(ltrim($banner, '/')));

        $this->put('/admin/themes/modules/category_shelves', [
            'home' => [
                'category_shelves' => [
                    'title' => 'Catalog',
                    'category_ids' => [],
                    'banner_image_url' => $banner,
                ],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $store = Store::query()->where('is_default', true)->first();
        $this->assertSame(
            $banner,
            $store->theme_settings['home']['category_shelves']['banner_image_url'] ?? null
        );

        $this->get('/admin/themes/modules/category_shelves')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Design/ClassicModule', false)
                ->where('settings.home.category_shelves.banner_image_url', $banner)
            );
    }

    public function test_category_shelves_banner_image_can_be_cleared(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $banner = '/themes/classic/assets/merchant/banners/15.png';
        $this->assertFileExists(public_path(ltrim($banner, '/')));

        $this->put('/admin/themes/modules/category_shelves', [
            'home' => [
                'category_shelves' => [
                    'category_ids' => [],
                    'banner_image_url' => $banner,
                ],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->put('/admin/themes/modules/category_shelves', [
            'home' => [
                'category_shelves' => [
                    'category_ids' => [],
                    'banner_image_url' => '',
                ],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $store = Store::query()->where('is_default', true)->first();
        $saved = $store->theme_settings['home']['category_shelves']['banner_image_url'] ?? null;
        $this->assertTrue($saved === '' || $saved === null, 'Cleared catalog banner must persist as empty.');

        $this->get('/admin/themes/modules/category_shelves')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Design/ClassicModule', false)
                ->where('settings.home.category_shelves.banner_image_url', function ($url) {
                    return $url === '' || $url === null;
                })
            );
    }

    public function test_category_shelves_admin_options_include_product_counts(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $categoryA = Category::query()->create(['is_enabled' => true, 'position' => 10]);
        $categoryA->translations()->create([
            'language_code' => 'en',
            'name' => 'Count Cat A',
            'slug' => 'count-cat-a',
        ]);
        $categoryB = Category::query()->create(['is_enabled' => true, 'position' => 11]);
        $categoryB->translations()->create([
            'language_code' => 'en',
            'name' => 'Count Cat B',
            'slug' => 'count-cat-b',
        ]);

        foreach (range(1, 3) as $i) {
            $product = Product::query()->create([
                'type' => 'simple',
                'sku' => 'COUNT-A-'.$i,
                'price' => 10 + $i,
                'stock_qty' => 5,
                'is_enabled' => true,
                'visibility' => 'both',
                'available_for_order' => true,
                'show_price' => true,
            ]);
            $product->translations()->create([
                'language_code' => 'en',
                'name' => 'Count A '.$i,
                'slug' => 'count-a-'.$i,
            ]);
            // Attach to both categories once — count must stay distinct per category.
            $product->categories()->attach([
                $categoryA->id => ['is_default' => true],
                $categoryB->id => ['is_default' => false],
            ]);
        }

        $disabled = Product::query()->create([
            'type' => 'simple',
            'sku' => 'COUNT-DISABLED',
            'price' => 9,
            'stock_qty' => 5,
            'is_enabled' => false,
            'visibility' => 'both',
            'available_for_order' => true,
            'show_price' => true,
        ]);
        $disabled->translations()->create([
            'language_code' => 'en',
            'name' => 'Disabled Count',
            'slug' => 'disabled-count',
        ]);
        $disabled->categories()->attach($categoryA->id, ['is_default' => true]);

        StorefrontCatalogCache::bump();

        $this->get('/admin/themes/modules/category_shelves')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Design/ClassicModule', false)
                ->where('code', 'category_shelves')
                ->has('options.categories')
                ->where('options.categories', function ($categories) use ($categoryA, $categoryB) {
                    $rows = collect($categories)->keyBy('id');
                    $this->assertTrue($rows->has($categoryA->id));
                    $this->assertTrue($rows->has($categoryB->id));
                    $this->assertSame(3, (int) $rows[$categoryA->id]['products_count']);
                    $this->assertSame(3, (int) $rows[$categoryB->id]['products_count']);

                    return true;
                })
            );
    }

    public function test_category_shelves_categories_save_without_title(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $category = Category::query()->create(['is_enabled' => true, 'position' => 0]);
        $category->translations()->create([
            'language_code' => 'en',
            'name' => 'Shelf Save',
            'slug' => 'shelf-save',
        ]);

        $this->put('/admin/themes/modules/category_shelves', [
            'home' => [
                'category_shelves' => [
                    'title' => 'Home Catalog',
                    'limit' => 2,
                    'columns' => 5,
                    'category_ids' => [$category->id],
                ],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $store = Store::query()->where('is_default', true)->first();
        $shelves = $store->theme_settings['home']['category_shelves'] ?? [];
        $this->assertArrayNotHasKey('title', $shelves);
        $this->assertSame([(int) $category->id], array_map('intval', $shelves['category_ids'] ?? []));
        $this->assertArrayNotHasKey('limit', $shelves);
        $this->assertArrayNotHasKey('columns', $shelves);
    }

    public function test_category_shelves_normalize_drops_obsolete_title_limit_and_columns(): void
    {
        $normalized = ThemeSettingsCatalog::normalize([
            'home' => [
                'category_shelves' => [
                    'title' => 'Catalog',
                    'limit' => 12,
                    'columns' => 5,
                    'category_ids' => [9],
                ],
            ],
        ]);

        $shelves = $normalized['home']['category_shelves'];
        $this->assertArrayNotHasKey('title', $shelves);
        $this->assertSame([9], $shelves['category_ids']);
        $this->assertTrue($shelves['show_view_all']);
        $this->assertArrayNotHasKey('limit', $shelves);
        $this->assertArrayNotHasKey('columns', $shelves);
    }

    public function test_category_shelf_product_limit_is_four(): void
    {
        $this->assertSame(
            4,
            ThemeSettingsCatalog::CATEGORY_SHELF_PRODUCT_LIMIT,
            'Home category shelves must return at most 4 products each.'
        );
    }

    public function test_category_shelves_show_view_all_saves_and_loads_enabled_and_disabled(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $this->put('/admin/themes/modules/category_shelves', [
            'home' => [
                'category_shelves' => [
                    'category_ids' => [],
                    'show_view_all' => false,
                ],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $store = Store::query()->where('is_default', true)->first();
        $this->assertFalse($store->theme_settings['home']['category_shelves']['show_view_all'] ?? true);

        $this->get('/admin/themes/modules/category_shelves')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Design/ClassicModule', false)
                ->where('settings.home.category_shelves.show_view_all', false)
            );

        $this->put('/admin/themes/modules/category_shelves', [
            'home' => [
                'category_shelves' => [
                    'category_ids' => [],
                    'show_view_all' => true,
                ],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $store->refresh();
        $this->assertTrue($store->theme_settings['home']['category_shelves']['show_view_all'] ?? false);

        $this->get('/admin/themes/modules/category_shelves')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.home.category_shelves.show_view_all', true)
            );
    }

    public function test_category_shelves_expose_products_count_and_category_url_for_view_all(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $category = Category::query()->create(['is_enabled' => true, 'position' => 0]);
        $category->translations()->create([
            'language_code' => 'en',
            'name' => 'Shelf View All',
            'slug' => 'shelf-view-all',
        ]);

        $limit = ThemeSettingsCatalog::CATEGORY_SHELF_PRODUCT_LIMIT;
        $this->assertSame(4, $limit);
        foreach (range(1, $limit + 2) as $i) {
            $product = Product::query()->create([
                'type' => 'simple',
                'sku' => 'SHELF-VA-'.$i,
                'price' => 10 + $i,
                'stock_qty' => 5,
                'is_enabled' => true,
                'visibility' => 'both',
                'available_for_order' => true,
                'show_price' => true,
            ]);
            $product->translations()->create([
                'language_code' => 'en',
                'name' => 'Shelf Product '.$i,
                'slug' => 'shelf-product-'.$i,
            ]);
            $product->categories()->attach($category->id, ['is_default' => true]);
        }

        $this->put('/admin/themes/modules/category_shelves', [
            'home' => [
                'category_shelves' => [
                    'title' => 'Catalog',
                    'category_ids' => [$category->id],
                ],
            ],
        ])->assertRedirect();
        StorefrontCatalogCache::bump();

        $home = $this->getJson('/api/v1/catalog/home')->assertOk()->json();
        $shelves = collect($home['category_shelves'] ?? []);
        $shelf = $shelves->first(fn ($row) => (int) ($row['category']['id'] ?? 0) === (int) $category->id);

        $this->assertNotNull($shelf, 'Expected configured category shelf in home payload.');
        $this->assertSame($limit + 2, (int) $shelf['category']['products_count']);
        $this->assertCount($limit, $shelf['products']);
        $this->assertLessThanOrEqual(4, count($shelf['products']));
        foreach ($shelves as $row) {
            $this->assertLessThanOrEqual(
                4,
                count($row['products'] ?? []),
                'No home category shelf may return more than 4 products.'
            );
        }
        $this->assertGreaterThan(
            count($shelf['products']),
            (int) $shelf['category']['products_count'],
            'View All should be shown when the category has more products than the shelf displays.',
        );
        $this->assertNotEmpty($shelf['category']['url'] ?? null);
        $this->assertStringContainsString('shelf-view-all', (string) $shelf['category']['url']);
    }

    public function test_category_shelves_hide_view_all_when_all_products_are_displayed(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $category = Category::query()->create(['is_enabled' => true, 'position' => 1]);
        $category->translations()->create([
            'language_code' => 'en',
            'name' => 'Shelf Exact',
            'slug' => 'shelf-exact',
        ]);

        foreach (range(1, 2) as $i) {
            $product = Product::query()->create([
                'type' => 'simple',
                'sku' => 'SHELF-EX-'.$i,
                'price' => 20 + $i,
                'stock_qty' => 3,
                'is_enabled' => true,
                'visibility' => 'both',
                'available_for_order' => true,
                'show_price' => true,
            ]);
            $product->translations()->create([
                'language_code' => 'en',
                'name' => 'Exact Product '.$i,
                'slug' => 'exact-product-'.$i,
            ]);
            $product->categories()->attach($category->id, ['is_default' => true]);
        }

        $this->put('/admin/themes/modules/category_shelves', [
            'home' => [
                'category_shelves' => [
                    'title' => 'Catalog',
                    'category_ids' => [$category->id],
                ],
            ],
        ])->assertRedirect();
        StorefrontCatalogCache::bump();

        $home = $this->getJson('/api/v1/catalog/home')->assertOk()->json();
        $shelf = collect($home['category_shelves'] ?? [])
            ->first(fn ($row) => (int) ($row['category']['id'] ?? 0) === (int) $category->id);

        $this->assertNotNull($shelf);
        $this->assertSame(2, (int) $shelf['category']['products_count']);
        $this->assertCount(2, $shelf['products']);
        $this->assertLessThan(
            ThemeSettingsCatalog::CATEGORY_SHELF_PRODUCT_LIMIT,
            count($shelf['products']),
            'Categories with fewer than 4 products must still return every available product.'
        );
        $this->assertSame(
            (int) $shelf['category']['products_count'],
            count($shelf['products']),
            'View All should stay hidden when every category product is already on the shelf.',
        );
    }

    public function test_category_shelf_image_is_null_when_category_has_no_media(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $category = Category::query()->create(['is_enabled' => true, 'position' => 2]);
        $category->translations()->create([
            'language_code' => 'en',
            'name' => 'Shelf No Image',
            'slug' => 'shelf-no-image',
        ]);

        $product = Product::query()->create([
            'type' => 'simple',
            'sku' => 'SHELF-NOIMG-1',
            'price' => 15,
            'stock_qty' => 4,
            'is_enabled' => true,
            'visibility' => 'both',
            'available_for_order' => true,
            'show_price' => true,
        ]);
        $product->translations()->create([
            'language_code' => 'en',
            'name' => 'No Image Shelf Product',
            'slug' => 'no-image-shelf-product',
        ]);
        $product->categories()->attach($category->id, ['is_default' => true]);

        $this->put('/admin/themes/modules/category_shelves', [
            'home' => [
                'category_shelves' => [
                    'title' => 'Catalog',
                    'category_ids' => [$category->id],
                ],
            ],
        ])->assertRedirect();
        StorefrontCatalogCache::bump();

        $home = $this->getJson('/api/v1/catalog/home')->assertOk()->json();
        $shelf = collect($home['category_shelves'] ?? [])
            ->first(fn ($row) => (int) ($row['category']['id'] ?? 0) === (int) $category->id);

        $this->assertNotNull($shelf);
        $this->assertNull($shelf['category']['image'] ?? null);
        $this->assertNull($shelf['category']['thumb'] ?? null);
    }

    public function test_category_shelf_image_uses_assigned_category_media(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $category = Category::query()->create([
            'is_enabled' => true,
            'position' => 3,
            'image_path' => 'catalog/categories/shelf-demo/cover.png',
        ]);
        $category->translations()->create([
            'language_code' => 'en',
            'name' => 'Shelf With Image',
            'slug' => 'shelf-with-image',
        ]);

        $product = Product::query()->create([
            'type' => 'simple',
            'sku' => 'SHELF-IMG-1',
            'price' => 18,
            'stock_qty' => 4,
            'is_enabled' => true,
            'visibility' => 'both',
            'available_for_order' => true,
            'show_price' => true,
        ]);
        $product->translations()->create([
            'language_code' => 'en',
            'name' => 'Image Shelf Product',
            'slug' => 'image-shelf-product',
        ]);
        $product->categories()->attach($category->id, ['is_default' => true]);

        $this->put('/admin/themes/modules/category_shelves', [
            'home' => [
                'category_shelves' => [
                    'title' => 'Catalog',
                    'category_ids' => [$category->id],
                ],
            ],
        ])->assertRedirect();
        StorefrontCatalogCache::bump();

        $home = $this->getJson('/api/v1/catalog/home')->assertOk()->json();
        $shelf = collect($home['category_shelves'] ?? [])
            ->first(fn ($row) => (int) ($row['category']['id'] ?? 0) === (int) $category->id);

        $this->assertNotNull($shelf);
        $this->assertNotEmpty($shelf['category']['image'] ?? null);
        $this->assertStringContainsString('catalog/categories/shelf-demo/cover.png', (string) $shelf['category']['image']);
        $this->assertStringNotContainsString('p-md-default', (string) $shelf['category']['image']);
    }

    public function test_storefront_category_shelves_respect_show_view_all_setting(): void
    {
        $home = (string) file_get_contents(base_path('storefront/src/pages/Home.jsx'));
        $this->assertTrue(
            (bool) preg_match('/function CategoryShelf\((.*?)(?=\n(?:function |export default))/s', $home, $match),
            'CategoryShelf component must exist on the homepage.'
        );
        $shelfComponent = $match[0] ?? '';
        $this->assertStringContainsString('showViewAllEnabled', $shelfComponent);
        $this->assertStringContainsString(
            'showViewAllEnabled && totalProducts > displayedProducts.length',
            $shelfComponent,
            'View All must require both the admin toggle and extra products beyond the shelf.'
        );
        $this->assertStringContainsString('show_view_all', $home);
        $this->assertStringContainsString('showViewAllEnabled={showViewAllEnabled}', $home);
    }

    public function test_storefront_category_shelves_use_static_grid_not_carousel(): void
    {
        $home = (string) file_get_contents(base_path('storefront/src/pages/Home.jsx'));
        $this->assertTrue(
            (bool) preg_match('/function CategoryShelf\((.*?)(?=\n(?:function |export default))/s', $home, $match),
            'CategoryShelf component must exist on the homepage.'
        );
        $shelfComponent = $match[0] ?? '';
        $this->assertStringContainsString('cx-shelf-products', $shelfComponent);
        $this->assertStringContainsString('ProductCard', $shelfComponent);
        $this->assertStringNotContainsString(
            'HomeCarousel',
            $shelfComponent,
            'Category products shelves must not initialize HomeCarousel.'
        );

        $styles = (string) file_get_contents(base_path('storefront/src/styles.css'));
        $this->assertStringContainsString('.cx-shelf-products', $styles);
        $this->assertMatchesRegularExpression(
            '/\.cx-shelf-products\s*\{[^}]*grid-template-columns:\s*repeat\(auto-fill/',
            $styles
        );
        $this->assertMatchesRegularExpression(
            '/\.cx-shelf-banner img,\s*\.cx-shelf-banner svg\s*\{[^}]*object-fit:\s*contain/',
            $styles,
            'Category shelf banners must use object-fit: contain.'
        );

        // Other homepage sections still use the shared carousel component.
        $this->assertStringContainsString('<HomeCarousel', $home);
    }
}
