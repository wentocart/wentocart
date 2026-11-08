<?php

namespace Tests\Feature;

use App\Domain\Auth\Models\Role;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Catalog\Services\SupplierService;
use App\Domain\Catalog\Support\PositionReorder;
use App\Domain\Catalog\Support\SupplierCsvSchema;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSupplierTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    protected function supplierFormPayload(array $overrides = []): array
    {
        return array_merge([
            'address' => '123 Main Street',
            'city' => 'Springfield',
            'state' => 'IL',
            'postcode' => '62701',
            'country' => 'US',
        ], $overrides);
    }

    public function test_super_admin_can_create_a_supplier(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->post('/admin/suppliers', $this->supplierFormPayload([
            'name' => 'Pacific Mills',
            'email' => 'sales@pacific.test',
            'is_enabled' => true,
        ]))->assertRedirect();

        $supplier = Supplier::query()->whereHas('translations', fn ($q) => $q->where('slug', 'pacific-mills'))->first();
        $this->assertNotNull($supplier);

        $this->get('/admin/suppliers')->assertOk()->assertSee('Pacific Mills');
        $this->get('/admin/suppliers?name=Pacific&status=active&products_min=0')->assertOk();
        $this->get('/api/v1/catalog/supplier/pacific-mills')->assertOk()
            ->assertJsonPath('supplier.name', 'Pacific Mills');
    }

    public function test_product_form_can_assign_suppliers(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $supplier = Supplier::query()->create(['is_enabled' => true]);
        $supplier->translations()->create([
            'language_code' => 'en',
            'name' => 'Second Source',
            'slug' => 'second-source',
        ]);

        $this->put("/admin/products/{$product->id}", [
            'name' => 'Demo Cotton Tee',
            'price' => 29.99,
            'sku' => 'DEMO-001',
            'supplier_ids' => [$supplier->id],
            'supplier_id' => $supplier->id,
        ])->assertRedirect();

        $product->refresh();
        $this->assertSame($supplier->id, $product->supplier_id);
        $this->assertTrue($product->suppliers->contains('id', $supplier->id));
    }

    public function test_catalog_manager_can_open_suppliers_page(): void
    {
        $this->seed();
        $staff = Admin::factory()->create(['email' => 'catalog@store.test']);
        $staff->roles()->sync([Role::query()->where('slug', 'catalog_manager')->value('id')]);

        $this->actingAs($staff, 'admin');
        $this->get('/admin/suppliers')->assertOk();
    }

    public function test_supplier_import_sample_and_csv_import(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $response = $this->get('/admin/suppliers/import/sample?delimiter=;&multivalue_separator=,');
        $response->assertOk();
        $this->assertStringContainsString('suppliers-sample.csv', (string) $response->headers->get('content-disposition'));

        $csv = $response->streamedContent();
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        $header = str_getcsv($lines[0], ';');
        $this->assertSame(SupplierCsvSchema::columns(), $header);
        $this->assertGreaterThanOrEqual(5, count($lines) - 1);

        $count = app(SupplierService::class)->importCsv($csv, 'en', ';', ',');
        $this->assertSame(count(SupplierCsvSchema::sampleSuppliers()), $count);

        $wento = Supplier::query()
            ->whereHas('translations', fn ($q) => $q->where('slug', 'wento-supply'))
            ->first();
        $this->assertNotNull($wento);
        $this->assertSame('orders@wentosupply.example', $wento->email);
        $this->assertSame('Wento Supply', $wento->translation('en')?->meta_title);
        $this->assertSame('https://facebook.com/wentosupply', $wento->facebook_url);
        $this->assertTrue($wento->is_enabled);

        $nordic = Supplier::query()
            ->whereHas('translations', fn ($q) => $q->where('slug', 'nordic-textiles'))
            ->first();
        $this->assertNotNull($nordic);
        $this->assertSame('Nordic Textiles', $nordic->translation('en')?->meta_title);
        $this->assertSame('textiles,apparel,wholesale', $nordic->translation('en')?->meta_keywords);

        $inactive = Supplier::query()
            ->whereHas('translations', fn ($q) => $q->where('slug', 'alpine-fresh-foods'))
            ->first();
        $this->assertNotNull($inactive);
        $this->assertFalse($inactive->is_enabled);

        $this->get('/admin/suppliers/export')->assertOk();
    }

    public function test_supplier_bulk_status_and_duplicate(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->post('/admin/suppliers', $this->supplierFormPayload([
            'name' => 'Bulk Supplier',
            'is_enabled' => true,
        ]))->assertRedirect('/admin/suppliers');

        $supplier = Supplier::query()
            ->whereHas('translations', fn ($q) => $q->where('name', 'Bulk Supplier'))
            ->firstOrFail();

        $this->post('/admin/suppliers/bulk-status', [
            'ids' => [$supplier->id],
            'enabled' => 0,
        ])->assertRedirect();
        $this->assertFalse($supplier->fresh()->is_enabled);

        $this->post('/admin/suppliers/bulk-status', [
            'ids' => [$supplier->id],
            'enabled' => 1,
        ])->assertRedirect();
        $this->assertTrue($supplier->fresh()->is_enabled);

        $expected = PositionReorder::next(Supplier::class);
        $this->post('/admin/suppliers/bulk-duplicate', [
            'ids' => [$supplier->id],
        ])->assertRedirect();

        $copy = Supplier::query()
            ->whereHas('translations', fn ($q) => $q->where('name', 'Bulk Supplier (copy)'))
            ->first();
        $this->assertNotNull($copy);
        $this->assertFalse($copy->is_enabled);
        $this->assertSame($expected, (int) $copy->position);
    }
}
