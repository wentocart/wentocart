<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\ShippingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GraphqlPlaceOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_place_order_mutation_creates_guest_order(): void
    {
        $this->seed();

        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $payment = PaymentMethod::query()->firstOrFail();
        $shipping = ShippingMethod::query()->firstOrFail();

        $this->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) { cartAdd(productId: $id, qty: 1) { item_count } }',
            'variables' => ['id' => (string) $product->id],
        ])->assertOk()->assertJsonPath('data.cartAdd.item_count', 1);

        $address = '{ firstName: "Ada", line1: "1 Main", city: "Austin", state: "TX", postalCode: "78701", countryCode: "US" }';
        $query = <<<GQL
mutation {
  placeOrder(
    email: "gql-guest@example.com"
    paymentMethodId: "{$payment->id}"
    shippingMethodId: "{$shipping->id}"
    billingAddress: {$address}
    shippingAddress: {$address}
    acceptTerms: true
  ) {
    order_number
    payment_status
    grand_total
  }
}
GQL;

        $response = $this->postJson('/api/v1/graphql', ['query' => $query]);
        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? []);
        $this->assertNotEmpty($response->json('data.placeOrder.order_number'));
        $this->assertDatabaseHas('orders', ['email' => 'gql-guest@example.com']);
    }
}
