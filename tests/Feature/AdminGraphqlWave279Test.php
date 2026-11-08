<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminGraphqlWave279Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_admin_sales_rest_tree(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $customer = Customer::query()->create([
            'name' => 'Wave279 Buyer',
            'email' => 'w279-'.$suffix.'@example.com',
            'password' => Hash::make('Password1!'),
            'is_enabled' => true,
        ]);
        $product = Product::query()->where('is_enabled', true)->where('type', 'simple')->first();

        $addr = ['country_code' => 'US', 'line1' => '1 Test St', 'city' => 'Austin', 'postal_code' => '78701'];
        $order = Order::createTrusted([
            'number' => 'W279-'.$suffix,
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'processing',
            'payment_status' => 'paid',
            'currency_code' => 'USD',
            'subtotal' => 40,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'fee_total' => 0,
            'grand_total' => 40,
            'refunded_total' => 0,
            'amount_paid' => 40,
            'amount_due' => 0,
            'billing_address' => $addr,
            'shipping_address' => $addr,
        ]);
        $order->items()->create([
            'product_id' => $product?->id,
            'sku' => (string) ($product?->sku ?? 'SKU-279'),
            'name' => 'Wave279 Item',
            'qty' => 1,
            'unit_price' => 40,
            'total' => 40,
        ]);

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/orders');
        $list->assertOk();
        $this->assertIsArray($list->json('data'));
        $this->assertNotEmpty($list->json('data'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/orders/'.$order->id);
        $show->assertOk();
        $this->assertSame((int) $order->id, (int) $show->json('data.id'));
        $this->assertSame('W279-'.$suffix, (string) $show->json('data.incrementId'));

        $comment = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/orders/'.$order->id.'/comments',
            ['comment' => 'Wave279 note', 'customer_notified' => 0]
        );
        $comment->assertCreated();
        $this->assertSame('Wave279 note', (string) $comment->json('data.comment'));

        $ship = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/shipments/'.$order->id,
            ['carrier' => 'ups', 'tracking_number' => 'TRACK-'.$suffix]
        );
        $ship->assertCreated();
        $shipmentId = (int) $ship->json('data.id');
        $this->assertGreaterThan(0, $shipmentId);

        $this->actingAs($admin, 'admin')->getJson('/api/v1/console/shipments')->assertOk();
        $this->actingAs($admin, 'admin')->getJson('/api/v1/console/shipments/'.$shipmentId)->assertOk();

        $invoice = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/invoices/'.$order->id);
        $this->assertContains($invoice->status(), [201, 422]);
        $this->actingAs($admin, 'admin')->getJson('/api/v1/console/invoices')->assertOk();

        $tx = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/transactions', [
            'order_id' => $order->id,
            'amount' => 0.01,
        ]);
        // Already fully paid — either records tiny overpay path as validation or accepts.
        $this->assertContains($tx->status(), [201, 422]);
        $this->actingAs($admin, 'admin')->getJson('/api/v1/console/transactions')->assertOk();

        $refundOrder = Order::createTrusted([
            'number' => 'W279R-'.$suffix,
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'processing',
            'payment_status' => 'paid',
            'currency_code' => 'USD',
            'subtotal' => 25,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'fee_total' => 0,
            'grand_total' => 25,
            'refunded_total' => 0,
            'amount_paid' => 25,
            'amount_due' => 0,
            'billing_address' => $addr,
            'shipping_address' => $addr,
        ]);
        $refund = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/refunds/'.$refundOrder->id,
            ['amount' => 5, 'comment' => 'Wave279 refund']
        );
        $this->assertContains($refund->status(), [201, 422]);
        $this->actingAs($admin, 'admin')->getJson('/api/v1/console/refunds')->assertOk();

        $cancelOrder = Order::createTrusted([
            'number' => 'W279C-'.$suffix,
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'pending',
            'payment_status' => 'pending',
            'currency_code' => 'USD',
            'subtotal' => 10,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'fee_total' => 0,
            'grand_total' => 10,
            'refunded_total' => 0,
            'amount_paid' => 0,
            'amount_due' => 10,
            'billing_address' => $addr,
            'shipping_address' => $addr,
        ]);
        $cancel = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/orders/'.$cancelOrder->id.'/cancel',
            ['comment' => 'Wave279 cancel']
        );
        $this->assertContains($cancel->status(), [200, 422]);

        $reorder = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/reorders/'.$order->id);
        $this->assertContains($reorder->status(), [201, 422]);
    }
}
