<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Settings\ShopSetting;
use App\Domain\Shipping\Models\ShippingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave97Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_checkout_sequence_mutations(): void
    {
        $this->seed();
        ShopSetting::put('customers.guest_checkout', true);

        $product = Product::query()->where('is_enabled', true)->where('type', 'simple')->first()
            ?? Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);
        Product::query()->whereKey($product->id)->update([
            'manage_stock' => true,
            'stock_qty' => max(20, (int) $product->stock_qty),
            'is_enabled' => true,
        ]);

        $payment = PaymentMethod::query()->where('is_enabled', true)->whereNull('seller_id')->first();
        $shipping = ShippingMethod::query()->where('is_enabled', true)->whereNull('seller_id')->first();
        $this->assertNotNull($payment);
        $this->assertNotNull($shipping);

        $tokenRes = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation { createCartToken { cart_token } }',
        ]);
        $tokenRes->assertOk();
        $cartToken = (string) $tokenRes->json('data.createCartToken.cart_token');
        $this->assertNotSame('', $cartToken);

        // API: cart token as Bearer
        $headers = ['Authorization' => 'Bearer '.$cartToken];

        $add = $this->withHeaders($headers)->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) {
                createAddProductInCart(productId: $id, quantity: 1) { item_count }
            }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $this->assertSame(200, $add->status(), json_encode($add->json()));
        $this->assertArrayNotHasKey('errors', $add->json() ?? [], json_encode($add->json()));

        $addr = $this->withHeaders($headers)->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                createCheckoutAddress(
                    billingFirstName: "Wave"
                    billingLastName: "NinetySeven"
                    billingEmail: "wave97@example.com"
                    billingAddress: "1 Main St"
                    billingCity: "Austin"
                    billingCountry: "US"
                    billingState: "TX"
                    billingPostcode: "78701"
                    billingPhoneNumber: "555-0197"
                    useForShipping: true
                ) { success message billingCity shippingCity cartToken }
            }',
        ]);
        $this->assertSame(200, $addr->status(), json_encode($addr->json()));
        $this->assertArrayNotHasKey('errors', $addr->json() ?? [], json_encode($addr->json()));
        $this->assertTrue((bool) $addr->json('data.createCheckoutAddress.success'));
        $this->assertSame('Austin', (string) $addr->json('data.createCheckoutAddress.billingCity'));

        $rates = $this->withHeaders($headers)->postJson('/api/v1/graphql', [
            'query' => '{ collectionShippingRates { id code title price } }',
        ]);
        $rates->assertOk();
        $this->assertArrayNotHasKey('errors', $rates->json() ?? [], json_encode($rates->json()));
        $this->assertNotEmpty($rates->json('data.collectionShippingRates'));

        $ship = $this->withHeaders($headers)->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) {
                createCheckoutShippingMethod(shippingMethodId: $id) {
                    success shippingMethodId
                }
            }',
            'variables' => ['id' => (string) $shipping->id],
        ]);
        $ship->assertOk();
        $this->assertArrayNotHasKey('errors', $ship->json() ?? [], json_encode($ship->json()));
        $this->assertTrue((bool) $ship->json('data.createCheckoutShippingMethod.success'));

        $payList = $this->withHeaders($headers)->postJson('/api/v1/graphql', [
            'query' => '{ collectionPaymentMethods { id code title } }',
        ]);
        $payList->assertOk();
        $this->assertArrayNotHasKey('errors', $payList->json() ?? [], json_encode($payList->json()));
        $this->assertNotEmpty($payList->json('data.collectionPaymentMethods'));

        $pay = $this->withHeaders($headers)->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) {
                createCheckoutPaymentMethod(paymentMethodId: $id) {
                    success paymentMethodId
                }
            }',
            'variables' => ['id' => (string) $payment->id],
        ]);
        $pay->assertOk();
        $this->assertArrayNotHasKey('errors', $pay->json() ?? [], json_encode($pay->json()));
        $this->assertTrue((bool) $pay->json('data.createCheckoutPaymentMethod.success'));

        $orderCountBefore = Order::query()->count();
        $place = $this->withHeaders($headers)->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                createCheckoutOrder {
                    success message orderId order_number grand_total redirect
                }
            }',
        ]);
        $this->assertSame(200, $place->status(), json_encode($place->json()));
        $this->assertArrayNotHasKey('errors', $place->json() ?? [], json_encode($place->json()));
        $this->assertTrue((bool) $place->json('data.createCheckoutOrder.success'));
        $this->assertNotEmpty($place->json('data.createCheckoutOrder.orderId'));
        $this->assertFalse((bool) $place->json('data.createCheckoutOrder.redirect'));
        $this->assertSame($orderCountBefore + 1, Order::query()->count());
        $this->assertTrue(
            Order::query()->where('email', 'wave97@example.com')->exists()
        );
    }
}
