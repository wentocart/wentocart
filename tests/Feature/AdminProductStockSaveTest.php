<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Warehouse;
use App\Domain\Catalog\Models\WarehouseStock;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProductStockSaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
    }

    /** @param  array<string, mixed>  $overrides */
    protected function payload(array $overrides = []): array
    {
        return $this->withProductCategories(array_merge([
            'type' => 'simple',
            'name' => 'Stock Save Product',
            'sku' => 'STOCK-SAVE-1',
            'price' => 12,
            'is_enabled' => true,
            'manage_stock' => true,
            'stock_status' => 'in_stock',
        ], $overrides));
    }

    public function test_updating_quantity_persists_on_the_product_and_default_warehouse(): void
    {
        $this->post('/admin/products', $this->payload([
            'stock_qty' => 25,
        ]))->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'STOCK-SAVE-1')->firstOrFail();
        $this->assertSame(25, (int) $product->stock_qty);

        $default = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->put('/admin/products/'.$product->id, $this->payload([
            'stock_qty' => 40,
            'warehouse_stocks' => [[
                'warehouse_id' => $default->id,
                'qty' => 40,
                'reserved_qty' => 0,
                'is_default' => true,
            ]],
        ]))->assertProductSaveRedirect();

        $this->assertSame(40, (int) $product->fresh()->stock_qty);
        $this->assertEqualsWithDelta(40, (float) WarehouseStock::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $default->id)
            ->value('qty'), 0.001);
    }

    public function test_empty_warehouse_payload_does_not_wipe_an_explicit_quantity(): void
    {
        $this->post('/admin/products', $this->payload([
            'stock_qty' => 25,
        ]))->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'STOCK-SAVE-1')->firstOrFail();

        $this->put('/admin/products/'.$product->id, $this->payload([
            'stock_qty' => 40,
            'warehouse_stocks' => [],
        ]))->assertProductSaveRedirect();

        $this->assertSame(40, (int) $product->fresh()->stock_qty);
    }

    public function test_quantity_field_allows_clearing_zero_instead_of_forcing_it(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/admin/Pages/Catalog/Products/Form.jsx'));
        $this->assertStringContainsString('function parseQtyField', $source);
        $this->assertStringContainsString("if (value === '' || value === '-' || value === '.')", $source);
        $this->assertStringContainsString('onChange={(e) => setCurrentStock(e.target.value)}', $source);
        $this->assertStringNotContainsString('Number(e.target.value) || 0', $source);
    }
}
