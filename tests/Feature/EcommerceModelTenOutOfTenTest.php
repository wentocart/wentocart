<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Checkout\Models\Cart;
use App\Domain\Checkout\Services\CartRecoveryService;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Localization\Models\Currency;
use App\Domain\Localization\Services\CurrencyRateSyncService;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Promo\Models\Coupon;
use App\Domain\Settings\ShopSetting;
use App\Domain\Shipping\Models\ShippingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EcommerceModelTenOutOfTenTest extends TestCase
{
    use RefreshDatabase;

    public function test_cross_device_abandoned_cart_recovery(): void
    {
        $this->seed();

        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $cart = Cart::create(['session_id' => 'abandoned-desktop-session']);
        app(CartService::class)->add($cart, $product, 2);

        $recovery = app(CartRecoveryService::class);
        $token = $recovery->tokenFor($cart);
        $this->assertNotEmpty($token);

        // Guest accesses recovery link from a different device (mobile)
        $response = $this->getJson("/api/v1/cart/recover?token={$token}");

        $response->assertOk();
        $response->assertJsonPath('cart.items.0.product_id', $product->id);
        $response->assertJsonPath('cart.items.0.qty', 2);
    }

    public function test_abandoned_cart_recovery_applies_incentive_coupon(): void
    {
        $this->seed();

        $coupon = Coupon::create([
            'code' => 'COMEBACK10',
            'type' => 'percent',
            'value' => 10,
            'is_enabled' => true,
        ]);
        ShopSetting::put('marketing.cart_recovery_coupon', 'COMEBACK10');

        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $cart = Cart::create(['session_id' => 'stale-cart-session']);
        app(CartService::class)->add($cart, $product, 1);

        $recovery = app(CartRecoveryService::class);
        $token = $recovery->tokenFor($cart);

        $response = $this->postJson('/api/v1/cart/recover', ['token' => $token]);

        $response->assertOk();
        $cart->refresh();
        $this->assertSame($coupon->id, $cart->coupon_id);
    }

    public function test_currency_rate_sync_updates_rates_relative_to_base(): void
    {
        $this->seed();

        // Ensure USD is default base and EUR, GBP exist
        Currency::query()->where('code', 'USD')->update(['is_default' => true, 'exchange_rate' => 1.0]);
        $eur = Currency::query()->firstOrCreate(
            ['code' => 'EUR'],
            ['name' => 'Euro', 'symbol' => '€', 'exchange_rate' => 1.0, 'is_enabled' => true]
        );
        $gbp = Currency::query()->firstOrCreate(
            ['code' => 'GBP'],
            ['name' => 'British Pound', 'symbol' => '£', 'exchange_rate' => 1.0, 'is_enabled' => true]
        );

        $service = app(CurrencyRateSyncService::class);
        $result = $service->sync([
            'EUR' => 0.923450,
            'GBP' => 0.789120,
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('USD', $result['base']);
        $this->assertSame(2, $result['updated']);

        $eur->refresh();
        $gbp->refresh();
        $this->assertSame(0.92345, (float) $eur->exchange_rate);
        $this->assertSame(0.78912, (float) $gbp->exchange_rate);
    }

    public function test_guest_to_customer_instant_conversion_at_checkout(): void
    {
        $this->seed();

        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $payment = PaymentMethod::query()->firstOrFail();
        $shipping = ShippingMethod::query()->firstOrFail();

        $email = 'instant.buyer@example.test';
        $this->assertDatabaseMissing('customers', ['email' => $email]);

        // Add to cart as guest
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();

        // Place checkout with create_account flag and password
        $response = $this->postJson('/api/v1/checkout', [
            'email' => $email,
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'create_account' => true,
            'password' => 'SecurePass123!',
            'billing_address' => [
                'first_name' => 'Alice',
                'last_name' => 'Express',
                'line1' => '456 Market St',
                'city' => 'Austin',
                'state' => 'TX',
                'postal_code' => '78701',
                'country_code' => 'US',
            ],
            'shipping_address' => [
                'first_name' => 'Alice',
                'last_name' => 'Express',
                'line1' => '456 Market St',
                'city' => 'Austin',
                'state' => 'TX',
                'postal_code' => '78701',
                'country_code' => 'US',
            ],
        ]);

        $response->assertCreated();

        // Verify customer account was automatically created and order linked
        $customer = Customer::query()->where('email', $email)->first();
        $this->assertNotNull($customer);
        $this->assertSame('Alice Express', $customer->name);
        $this->assertTrue($customer->is_enabled);

        $orderNumber = $response->json('order.number');
        $this->assertDatabaseHas('orders', [
            'number' => $orderNumber,
            'customer_id' => $customer->id,
            'email' => $email,
        ]);
        $this->assertDatabaseHas('customer_addresses', [
            'customer_id' => $customer->id,
            'line1' => '456 Market St',
            'is_default_shipping' => 1,
            'is_default_billing' => 1,
        ]);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('SecurePass123!', $customer->password));
        $response->assertJsonPath('customer.email', $email);
    }

    public function test_checkout_options_includes_express_checkout_readiness(): void
    {
        $this->seed();

        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();

        $response = $this->getJson('/api/v1/checkout/options')->assertOk();

        $response->assertJsonPath('express_checkout.apple_pay_ready', true);
        $response->assertJsonPath('express_checkout.google_pay_ready', true);
        $this->assertNotNull($response->json('express_checkout.currency_code'));
    }
}
