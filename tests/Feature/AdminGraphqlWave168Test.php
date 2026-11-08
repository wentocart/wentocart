<?php

namespace Tests\Feature;

use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave168Test extends TestCase
{
    use RefreshDatabase;

    public function test_place_rejects_unsupported_payment_and_addresses_prefill(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $ship = ShippingMethod::query()->where('is_enabled', true)->firstOrFail();
        $cod = PaymentMethod::query()->where('code', 'cod')->where('is_enabled', true)->firstOrFail();
        $invoice = PaymentMethod::query()->where('code', 'invoice')->where('is_enabled', true)->firstOrFail();

        $sku = 'W168-'.strtoupper(substr(uniqid(), -6));
        $productId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createSimpleProduct(sku: $sku, name: "Wave168", price: 25, stockQty: 5, enabled: true) { id }
            }',
            'variables' => ['sku' => $sku],
        ])->json('data.createSimpleProduct.id');

        $email = 'w168-'.strtolower(substr(uniqid(), -6)).'@example.test';
        $customerId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createCustomer(email: $e, firstName: "Wave", lastName: "168", password: "Password1!") { id }
            }',
            'variables' => ['e' => $email],
        ])->json('data.createCustomer.id');

        // Address book create via GraphQL then REST list for Create-Order prefill
        $addrCreate = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($input: CreateAdminCustomerAddressInput!) {
                createAdminCustomerAddress(input: $input) { address { id line1 } }
            }',
            'variables' => [
                'input' => [
                    'customerId' => $customerId,
                    'firstName' => 'Wave',
                    'lastName' => '168',
                    'line1' => '168 Prefill St',
                    'city' => 'Austin',
                    'state' => 'TX',
                    'postalCode' => '78701',
                    'countryCode' => 'US',
                    'phone' => '5550168',
                    'defaultBilling' => true,
                    'defaultShipping' => true,
                ],
            ],
        ]);
        $addrCreate->assertOk();
        $this->assertArrayNotHasKey('errors', $addrCreate->json() ?? [], json_encode($addrCreate->json()));

        $book = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/customers/'.$customerId.'/addresses');
        $book->assertOk();
        $this->assertNotEmpty($book->json('data'));
        $this->assertSame('168 Prefill St', (string) $book->json('data.0.line1'));

        $cartId = (string) $this->actingAs($admin, 'admin')
            ->postJson('/api/v1/console/customers/'.$customerId.'/order-composer/drafts')
            ->json('cartId');
        $this->actingAs($admin, 'admin')->postJson('/api/v1/console/carts/'.$cartId.'/items', [
            'productId' => (int) $productId,
            'quantity' => 1,
        ])->assertOk();
        $this->actingAs($admin, 'admin')->postJson('/api/v1/console/carts/'.$cartId.'/addresses', [
            'billing' => [
                'firstName' => 'Wave', 'lastName' => '168', 'line1' => '168 Prefill St',
                'city' => 'Austin', 'state' => 'TX', 'postalCode' => '78701',
                'countryCode' => 'US', 'phone' => '5550168', 'useForShipping' => true,
            ],
        ])->assertOk();
        $this->actingAs($admin, 'admin')->postJson('/api/v1/console/carts/'.$cartId.'/shipping-methods', [
            'shippingMethod' => $ship->code,
        ])->assertOk();

        // API alias cod → Wento cod
        $setCod = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/carts/'.$cartId.'/payment-methods', [
            'payment' => 'cod',
        ]);
        $setCod->assertOk();
        $this->assertSame($cod->code, (string) $setCod->json('paymentMethodTitle'));

        // Unsupported gateway rejected at place (API 422)
        $this->actingAs($admin, 'admin')->postJson('/api/v1/console/carts/'.$cartId.'/payment-methods', [
            'payment' => $invoice->code,
        ])->assertOk();
        $this->actingAs($admin, 'admin')
            ->postJson('/api/v1/console/orders/place/'.$cartId)
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'draft_cart_error']);

        // Allowed place with bank_transfer alias → bank_transfer
        $this->actingAs($admin, 'admin')->postJson('/api/v1/console/carts/'.$cartId.'/payment-methods', [
            'payment' => 'bank_transfer',
        ])->assertOk();
        $place = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/orders/place/'.$cartId);
        $place->assertOk();
        $this->assertTrue((bool) $place->json('success'));

    }
}
