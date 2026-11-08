<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Checkout\Models\CheckoutGroup;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\CheckoutLifecycle;
use App\Domain\Payment\Contracts\PaymentGateway;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Payment\PaymentCaptureResult;
use App\Domain\Payment\PaymentGatewayRegistry;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Subscription\Models\CustomerSubscription;
use App\Domain\Subscription\Models\SubscriptionPlan;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShippingSplitCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function makeMethod(string $code, float $price): ShippingMethod
    {
        return ShippingMethod::query()->create([
            'code' => $code,
            'name' => 'Ship '.$code,
            'type' => 'flat',
            'driver' => 'offline',
            'price' => $price,
            'is_enabled' => true,
            'position' => (int) round($price),
            'conditions' => ShippingMethod::emptyConditions(),
            'rate_config' => ['tiers' => []],
        ]);
    }

    protected function makeProduct(string $sku, float $price, ?ShippingMethod $method = null, array $overrides = []): Product
    {
        $admin = Admin::query()->firstOrFail();
        $this->actingAs($admin, 'admin');
        $payload = $this->withProductCategories(array_merge([
            'type' => 'simple',
            'name' => $sku,
            'sku' => $sku,
            'price' => $price,
            'is_enabled' => true,
            'manage_stock' => false,
            'available_all_carriers' => $method ? false : true,
            'shipping_method_ids' => $method ? [$method->id] : [],
        ], $overrides));
        $this->post('/admin/products', $payload)->assertProductSaveRedirect($sku);
        \Illuminate\Support\Facades\Auth::guard('admin')->logout();

        return Product::query()->where('sku', $sku)->firstOrFail()->load('shippingMethods');
    }

    /** @return array<string, mixed> */
    protected function checkoutPayload(PaymentMethod $payment, ShippingMethod $shipping, string $email = 'split@example.com'): array
    {
        return [
            'email' => $email,
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ];
    }

    protected function registerFakeGateway(string $driver, callable $capture, bool $transactional = true): PaymentMethod
    {
        $fake = new class($driver, $capture, $transactional) implements PaymentGateway
        {
            public function __construct(private string $driverName, private $captureFn, private bool $transactional) {}

            public function driver(): string
            {
                return $this->driverName;
            }

            public function checkoutLifecycle(): CheckoutLifecycle
            {
                return CheckoutLifecycle::OrderFirst;
            }

            public function supportsTransactionalCapture(): bool
            {
                return $this->transactional;
            }

            public function capture(Order $order, array $payload = []): PaymentCaptureResult
            {
                return ($this->captureFn)($order, $payload);
            }
        };
        app(PaymentGatewayRegistry::class)->register($fake);

        $payment = PaymentMethod::query()->whereNull('seller_id')->where('is_enabled', true)->firstOrFail();
        $payment->update(['driver' => $driver]);

        return $payment->fresh();
    }

    public function test_three_methods_create_three_orders_one_checkout_reference_and_one_payment(): void
    {
        $methodA = $this->makeMethod('split-a', 10);
        $methodB = $this->makeMethod('split-b', 20);
        $methodC = $this->makeMethod('split-c', 15);
        $productA = $this->makeProduct('SPLIT-A', 30, $methodA);
        $productB = $this->makeProduct('SPLIT-B', 40, $methodB);
        $productC = $this->makeProduct('SPLIT-C', 50, $methodC);

        $captures = 0;
        $capturedAmount = null;
        $payment = $this->registerFakeGateway('split_pay', function (Order $order, array $payload) use (&$captures, &$capturedAmount) {
            $captures++;
            $capturedAmount = (float) ($payload['capture_amount'] ?? $order->grand_total);

            return PaymentCaptureResult::paid(['txn' => 'split-1']);
        });

        $this->postJson('/api/v1/cart', ['product_id' => $productA->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart', ['product_id' => $productB->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart', ['product_id' => $productC->id, 'qty' => 1])->assertOk();

        $response = $this->postJson('/api/v1/checkout', $this->checkoutPayload($payment, $methodA));
        $response->assertCreated();

        $this->assertSame(1, $captures);
        $orders = Order::query()->where('email', 'split@example.com')->orderBy('id')->get();
        $this->assertCount(3, $orders);
        $this->assertCount(3, $response->json('orders'));
        $reference = $orders[0]->number;
        $this->assertNotEmpty($reference);
        $this->assertStringStartsWith('WENTO-', $reference);
        $this->assertTrue($orders->every(fn (Order $order) => $order->number === $reference));
        $this->assertTrue($orders->every(fn (Order $order) => $order->checkout_reference === $reference));
        $this->assertCount(1, $orders->pluck('number')->unique());
        $this->assertCount(3, $orders->pluck('id')->unique());
        $this->assertSame(1, CheckoutGroup::query()->where('reference', $reference)->count());

        $byProduct = [];
        foreach ($orders as $order) {
            $this->assertCount(1, $order->items);
            $byProduct[(int) $order->items->first()->product_id] = $order;
            $this->assertSame('paid', $order->payment_status);
        }

        $this->assertSame((int) $methodA->id, (int) $byProduct[$productA->id]->shipping_method_id);
        $this->assertSame((int) $methodB->id, (int) $byProduct[$productB->id]->shipping_method_id);
        $this->assertSame((int) $methodC->id, (int) $byProduct[$productC->id]->shipping_method_id);
        $this->assertEqualsWithDelta(10, (float) $byProduct[$productA->id]->shipping_total, 0.01);
        $this->assertEqualsWithDelta(20, (float) $byProduct[$productB->id]->shipping_total, 0.01);
        $this->assertEqualsWithDelta(15, (float) $byProduct[$productC->id]->shipping_total, 0.01);

        $sum = round($orders->sum(fn (Order $order) => (float) $order->grand_total), 2);
        $this->assertEqualsWithDelta($sum, (float) $capturedAmount, 0.01);
        $this->assertEqualsWithDelta($sum, (float) CheckoutGroup::query()->where('reference', $reference)->value('grand_total'), 0.01);
        $this->assertSame($reference, $response->json('checkout_reference'));
        $this->assertSame($reference, $response->json('order.number'));
        foreach ($response->json('orders') as $row) {
            $this->assertSame($reference, $row['number']);
        }
    }

    public function test_two_same_and_one_different_create_two_orders(): void
    {
        $methodA = $this->makeMethod('pair-a', 8);
        $methodB = $this->makeMethod('pair-b', 12);
        $productA1 = $this->makeProduct('PAIR-A1', 20, $methodA);
        $productA2 = $this->makeProduct('PAIR-A2', 25, $methodA);
        $productB = $this->makeProduct('PAIR-B', 15, $methodB);
        $payment = PaymentMethod::query()->where('is_enabled', true)->firstOrFail();

        $this->postJson('/api/v1/cart', ['product_id' => $productA1->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart', ['product_id' => $productA2->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart', ['product_id' => $productB->id, 'qty' => 1])->assertOk();

        $this->postJson('/api/v1/checkout', $this->checkoutPayload($payment, $methodA, 'pair@example.com'))
            ->assertCreated();

        $orders = Order::query()->where('email', 'pair@example.com')->get();
        $this->assertCount(2, $orders);
        $grouped = $orders->keyBy('shipping_method_id');
        $this->assertCount(2, $grouped[$methodA->id]->items);
        $this->assertCount(1, $grouped[$methodB->id]->items);
        $this->assertEqualsWithDelta(8, (float) $grouped[$methodA->id]->shipping_total, 0.01);
        $this->assertEqualsWithDelta(12, (float) $grouped[$methodB->id]->shipping_total, 0.01);
        $this->assertSame($orders[0]->checkout_reference, $orders[1]->checkout_reference);
        $this->assertSame($orders[0]->number, $orders[1]->number);
        $this->assertNotSame($orders[0]->id, $orders[1]->id);
    }

    public function test_all_same_method_creates_one_order(): void
    {
        $method = $this->makeMethod('same-flat', 7);
        $a = $this->makeProduct('SAME-A', 11, $method);
        $b = $this->makeProduct('SAME-B', 12, $method);
        $c = $this->makeProduct('SAME-C', 13, $method);
        $payment = PaymentMethod::query()->where('is_enabled', true)->firstOrFail();

        $this->postJson('/api/v1/cart', ['product_id' => $a->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart', ['product_id' => $b->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart', ['product_id' => $c->id, 'qty' => 1])->assertOk();

        $this->postJson('/api/v1/checkout', $this->checkoutPayload($payment, $method, 'same@example.com'))
            ->assertCreated();

        $orders = Order::query()->where('email', 'same@example.com')->get();
        $this->assertCount(1, $orders);
        $this->assertCount(3, $orders[0]->items);
        $this->assertEqualsWithDelta(7, (float) $orders[0]->shipping_total, 0.01);
        $this->assertNotEmpty($orders[0]->number);
        $this->assertSame($orders[0]->number, $orders[0]->checkout_reference);
    }

    public function test_single_method_seed_product_still_places_one_order(): void
    {
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $payment = PaymentMethod::query()->firstOrFail();
        $shipping = ShippingMethod::query()->where('is_enabled', true)->firstOrFail();

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload($payment, $shipping, 'legacy@example.com'))
            ->assertCreated()
            ->assertJsonPath('order.email', 'legacy@example.com');

        $this->assertSame(1, Order::query()->where('email', 'legacy@example.com')->count());
        $legacy = Order::query()->where('email', 'legacy@example.com')->first();
        $this->assertNotEmpty($legacy->number);
        $this->assertSame($legacy->number, $legacy->checkout_reference);
    }

    public function test_virtual_and_physical_split_into_two_orders(): void
    {
        $method = $this->makeMethod('phys-only', 9);
        $physical = $this->makeProduct('PHYS-1', 22, $method);
        $virtual = $this->makeProduct('VIRT-1', 18, null, ['type' => 'virtual']);
        $virtual->forceFill(['type' => 'virtual'])->save();
        $this->assertSame('virtual', $virtual->fresh()->type);
        $this->assertFalse($virtual->fresh()->requiresShipping());
        $payment = PaymentMethod::query()->where('is_enabled', true)->firstOrFail();

        $this->postJson('/api/v1/cart', ['product_id' => $physical->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart', ['product_id' => $virtual->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload($payment, $method, 'mix@example.com'))
            ->assertCreated();

        $orders = Order::query()->where('email', 'mix@example.com')->get();
        $this->assertCount(2, $orders);
        $virtualOrder = $orders->first(fn (Order $order) => $order->items->contains(fn ($item) => (int) $item->product_id === (int) $virtual->id));
        $physicalOrder = $orders->first(fn (Order $order) => (int) $order->shipping_method_id === (int) $method->id);
        $this->assertNotNull($virtualOrder);
        $this->assertNotNull($physicalOrder);
        $this->assertNull($virtualOrder->shipping_method_id);
        $this->assertEqualsWithDelta(0, (float) $virtualOrder->shipping_total, 0.01);
        $this->assertEqualsWithDelta(9, (float) $physicalOrder->shipping_total, 0.01);
    }

    public function test_payment_failure_rolls_back_all_child_orders(): void
    {
        $methodA = $this->makeMethod('fail-a', 4);
        $methodB = $this->makeMethod('fail-b', 6);
        $a = $this->makeProduct('FAIL-A', 10, $methodA);
        $b = $this->makeProduct('FAIL-B', 10, $methodB);
        $payment = $this->registerFakeGateway('split_fail', fn () => PaymentCaptureResult::failed('Declined'));

        $this->postJson('/api/v1/cart', ['product_id' => $a->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart', ['product_id' => $b->id, 'qty' => 1])->assertOk();

        $beforeOrders = Order::query()->count();
        $beforeGroups = CheckoutGroup::query()->count();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload($payment, $methodA, 'fail@example.com'))
            ->assertStatus(422);

        $this->assertSame($beforeOrders, Order::query()->count());
        $this->assertSame($beforeGroups, CheckoutGroup::query()->count());
        $this->assertGreaterThan(0, \App\Domain\Checkout\Models\CartItem::query()->count());
    }

    public function test_cancelling_one_child_does_not_cancel_sibling(): void
    {
        $methodA = $this->makeMethod('can-a', 3);
        $methodB = $this->makeMethod('can-b', 5);
        $a = $this->makeProduct('CAN-A', 16, $methodA);
        $b = $this->makeProduct('CAN-B', 17, $methodB);
        $payment = PaymentMethod::query()->where('is_enabled', true)->firstOrFail();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Sam Split',
            'email' => 'sam.split@example.com',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
        ])->assertOk();

        $this->postJson('/api/v1/cart', ['product_id' => $a->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart', ['product_id' => $b->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload($payment, $methodA, 'sam.split@example.com'))
            ->assertCreated();

        $orders = Order::query()->where('email', 'sam.split@example.com')->orderBy('id')->get();
        $this->assertCount(2, $orders);
        $this->assertSame($orders[0]->number, $orders[1]->number);
        $this->getJson('/api/v1/account/orders/'.$orders[0]->number.'?id='.$orders[1]->id)
            ->assertOk()
            ->assertJsonPath('order.id', $orders[1]->id)
            ->assertJsonPath('order.number', $orders[0]->number);

        $this->postJson('/api/v1/account/orders/'.$orders[0]->number.'/cancel?id='.$orders[0]->id)->assertOk();
        $this->assertSame('cancelled', $orders[0]->fresh()->status);
        $this->assertNotSame('cancelled', $orders[1]->fresh()->status);
        $this->assertSame($orders[0]->checkout_reference, $orders[1]->fresh()->checkout_reference);
    }

    public function test_refunding_one_child_does_not_refund_sibling(): void
    {
        $methodA = $this->makeMethod('ref-a', 2);
        $methodB = $this->makeMethod('ref-b', 4);
        $a = $this->makeProduct('REF-A', 21, $methodA);
        $b = $this->makeProduct('REF-B', 22, $methodB);
        $payment = $this->registerFakeGateway('split_refund', fn () => PaymentCaptureResult::paid(['ok' => true]));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Refund Split',
            'email' => 'refund.split@example.com',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
        ])->assertOk();
        $this->postJson('/api/v1/cart', ['product_id' => $a->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart', ['product_id' => $b->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload($payment, $methodA, 'refund.split@example.com'))
            ->assertCreated();

        $orders = Order::query()->where('email', 'refund.split@example.com')->orderBy('id')->get();
        $target = $orders[0];
        $sibling = $orders[1];
        $item = $target->items->first();

        $this->actingAs(Admin::query()->firstOrFail(), 'admin')
            ->post('/admin/orders/'.$target->id.'/refund', [
                'items' => [['order_item_id' => $item->id, 'qty' => $item->qty]],
            ])
            ->assertRedirect();

        $this->assertTrue(in_array($target->fresh()->payment_status, ['refunded', 'partially_refunded'], true));
        $this->assertSame('paid', $sibling->fresh()->payment_status);
        $this->assertSame($target->checkout_reference, $sibling->fresh()->checkout_reference);
    }

    public function test_subscription_products_in_different_shipping_groups_stay_independent(): void
    {
        $methodA = $this->makeMethod('sub-a', 5);
        $methodB = $this->makeMethod('sub-b', 6);
        $admin = Admin::query()->firstOrFail();
        $this->actingAs($admin, 'admin');
        $this->post('/admin/subscription-plans', [
            'name' => 'Split Monthly',
            'is_enabled' => true,
            'duration' => 0,
            'cycle' => 1,
            'frequency' => 'month',
            'trial_enabled' => false,
        ]);
        $plan = SubscriptionPlan::query()->orderByDesc('id')->firstOrFail();
        \Illuminate\Support\Facades\Auth::guard('admin')->logout();

        $productA = $this->makeProduct('SUB-SHIP-A', 40, $methodA, [
            'subscription_enabled' => true,
            'purchase_option' => 'subscription',
            'subscription_plans' => [
                ['subscription_plan_id' => $plan->id, 'price' => 40, 'is_enabled' => true, 'is_default' => true],
            ],
        ]);
        $productB = $this->makeProduct('SUB-SHIP-B', 45, $methodB, [
            'subscription_enabled' => true,
            'purchase_option' => 'subscription',
            'subscription_plans' => [
                ['subscription_plan_id' => $plan->id, 'price' => 45, 'is_enabled' => true, 'is_default' => true],
            ],
        ]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Sub Split',
            'email' => 'sub.split@example.com',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
        ])->assertOk();

        $this->postJson('/api/v1/cart', [
            'product_id' => $productA->id,
            'qty' => 1,
            'subscription_plan_id' => $plan->id,
        ])->assertOk();
        $this->postJson('/api/v1/cart', [
            'product_id' => $productB->id,
            'qty' => 1,
            'subscription_plan_id' => $plan->id,
        ])->assertOk();

        $payment = PaymentMethod::query()->where('is_enabled', true)->firstOrFail();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload($payment, $methodA, 'sub.split@example.com'))
            ->assertCreated();

        $orders = Order::query()->where('email', 'sub.split@example.com')->get();
        $this->assertCount(2, $orders);
        $subs = CustomerSubscription::query()->where('customer_id', Customer::query()->where('email', 'sub.split@example.com')->value('id'))->get();
        $this->assertCount(2, $subs);
        $this->assertCount(2, $subs->pluck('order_id')->unique());
        $this->assertCount(2, $subs->pluck('order_item_id')->unique());
        foreach ($subs as $sub) {
            $this->assertTrue($orders->contains('id', $sub->order_id));
            $item = $orders->firstWhere('id', $sub->order_id)?->items->first();
            $this->assertSame((int) $item?->id, (int) $sub->order_item_id);
        }
    }

    public function test_ambiguous_product_methods_are_not_silently_assigned(): void
    {
        $methodA = $this->makeMethod('amb-a', 3);
        $methodB = $this->makeMethod('amb-b', 4);
        $methodC = $this->makeMethod('amb-c', 5);
        $admin = Admin::query()->firstOrFail();
        $this->actingAs($admin, 'admin');
        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'simple',
            'name' => 'AMB-1',
            'sku' => 'AMB-1',
            'price' => 10,
            'is_enabled' => true,
            'manage_stock' => false,
            'available_all_carriers' => false,
            'shipping_method_ids' => [$methodA->id, $methodB->id],
        ]))->assertProductSaveRedirect('AMB-1');
        \Illuminate\Support\Facades\Auth::guard('admin')->logout();
        $product = Product::query()->where('sku', 'AMB-1')->firstOrFail();
        $payment = PaymentMethod::query()->where('is_enabled', true)->firstOrFail();

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload($payment, $methodC, 'amb@example.com'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['shipping_method_id']);
        $this->assertSame(0, Order::query()->where('email', 'amb@example.com')->count());
    }
}
