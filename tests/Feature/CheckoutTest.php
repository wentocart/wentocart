<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\ShippingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_place_an_order(): void
    {
        $this->seed();

        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $payment = PaymentMethod::query()->firstOrFail();
        $shipping = ShippingMethod::query()->firstOrFail();

        $add = $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1]);
        $add->assertOk();

        $this->postJson('/api/v1/checkout', [
            'email' => 'guest@example.com',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated()->assertJsonPath('order.email', 'guest@example.com');

        $this->assertDatabaseHas('orders', ['email' => 'guest@example.com']);
        $this->assertSame(49, (int) $product->fresh()->stock_qty);
    }

    public function test_guest_checkout_survives_unwritable_log_mailer(): void
    {
        $this->seed();
        config(['mail.default' => 'log']);

        $log = storage_path('logs/laravel.log');
        if (! is_file($log)) {
            file_put_contents($log, '');
        }
        $previous = fileperms($log) & 0777;
        chmod($log, 0444);

        try {
            $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
            $payment = PaymentMethod::query()->firstOrFail();
            $shipping = ShippingMethod::query()->firstOrFail();

            $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();

            $this->postJson('/api/v1/checkout', [
                'email' => 'log-locked@example.com',
                'payment_method_id' => $payment->id,
                'shipping_method_id' => $shipping->id,
                'billing_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
                'shipping_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            ])->assertCreated()->assertJsonPath('order.email', 'log-locked@example.com');
        } finally {
            chmod($log, $previous ?: 0666);
        }
    }

    public function test_checkout_requires_a_state_and_a_postal_code_for_the_country(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $payment = PaymentMethod::query()->firstOrFail();
        $shipping = ShippingMethod::query()->firstOrFail();
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();

        $this->postJson('/api/v1/checkout', [
            'email' => 'guest@example.com',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'country_code' => 'US'],
        ])->assertStatus(422)
            ->assertJsonValidationErrors([
                'billing_address.state' => 'Select a state.',
                'billing_address.postal_code' => 'Enter a postal code.',
            ]);

        $this->postJson('/api/v1/checkout', [
            'email' => 'guest@example.com',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78', 'country_code' => 'US'],
        ])->assertStatus(422)
            ->assertJsonValidationErrors([
                'billing_address.postal_code' => 'Enter a valid postal code, for example 12345.',
            ]);
    }

    public function test_customer_can_register_and_review(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Pat',
            'email' => 'pat@example.com',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
        ])->assertOk();

        $this->postJson('/api/v1/account/reviews', [
            'product_id' => $product->id,
            'rating' => 5,
            'title' => 'Great',
        ])->assertCreated();

        $this->assertDatabaseHas('reviews', ['title' => 'Great']);
        $this->assertNotNull(Customer::query()->where('email', 'pat@example.com')->first());
    }
}
