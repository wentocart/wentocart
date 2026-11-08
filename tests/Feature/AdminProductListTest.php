<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Catalog\Support\ProductCsvSchema;
use App\Domain\Ops\ShopStats;
use App\Domain\Tax\Models\TaxClass;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProductListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
    }

    public function test_admin_can_filter_products_by_column_filters(): void
    {
        $category = Category::query()->create(['is_enabled' => true, 'position' => 0]);
        $category->translations()->create([
            'language_code' => 'en',
            'name' => 'Headphones',
            'slug' => 'headphones-list',
        ]);

        $match = Product::query()->create([
            'type' => 'simple',
            'sku' => 'REF-100',
            'price' => 50,
            'stock_qty' => 8,
            'is_enabled' => true,
        ]);
        $match->translations()->create([
            'language_code' => 'en',
            'name' => 'Wireless Headphones',
            'slug' => 'wireless-headphones',
        ]);
        $match->categories()->attach($category->id, ['is_default' => true]);

        $other = Product::query()->create([
            'type' => 'simple',
            'sku' => 'REF-200',
            'price' => 10,
            'stock_qty' => 2,
            'is_enabled' => false,
        ]);
        $other->translations()->create([
            'language_code' => 'en',
            'name' => 'Cable',
            'slug' => 'cable',
        ]);

        $this->get('/admin/products?name=Wireless&sku=REF-100&category=Head&price_min=40&price_max=60&qty_min=5&status=active')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/Products/Index', false)
                ->has('products.data', 1)
                ->where('products.data.0.id', $match->id)
                ->where('filters.name', 'Wireless')
                ->where('filters.sku', 'REF-100'));
    }

    public function test_admin_product_name_filter_matches_inside_the_name(): void
    {
        $product = Product::query()->create([
            'type' => 'simple',
            'sku' => 'MID-NAME',
            'price' => 12,
            'stock_qty' => 1,
            'is_enabled' => true,
        ]);
        $product->translations()->create([
            'language_code' => 'en',
            'name' => 'Super Headphones Kit',
            'slug' => 'super-headphones-kit',
        ]);

        $this->get('/admin/products?name=Headphone')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/Products/Index', false)
                ->has('products.data', 1)
                ->where('products.data.0.id', $product->id));
    }

    public function test_admin_can_sort_products_by_category(): void
    {
        $this->get('/admin/products?sort=category&dir=asc')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/Products/Index', false)
                ->where('filters.sort', 'category'));
    }

    public function test_negative_price_filter_is_clamped_to_zero(): void
    {
        $this->get('/admin/products?price_min=-9')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/Products/Index', false)
                ->where('filters.price_min', '0'));
    }

    public function test_product_list_includes_kpi_stats(): void
    {
        Product::query()->create([
            'type' => 'simple',
            'sku' => 'KPI-ON',
            'price' => 40,
            'stock_qty' => 10,
            'is_enabled' => true,
        ])->translations()->create([
            'language_code' => 'en',
            'name' => 'KPI In Stock',
            'slug' => 'kpi-in-stock',
        ]);

        Product::query()->create([
            'type' => 'simple',
            'sku' => 'KPI-OOS',
            'price' => 20,
            'stock_qty' => 0,
            'is_enabled' => true,
        ])->translations()->create([
            'language_code' => 'en',
            'name' => 'KPI Out of Stock',
            'slug' => 'kpi-out-of-stock',
        ]);

        ShopStats::flushProducts();
        $stats = ShopStats::productStats();

        $this->assertSame(Product::query()->count(), $stats['total']);
        $this->assertSame(Product::query()->where('is_enabled', true)->count(), $stats['active']);
        $this->assertSame(Product::query()->where('stock_qty', '<=', 0)->count(), $stats['out_of_stock']);
        $this->assertSame(Product::query()->where('created_at', '>=', now()->subDays(30))->count(), $stats['new']);
        $this->assertEqualsWithDelta((float) Product::query()->avg('price'), $stats['avg_price'], 0.01);
        $this->assertArrayHasKey('total_trend', $stats);
        $this->assertArrayHasKey('new_trend', $stats);
        $this->assertArrayHasKey('active_trend', $stats);
        $this->assertArrayHasKey('out_of_stock_trend', $stats);
        $this->assertArrayHasKey('avg_price_trend', $stats);

        $this->get('/admin/products')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/Products/Index', false));
    }

    public function test_simple_product_can_save_with_zero_price(): void
    {
        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'simple',
            'name' => 'Free sample',
            'sku' => 'FREE-SAMPLE',
            'price' => 0,
            'is_enabled' => true,
        ]))->assertProductSaveRedirect();

        $this->assertSame(0.0, (float) Product::query()->where('sku', 'FREE-SAMPLE')->value('price'));
    }

    public function test_simple_product_cannot_save_with_negative_price(): void
    {
        $this->from('/admin/products/create')->post('/admin/products', $this->withProductCategories([
            'type' => 'simple',
            'name' => 'Bad price',
            'sku' => 'NEG-PRICE',
            'price' => -1,
            'is_enabled' => true,
        ]))->assertRedirect('/admin/products/create')
            ->assertSessionHasErrors('price');
    }

    public function test_admin_can_bulk_enable_disable_duplicate_and_delete_products(): void
    {
        $a = Product::query()->create([
            'type' => 'simple',
            'sku' => 'BULK-A',
            'price' => 1,
            'stock_qty' => 1,
            'is_enabled' => false,
        ]);
        $a->translations()->create(['language_code' => 'en', 'name' => 'Bulk A', 'slug' => 'bulk-a']);

        $b = Product::query()->create([
            'type' => 'simple',
            'sku' => 'BULK-B',
            'price' => 1,
            'stock_qty' => 1,
            'is_enabled' => false,
        ]);
        $b->translations()->create(['language_code' => 'en', 'name' => 'Bulk B', 'slug' => 'bulk-b']);

        $this->post('/admin/products/bulk-status', [
            'ids' => [(string) $a->id, (string) $b->id],
            'enabled' => 0,
        ])->assertRedirect();
        $this->assertFalse($a->fresh()->is_enabled);
        $this->assertFalse($b->fresh()->is_enabled);

        $this->post('/admin/products/bulk-status', [
            'ids' => [$a->id, $b->id],
            'enabled' => true,
        ])->assertRedirect();
        $this->assertTrue($a->fresh()->is_enabled);
        $this->assertTrue($b->fresh()->is_enabled);

        $before = Product::query()->count();
        $this->post('/admin/products/bulk-duplicate', ['ids' => [$a->id]])->assertRedirect();
        $this->assertSame($before + 1, Product::query()->count());
        // Second duplicate of the same product must not collide on translation slug.
        $this->post('/admin/products/bulk-duplicate', ['ids' => [$a->id]])->assertRedirect();
        $this->assertSame($before + 2, Product::query()->count());

        $this->post('/admin/products/'.$a->id.'/toggle')->assertRedirect();
        $this->assertFalse($a->fresh()->is_enabled);

        $this->post('/admin/products/bulk-delete', ['ids' => [$b->id]])->assertRedirect();
        $this->assertNull(Product::query()->find($b->id));
    }

    public function test_product_import_sample_csv_matches_importer_columns(): void
    {
        $response = $this->get('/admin/products/import/sample?delimiter=;&multivalue_separator=,');
        $response->assertOk();
        $response->assertHeader('content-disposition');
        $this->assertStringContainsString('products-sample.csv', (string) $response->headers->get('content-disposition'));

        $csv = $response->streamedContent();
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        $header = str_getcsv($lines[0], ';');
        $this->assertSame(ProductCsvSchema::columns(), $header);
        $this->assertContains('sku', $header);
        $this->assertContains('type', $header);
        $this->assertContains('attributes', $header);
        $this->assertContains('combinations', $header);
        $this->assertContains('components', $header);
        $this->assertContains('gift_card_amounts', $header);
        $this->assertSame(15, count(ProductCsvSchema::sampleProducts()));
        $this->assertSame(16, count($lines)); // header + 15 products
    }

    public function test_product_csv_import_accepts_legacy_columns(): void
    {
        $csv = "sku,name,price,stock_qty,description\nLEGACY-1,Legacy Product,12.50,3,Short desc\n";
        $count = app(ProductService::class)->importCsv($csv, 'en', ',', ',');
        $this->assertSame(1, $count);
        $product = Product::query()->where('sku', 'LEGACY-1')->first();
        $this->assertNotNull($product);
        $this->assertSame('Legacy Product', $product->translation('en')?->name);
        $this->assertEquals(12.50, (float) $product->price);
    }

    public function test_product_sample_csv_imports_all_rows(): void
    {
        TaxClass::query()->firstOrCreate(['name' => 'Standard']);

        $service = app(ProductService::class);
        $csv = $service->sampleCsv(';', ',');
        $count = $service->importCsv($csv, 'en', ';', ',');

        $this->assertSame(15, $count);
        $this->assertNotNull(Product::query()->where('sku', 'STD-MOUSE-01')->first());
        $this->assertNotNull(Product::query()->where('sku', 'COMBO-TEE-01')->where('type', 'combination')->first());
        $this->assertNotNull(Product::query()->where('sku', 'VIRT-EBOOK-01')->where('type', 'virtual')->first());
        $this->assertNotNull(Product::query()->where('sku', 'SVC-CONSULT-01')->where('type', 'service')->first());
        $this->assertNotNull(Product::query()->where('sku', 'RENT-CAM-01')->where('type', 'rental')->first());
        $this->assertNotNull(Product::query()->where('sku', 'GIFT-CARD-01')->where('type', 'gift_card')->first());
        $this->assertNotNull(Product::query()->where('sku', 'BNDL-DESK-01')->where('type', 'bundle')->first());
        $this->assertNotNull(Product::query()->where('sku', 'GRP-OFFICE-01')->where('type', 'group')->first());
    }

    public function test_product_csv_reimport_updates_existing_sku_instead_of_duplicating(): void
    {
        $service = app(ProductService::class);
        $service->importCsv("sku,name,price\nWTS-CART,Abandoned Cart,49.00\n", 'en', ',', ',');
        $service->importCsv("sku,name,price\nWTS-CART,Abandoned Cart Recovery,69.99\n", 'en', ',', ',');

        $this->assertSame(1, Product::query()->where('sku', 'WTS-CART')->count());
        $product = Product::query()->where('sku', 'WTS-CART')->first();
        $this->assertSame('Abandoned Cart Recovery', $product?->translation('en')?->name);
        $this->assertEquals(69.99, (float) $product?->price);
    }

    public function test_product_csv_reimport_keeps_existing_values_when_cells_are_blank(): void
    {
        $service = app(ProductService::class);
        $service->importCsv(
            "sku,name,price,short_description\nWTS-KEEP,Keep Price,69.99,Recover lost carts\n",
            'en',
            ',',
            ',',
        );
        $service->importCsv("sku,name,price,short_description\nWTS-KEEP,Keep Price Updated,,\n", 'en', ',', ',');

        $product = Product::query()->where('sku', 'WTS-KEEP')->first();
        $this->assertSame(1, Product::query()->where('sku', 'WTS-KEEP')->count());
        $this->assertSame('Keep Price Updated', $product?->translation('en')?->name);
        $this->assertEquals(69.99, (float) $product?->price);
        $this->assertSame('Recover lost carts', $product?->translation('en')?->short_description);
    }

    public function test_product_csv_import_creates_missing_categories(): void
    {
        $service = app(ProductService::class);
        $service->importCsv("sku,name,categories\nCAT-NEW,Categorized,\"extensions,auth\"\n", 'en', ',', ',');

        $product = Product::query()->where('sku', 'CAT-NEW')->first();
        $this->assertNotNull($product);
        $slugs = $product->categories()->with('translations')->get()
            ->map(fn ($category) => $category->translation('en')?->slug)
            ->all();
        $this->assertContains('extensions', $slugs);
        $this->assertContains('auth', $slugs);
    }

    public function test_product_csv_reimport_skips_images_that_already_exist_by_basename(): void
    {
        $service = app(ProductService::class);
        $service->importCsv("sku,name,price\nIMG-KEEP,Has Image,10.00\n", 'en', ',', ',');
        $product = Product::query()->where('sku', 'IMG-KEEP')->first();
        $this->assertNotNull($product);
        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'catalog/products/'.$product->id.'/logo.png',
            'position' => 1,
        ]);

        $service->importCsv(
            "sku,name,images\nIMG-KEEP,Has Image,https://example.com/files/logo.png\n",
            'en',
            ',',
            ',',
        );

        $this->assertSame(1, $product->images()->count());
    }

    public function test_product_import_accepts_excel_html_table(): void
    {
        $html = '<html><body><table><tr><th>sku</th><th>name</th><th>price</th></tr>'
            .'<tr><td>XLS-1</td><td>From Excel</td><td>12.50</td></tr></table></body></html>';

        $count = app(ProductService::class)->importCsv($html, 'en', ';', ',');

        $this->assertSame(1, $count);
        $product = Product::query()->where('sku', 'XLS-1')->first();
        $this->assertSame('From Excel', $product?->translation('en')?->name);
        $this->assertEquals(12.50, (float) $product?->price);
    }

    public function test_product_csv_import_keeps_extra_extension_columns(): void
    {
        $csv = "sku,name,price,overview_html,faq\nEXT-1,Social Login,0.00,<p>Overview</p>,[]\n";
        $count = app(ProductService::class)->importCsv($csv, 'en', ',', ',');

        $this->assertSame(1, $count);
        $this->assertNotNull(Product::query()->where('sku', 'EXT-1')->first());
    }
}
