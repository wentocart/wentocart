<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Warehouse;
use App\Domain\Catalog\Models\WarehouseStock;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminWarehouseListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(Admin::query()->firstOrFail(), 'admin');
    }

    /** @return array<string, mixed> */
    protected function requiredContactPayload(): array
    {
        return [
            'email' => 'contact@example.com',
            'address' => '100 Test Street',
            'city' => 'Boston',
            'state' => 'MA',
            'postcode' => '02110',
            'country' => 'US',
        ];
    }

    protected function makeEastWarehouse(array $overrides = []): Warehouse
    {
        return Warehouse::query()->create(array_merge([
            'name' => 'East DC',
            'code' => 'EAST',
            'is_enabled' => true,
            'is_default' => false,
            'email' => 'east@example.com',
            'address' => '200 East Ave',
            'city' => 'Boston',
            'state' => 'MA',
            'postcode' => '02110',
            'country' => 'US',
        ], $overrides));
    }

    public function test_index_renders_paginated_list_props(): void
    {
        $this->get('/admin/warehouses')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Catalog/Warehouses/Index', false)
                ->has('warehouses.data')
                ->has('warehouses.total')
                ->has('counts.all')
                ->has('counts.active')
                ->has('counts.inactive')
                ->has('filters'));

        $this->get('/admin/warehouses?status=active&name=Main&code=MAIN&id_min=1&sort=name&dir=asc')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Catalog/Warehouses/Index', false)
                ->where('filters.status', 'active')
                ->where('filters.sort', 'name')
                ->where('filters.dir', 'asc'));
    }

    public function test_create_and_edit_pages_render(): void
    {
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $this->get('/admin/warehouses/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Catalog/Warehouses/Form', false)
                ->where('warehouse', null)
                ->has('countries'));

        $this->get('/admin/warehouses/'.$warehouse->id.'/edit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Catalog/Warehouses/Form', false)
                ->where('warehouse.id', $warehouse->id)
                ->where('warehouse.is_default', true)
                ->where('warehouse.actions.delete', false)
                ->has('countries'));
    }

    public function test_store_and_update_via_form_redirect_to_index(): void
    {
        $this->post('/admin/warehouses', array_merge([
            'name' => 'West DC',
            'code' => 'west',
            'is_enabled' => true,
            'is_default' => false,
            'translations' => [
                'en' => ['language_code' => 'en', 'name' => 'West DC', 'description' => 'West coast depot'],
            ],
        ], $this->requiredContactPayload()))->assertRedirect('/admin/warehouses');

        $west = Warehouse::query()->where('code', 'WEST')->firstOrFail();
        $this->assertSame('West DC', $west->name);
        $this->assertSame('West DC', $west->fresh()->translations()->where('language_code', 'en')->value('name'));
        $this->assertSame('West coast depot', $west->fresh()->translations()->where('language_code', 'en')->value('description'));

        $this->put('/admin/warehouses/'.$west->id, array_merge([
            'name' => 'West Hub',
            'code' => 'WEST',
            'is_enabled' => true,
            'is_default' => false,
            'translations' => [
                'en' => ['language_code' => 'en', 'name' => 'West Hub', 'description' => 'Updated notes'],
            ],
        ], $this->requiredContactPayload()))->assertRedirect('/admin/warehouses');

        $this->assertSame('West Hub', $west->fresh()->name);
        $this->assertSame('West Hub', $west->fresh()->translations()->where('language_code', 'en')->value('name'));
        $this->assertSame('Updated notes', $west->fresh()->translations()->where('language_code', 'en')->value('description'));
    }

    public function test_import_sample_includes_five_example_rows(): void
    {
        $response = $this->get('/admin/warehouses/import/sample')->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('EAST-DEPOT', $csv);
        $this->assertStringContainsString('WEST-HUB', $csv);
        $this->assertStringContainsString('CENTRAL', $csv);
        $this->assertStringContainsString('NORTH-OVF', $csv);
        $this->assertStringContainsString('SOUTH-RET', $csv);
        $lines = array_values(array_filter(preg_split("/\r\n|\n|\r/", trim($csv)) ?: []));
        $this->assertCount(6, $lines);
    }

    public function test_toggle_and_bulk_status(): void
    {
        $east = $this->makeEastWarehouse();
        $default = Warehouse::query()->where('is_default', true)->firstOrFail();

        $this->post('/admin/warehouses/'.$east->id.'/toggle')->assertRedirect();
        $this->assertFalse($east->fresh()->is_enabled);

        $this->post('/admin/warehouses/'.$east->id.'/toggle')->assertRedirect();
        $this->assertTrue($east->fresh()->is_enabled);

        $this->from('/admin/warehouses')
            ->post('/admin/warehouses/'.$default->id.'/toggle')
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertTrue($default->fresh()->is_enabled);

        $this->post('/admin/warehouses/bulk-status', [
            'ids' => [$east->id, $default->id],
            'enabled' => 0,
        ])->assertRedirect();

        $this->assertFalse($east->fresh()->is_enabled);
        $this->assertTrue($default->fresh()->is_enabled);

        $this->post('/admin/warehouses/bulk-status', [
            'ids' => [$east->id],
            'enabled' => 1,
        ])->assertRedirect();
        $this->assertTrue($east->fresh()->is_enabled);
    }

    public function test_bulk_delete_skips_default_and_stocked_warehouses(): void
    {
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $empty = $this->makeEastWarehouse(['code' => 'EMPTY', 'name' => 'Empty DC']);
        $stocked = $this->makeEastWarehouse(['code' => 'STOCK', 'name' => 'Stocked DC']);
        WarehouseStock::query()->create([
            'warehouse_id' => $stocked->id,
            'product_id' => $product->id,
            'qty' => 5,
            'reserved_qty' => 0,
        ]);
        $default = Warehouse::query()->where('is_default', true)->firstOrFail();

        $this->post('/admin/warehouses/bulk-delete', [
            'ids' => [$empty->id, $stocked->id, $default->id],
        ])->assertRedirect();

        $this->assertDatabaseMissing('warehouses', ['id' => $empty->id]);
        $this->assertDatabaseHas('warehouses', ['id' => $stocked->id]);
        $this->assertDatabaseHas('warehouses', ['id' => $default->id]);
    }

    public function test_import_export_sample_still_work(): void
    {
        $this->get('/admin/warehouses/export')->assertOk();
        $this->get('/admin/warehouses/import/sample')->assertOk();
    }
}
