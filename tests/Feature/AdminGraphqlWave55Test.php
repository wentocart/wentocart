<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave55Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_named_create_admin_place_order_and_admin_cart_aliases(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);
        $ship = ShippingMethod::query()->where('is_enabled', true)->orderBy('id')->first();
        $pay = PaymentMethod::query()->where('is_enabled', true)->orderBy('id')->first();
        $this->assertNotNull($ship);
        $this->assertNotNull($pay);

        $sku = 'W55-'.strtoupper(substr(uniqid(), -6));
        $productId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createSimpleProduct(sku: $sku, name: "Wave55 Place", price: 35, stockQty: 8, enabled: true) { id }
            }',
            'variables' => ['sku' => $sku],
        ])->json('data.createSimpleProduct.id');
        Product::query()->whereKey((int) $productId)->update(['manage_stock' => true, 'stock_qty' => 8]);

        $email = 'w55-'.uniqid().'@example.com';
        $customerId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createCustomer(email: $e, firstName: "Wave", lastName: "FiftyFive") { id }
            }',
            'variables' => ['e' => $email],
        ])->json('data.createCustomer.id');

        $cartId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!) {
                createAdminOrderComposerDraft(customerId: $cid) { cart_id }
            }',
            'variables' => ['cid' => $customerId],
        ])->json('data.createAdminOrderComposerDraft.cart_id');

        $add = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!, $pid: ID!) {
                addItemAdminCart(cartId: $cid, productId: $pid, quantity: 1) { item_count subtotal }
            }',
            'variables' => ['cid' => $cartId, 'pid' => $productId],
        ]);
        $add->assertOk();
        $this->assertArrayNotHasKey('errors', $add->json() ?? [], json_encode($add->json()));

        $incomplete = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!) {
                createAdminPlaceOrder(cartId: $cid) { success order_number }
            }',
            'variables' => ['cid' => $cartId],
        ]);
        $incomplete->assertOk();
        $this->assertNotEmpty($incomplete->json('errors'));
        $this->assertStringContainsString('Addresses missing', (string) ($incomplete->json('errors.0.message') ?? ''));

        $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!) {
                saveAddressAdminCart(
                    cartId: $cid
                    billing: {
                        firstName: "Wave"
                        lastName: "FiftyFive"
                        line1: "55 Place Rd"
                        city: "Austin"
                        state: "TX"
                        postalCode: "78701"
                        countryCode: "US"
                        phone: "5550199"
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

        $cart = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($cid: ID!) {
                adminComposerCart(cartId: $cid) { cart_id item_count payment_method_code }
                adminComposerCartShippingRates(cartId: $cid) { code }
                adminComposerCartPaymentMethods(cartId: $cid) { code }
            }',
            'variables' => ['cid' => $cartId],
        ]);
        $cart->assertOk();
        $this->assertArrayNotHasKey('errors', $cart->json() ?? [], json_encode($cart->json()));
        $this->assertSame($cartId, (string) $cart->json('data.adminComposerCart.cart_id'));

        $place = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!) {
                createAdminPlaceOrder(cartId: $cid, customerNote: "Wave55 place") {
                    success message order_id order_number grand_total
                    order { number status }
                }
            }',
            'variables' => ['cid' => $cartId],
        ]);
        $place->assertOk();
        $this->assertArrayNotHasKey('errors', $place->json() ?? [], json_encode($place->json()));
        $this->assertTrue((bool) $place->json('data.createAdminPlaceOrder.success'));
        $this->assertNotSame('', (string) $place->json('data.createAdminPlaceOrder.order_number'));
    }

    public function test_admin_invoices_list_after_create_invoice(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $sku = 'W55I-'.strtoupper(substr(uniqid(), -6));
        $productId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createSimpleProduct(sku: $sku, name: "Wave55 Inv", price: 20, stockQty: 4, enabled: true) { id }
            }',
            'variables' => ['sku' => $sku],
        ])->json('data.createSimpleProduct.id');
        Product::query()->whereKey((int) $productId)->update(['manage_stock' => true, 'stock_qty' => 4]);

        $email = 'w55i-'.uniqid().'@example.com';
        $customerId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createCustomer(email: $e, firstName: "Inv", lastName: "FiftyFive") { id }
            }',
            'variables' => ['e' => $email],
        ])->json('data.createCustomer.id');

        $orderNumber = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!, $pid: ID!) {
                createAdminOrder(customerId: $cid, items: [{ productId: $pid, quantity: 1 }]) { number }
            }',
            'variables' => ['cid' => $customerId, 'pid' => $productId],
        ])->json('data.createAdminOrder.number');

        $invoice = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($n: String!) {
                createAdminInvoice(number: $n, withHtml: false) { invoice_number order_number }
            }',
            'variables' => ['n' => $orderNumber],
        ]);
        $invoice->assertOk();
        $this->assertArrayNotHasKey('errors', $invoice->json() ?? [], json_encode($invoice->json()));
        $invoiceNumber = (string) $invoice->json('data.createAdminInvoice.invoice_number');
        $this->assertNotSame('', $invoiceNumber);

        $list = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($n: String!, $inv: String!) {
                adminInvoices(orderNumber: $n) { invoice_number order_number }
                adminInvoice(orderNumber: $n, invoiceNumber: $inv) { invoice_number }
            }',
            'variables' => ['n' => $orderNumber, 'inv' => $invoiceNumber],
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? [], json_encode($list->json()));
        $this->assertNotEmpty($list->json('data.adminInvoices'));
        $this->assertSame($invoiceNumber, (string) $list->json('data.adminInvoice.invoice_number'));
    }
}
