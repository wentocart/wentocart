<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Settings\ShopSetting;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProductDetailsValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(Admin::query()->firstOrFail(), 'admin');
        ShopSetting::put('products.sku_mode', 'manual');
        ShopSetting::put('products.barcode_mode', 'required');
    }

    /**
     * @return array<string, mixed>
     */
    protected function basePayload(array $overrides = []): array
    {
        return $this->withProductCategories(array_merge([
            'type' => 'simple',
            'name' => 'Validation Probe',
            'slug' => 'validation-probe',
            'price' => 19.99,
            'is_enabled' => true,
            'sku' => 'PROBE-SKU',
            'upc' => '012345678905',
            'gtin' => '012345678905',
        ], $overrides));
    }

    public function test_missing_sku_upc_and_gtin_return_field_errors(): void
    {
        $this->from('/admin/products/create?type=simple')
            ->post('/admin/products', $this->basePayload([
                'sku' => '',
                'upc' => '',
                'gtin' => '',
            ]))
            ->assertRedirect('/admin/products/create?type=simple')
            ->assertSessionHasErrors([
                'sku' => 'SKU is required.',
                'upc' => 'UPC is required.',
                'gtin' => 'GTIN is required.',
            ]);

        $this->assertNull(Product::query()->where('slug', 'validation-probe')->first());
    }

    public function test_partial_barcode_errors_are_returned_independently(): void
    {
        $this->from('/admin/products/create?type=simple')
            ->post('/admin/products', $this->basePayload([
                'sku' => 'PROBE-SKU',
                'upc' => '',
                'gtin' => '012345678905',
            ]))
            ->assertRedirect('/admin/products/create?type=simple')
            ->assertSessionHasErrors(['upc' => 'UPC is required.'])
            ->assertSessionDoesntHaveErrors(['sku', 'gtin']);

        $this->from('/admin/products/create?type=simple')
            ->post('/admin/products', $this->basePayload([
                'sku' => 'PROBE-SKU',
                'upc' => '012345678905',
                'gtin' => '',
            ]))
            ->assertRedirect('/admin/products/create?type=simple')
            ->assertSessionHasErrors(['gtin' => 'GTIN is required.'])
            ->assertSessionDoesntHaveErrors(['sku', 'upc']);
    }

    public function test_valid_sku_upc_and_gtin_create_the_product(): void
    {
        $this->from('/admin/products/create?type=simple')
            ->post('/admin/products', $this->basePayload())
            ->assertProductSaveRedirect('PROBE-SKU');

        $product = Product::query()->where('sku', 'PROBE-SKU')->first();
        $this->assertNotNull($product);
        $this->assertSame('012345678905', $product->upc);
        $this->assertSame('012345678905', $product->gtin);
    }

    public function test_optional_barcode_mode_allows_empty_upc_and_gtin(): void
    {
        ShopSetting::put('products.barcode_mode', 'optional');

        $this->from('/admin/products/create?type=simple')
            ->post('/admin/products', $this->basePayload([
                'sku' => 'OPTIONAL-SKU',
                'upc' => '',
                'gtin' => '',
            ]))
            ->assertProductSaveRedirect('OPTIONAL-SKU');

        $this->assertNotNull(Product::query()->where('sku', 'OPTIONAL-SKU')->first());
    }
}
