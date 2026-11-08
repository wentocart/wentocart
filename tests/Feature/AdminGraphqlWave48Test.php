<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave48Test extends TestCase
{
    use RefreshDatabase;

    public function test_draft_cart_address_shipping_payment_sequence_and_place(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $ship = ShippingMethod::query()->where('is_enabled', true)->orderBy('id')->first();
        $pay = PaymentMethod::query()->where('is_enabled', true)->orderBy('id')->first();
        $this->assertNotNull($ship);
        $this->assertNotNull($pay);

        $sku = 'W48-'.strtoupper(substr(uniqid(), -6));
        $productRes = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createSimpleProduct(sku: $sku, name: "Wave48 Chain", price: 28, stockQty: 10, enabled: true) { id }
            }',
            'variables' => ['sku' => $sku],
        ]);
        $productRes->assertOk();
        $productId = (string) $productRes->json('data.createSimpleProduct.id');
        Product::query()->whereKey((int) $productId)->update(['manage_stock' => true, 'stock_qty' => 10]);

        $email = 'w48-'.uniqid().'@example.com';
        $customer = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createCustomer(email: $e, firstName: "Wave", lastName: "FortyEight") { id }
            }',
            'variables' => ['e' => $email],
        ]);
        $customer->assertOk();
        $customerId = (string) $customer->json('data.createCustomer.id');

        $draft = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!) {
                createAdminOrderComposerDraft(customerId: $cid) { cart_id }
            }',
            'variables' => ['cid' => $customerId],
        ]);
        $draft->assertOk();
        $cartId = (string) $draft->json('data.createAdminOrderComposerDraft.cart_id');

        $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!, $pid: ID!) {
                addItemAdminOrderComposerDraft(cartId: $cid, productId: $pid, quantity: 1) { item_count }
            }',
            'variables' => ['cid' => $cartId, 'pid' => $productId],
        ])->assertOk();

        $shipBeforeAddress = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($cid: ID!) { adminOrderComposerDraftShippingMethods(cartId: $cid) { code } }',
            'variables' => ['cid' => $cartId],
        ]);
        $shipBeforeAddress->assertOk();
        $this->assertNotEmpty($shipBeforeAddress->json('errors'));

        $addr = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!) {
                saveAddressAdminOrderComposerDraft(
                    cartId: $cid
                    billing: {
                        firstName: "Wave"
                        lastName: "FortyEight"
                        line1: "48 Chain Ave"
                        city: "Chicago"
                        state: "IL"
                        postalCode: "60601"
                        countryCode: "US"
                        phone: "5550100"
                    }
                    useForShipping: true
                ) {
                    has_addresses shipping_method_code payment_method_code
                }
            }',
            'variables' => ['cid' => $cartId],
        ]);
        $addr->assertOk();
        $this->assertArrayNotHasKey('errors', $addr->json() ?? [], json_encode($addr->json()));
        $this->assertTrue((bool) $addr->json('data.saveAddressAdminOrderComposerDraft.has_addresses'));

        $rates = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($cid: ID!) {
                adminOrderComposerDraftShippingMethods(cartId: $cid) { code name price }
            }',
            'variables' => ['cid' => $cartId],
        ]);
        $rates->assertOk();
        $this->assertArrayNotHasKey('errors', $rates->json() ?? []);
        $this->assertNotEmpty($rates->json('data.adminOrderComposerDraftShippingMethods'));

        $setShip = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!, $code: String!) {
                setShippingMethodAdminOrderComposerDraft(cartId: $cid, code: $code) {
                    shipping_method_code shipping_total
                }
            }',
            'variables' => ['cid' => $cartId, 'code' => $ship->code],
        ]);
        $setShip->assertOk();
        $this->assertArrayNotHasKey('errors', $setShip->json() ?? [], json_encode($setShip->json()));
        $this->assertSame($ship->code, (string) $setShip->json('data.setShippingMethodAdminOrderComposerDraft.shipping_method_code'));

        $pays = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($cid: ID!) {
                adminOrderComposerDraftPaymentMethods(cartId: $cid) { code name }
            }',
            'variables' => ['cid' => $cartId],
        ]);
        $pays->assertOk();
        $this->assertArrayNotHasKey('errors', $pays->json() ?? []);
        $this->assertNotEmpty($pays->json('data.adminOrderComposerDraftPaymentMethods'));

        $setPay = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!, $code: String!) {
                setPaymentMethodAdminOrderComposerDraft(cartId: $cid, code: $code) {
                    payment_method_code grand_total
                }
            }',
            'variables' => ['cid' => $cartId, 'code' => $pay->code],
        ]);
        $setPay->assertOk();
        $this->assertArrayNotHasKey('errors', $setPay->json() ?? [], json_encode($setPay->json()));
        $this->assertSame($pay->code, (string) $setPay->json('data.setPaymentMethodAdminOrderComposerDraft.payment_method_code'));

        $place = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!) {
                placeAdminOrderComposerDraft(cartId: $cid, requireComplete: true, customerNote: "Wave48 full chain") {
                    number grand_total
                }
            }',
            'variables' => ['cid' => $cartId],
        ]);
        $place->assertOk();
        $this->assertArrayNotHasKey('errors', $place->json() ?? [], json_encode($place->json()));
        $orderNumber = (string) $place->json('data.placeAdminOrderComposerDraft.number');
        $order = Order::query()->where('number', $orderNumber)->first();
        $this->assertNotNull($order);
        $this->assertSame((int) $ship->id, (int) $order->shipping_method_id);
        $this->assertSame((int) $pay->id, (int) $order->payment_method_id);
        $this->assertEqualsWithDelta((float) $ship->price, (float) $order->shipping_total, 0.001);
    }
}
