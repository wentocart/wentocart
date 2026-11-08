<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductServiceSetting;
use App\Domain\Settings\ShopSetting;
use App\Models\Admin;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave215Test extends TestCase
{
    use RefreshDatabase;

    public function test_shop_booking_slots_and_admin_bookings_rest(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00'));

        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->forceFill([
            'type' => 'service',
            'manage_stock' => false,
            'is_enabled' => true,
        ])->save();

        ProductServiceSetting::query()->updateOrCreate(
            ['product_id' => $product->id],
            [
                'service_type' => 'appointment',
                'duration' => 60,
                'duration_unit' => 'minutes',
                'pricing_model' => 'fixed',
                'delivery_method' => 'online',
                'availability_config' => [
                    'available_days' => ['mon', 'wed', 'fri'],
                    'start_time' => '09:00',
                    'end_time' => '12:00',
                    'min_advance_hours' => 0,
                    'max_advance_days' => 30,
                    'max_bookings' => 5,
                ],
            ]
        );

        $slots = $this->getJson('/api/v1/store/booking-slots?id='.$product->id.'&date=2026-10-07');
        $slots->assertOk();
        $this->assertIsArray($slots->json());
        $this->assertNotEmpty($slots->json());
        $this->assertNotEmpty($slots->json('0.slotId'));
        $this->assertNotEmpty($slots->json('0.from'));

        $closed = $this->getJson('/api/v1/booking-slots?id='.$product->id.'&date=2026-10-06');
        $closed->assertOk();
        $this->assertSame([], $closed->json());

        $bad = $this->getJson('/api/v1/store/booking-slots?id=0&date=bad');
        $bad->assertStatus(422);

        $admin = Admin::query()->firstOrFail();
        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/bookings');
        $list->assertOk();
        $this->assertIsArray($list->json('data'));
        $this->assertArrayHasKey('meta', $list->json() ?? []);

        $gql = $this->postJson('/api/v1/graphql', [
            'query' => 'query ($id: Int!, $date: String!) { bookingSlots(id: $id, date: $date) { slotId } }',
            'variables' => ['id' => (int) $product->id, 'date' => '2026-10-07'],
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
        $this->assertNotEmpty($gql->json('data.bookingSlots'));

        Carbon::setTestNow();
    }
}
