<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\ReturnReason;
use App\Domain\Order\Models\ShopReturn;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Settings\ShopSetting;
use App\Domain\Shipping\Models\ShippingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminGraphqlWave252Test extends TestCase
{
    use RefreshDatabase;

    public function test_shop_rma_returns_rest_rest_compat(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);
        ShopSetting::put('returns.enabled', true);
        ShopSetting::put('returns.reason_required', false);
        ShopSetting::put('returns.window_days', 30);
        ShopSetting::put('returns.partial', true);

        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $payment = PaymentMethod::query()->firstOrFail();
        $shipping = ShippingMethod::query()->where('is_enabled', true)->firstOrFail();
        $customer = Customer::factory()->create();
        $reason = ReturnReason::query()->where('is_enabled', true)->first()
            ?? ReturnReason::query()->create([
                'code' => 'wave252',
                'name' => 'Wave252 Damaged',
                'is_enabled' => true,
                'position' => 1,
            ]);

        Auth::guard('admin')->logout();
        $this->actingAs($customer, 'customer')->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertOk();
        $placed = $this->actingAs($customer, 'customer')->postJson('/api/v1/checkout', [
            'email' => $customer->email,
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => [
                'first_name' => 'Wave', 'line1' => '1 Main', 'city' => 'Austin',
                'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US',
            ],
            'shipping_address' => [
                'first_name' => 'Wave', 'line1' => '1 Main', 'city' => 'Austin',
                'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US',
            ],
        ])->assertCreated();
        $orderId = (int) ($placed->json('order.id') ?? $placed->json('data.order.id') ?? 0);
        $this->assertGreaterThan(0, $orderId);

        $orders = $this->actingAs($customer, 'customer')->getJson('/api/v1/store/returnable-orders');
        $orders->assertOk();
        $this->assertNotEmpty($orders->json());
        $this->assertSame($orderId, (int) collect($orders->json())->first()['id']);

        $items = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/store/returnable-items?order_id='.$orderId);
        $items->assertOk();
        $this->assertNotEmpty($items->json());
        $orderItemId = (int) ($items->json('0.orderItemId') ?? $items->json('0.id'));

        $reasons = $this->actingAs($customer, 'customer')->getJson('/api/v1/store/return-reasons');
        $reasons->assertOk();
        $this->assertNotEmpty($reasons->json());

        $fields = $this->actingAs($customer, 'customer')->getJson('/api/v1/store/return-custom-fields');
        $fields->assertOk();

        $create = $this->actingAs($customer, 'customer')->postJson('/api/v1/store/returns', [
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'rma_qty' => 1,
            'resolution_type' => 'return',
            'rma_reason_id' => (int) $reason->id,
            'agreement' => true,
            'customer_note' => 'Wave252 damaged box',
        ]);
        $create->assertCreated();
        $returnId = (int) $create->json('id');
        $this->assertGreaterThan(0, $returnId);
        $this->assertTrue((bool) $create->json('canCancel'));

        $list = $this->actingAs($customer, 'customer')->getJson('/api/v1/store/returns');
        $list->assertOk();
        $this->assertContains($returnId, collect($list->json())->pluck('id')->all());

        $show = $this->actingAs($customer, 'customer')->getJson('/api/v1/store/returns/'.$returnId);
        $show->assertOk();
        $this->assertSame($returnId, (int) $show->json('id'));

        $msg = $this->actingAs($customer, 'customer')->postJson('/api/v1/store/return-messages', [
            'return_id' => $returnId,
            'message' => 'Photo coming soon',
        ]);
        $msg->assertCreated();

        $thread = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/store/return-messages?return_id='.$returnId);
        $thread->assertOk();
        $this->assertNotEmpty($thread->json());

        $cancel = $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/store/returns/'.$returnId.'/cancel');
        $cancel->assertOk();
        $this->assertSame(ShopReturn::STATUS_REJECTED, (string) $cancel->json('status'));
        $this->assertTrue((bool) $cancel->json('canReopen'));

        $reopen = $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/store/returns/'.$returnId.'/reopen');
        $reopen->assertOk();
        $this->assertSame(ShopReturn::STATUS_REQUESTED, (string) $reopen->json('status'));
    }
}
