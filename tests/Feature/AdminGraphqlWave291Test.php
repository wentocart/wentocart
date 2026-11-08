<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductComponent;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave291Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_admin_bundle_grouped_and_booking_rest(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));
        $childA = Product::query()->where('is_enabled', true)->where('type', 'simple')->orderBy('id')->firstOrFail();
        $childB = Product::query()->where('is_enabled', true)->where('type', 'simple')->where('id', '!=', $childA->id)->orderBy('id')->first();
        if (! $childB) {
            $mk = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/products', [
                'sku' => 'w291-child-'.$suffix,
                'name' => 'Wave291 Child '.$suffix,
                'price' => 8,
                'type' => 'simple',
                'stock_qty' => 10,
                'status' => 1,
            ]);
            $mk->assertCreated();
            $childB = Product::query()->findOrFail((int) $mk->json('data.id'));
        }

        $bundle = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/products', [
            'sku' => 'w291-bundle-'.$suffix,
            'name' => 'Wave291 Bundle '.$suffix,
            'price' => 0,
            'type' => 'bundle',
            'stock_qty' => 0,
            'status' => 1,
        ]);
        $bundle->assertCreated();
        $bundleId = (int) $bundle->json('data.id');

        $putBundle = $this->actingAs($admin, 'admin')->putJson(
            '/api/v1/console/products/'.$bundleId.'/bundle-options',
            [
                'bundleOptions' => [
                    [
                        'label' => 'Kit '.$suffix,
                        'products' => [
                            ['product_id' => $childA->id, 'qty' => 1, 'isRequired' => 1],
                            ['product_id' => $childB->id, 'qty' => 2, 'isDefault' => 1],
                        ],
                    ],
                ],
            ]
        );
        $putBundle->assertOk();
        $this->assertNotEmpty($putBundle->json('data'));
        $this->assertSame(2, ProductComponent::query()->where('parent_product_id', $bundleId)->count());

        $group = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/products', [
            'sku' => 'w291-group-'.$suffix,
            'name' => 'Wave291 Group '.$suffix,
            'price' => 0,
            'type' => 'group',
            'stock_qty' => 0,
            'status' => 1,
        ]);
        $group->assertCreated();
        $groupId = (int) $group->json('data.id');

        $putLinks = $this->actingAs($admin, 'admin')->putJson(
            '/api/v1/console/products/'.$groupId.'/links',
            [
                'links' => [
                    ['associated_product_id' => $childA->id, 'qty' => 1],
                    ['associatedProductId' => $childB->id, 'qty' => 1],
                ],
            ]
        );
        $putLinks->assertOk();
        $this->assertCount(2, $putLinks->json('data') ?? []);

        $alias = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/products/'.$groupId.'/grouped-products');
        $alias->assertOk();
        $this->assertCount(2, $alias->json('data') ?? []);

        $service = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/products', [
            'sku' => 'w291-svc-'.$suffix,
            'name' => 'Wave291 Service '.$suffix,
            'price' => 40,
            'type' => 'service',
            'stock_qty' => 0,
            'status' => 1,
        ]);
        $service->assertCreated();
        $serviceId = (int) $service->json('data.id');

        $booking = $this->actingAs($admin, 'admin')->putJson(
            '/api/v1/console/products/'.$serviceId.'/bookings',
            [
                'booking' => [
                    'type' => 'appointment',
                    'duration' => 45,
                    'location' => 'Studio '.$suffix,
                    'availability_config' => [
                        'available_days' => ['mon', 'tue', 'wed', 'thu', 'fri'],
                        'start_time' => '09:00',
                        'end_time' => '17:00',
                    ],
                ],
            ]
        );
        $booking->assertOk();
        $this->assertNotEmpty($booking->json('data'));
        $this->assertSame('appointment', (string) ($booking->json('data.0.type') ?? ''));
    }
}
