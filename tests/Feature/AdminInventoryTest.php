<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
    }

    protected function productPayload(array $overrides = []): array
    {
        return $this->withProductCategories(array_replace_recursive([
            'type' => 'simple',
            'sku' => 'INV-'.substr(md5((string) microtime(true)), 0, 6),
            'price' => 25,
            'stock_qty' => 10,
            'is_enabled' => true,
            'translations' => [
                'en' => [
                    'language_code' => 'en',
                    'name' => 'Inventory Widget',
                    'slug' => 'inventory-widget-'.substr(md5((string) random_int(1, 9999)), 0, 4),
                ],
            ],
        ], $overrides));
    }

    public function test_admin_inventory_list_page_loads(): void
    {
        $this->post('/admin/products', $this->productPayload(['sku' => 'INV-LIST']))->assertRedirect();

        $this->get('/admin/inventory')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/Inventory/Index', false)
                ->has('inventory.data')
                ->where('inventory.data', fn ($rows) => collect($rows)->contains(fn ($row) => ($row['sku'] ?? null) === 'INV-LIST'))
            );
    }

    public function test_admin_can_adjust_single_product_stock(): void
    {
        $this->post('/admin/products', $this->productPayload(['sku' => 'INV-ADJ', 'stock_qty' => 5]))->assertRedirect();
        $product = Product::query()->where('sku', 'INV-ADJ')->firstOrFail();

        $this->post("/admin/inventory/{$product->id}/adjust", ['delta' => 3])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(8, (int) $product->fresh()->stock_qty);
    }

    public function test_admin_can_bulk_adjust_inventory(): void
    {
        $this->post('/admin/products', $this->productPayload(['sku' => 'INV-B1', 'stock_qty' => 4]))->assertRedirect();
        $this->post('/admin/products', $this->productPayload(['sku' => 'INV-B2', 'stock_qty' => 6]))->assertRedirect();

        $first = Product::query()->where('sku', 'INV-B1')->firstOrFail();
        $second = Product::query()->where('sku', 'INV-B2')->firstOrFail();

        $this->post('/admin/inventory/bulk-adjust', [
            'ids' => [$first->id, $second->id],
            'delta' => 2,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(6, (int) $first->fresh()->stock_qty);
        $this->assertSame(8, (int) $second->fresh()->stock_qty);
    }

    public function test_admin_can_sort_inventory_by_available(): void
    {
        $this->post('/admin/products', $this->productPayload(['sku' => 'INV-AVL1', 'stock_qty' => 5]))->assertRedirect();
        $this->post('/admin/products', $this->productPayload(['sku' => 'INV-AVL2', 'stock_qty' => 15]))->assertRedirect();

        $this->get('/admin/inventory?sort=available&dir=desc')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/Inventory/Index', false)
                ->where('filters.sort', 'available')
                ->where('filters.dir', 'desc'));

        $this->get('/admin/inventory?sort=available&dir=asc')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/Inventory/Index', false)
                ->where('filters.sort', 'available')
                ->where('filters.dir', 'asc'));
    }

    public function test_admin_can_filter_inventory_by_column_ranges(): void
    {
        $this->post('/admin/products', $this->productPayload(['sku' => 'INV-RNG1']))->assertRedirect();
        $this->post('/admin/products', $this->productPayload(['sku' => 'INV-RNG2']))->assertRedirect();

        Product::query()->where('sku', 'INV-RNG1')->update(['stock_qty' => 3, 'reserved_qty' => 0]);
        Product::query()->where('sku', 'INV-RNG2')->update(['stock_qty' => 20, 'reserved_qty' => 0]);

        $this->get('/admin/inventory?available_min=10')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/Inventory/Index', false)
                ->where('inventory.data', fn ($rows) => collect($rows)->contains(fn ($row) => ($row['sku'] ?? null) === 'INV-RNG2')
                    && ! collect($rows)->contains(fn ($row) => ($row['sku'] ?? null) === 'INV-RNG1')));

        $this->get('/admin/inventory?stock_max=5')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/Inventory/Index', false)
                ->where('inventory.data', fn ($rows) => collect($rows)->contains(fn ($row) => ($row['sku'] ?? null) === 'INV-RNG1')
                    && ! collect($rows)->contains(fn ($row) => ($row['sku'] ?? null) === 'INV-RNG2')));
    }

    public function test_stock_journal_list_page_loads(): void
    {
        $this->get('/admin/inventory/journal')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/Inventory/Journal', false));
    }
}
