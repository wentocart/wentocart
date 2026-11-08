<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\UrlRewrite;
use App\Domain\Catalog\Services\PlatformMigrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MarketViabilityTenOutOfTenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_detects_store_csv_format_and_migrates_catalog(): void
    {
        $importer = app(PlatformMigrationService::class);

        $headers = ['ID', 'Type', 'SKU', 'Name', 'Published', 'Regular price', 'Sale price', 'In stock?', 'Stock', 'Categories', 'Images', 'Weight (kg)'];
        $this->assertSame('store_csv', $importer->detectPlatform($headers));

        $rows = [
            [
                'ID' => '101',
                'Type' => 'simple',
                'SKU' => 'IMP-SHIRT-BLUE',
                'Name' => 'Premium Oxford Cotton Shirt',
                'Published' => '1',
                'Regular price' => '79.00',
                'Sale price' => '59.00',
                'In stock?' => '1',
                'Stock' => '25',
                'Categories' => 'Apparel > Men, Office Wear',
                'Images' => 'https://example.com/shirt1.jpg, https://example.com/shirt2.jpg',
                'Weight (kg)' => '0.4',
            ],
            [
                'ID' => '102',
                'Type' => 'simple',
                'SKU' => 'IMP-BELT-LEATHER',
                'Name' => 'Handmade Leather Belt',
                'Published' => '1',
                'Regular price' => '45.00',
                'Sale price' => '',
                'In stock?' => '1',
                'Stock' => '10',
                'Categories' => 'Accessories',
                'Images' => 'https://example.com/belt.jpg',
                'Weight (kg)' => '0.2',
            ],
        ];

        $result = $importer->importRows($rows, 'store_csv', false, 'en');

        $this->assertSame('store_csv', $result['platform']);
        $this->assertSame(2, $result['products_created']);
        $this->assertSame(0, $result['products_updated']);
        $this->assertEmpty($result['errors']);

        // Verify Product 1 details
        $shirt = Product::query()->where('sku', 'IMP-SHIRT-BLUE')->first();
        $this->assertNotNull($shirt);
        $this->assertEquals(59.00, (float) $shirt->price);
        $this->assertEquals(79.00, (float) $shirt->compare_price);
        $this->assertSame(25, (int) $shirt->stock_qty);
        $this->assertSame('in_stock', $shirt->stock_status);
        $this->assertSame('Premium Oxford Cotton Shirt', $shirt->translation('en')?->name);

        // Verify Categories created and attached
        $this->assertTrue(Category::query()->whereHas('translations', fn ($q) => $q->where('name', 'Apparel'))->exists());
        $this->assertTrue(Category::query()->whereHas('translations', fn ($q) => $q->where('name', 'Men'))->exists());
        $this->assertTrue(Category::query()->whereHas('translations', fn ($q) => $q->where('name', 'Office Wear'))->exists());
        $this->assertCount(3, $shirt->categories);

        // Verify SEO rewrite generated
        $this->assertTrue(UrlRewrite::query()->where('entity_type', 'product')->where('entity_id', $shirt->id)->exists());
    }

    public function test_detects_catalog_csv_format_and_migrates_catalog(): void
    {
        $importer = app(PlatformMigrationService::class);

        $headers = ['Product ID', 'Active (0/1)', 'Name *', 'Categories (x,y,z...)', 'Price tax excluded', 'Wholesale price', 'Quantity', 'Reference #', 'Rewritten URL', 'Manufacturer'];
        $this->assertSame('catalog_csv', $importer->detectPlatform($headers));

        $rows = [
            [
                'Product ID' => '201',
                'Active (0/1)' => '1',
                'Name *' => 'Noise Cancelling Headphones',
                'Categories (x,y,z...)' => 'Audio, Electronics',
                'Price tax excluded' => '199.99',
                'Wholesale price' => '120.00',
                'Quantity' => '8',
                'Reference #' => 'IMP-AUDIO-NC7',
                'Rewritten URL' => 'noise-cancelling-pro-headphones',
                'Manufacturer' => 'Acoustic Sound Lab',
            ],
        ];

        $result = $importer->importRows($rows, 'catalog_csv', false, 'en');

        $this->assertSame('catalog_csv', $result['platform']);
        $this->assertSame(1, $result['products_created']);
        $this->assertEmpty($result['errors']);

        $product = Product::query()->where('sku', 'IMP-AUDIO-NC7')->first();
        $this->assertNotNull($product);
        $this->assertEquals(199.99, (float) $product->price);
        $this->assertEquals(120.00, (float) $product->cost_price);
        $this->assertSame(8, (int) $product->stock_qty);

        // Verify Manufacturer created as Brand
        $brand = Brand::query()->whereHas('translations', fn ($q) => $q->where('name', 'Acoustic Sound Lab'))->first();
        $this->assertNotNull($brand);
        $this->assertSame($brand->id, $product->brand_id);

        // Verify Preserved Rewritten URL in UrlRewrites
        $rewrite = UrlRewrite::query()->where('entity_type', 'product')->where('entity_id', $product->id)->first();
        $this->assertNotNull($rewrite);
        $this->assertStringContainsString('noise-cancelling-pro-headphones', $rewrite->request_path);
    }

    public function test_cli_migrate_catalog_command_supports_dry_run_and_execution(): void
    {
        $tempDir = storage_path('framework/testing');
        File::ensureDirectoryExists($tempDir);
        $tempCsv = $tempDir.'/sample_store_csv_export.csv';

        $csvContent = implode("\n", [
            'ID,Type,SKU,Name,Published,Regular price,Sale price,In stock?,Stock,Categories',
            '301,simple,CLI-MIG-1,Smart LED Bulb,1,24.99,,1,50,Smart Home',
        ]);
        file_put_contents($tempCsv, $csvContent);

        try {
            // 1. Dry run execution
            $this->artisan('wento:migrate-catalog', [
                'file' => $tempCsv,
                '--dry-run' => true,
            ])
                ->expectsOutputToContain('DRY RUN')
                ->expectsOutputToContain('Detected Format:     store_csv')
                ->assertSuccessful();

            $this->assertFalse(Product::query()->where('sku', 'CLI-MIG-1')->exists());

            // 2. Real execution
            $this->artisan('wento:migrate-catalog', [
                'file' => $tempCsv,
            ])
                ->expectsOutputToContain('Detected Format:     store_csv')
                ->expectsOutputToContain('Products Created:    1')
                ->assertSuccessful();

            $this->assertTrue(Product::query()->where('sku', 'CLI-MIG-1')->exists());
        } finally {
            if (file_exists($tempCsv)) {
                @unlink($tempCsv);
            }
        }
    }

    public function test_migration_is_idempotent_updating_existing_products_by_sku(): void
    {
        $importer = app(PlatformMigrationService::class);

        $row1 = [
            'SKU' => 'IDEM-PRODUCT-1',
            'Name' => 'Idempotent Item',
            'Regular price' => '100.00',
            'Stock' => '10',
            'In stock?' => '1',
        ];

        // First pass creates product
        $res1 = $importer->importRows([$row1], 'store_csv', false, 'en');
        $this->assertSame(1, $res1['products_created']);
        $this->assertSame(0, $res1['products_updated']);

        $product = Product::query()->where('sku', 'IDEM-PRODUCT-1')->first();
        $this->assertEquals(100.00, (float) $product->price);
        $this->assertSame(10, (int) $product->stock_qty);

        // Second pass updates product price and stock without creating duplicates
        $row2 = [
            'SKU' => 'IDEM-PRODUCT-1',
            'Name' => 'Idempotent Item',
            'Regular price' => '120.00',
            'Stock' => '35',
            'In stock?' => '1',
        ];
        $res2 = $importer->importRows([$row2], 'store_csv', false, 'en');
        $this->assertSame(0, $res2['products_created']);
        $this->assertSame(1, $res2['products_updated']);

        $product->refresh();
        $this->assertEquals(120.00, (float) $product->price);
        $this->assertSame(35, (int) $product->stock_qty);
        $this->assertSame(1, Product::query()->where('sku', 'IDEM-PRODUCT-1')->count());
    }
}
