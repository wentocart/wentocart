<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\PreorderService;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Checkout\Services\CheckoutService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Order\OrderStageService;
use App\Domain\Payment\CheckoutLifecycle;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Payment\PaymentCaptureResult;
use App\Domain\Payment\PaymentGatewayRegistry;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AccountPreorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_list_preorders(): void
    {
        $this->getJson('/api/v1/account/preorders')->assertUnauthorized();
    }

    public function test_empty_account_returns_no_preorder_rows(): void
    {
        $this->seed();
        $customer = Customer::factory()->create();

        $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/preorders')
            ->assertOk()
            ->assertJsonPath('preorders.total', 0)
            ->assertJsonPath('preorders.data', []);
    }

    public function test_lists_paid_pending_failed_cancelled_and_fulfilled_preorders(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $customer = Customer::factory()->create();

        $full = $this->placePreorder($customer, [
            'sku' => 'PRE-FULL',
            'qty' => 1,
            'preorder' => [
                'payment_type' => 'full',
                'preorder_price' => 80,
            ],
        ]);
        $full->updateTrusted(['payment_status' => 'paid', 'amount_paid' => $full->grand_total, 'amount_due' => 0]);

        $deposit = $this->placePreorder($customer, [
            'sku' => 'PRE-DEP',
            'qty' => 2,
            'preorder' => [
                'payment_type' => 'deposit',
                'deposit_type' => 'fixed',
                'deposit_value' => 30,
                'preorder_price' => 90,
            ],
        ]);
        $deposit->updateTrusted(['payment_status' => 'partial']);

        $pending = $this->placePreorder($customer, [
            'sku' => 'PRE-PEND',
            'qty' => 1,
            'preorder' => [
                'payment_type' => 'full',
                'preorder_price' => 50,
            ],
        ]);
        $pending->updateTrusted(['payment_status' => 'pending']);

        $failed = $this->placePreorder($customer, [
            'sku' => 'PRE-FAIL',
            'qty' => 1,
            'preorder' => [
                'payment_type' => 'full',
                'preorder_price' => 40,
            ],
        ]);
        $failed->updateTrusted(['payment_status' => 'failed']);

        $cancelled = $this->placePreorder($customer, [
            'sku' => 'PRE-CXL',
            'qty' => 1,
            'preorder' => [
                'payment_type' => 'full',
                'preorder_price' => 45,
            ],
        ]);
        app(OrderStageService::class)->apply($cancelled, 'cancelled', 'buyer cancel');

        $fulfilled = $this->placePreorder($customer, [
            'sku' => 'PRE-SHIP',
            'qty' => 1,
            'preorder' => [
                'payment_type' => 'full',
                'preorder_price' => 70,
            ],
        ]);
        $fulfilled->updateTrusted(['payment_status' => 'paid', 'amount_paid' => $fulfilled->grand_total, 'amount_due' => 0]);
        app(OrderStageService::class)->apply($fulfilled->fresh(), 'shipped', 'fulfill');

        $rows = collect($this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/preorders?per_page=20')
            ->assertOk()
            ->json('preorders.data'));

        $this->assertCount(6, $rows);

        $fullRow = $rows->firstWhere('order_number', $full->number);
        $depositRow = $rows->firstWhere('order_number', $deposit->number);
        $pendingRow = $rows->firstWhere('order_number', $pending->number);
        $failedRow = $rows->firstWhere('order_number', $failed->number);
        $cancelledRow = $rows->firstWhere('order_number', $cancelled->fresh()->number);
        $fulfilledRow = $rows->firstWhere('order_number', $fulfilled->fresh()->number);

        $this->assertNotNull($fullRow);
        $this->assertSame('fully_paid', $fullRow['status']);
        $this->assertSame('fully_paid', $fullRow['payment_status']);
        $this->assertSame('awaiting_release', $fullRow['preorder_status']);
        $this->assertFalse($fullRow['can_complete_payment']);
        $this->assertEquals(80.0, (float) $fullRow['price']);
        $this->assertEquals(80.0, (float) $fullRow['paid']);
        $this->assertEquals(0.0, (float) $fullRow['remaining']);
        $this->assertEquals(1.0, (float) $fullRow['quantity']);
        $this->assertNotEmpty($fullRow['product']['url']);

        $this->assertSame('partially_paid', $depositRow['status']);
        $this->assertSame('partially_paid', $depositRow['payment_status']);
        $this->assertFalse($depositRow['can_complete_payment']);
        $this->assertEquals(2.0, (float) $depositRow['quantity']);
        $this->assertEquals(180.0, (float) $depositRow['price']);
        $this->assertEquals(60.0, (float) $depositRow['paid']);
        $this->assertEquals(120.0, (float) $depositRow['remaining']);

        $this->assertSame('payment_pending', $pendingRow['status']);
        $this->assertSame('payment_pending', $pendingRow['payment_status']);
        $this->assertFalse($pendingRow['can_complete_payment']);
        $this->assertEquals(0.0, (float) $pendingRow['paid']);
        $this->assertEquals(50.0, (float) $pendingRow['remaining']);

        $this->assertSame('payment_failed', $failedRow['status']);
        $this->assertEquals(0.0, (float) $failedRow['paid']);
        $this->assertFalse($failedRow['can_complete_payment']);

        $this->assertSame('cancelled', $cancelledRow['status']);
        $this->assertSame('cancelled', $cancelledRow['preorder_status']);
        $this->assertFalse($cancelledRow['can_complete_payment']);
        $this->assertSame('fully_paid', $fulfilledRow['payment_status']);
        $this->assertSame('fulfilled', $fulfilledRow['preorder_status']);
        $this->assertFalse($fulfilledRow['can_complete_payment']);
    }

    public function test_percent_deposit_qty_four_matches_order_detail_and_excludes_shipping(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $customer = Customer::factory()->create();
        $order = $this->placePreorder($customer, [
            'sku' => 'PRE-25PCT',
            'price' => 100,
            'qty' => 4,
            'preorder' => [
                'payment_type' => 'deposit',
                'deposit_type' => 'percent',
                'deposit_value' => 25,
                'preorder_price' => 100,
            ],
        ]);
        $line = $order->items->first();
        $this->assertEquals(25.0, (float) $line->unit_price);
        $this->assertEquals(100.0, (float) $line->total);
        $this->assertEquals(100.0, (float) ($line->preorder_data['deposit'] ?? 0));
        $this->assertEquals(300.0, (float) ($line->preorder_data['remaining_balance'] ?? 0));

        $order->updateTrusted(['payment_status' => 'pending', 'amount_paid' => $order->grand_total]);

        $pendingList = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/preorders')
            ->assertOk()
            ->json('preorders.data.0');
        $pendingDetail = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/orders/'.$order->number)
            ->assertOk()
            ->json('order.items.0.preorder');

        $this->assertSame($pendingList['paid'], $pendingDetail['paid']);
        $this->assertSame($pendingList['remaining'], $pendingDetail['remaining']);
        $this->assertSame($pendingList['price'], $pendingDetail['price']);
        $this->assertEquals(400.0, (float) $pendingList['price']);
        $this->assertEquals(100.0, (float) $pendingList['required_now']);
        $this->assertEquals(0.0, (float) $pendingList['paid']);
        $this->assertEquals(400.0, (float) $pendingList['remaining']);
        $this->assertSame('payment_pending', $pendingList['status']);

        $order->updateTrusted([
            'payment_status' => 'partial',
            'amount_paid' => $order->grand_total,
            'amount_due' => 0,
        ]);

        $paidList = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/preorders')
            ->assertOk()
            ->json('preorders.data.0');
        $paidDetail = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/orders/'.$order->fresh()->number)
            ->assertOk()
            ->json('order.items.0.preorder');

        $this->assertEquals(400.0, (float) $paidList['price']);
        $this->assertEquals(100.0, (float) $paidList['paid']);
        $this->assertEquals(300.0, (float) $paidList['remaining']);
        $this->assertSame('partially_paid', $paidList['status']);
        $this->assertFalse($paidList['can_complete_payment']);
        $this->assertEquals((float) $paidList['paid'], (float) $paidDetail['paid']);
        $this->assertEquals((float) $paidList['remaining'], (float) $paidDetail['remaining']);
        $this->assertEquals(100.0, (float) $paidDetail['paid']);
    }

    public function test_same_product_in_two_orders_stays_as_separate_rows(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $customer = Customer::factory()->create();
        $product = $this->makePreorder([
            'sku' => 'PRE-DUP',
            'preorder' => [
                'payment_type' => 'full',
                'preorder_price' => 25,
            ],
        ]);

        $first = $this->checkout($customer, $product, 1);
        $first->updateTrusted(['payment_status' => 'paid']);
        $second = $this->checkout($customer, $product, 3);
        $second->updateTrusted(['payment_status' => 'paid']);

        $rows = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/preorders')
            ->assertOk()
            ->json('preorders.data');

        $this->assertCount(2, $rows);
        $this->assertNotSame($rows[0]['order_number'], $rows[1]['order_number']);
        $qtys = collect($rows)->pluck('quantity')->map(fn ($qty) => (float) $qty)->sort()->values()->all();
        $this->assertSame([1.0, 3.0], $qtys);
        $this->assertEquals(25.0, (float) collect($rows)->firstWhere('quantity', 1)['paid']);
        $this->assertEquals(75.0, (float) collect($rows)->firstWhere('quantity', 3)['paid']);
    }

    public function test_customer_cannot_see_another_customers_preorders(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $owner = Customer::factory()->create();
        $stranger = Customer::factory()->create();
        $order = $this->placePreorder($owner, [
            'sku' => 'PRE-OWN',
            'qty' => 1,
            'preorder' => ['payment_type' => 'full', 'preorder_price' => 33],
        ]);

        $this->actingAs($stranger, 'customer')
            ->getJson('/api/v1/account/preorders')
            ->assertOk()
            ->assertJsonPath('preorders.total', 0)
            ->assertJsonMissing(['order_number' => $order->number]);
    }

    public function test_preorders_are_paginated(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $customer = Customer::factory()->create();
        $product = $this->makePreorder([
            'sku' => 'PRE-PAGE',
            'preorder' => ['payment_type' => 'full', 'preorder_price' => 10],
        ]);
        for ($i = 0; $i < 12; $i++) {
            $this->checkout($customer, $product, 1);
        }

        $page1 = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/preorders?per_page=10&page=1')
            ->assertOk();
        $this->assertSame(12, (int) $page1->json('preorders.total'));
        $this->assertCount(10, $page1->json('preorders.data'));
        $this->assertSame(2, (int) $page1->json('preorders.last_page'));

        $page2 = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/preorders?per_page=10&page=2')
            ->assertOk();
        $this->assertCount(2, $page2->json('preorders.data'));
    }

    public function test_admin_marking_order_paid_keeps_preorder_in_account_list(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $customer = Customer::factory()->create();
        $order = $this->placePreorder($customer, [
            'sku' => 'PRE-BO-PAID',
            'qty' => 2,
            'preorder' => [
                'payment_type' => 'deposit',
                'deposit_type' => 'percent',
                'deposit_value' => 25,
                'preorder_price' => 100,
            ],
        ]);
        $line = $order->items->first();
        $this->assertTrue((bool) ($line->preorder_data['enabled'] ?? false));

        $admin = Admin::query()->first() ?: Admin::factory()->create();
        $this->actingAs($admin, 'admin');
        $this->put('/admin/orders/'.$order->id, [
            'email' => $customer->email,
            'customer_id' => $customer->id,
            'status' => $order->status,
            'currency_code' => $order->currency_code ?: 'USD',
            'payment_status' => 'paid',
            'payment_method_id' => $order->payment_method_id,
            'shipping_method_id' => $order->shipping_method_id,
            'shipping_total' => (float) $order->shipping_total,
            'billing_address' => [
                'first_name' => 'P',
                'last_name' => 'O',
                'line1' => '1 Main',
                'city' => 'Austin',
                'country_code' => 'US',
            ],
            'shipping_address' => [
                'first_name' => 'P',
                'last_name' => 'O',
                'line1' => '1 Main',
                'city' => 'Austin',
                'country_code' => 'US',
            ],
            'items' => [[
                'product_id' => $line->product_id,
                'sku' => $line->sku,
                'name' => $line->name,
                'qty' => (int) $line->qty,
                'unit_price' => (float) $line->unit_price,
                'tax_rate' => 0,
                'discount' => 0,
                'discount_type' => 'amount',
            ]],
        ])->assertRedirect();

        $fresh = $order->fresh('items');
        $this->assertSame('paid', $fresh->payment_status);
        $this->assertTrue((bool) ($fresh->items->first()->preorder_data['enabled'] ?? false));

        $row = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/preorders')
            ->assertOk()
            ->json('preorders.data.0');

        $this->assertSame($fresh->number, $row['order_number']);
        $this->assertEquals(200.0, (float) $row['price']);
        $this->assertEquals(50.0, (float) $row['paid']);
        $this->assertEquals(150.0, (float) $row['remaining']);
        $this->assertSame('partially_paid', $row['payment_status']);
        $this->assertFalse($row['can_complete_payment']);
        $this->assertSame(1, (int) $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/preorders')
            ->json('preorders.total'));
    }

    public function test_missing_preorder_snapshot_still_lists_25_percent_paid_line(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $customer = Customer::factory()->create();
        $order = $this->placePreorder($customer, [
            'sku' => 'PRE-LOST-JSON',
            'qty' => 4,
            'preorder' => [
                'payment_type' => 'deposit',
                'deposit_type' => 'percent',
                'deposit_value' => 25,
                'preorder_price' => 100,
            ],
        ]);
        $order->updateTrusted([
            'payment_status' => 'paid',
            'amount_paid' => $order->grand_total,
            'amount_due' => 0,
        ]);
        $line = $order->items->first();
        $line->preorder_data = null;
        $line->save();

        $row = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/preorders')
            ->assertOk()
            ->json('preorders.data.0');

        $this->assertSame($order->number, $row['order_number']);
        $this->assertEquals(4.0, (float) $row['quantity']);
        $this->assertEquals(400.0, (float) $row['price']);
        $this->assertEquals(100.0, (float) $row['paid']);
        $this->assertEquals(300.0, (float) $row['remaining']);
        $this->assertSame('partially_paid', $row['payment_status']);
        $this->assertFalse($row['can_complete_payment']);
        $this->assertTrue((bool) ($line->fresh()->preorder_data['enabled'] ?? false));
    }

    public function test_complete_payment_enables_when_product_is_back_in_stock(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $customer = Customer::factory()->create();
        $order = $this->placePreorder($customer, [
            'sku' => 'PRE-STOCK',
            'qty' => 4,
            'preorder' => [
                'payment_type' => 'deposit',
                'deposit_type' => 'percent',
                'deposit_value' => 25,
                'preorder_price' => 100,
            ],
        ]);
        $order->updateTrusted([
            'payment_status' => 'partial',
            'amount_paid' => $order->grand_total,
            'amount_due' => 0,
        ]);
        $blocked = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/preorders')
            ->assertOk()
            ->json('preorders.data.0');
        $this->assertFalse($blocked['can_complete_payment']);

        $product = Product::query()->where('sku', 'PRE-STOCK')->firstOrFail();
        $product->update(['stock_qty' => 12, 'stock_status' => 'in_stock']);

        $open = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/preorders')
            ->assertOk()
            ->json('preorders.data.0');
        $this->assertTrue($open['can_complete_payment']);
    }

    public function test_complete_payment_enables_when_stock_qty_increases_even_if_status_stays_out_of_stock(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $customer = Customer::factory()->create();
        $order = $this->placePreorder($customer, [
            'sku' => 'PRE-QTY-OOS',
            'qty' => 1,
            'preorder' => [
                'payment_type' => 'deposit',
                'deposit_type' => 'percent',
                'deposit_value' => 25,
                'preorder_price' => 200,
                'fulfillment_rule' => 'auto',
                'release_at' => '2026-11-01T00:00',
            ],
        ]);
        $order->updateTrusted([
            'payment_status' => 'partial',
            'amount_paid' => $order->grand_total,
            'amount_due' => 0,
        ]);
        $this->assertFalse($this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/preorders')
            ->json('preorders.data.0.can_complete_payment'));

        Product::query()->where('sku', 'PRE-QTY-OOS')->firstOrFail()
            ->update(['stock_qty' => 100, 'stock_status' => 'out_of_stock']);

        $row = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/preorders')
            ->assertOk()
            ->json('preorders.data.0');
        $this->assertTrue($row['can_complete_payment']);
        $this->assertSame('ready', $row['preorder_status']);
        $this->assertEquals(150.0, (float) $row['remaining']);
    }

    public function test_switching_product_fulfillment_to_auto_unlocks_when_stock_is_available(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $customer = Customer::factory()->create();
        $order = $this->placePreorder($customer, [
            'sku' => 'PRE-SWITCH-AUTO',
            'qty' => 1,
            'preorder' => [
                'payment_type' => 'deposit',
                'deposit_type' => 'percent',
                'deposit_value' => 25,
                'preorder_price' => 200,
                'fulfillment_rule' => 'manual',
                'release_at' => '2026-11-01T00:00',
            ],
        ]);
        $order->updateTrusted([
            'payment_status' => 'partial',
            'amount_paid' => $order->grand_total,
            'amount_due' => 0,
        ]);
        $line = $order->items->first();
        $this->assertSame('manual', $line->preorder_data['fulfillment_rule'] ?? null);

        $product = Product::query()->where('sku', 'PRE-SWITCH-AUTO')->firstOrFail();
        $product->update(['stock_qty' => 100, 'stock_status' => 'out_of_stock']);
        $product->preorderSetting()->update(['fulfillment_rule' => 'auto']);

        $row = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/preorders')
            ->assertOk()
            ->json('preorders.data.0');
        $this->assertTrue($row['can_complete_payment']);
        $this->assertSame('ready', $row['preorder_status']);
    }

    public function test_manual_fulfillment_without_stock_keeps_complete_payment_disabled(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $customer = Customer::factory()->create();
        $order = $this->placePreorder($customer, [
            'sku' => 'PRE-MANUAL-BLOCK',
            'qty' => 1,
            'preorder' => [
                'payment_type' => 'deposit',
                'deposit_type' => 'percent',
                'deposit_value' => 25,
                'preorder_price' => 80,
                'fulfillment_rule' => 'manual',
                'release_at' => '2026-10-01T00:00',
            ],
        ]);
        $order->updateTrusted([
            'payment_status' => 'partial',
            'amount_paid' => $order->grand_total,
            'amount_due' => 0,
        ]);
        $row = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/preorders')
            ->assertOk()
            ->json('preorders.data.0');
        $this->assertFalse($row['can_complete_payment']);
        $this->assertSame('awaiting_release', $row['preorder_status']);
        $this->assertGreaterThan(0, (float) $row['remaining']);
    }

    public function test_customer_can_complete_remaining_payment_and_preorder_stays_listed(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $customer = Customer::factory()->create();
        $order = $this->placePreorder($customer, [
            'sku' => 'PRE-COMPLETE',
            'qty' => 4,
            'preorder' => [
                'payment_type' => 'deposit',
                'deposit_type' => 'percent',
                'deposit_value' => 25,
                'preorder_price' => 100,
            ],
        ]);
        $order->updateTrusted([
            'payment_status' => 'partial',
            'amount_paid' => $order->grand_total,
            'amount_due' => 0,
        ]);
        $paypal = $this->paypalMethod();
        $order->updateTrusted(['payment_method_id' => $paypal->id]);
        $itemId = (int) $order->items->first()->id;

        $listed = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/preorders')
            ->assertOk()
            ->json('preorders.data.0');
        $this->assertEquals(100.0, (float) $listed['paid']);
        $this->assertEquals(300.0, (float) $listed['remaining']);
        $this->assertFalse($listed['can_complete_payment']);

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/account/preorders/'.$itemId.'/pay')
            ->assertUnprocessable();

        app(PreorderService::class)->markItemReady($order->items->first());

        $ready = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/preorders')
            ->assertOk()
            ->json('preorders.data.0');
        $this->assertTrue($ready['can_complete_payment']);
        $this->assertSame('ready', $ready['preorder_status']);

        $queued = $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/account/preorders/'.$itemId.'/pay')
            ->assertOk();
        $this->assertSame('/cart', $queued->json('redirect_url'));
        $this->assertEquals(300.0, (float) $queued->json('remaining'));
        $this->assertEquals(100.0, (float) $queued->json('preorder.paid'));
        $this->assertEquals(300.0, (float) $queued->json('preorder.remaining'));

        $cart = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->json('cart.items');
        $this->assertCount(1, $cart);
        $this->assertTrue((bool) ($cart[0]['preorder_data']['final_payment'] ?? false));
        $this->assertSame($itemId, (int) ($cart[0]['preorder_data']['source_order_item_id'] ?? 0));
        $this->assertSame($order->number, $cart[0]['preorder_data']['source_order_number'] ?? null);
        $this->assertEquals(300.0, (float) ($cart[0]['unit_price'] ?? 0));
        $this->assertEquals(1.0, (float) ($cart[0]['qty'] ?? 0));

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/account/preorders/'.$itemId.'/pay')
            ->assertOk();
        $cartPayload = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->json('cart.items');
        $this->assertCount(1, $cartPayload);

        $paymentOrder = $this->checkoutCurrent($customer, $this->instantPaidMethod());
        $this->assertTrue((bool) data_get($paymentOrder->payment_details, 'preorder_final_payment'));
        $this->assertSame($order->id, (int) data_get($paymentOrder->payment_details, 'source_orders.0.order_id'));

        $paid = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/preorders')
            ->assertOk();
        $this->assertSame(1, (int) $paid->json('preorders.total'));
        $row = $paid->json('preorders.data.0');
        $this->assertEquals(400.0, (float) $row['price']);
        $this->assertEquals(400.0, (float) $row['paid']);
        $this->assertEquals(0.0, (float) $row['remaining']);
        $this->assertSame('fully_paid', $row['payment_status']);
        $this->assertFalse($row['can_complete_payment']);
        $this->assertSame($order->number, $row['order_number']);

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/account/preorders/'.$itemId.'/pay')
            ->assertUnprocessable();
    }

    public function test_failed_remaining_payment_does_not_increase_paid_amount(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $customer = Customer::factory()->create();
        $order = $this->placePreorder($customer, [
            'sku' => 'PRE-FAIL-PAY',
            'qty' => 4,
            'preorder' => [
                'payment_type' => 'deposit',
                'deposit_type' => 'percent',
                'deposit_value' => 25,
                'preorder_price' => 100,
            ],
        ]);
        $order->updateTrusted([
            'payment_status' => 'partial',
            'amount_paid' => $order->grand_total,
            'amount_due' => 0,
            'payment_method_id' => $this->paypalMethod()->id,
        ]);
        $itemId = (int) $order->items->first()->id;
        $paidBefore = (float) $order->fresh()->amount_paid;
        app(PreorderService::class)->markItemReady($order->items->first());

        app(PaymentGatewayRegistry::class)->register(new class implements \App\Domain\Payment\Contracts\PaymentGateway
        {
            public function driver(): string
            {
                return 'paypal';
            }

            public function checkoutLifecycle(): CheckoutLifecycle
            {
                return CheckoutLifecycle::OrderFirst;
            }

            public function capture(\App\Domain\Order\Models\Order $order, array $payload = []): PaymentCaptureResult
            {
                return PaymentCaptureResult::failed('Card declined.');
            }

            public function supportsTransactionalCapture(): bool
            {
                return true;
            }
        });

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/account/preorders/'.$itemId.'/pay')
            ->assertOk()
            ->assertJsonPath('redirect_url', '/cart');

        try {
            $this->checkoutCurrent($customer, $this->paypalMethod());
            $this->fail('Checkout should have rejected the failed remaining payment.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }

        $row = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/preorders')
            ->assertOk()
            ->json('preorders.data.0');
        $this->assertEquals(100.0, (float) $row['paid']);
        $this->assertEquals(300.0, (float) $row['remaining']);
        $this->assertTrue($row['can_complete_payment']);
        $this->assertEquals($paidBefore, (float) $order->fresh()->amount_paid);
    }

    public function test_customer_cannot_pay_another_customers_preorder(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $owner = Customer::factory()->create();
        $stranger = Customer::factory()->create();
        $order = $this->placePreorder($owner, [
            'sku' => 'PRE-PAY-OWN',
            'qty' => 1,
            'preorder' => [
                'payment_type' => 'deposit',
                'deposit_type' => 'fixed',
                'deposit_value' => 20,
                'preorder_price' => 80,
            ],
        ]);
        $order->updateTrusted(['payment_status' => 'partial', 'amount_paid' => $order->grand_total, 'amount_due' => 0]);
        $itemId = (int) $order->items->first()->id;

        $this->actingAs($stranger, 'customer')
            ->postJson('/api/v1/account/preorders/'.$itemId.'/pay')
            ->assertNotFound();

        $this->assertEquals((float) $order->amount_paid, (float) $order->fresh()->amount_paid);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function placePreorder(Customer $customer, array $options): Order
    {
        $qty = (float) ($options['qty'] ?? 1);
        $product = $this->makePreorder([
            'sku' => $options['sku'],
            'preorder' => $options['preorder'] ?? [],
        ]);

        return $this->checkout($customer, $product, $qty);
    }

    protected function checkout(Customer $customer, Product $product, float $qty): Order
    {
        $carts = app(CartService::class);
        $cart = $carts->current($customer);
        $carts->add($cart, $product->fresh()->load('preorderSetting'), $qty);
        $pay = PaymentMethod::query()->where('is_enabled', true)->firstOrFail();
        $ship = ShippingMethod::query()->where('is_enabled', true)->value('id');
        $addr = [
            'first_name' => 'P', 'last_name' => 'O', 'line1' => '1 Main',
            'city' => 'Austin', 'state' => 'TX', 'postcode' => '78701', 'country_code' => 'US',
        ];
        $result = app(CheckoutService::class)->place($cart->fresh(['items.product.preorderSetting']), [
            'email' => $customer->email,
            'payment_method_id' => $pay->id,
            'shipping_method_id' => $ship,
            'billing_address' => $addr,
            'shipping_address' => $addr,
        ], $customer);

        return $result['order']->fresh('items');
    }

    protected function checkoutCurrent(Customer $customer, PaymentMethod $pay): Order
    {
        $carts = app(CartService::class);
        $cart = $carts->current($customer);
        $ship = ShippingMethod::query()->where('is_enabled', true)->value('id');
        $addr = [
            'first_name' => 'P', 'last_name' => 'O', 'line1' => '1 Main',
            'city' => 'Austin', 'state' => 'TX', 'postcode' => '78701', 'country_code' => 'US',
        ];
        $payload = [
            'email' => $customer->email,
            'payment_method_id' => $pay->id,
            'billing_address' => $addr,
            'shipping_address' => $addr,
        ];
        if ($ship) {
            $payload['shipping_method_id'] = $ship;
        }
        $result = app(CheckoutService::class)->place($cart->fresh(['items.product.preorderSetting']), $payload, $customer);

        return $result['order']->fresh(['items']);
    }

    protected function instantPaidMethod(): PaymentMethod
    {
        app(PaymentGatewayRegistry::class)->register(new class implements \App\Domain\Payment\Contracts\PaymentGateway
        {
            public function driver(): string
            {
                return 'instant_paid';
            }

            public function checkoutLifecycle(): CheckoutLifecycle
            {
                return CheckoutLifecycle::OrderFirst;
            }

            public function capture(\App\Domain\Order\Models\Order $order, array $payload = []): PaymentCaptureResult
            {
                return PaymentCaptureResult::paid(['test' => true]);
            }

            public function supportsTransactionalCapture(): bool
            {
                return true;
            }
        });

        return PaymentMethod::query()->updateOrCreate(
            ['code' => 'instant_paid'],
            [
                'name' => 'Instant paid',
                'driver' => 'instant_paid',
                'is_enabled' => true,
                'position' => 1,
                'config' => [],
                'rules' => PaymentMethod::emptyRules(),
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makePreorder(array $overrides): Product
    {
        $this->actingAs(Admin::query()->first(), 'admin');
        $preorder = $overrides['preorder'] ?? [];
        unset($overrides['preorder']);

        $this->post('/admin/products', $this->withProductCategories(array_merge([
            'type' => 'simple',
            'name' => $overrides['sku'] ?? 'Preorder Product',
            'slug' => strtolower((string) ($overrides['sku'] ?? 'preorder-product')),
            'price' => 100,
            'stock_qty' => 0,
            'manage_stock' => true,
            'is_enabled' => true,
            'available_for_order' => true,
            'preorder_enabled' => true,
            'preorder' => array_merge([
                'start_at' => '2026-10-01T00:00',
                'end_at' => '2026-10-30T23:59',
                'release_at' => '2026-11-01T00:00',
                'payment_type' => 'full',
                'min_quantity' => 1,
                'show_badge' => true,
                'show_release_date' => true,
                'show_delivery_date' => true,
                'fulfillment_rule' => 'auto',
                'total_limit' => 500,
            ], $preorder),
        ], $overrides)))->assertProductSaveRedirect();

        return Product::query()->where('sku', $overrides['sku'])->firstOrFail();
    }

    protected function paypalMethod(): PaymentMethod
    {
        return PaymentMethod::query()->updateOrCreate(
            ['code' => 'paypal'],
            [
                'name' => 'PayPal',
                'driver' => 'paypal',
                'is_enabled' => true,
                'position' => 20,
                'config' => [],
                'rules' => PaymentMethod::emptyRules(),
            ]
        );
    }
}
