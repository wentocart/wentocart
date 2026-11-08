<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Settings\ShopSetting;
use App\Domain\Shipping\Models\ShippingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave226Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_shop_checkout_sequence_rest(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $payment = PaymentMethod::query()->where('is_enabled', true)->firstOrFail();
        $shipping = ShippingMethod::query()->where('is_enabled', true)->firstOrFail();

        $this->postJson('/api/v1/store/add-product-in-cart', [
            'productId' => $product->id,
            'quantity' => 1,
        ])->assertOk();

        $emptyShip = $this->getJson('/api/v1/store/checkout-shipping-methods');
        $emptyShip->assertOk()->assertExactJson([]);
        $this->assertNull($emptyShip->headers->get('X-Wento-Cache'));

        $addr = $this->postJson('/api/v1/store/checkout-addresses', [
            'billingFirstName' => 'Ada',
            'billingLastName' => 'Lovelace',
            'billingEmail' => 'wave226@example.test',
            'billingAddress' => '1 Main St',
            'billingCity' => 'Austin',
            'billingState' => 'TX',
            'billingCountry' => 'US',
            'billingPostcode' => '78701',
            'billingPhoneNumber' => '+15551212',
            'useForShipping' => true,
        ]);
        $addr->assertOk();
        $this->assertSame('Ada', $addr->json('billingFirstName'));
        $this->assertSame('Austin', $addr->json('shippingCity'));

        $saved = $this->getJson('/api/v1/store/checkout-addresses');
        $saved->assertOk();
        $this->assertSame('Ada', $saved->json('billingFirstName'), (string) $saved->getContent());
        $this->assertNull($saved->headers->get('X-Wento-Cache'));

        $cart = $this->postJson('/api/v1/store/cart', []);
        $cart->assertOk();
        $this->assertGreaterThan(0, (int) $cart->json('itemsCount'));

        $ships = $this->getJson('/api/v1/store/checkout-shipping-methods');
        $ships->assertOk();
        $this->assertNull($ships->headers->get('X-Wento-Cache'));
        $this->assertNotEmpty($ships->json(), 'ships='.$ships->getContent());
        $method = (string) ($ships->json('0.method') ?? '');
        $this->assertNotEmpty($method);

        $this->postJson('/api/v1/store/checkout-shipping-methods', [
            'shippingMethod' => $method,
        ])->assertOk()->assertJsonPath('success', true);

        $pays = $this->getJson('/api/v1/store/payment-methods');
        $pays->assertOk();
        $this->assertNull($pays->headers->get('X-Wento-Cache'));
        $this->assertNotEmpty($pays->json());
        $payCode = (string) ($pays->json('0.method') ?? $payment->code);
        $this->assertNotEmpty($payCode);

        $this->postJson('/api/v1/store/checkout-payment-methods', [
            'paymentMethod' => $payCode,
        ])->assertOk()->assertJsonPath('success', true);

        $placed = $this->postJson('/api/v1/store/checkout-orders', []);
        $placed->assertCreated();
        $this->assertTrue((bool) $placed->json('success'));
        $this->assertNotEmpty((string) $placed->json('orderId'));
        $this->assertFalse((bool) $placed->json('redirect'));

        // Cart emptied — second place fails API-style
        $again = $this->postJson('/api/v1/store/checkout-orders', []);
        $again->assertStatus(500);
        $this->assertStringContainsString('Cart is empty', (string) $again->json('detail'));

        // Sanity: shipping id resolution still works against seeded method
        $this->assertGreaterThan(0, (int) $shipping->id);
    }
}
