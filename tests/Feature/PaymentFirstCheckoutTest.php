<?php

namespace Tests\Feature;

use App\Domain\Checkout\Models\Cart;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\CheckoutLifecycle;
use App\Domain\Payment\Models\PaymentCheckout;
use App\Domain\Payment\Models\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentFirstCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_stripe_payment_first_does_not_create_order_without_payment_proof(): void
    {
        $this->seed('Database\\Seeders\\DatabaseSeeder');

        $payment = PaymentMethod::query()->where('driver', 'stripe')->first();
        if (! $payment) {
            $this->markTestSkipped('Stripe payment method is not installed.');
        }

        $gateway = app(\App\Domain\Payment\PaymentGatewayRegistry::class)->get('stripe');
        if ($gateway->checkoutLifecycle() !== CheckoutLifecycle::PaymentFirst) {
            $this->markTestSkipped('Stripe is not configured as payment-first.');
        }

        $this->postJson('/api/v1/cart/items', ['product_id' => 1, 'qty' => 1])->assertOk();
        $options = $this->getJson('/api/v1/checkout/options')->assertOk()->json();
        $shippingId = collect($options['shipping_methods'] ?? [])->value('id');

        $beforeOrders = Order::query()->count();
        $cart = Cart::query()->first();
        $this->assertNotNull($cart);
        $itemCount = $cart->items()->count();
        $this->assertGreaterThan(0, $itemCount);

        $response = $this->postJson('/api/v1/checkout', [
            'email' => 'stripe-test@example.com',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shippingId,
            'billing_address' => [
                'first_name' => 'Test',
                'last_name' => 'Buyer',
                'line1' => '1 Main St',
                'city' => 'Austin',
                'country_code' => 'US',
                'postal_code' => '78701',
            ],
            'shipping_address' => [
                'first_name' => 'Test',
                'last_name' => 'Buyer',
                'line1' => '1 Main St',
                'city' => 'Austin',
                'country_code' => 'US',
                'postal_code' => '78701',
            ],
        ]);

        $this->assertSame($beforeOrders, Order::query()->count());
        $cart->refresh();
        $this->assertSame($itemCount, $cart->items()->count());
        $this->assertNotNull(PaymentCheckout::query()->first());
        $response->assertOk();
        $this->assertNull($response->json('order'));
        $this->assertSame('payment_first', $response->json('checkout_lifecycle'));
    }
}
