<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductRentalSetting;
use App\Domain\Catalog\Models\ProductServiceSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave118Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_product_booking_products_connection(): void
    {
        $this->seed();

        $service = Product::query()->create([
            'type' => 'service',
            'sku' => 'W118-SVC-'.uniqid(),
            'price' => 55,
            'is_enabled' => true,
            'manage_stock' => false,
        ]);
        $service->translations()->create([
            'language_code' => 'en',
            'name' => 'Wave118 Appointment',
            'slug' => 'wave118-svc-'.uniqid(),
        ]);
        ProductServiceSetting::query()->create([
            'product_id' => $service->id,
            'service_type' => 'appointment',
            'duration' => 30,
            'duration_unit' => 'minute',
            'pricing_model' => 'fixed',
            'delivery_method' => 'online',
            'service_area' => 'Studio A',
        ]);

        $svcQ = $this->postJson('/api/v1/graphql', [
            'query' => 'query ($id: ID!) {
                product(id: $id) {
                    type bookingType
                    bookingProducts(first: 5) {
                        totalCount
                        edges {
                            node {
                                type location startingPrice
                                appointmentSlot { duration sameSlotAllDays }
                                rentalSlot { rentingType }
                            }
                        }
                    }
                }
            }',
            'variables' => ['id' => (string) $service->id],
        ]);
        $svcQ->assertOk();
        $this->assertArrayNotHasKey('errors', $svcQ->json() ?? [], json_encode($svcQ->json()));
        $this->assertSame('appointment', (string) $svcQ->json('data.product.bookingType'));
        $this->assertSame(1, (int) $svcQ->json('data.product.bookingProducts.totalCount'));
        $this->assertSame('appointment', (string) $svcQ->json('data.product.bookingProducts.edges.0.node.type'));
        $this->assertSame(30.0, (float) $svcQ->json('data.product.bookingProducts.edges.0.node.appointmentSlot.duration'));

        $rental = Product::query()->create([
            'type' => 'rental',
            'sku' => 'W118-RENT-'.uniqid(),
            'price' => 25,
            'is_enabled' => true,
            'manage_stock' => false,
        ]);
        $rental->translations()->create([
            'language_code' => 'en',
            'name' => 'Wave118 Rental',
            'slug' => 'wave118-rent-'.uniqid(),
        ]);
        ProductRentalSetting::query()->create([
            'product_id' => $rental->id,
            'rental_unit' => 'day',
            'min_duration' => 1,
            'max_duration' => 7,
            'default_duration' => 1,
            'pricing_model' => 'per_day',
            'return_location' => 'Depot 1',
        ]);

        $rentQ = $this->postJson('/api/v1/graphql', [
            'query' => 'query ($id: ID!) {
                product(id: $id) {
                    bookingType
                    bookingProducts(first: 5) {
                        edges {
                            node {
                                type
                                rentalSlot { rentingType dailyPrice }
                            }
                        }
                    }
                }
            }',
            'variables' => ['id' => (string) $rental->id],
        ]);
        $rentQ->assertOk();
        $this->assertArrayNotHasKey('errors', $rentQ->json() ?? [], json_encode($rentQ->json()));
        $this->assertSame('rental', (string) $rentQ->json('data.product.bookingType'));
        $this->assertSame('day', (string) $rentQ->json('data.product.bookingProducts.edges.0.node.rentalSlot.rentingType'));
    }
}
