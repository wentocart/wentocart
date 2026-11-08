<?php

namespace Tests\Feature;

use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave163Test extends TestCase
{
    use RefreshDatabase;

    public function test_create_admin_place_order_exposes_rest_result_aliases(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $ship = ShippingMethod::query()->where('is_enabled', true)->firstOrFail();
        $pay = PaymentMethod::query()->where('is_enabled', true)->firstOrFail();

        $sku = 'W163-'.strtoupper(substr(uniqid(), -6));
        $productId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createSimpleProduct(sku: $sku, name: "Wave163", price: 18, stockQty: 5, enabled: true) { id }
            }',
            'variables' => ['sku' => $sku],
        ])->json('data.createSimpleProduct.id');

        $email = 'w163-'.strtolower(substr(uniqid(), -6)).'@example.test';
        $customerId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createCustomer(email: $e, firstName: "Wave", lastName: "163", password: "Password1!") { id }
            }',
            'variables' => ['e' => $email],
        ])->json('data.createCustomer.id');

        $cartId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!) { createAdminOrderComposerDraft(customerId: $cid) { cart_id } }',
            'variables' => ['cid' => $customerId],
        ])->json('data.createAdminOrderComposerDraft.cart_id');

        $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!, $pid: ID!) {
                addItemAdminCart(cartId: $cid, productId: $pid, quantity: 1) { item_count }
            }',
            'variables' => ['cid' => $cartId, 'pid' => $productId],
        ])->assertOk();

        $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!) {
                saveAddressAdminCart(
                    cartId: $cid
                    billing: {
                        firstName: "Wave" lastName: "163" line1: "163 Place Rd"
                        city: "Austin" state: "TX" postalCode: "78701" countryCode: "US" phone: "5550163"
                    }
                    useForShipping: true
                ) { has_addresses }
            }',
            'variables' => ['cid' => $cartId],
        ])->assertOk();

        $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!, $code: String!) {
                setShippingMethodAdminCart(cartId: $cid, code: $code) { shipping_method_code }
            }',
            'variables' => ['cid' => $cartId, 'code' => $ship->code],
        ])->assertOk();

        $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!, $code: String!) {
                setPaymentMethodAdminCart(cartId: $cid, code: $code) { payment_method_code }
            }',
            'variables' => ['cid' => $cartId, 'code' => $pay->code],
        ])->assertOk();

        $place = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($input: AdminPlaceOrderInput!) {
                createAdminPlaceOrder(input: $input) {
                    success message orderId incrementId customerId grandTotal order_id order_number
                }
            }',
            'variables' => ['input' => ['cartId' => $cartId, 'customerNote' => 'Wave163']],
        ]);
        $place->assertOk();
        $this->assertArrayNotHasKey('errors', $place->json() ?? [], json_encode($place->json()));
        $this->assertTrue((bool) $place->json('data.createAdminPlaceOrder.success'));
        $this->assertNotSame('', (string) $place->json('data.createAdminPlaceOrder.orderId'));
        $this->assertSame(
            (string) $place->json('data.createAdminPlaceOrder.orderId'),
            (string) $place->json('data.createAdminPlaceOrder.order_id'),
        );
        $this->assertSame(
            (string) $place->json('data.createAdminPlaceOrder.incrementId'),
            (string) $place->json('data.createAdminPlaceOrder.order_number'),
        );
        $this->assertSame($customerId, (string) $place->json('data.createAdminPlaceOrder.customerId'));
        $this->assertGreaterThan(0, (float) $place->json('data.createAdminPlaceOrder.grandTotal'));
    }
}
