<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Settings\ShopSetting;
use App\Domain\Shipping\Models\ShippingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave254Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_guest_checkout_rest_with_cart_token_bearer(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);
        ShopSetting::put('customers.guest_checkout', true);

        $product = Product::query()->where('sku', 'DEMO-001')->first()
            ?? Product::query()->where('is_enabled', true)->where('type', 'simple')->first();
        $this->assertNotNull($product);
        Product::query()->whereKey($product->id)->update([
            'manage_stock' => true,
            'stock_qty' => max(20, (int) $product->stock_qty),
            'is_enabled' => true,
        ]);
        $payment = PaymentMethod::query()->where('is_enabled', true)->whereNull('seller_id')->firstOrFail();
        $shipping = ShippingMethod::query()->where('is_enabled', true)->whereNull('seller_id')->firstOrFail();

        $first = $this->postJson('/api/v1/store/cart-tokens', []);
        $first->assertCreated();
        $tokenA = (string) $first->json('cartToken');
        $this->assertNotSame('', $tokenA);

        $second = $this->postJson('/api/v1/store/cart-tokens', []);
        $second->assertCreated();
        $tokenB = (string) $second->json('cartToken');
        $this->assertNotSame('', $tokenB);
        $this->assertNotSame($tokenA, $tokenB, 'API: each create-cart issues a new cartToken');

        $headers = ['Authorization' => 'Bearer '.$tokenB];

        $add = $this->withHeaders($headers)->postJson('/api/v1/store/add-product-in-cart', [
            'productId' => (int) $product->id,
            'quantity' => 1,
        ]);
        $add->assertOk();
        $this->assertGreaterThanOrEqual(1, (int) $add->json('itemsCount'));

        $addr = $this->withHeaders($headers)->postJson('/api/v1/store/checkout-addresses', [
            'billingFirstName' => 'Guest',
            'billingLastName' => 'Wave254',
            'billingEmail' => 'guest.w254@example.com',
            'billingAddress' => '1 Main St',
            'billingCity' => 'Austin',
            'billingState' => 'TX',
            'billingCountry' => 'US',
            'billingPostcode' => '78701',
            'billingPhoneNumber' => '5550199',
            'useForShipping' => true,
        ]);
        $addr->assertOk();
        $this->assertSame('Austin', (string) $addr->json('billingCity'));

        $rates = $this->withHeaders($headers)->getJson('/api/v1/store/checkout-shipping-methods');
        $rates->assertOk();
        $this->assertNotEmpty($rates->json());
        $shipMethod = (string) ($rates->json('0.method') ?? $rates->json('0.code') ?? $shipping->code ?? $shipping->id);

        $ship = $this->withHeaders($headers)->postJson('/api/v1/store/checkout-shipping-methods', [
            'shippingMethod' => $shipMethod !== '' ? $shipMethod : (string) $shipping->id,
        ]);
        $ship->assertOk();
        $this->assertTrue((bool) $ship->json('success'));

        $pays = $this->withHeaders($headers)->getJson('/api/v1/store/payment-methods');
        $pays->assertOk();
        $this->assertNotEmpty($pays->json());
        $payMethod = (string) ($pays->json('0.method') ?? $payment->code ?? $payment->id);

        $pay = $this->withHeaders($headers)->postJson('/api/v1/store/checkout-payment-methods', [
            'paymentMethod' => $payMethod,
        ]);
        $pay->assertOk();
        $this->assertTrue((bool) $pay->json('success'));

        $before = Order::query()->count();
        $place = $this->withHeaders($headers)->postJson('/api/v1/store/checkout-orders', []);
        $place->assertCreated();
        $this->assertTrue((bool) $place->json('success'));
        $this->assertFalse((bool) $place->json('redirect'));
        $this->assertNotEmpty($place->json('orderId'));
        $this->assertSame($before + 1, Order::query()->count());

        $guestOrder = Order::query()->latest('id')->first();
        $this->assertNotNull($guestOrder);
        $this->assertNull($guestOrder->customer_id);
        $this->assertSame('guest.w254@example.com', (string) $guestOrder->email);
    }
}
