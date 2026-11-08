<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\ShopReturn;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminGraphqlWave205Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_rma_requests_rest_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $payment = PaymentMethod::query()->firstOrFail();
        $shipping = ShippingMethod::query()->where('is_enabled', true)->firstOrFail();
        $customer = Customer::factory()->create();

        Auth::guard('admin')->logout();
        $this->actingAs($customer, 'customer')->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $placed = $this->actingAs($customer, 'customer')->postJson('/api/v1/checkout', [
            'email' => $customer->email,
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Wave', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Wave', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated();
        $orderId = (int) ($placed->json('order.id') ?? $placed->json('data.order.id') ?? 0);
        $this->assertGreaterThan(0, $orderId);

        Auth::guard('customer')->logout();
        $this->postJson('/api/v1/auth/logout');

        $returnable = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/rma/returnable-items/'.$orderId);
        $returnable->assertOk();
        $this->assertNotEmpty($returnable->json('data'));
        $orderItemId = (int) ($returnable->json('data.0.orderItemId') ?? $returnable->json('data.0.id'));

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/rma/requests', [
            'orderId' => $orderId,
            'items' => [['order_item_id' => $orderItemId, 'qty' => 1]],
            'information' => 'Wave205 damaged',
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');
        $this->assertGreaterThan(0, $id);
        $available = json_decode((string) $create->json('availableStatuses'), true) ?: [];
        $this->assertNotEmpty($available);

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/rma/requests?order_id='.$orderId);
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $this->assertArrayHasKey('currentPage', $list->json('meta'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/rma/requests/'.$id);
        $show->assertOk();
        $this->assertNotEmpty(json_decode((string) $show->json('availableStatuses'), true));

        $statusId = (string) ($available[0]['id'] ?? '');
        $update = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/rma/requests/'.$id.'/status', [
            'rmaStatusId' => $statusId,
            'note' => 'Wave205 approved',
        ]);
        $update->assertOk();
        $this->assertSame('approved', (string) $update->json('status'));

        $msg = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/rma/requests/'.$id.'/messages', [
            'body' => 'Wave205 packing tip',
        ]);
        $msg->assertCreated();
        $this->assertSame('Wave205 packing tip', (string) $msg->json('body'));

        $messages = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/rma/requests/'.$id.'/messages');
        $messages->assertOk();
        $this->assertNotEmpty($messages->json('data'));

        ShopReturn::query()->whereKey($id)->update(['status' => ShopReturn::STATUS_REJECTED]);
        $reopen = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/rma/requests/'.$id.'/reopen');
        $reopen->assertOk();
        $this->assertSame(ShopReturn::STATUS_REQUESTED, (string) $reopen->json('status'));

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminReturns(limit: 5) { id statusTitle } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
    }
}
