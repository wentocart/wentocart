<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\BrandService;
use App\Domain\Catalog\Support\BrandCsvSchema;
use App\Domain\Catalog\Support\PositionReorder;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandManagementTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    protected function brandFormPayload(array $overrides = []): array
    {
        return array_merge([
            'address' => '123 Main Street',
            'city' => 'Springfield',
            'state' => 'IL',
            'postcode' => '62701',
            'country' => 'US',
        ], $overrides);
    }

    public function test_admin_can_manage_brands_and_product_assignment(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->get('/admin/brands')->assertOk();
        $this->get('/admin/brands?name=North&status=active&id_min=1')->assertOk();
        $this->get('/admin/brands/create')->assertOk();

        $this->from('/admin/brands/create')->post('/admin/brands', [
            'name' => '',
            'is_enabled' => true,
        ])->assertSessionHasErrors();

        $this->post('/admin/brands', $this->brandFormPayload([
            'name' => 'Northwind',
            'slug' => 'northwind',
            'is_enabled' => true,
            'website' => 'https://northwind.example',
            'email' => 'hello@northwind.example',
        ]))->assertRedirect('/admin/brands');

        $brand = Brand::query()->whereHas('translations', fn ($q) => $q->where('slug', 'northwind'))->first();
        $this->assertNotNull($brand);
        $this->assertSame('Northwind', $brand->translation()->name);
        $this->assertSame('northwind', $brand->translation()->slug);
        $this->assertTrue($brand->is_enabled);
        $this->assertNull($brand->code);

        $this->post('/admin/brands', $this->brandFormPayload([
            'name' => 'Disabled Co',
            'slug' => 'disabled-co',
            'is_enabled' => false,
        ]))->assertRedirect();

        $disabled = Brand::query()->whereHas('translations', fn ($q) => $q->where('slug', 'disabled-co'))->first();
        $this->assertFalse($disabled->is_enabled);

        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $originalBrandId = $product->brand_id;
        $payload = fn (array $overrides = []) => $this->withProductCategories(array_merge([
            'sku' => $product->sku,
            'name' => $product->translation()->name,
            'price' => $product->price,
            'brand_id' => $product->brand_id,
        ], $overrides));

        $this->from('/admin/products/'.$product->id.'/edit')
            ->put('/admin/products/'.$product->id, $payload(['brand_id' => $disabled->id]))
            ->assertSessionHasErrors(['brand_id']);

        $product->update(['brand_id' => $brand->id]);
        $this->assertSame($brand->id, (int) $product->fresh()->brand_id);

        $this->put('/admin/brands/'.$brand->id, $this->brandFormPayload([
            'name' => 'Northwind',
            'slug' => 'northwind',
            'is_enabled' => true,
        ]))->assertRedirect('/admin/brands');

        $this->post('/admin/brands/'.$brand->id.'/toggle')->assertRedirect();
        $brand->refresh();
        $this->assertFalse($brand->is_enabled);
        $this->assertSame($brand->id, (int) $product->fresh()->brand_id);

        $this->from('/admin/products/'.$product->id.'/edit')
            ->put('/admin/products/'.$product->id, $payload(['brand_id' => $brand->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame($brand->id, (int) $product->fresh()->brand_id);

        $this->from('/admin/brands')->delete('/admin/brands/'.$brand->id)
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertNotNull($brand->fresh());

        $product->update(['brand_id' => $originalBrandId]);

        $this->delete('/admin/brands/'.$brand->id)->assertRedirect('/admin/brands');
        $this->assertNull(Brand::query()->find($brand->id));
        $this->assertNotNull(Brand::withTrashed()->find($brand->id));
    }

    public function test_brand_import_sample_and_csv_import(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $response = $this->get('/admin/brands/import/sample?delimiter=;&multivalue_separator=,');
        $response->assertOk();
        $this->assertStringContainsString('brands-sample.csv', (string) $response->headers->get('content-disposition'));

        $csv = $response->streamedContent();
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        $header = str_getcsv($lines[0], ';');
        $this->assertSame(BrandCsvSchema::columns(), $header);
        $this->assertGreaterThanOrEqual(5, count($lines) - 1);

        $count = app(BrandService::class)->importCsv($csv, 'en', ';', ',');
        $this->assertSame(count(BrandCsvSchema::sampleBrands()), $count);

        $volt = Brand::query()->where('code', 'VOLT')->first();
        $this->assertNotNull($volt);
        $this->assertSame('Volt', $volt->translation('en')?->name);
        $this->assertTrue($volt->is_enabled);

        $archive = Brand::query()->where('code', 'ARCH')->first();
        $this->assertNotNull($archive);
        $this->assertFalse($archive->is_enabled);

        $this->get('/admin/brands/export')->assertOk();
    }

    public function test_brand_bulk_status_and_duplicate(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->post('/admin/brands', $this->brandFormPayload([
            'name' => 'Bulk Brand',
            'is_enabled' => true,
        ]))->assertRedirect('/admin/brands');

        $brand = Brand::query()
            ->whereHas('translations', fn ($q) => $q->where('name', 'Bulk Brand'))
            ->firstOrFail();

        $this->post('/admin/brands/bulk-status', [
            'ids' => [$brand->id],
            'enabled' => 0,
        ])->assertRedirect();
        $this->assertFalse($brand->fresh()->is_enabled);

        $this->post('/admin/brands/bulk-status', [
            'ids' => [$brand->id],
            'enabled' => 1,
        ])->assertRedirect();
        $this->assertTrue($brand->fresh()->is_enabled);

        $expected = PositionReorder::next(Brand::class);
        $this->post('/admin/brands/bulk-duplicate', [
            'ids' => [$brand->id],
        ])->assertRedirect();

        $copy = Brand::query()
            ->whereHas('translations', fn ($q) => $q->where('name', 'Bulk Brand (copy)'))
            ->first();
        $this->assertNotNull($copy);
        $this->assertFalse($copy->is_enabled);
        $this->assertSame($expected, (int) $copy->position);
    }
}
