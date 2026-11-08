<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave266Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_booking_slot_config_rest(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $defaults = $this->getJson('/api/v1/store/booking_product_default_slots?per_page=20');
        $defaults->assertOk();
        $this->assertIsArray($defaults->json());
        $this->assertTrue($defaults->headers->has('X-Total-Count'));
        $this->assertGreaterThanOrEqual(0, (int) $defaults->headers->get('X-Total-Count'));

        $appointments = $this->getJson('/api/v1/store/booking_product_appointment_slots?per_page=20');
        $appointments->assertOk();

        $rentals = $this->getJson('/api/v1/store/booking_product_rental_slots');
        $rentals->assertOk();

        $tables = $this->getJson('/api/v1/store/booking_product_table_slots');
        $tables->assertOk();
        $this->assertSame([], $tables->json());

        $tickets = $this->getJson('/api/v1/store/booking_product_event_tickets');
        $tickets->assertOk();
        $this->assertSame([], $tickets->json());

        $service = Product::query()->enabled()->where('type', 'service')->with('serviceSetting')->first();
        if ($service) {
            $stype = (string) ($service->serviceSetting?->service_type ?? 'appointment');
            $path = $stype === 'appointment'
                ? '/api/v1/store/booking_product_appointment_slots/'.$service->id
                : '/api/v1/store/booking_product_default_slots/'.$service->id;
            $one = $this->getJson($path);
            $one->assertOk();
            $this->assertSame((int) $service->id, (int) $one->json('id'));
            $this->assertArrayHasKey('bookingType', $one->json() ?? []);
            $this->assertArrayHasKey('slots', $one->json() ?? []);

            $bp = $this->getJson('/api/v1/store/products/'.$service->id.'/bookings');
            $bp->assertOk();
            if (! empty($bp->json())) {
                $iriKey = $stype === 'appointment' ? 'appointmentSlot' : 'defaultSlot';
                $this->assertNotEmpty((string) $bp->json('0.'.$iriKey));
            }
        }

        $missing = $this->getJson('/api/v1/store/booking_product_default_slots/99999999');
        $missing->assertNotFound();
    }
}
