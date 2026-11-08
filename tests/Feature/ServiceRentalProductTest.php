<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\RentalProductService;
use App\Domain\Catalog\Services\ServiceProductService;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceRentalProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_product_skips_inventory_and_shipping_defaults(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->get('/admin/products/create?type=service')->assertOk();

        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'service',
            'name' => 'Website Consultation',
            'slug' => 'website-consultation',
            'sku' => 'SVC-CONSULT',
            'price' => 2000,
            'is_enabled' => true,
            'service' => [
                'service_type' => 'hourly',
                'duration' => 2,
                'duration_unit' => 'hours',
                'pricing_model' => 'per_hour',
                'delivery_method' => 'online',
            ],
        ]))->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'SVC-CONSULT')->first();
        $this->assertNotNull($product);
        $this->assertSame('service', $product->type);
        $this->assertFalse((bool) $product->manage_stock);

        $config = app(ServiceProductService::class)->presentConfig($product);
        $this->assertSame('hourly', $config['service_type']);
        $this->assertEquals(2.0, $config['duration']);

        $this->get('/admin/products/'.$product->id.'/edit')->assertOk();
    }

    public function test_rental_product_persists_configuration_and_tiers(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'rental',
            'name' => 'Camera Rental',
            'slug' => 'camera-rental',
            'sku' => 'RENT-CAM',
            'price' => 2000,
            'manage_stock' => true,
            'stock_qty' => 10,
            'is_enabled' => true,
            'rental' => [
                'rental_unit' => 'day',
                'min_duration' => 1,
                'max_duration' => 30,
                'default_duration' => 3,
                'pricing_model' => 'per_day',
                'security_deposit_type' => 'fixed',
                'security_deposit_value' => 5000,
                'inventory_mode' => 'quantity',
                'maintenance_units' => 1,
                'out_of_service_units' => 0,
                'fulfillment_method' => 'both',
                'return_method' => 'both',
                'late_fee_type' => 'per_day',
                'late_fee_value' => 500,
            ],
            'rental_pricing_tiers' => [
                ['min_duration' => 1, 'max_duration' => 3, 'unit' => 'day', 'price' => 2000, 'position' => 0],
                ['min_duration' => 4, 'max_duration' => 7, 'unit' => 'day', 'price' => 1800, 'position' => 1],
            ],
        ]))->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'RENT-CAM')->firstOrFail();
        $this->assertSame('rental', $product->type);
        $this->assertTrue((bool) $product->manage_stock);

        $config = app(RentalProductService::class)->presentConfig($product);
        $this->assertSame('day', $config['rental_unit']);
        $this->assertCount(2, $config['pricing_tiers']);
        $this->assertEquals(5000.0, $config['security_deposit_value']);

        $summary = app(RentalProductService::class)->inventorySummary($product);
        $this->assertSame('quantity', $summary['mode']);
        $this->assertSame(10, $summary['total_units']);
        $this->assertSame(9, $summary['available_units']);

        $this->put('/admin/products/' . $product->id, $this->withProductCategories([
            'type' => 'rental',
            'name' => 'Camera Rental',
            'slug' => 'camera-rental',
            'sku' => 'RENT-CAM',
            'price' => 2000,
            'manage_stock' => true,
            'stock_qty' => 10,
            'is_enabled' => true,
            'rental' => [
                'rental_unit' => 'day',
                'min_duration' => 1,
                'max_duration' => 30,
                'default_duration' => 3,
                'pricing_model' => 'per_day',
                'security_deposit_type' => 'fixed',
                'security_deposit_value' => 5000,
                'inventory_mode' => 'asset',
                'fulfillment_method' => 'both',
                'return_method' => 'both',
                'late_fee_type' => 'none',
            ],
            'rental_pricing_tiers' => [],
            'rental_assets' => [
                ['asset_id' => 'CAM-001', 'status' => 'available', 'position' => 0],
                ['asset_id' => 'CAM-002', 'status' => 'maintenance', 'position' => 1],
            ],
        ]))->assertRedirect('/admin/products/' . $product->id . '/edit');

        $config = app(RentalProductService::class)->presentConfig($product->fresh());
        $this->assertSame('asset', $config['inventory_mode']);
        $this->assertCount(2, $config['assets']);
    }

    public function test_rental_rejects_overlapping_pricing_tiers(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $response = $this->from('/admin/products/create?type=rental')->post('/admin/products', $this->withProductCategories([
            'type' => 'rental',
            'name' => 'Invalid Rental',
            'slug' => 'invalid-rental',
            'sku' => 'RENT-BAD',
            'price' => 100,
            'is_enabled' => true,
            'rental' => [
                'rental_unit' => 'day',
                'min_duration' => 1,
                'max_duration' => 10,
                'default_duration' => 1,
                'pricing_model' => 'per_day',
            ],
            'rental_pricing_tiers' => [
                ['min_duration' => 1, 'max_duration' => 5, 'unit' => 'day', 'price' => 100],
                ['min_duration' => 4, 'max_duration' => 7, 'unit' => 'day', 'price' => 90],
            ],
        ]));

        $response->assertRedirect('/admin/products/create?type=rental');
        $response->assertSessionHasErrors('rental_pricing_tiers.1.min_duration');
    }

    public function test_gift_card_product_regression(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'gift_card',
            'name' => 'Gift Card',
            'slug' => 'gift-card',
            'sku' => 'GC-REG',
            'price' => 100,
            'is_enabled' => true,
            'gift_card' => ['amount_type' => 'fixed', 'validity_type' => 'never'],
            'gift_card_amounts' => [['value' => 100, 'selling_price' => 100, 'is_enabled' => true, 'position' => 0]],
        ]))->assertProductSaveRedirect();

        $this->assertSame('gift_card', Product::query()->where('sku', 'GC-REG')->value('type'));
    }
}
