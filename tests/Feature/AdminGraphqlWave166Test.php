<?php

namespace Tests\Feature;

use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave166Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_create_order_six_step_and_helper_aliases(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $ship = ShippingMethod::query()->where('is_enabled', true)->firstOrFail();
        $pay = PaymentMethod::query()->where('is_enabled', true)->firstOrFail();

        $sku = 'W166-'.strtoupper(substr(uniqid(), -6));
        $productId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createSimpleProduct(sku: $sku, name: "Wave166", price: 22, stockQty: 8, enabled: true) { id }
            }',
            'variables' => ['sku' => $sku],
        ])->json('data.createSimpleProduct.id');
        $this->assertNotSame('', $productId);

        $email = 'w166-'.strtolower(substr(uniqid(), -6)).'@example.test';
        $customerId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createCustomer(email: $e, firstName: "Wave", lastName: "166", password: "Password1!") { id }
            }',
            'variables' => ['e' => $email],
        ])->json('data.createCustomer.id');
        $this->assertNotSame('', $customerId);

        // GraphQL API helper aliases exist and return lists.
        $helpers = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($cid: ID!) {
                adminCustomerCartItems(customerId: $cid) { product_id source }
                adminCustomerWishlistItems(customerId: $cid) { product_id source }
                adminCustomerRecentOrderItems(customerId: $cid) { product_id source }
                adminCustomerCompareItems(customerId: $cid) { product_id source }
            }',
            'variables' => ['cid' => $customerId],
        ]);
        $helpers->assertOk();
        $this->assertArrayNotHasKey('errors', $helpers->json() ?? [], json_encode($helpers->json()));
        $this->assertIsArray($helpers->json('data.adminCustomerCartItems'));
        $this->assertIsArray($helpers->json('data.adminCustomerWishlistItems'));

        // REST helper panels
        $this->actingAs($admin, 'admin')
            ->getJson('/api/v1/console/customers/'.$customerId.'/wishlist-items')
            ->assertOk()
            ->assertJsonStructure(['data']);
        $this->actingAs($admin, 'admin')
            ->getJson('/api/v1/console/customers/'.$customerId.'/cart-items')
            ->assertOk()
            ->assertJsonStructure(['data']);

        // Step 1 — create draft
        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/customers/'.$customerId.'/order-composer/drafts');
        $create->assertCreated();
        $cartId = (string) $create->json('cartId');
        $this->assertNotSame('', $cartId);

        // Step 2 — add item
        $add = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/carts/'.$cartId.'/items', [
            'productId' => (int) $productId,
            'quantity' => 2,
        ]);
        $add->assertOk();
        $this->assertSame(1, (int) $add->json('itemsCount'));
        $this->assertSame(2.0, (float) ($add->json('items.0.quantity') ?? 0));
        $itemId = (string) ($add->json('items.0.cartItemId') ?? $add->json('items.0.id') ?? '');
        $this->assertNotSame('', $itemId);

        // Update qty (API qty map)
        $upd = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/carts/'.$cartId.'/items', [
            'qty' => [$itemId => 3],
        ]);
        $upd->assertOk();
        $updatedQty = collect($upd->json('items'))->firstWhere('cartItemId', $itemId)['quantity']
            ?? collect($upd->json('items'))->sum(fn ($row) => (float) ($row['quantity'] ?? 0));
        $this->assertSame(3.0, (float) $updatedQty);

        // Step 3 — addresses
        $addr = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/carts/'.$cartId.'/addresses', [
            'billing' => [
                'firstName' => 'Wave',
                'lastName' => '166',
                'line1' => '166 Rest Ave',
                'city' => 'Austin',
                'state' => 'TX',
                'postalCode' => '78701',
                'countryCode' => 'US',
                'phone' => '5550166',
                'useForShipping' => true,
            ],
        ]);
        $addr->assertOk();
        $this->assertNotNull($addr->json('billingAddress'));

        // Step 4 — shipping
        $shipList = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/carts/'.$cartId.'/shipping-methods');
        $shipList->assertOk();
        $this->assertNotEmpty($shipList->json('data'));
        $setShip = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/carts/'.$cartId.'/shipping-methods', [
            'shippingMethod' => $ship->code,
        ]);
        $setShip->assertOk();
        $this->assertSame($ship->code, (string) $setShip->json('shippingMethod'));

        // Step 5 — payment
        $payList = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/carts/'.$cartId.'/payment-methods');
        $payList->assertOk();
        $this->assertNotEmpty($payList->json('data'));
        $setPay = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/carts/'.$cartId.'/payment-methods', [
            'payment' => $pay->code,
        ]);
        $setPay->assertOk();
        $this->assertSame($pay->code, (string) $setPay->json('paymentMethodTitle'));

        // GraphQL cartId camelCase on draft cart
        $gqlCart = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($cid: ID!) { adminComposerCart(cartId: $cid) { cartId customerId itemsCount } }',
            'variables' => ['cid' => $cartId],
        ]);
        $gqlCart->assertOk();
        $this->assertSame($cartId, (string) $gqlCart->json('data.adminComposerCart.cartId'));
        $this->assertSame($customerId, (string) $gqlCart->json('data.adminComposerCart.customerId'));

        // Step 6 — place
        $place = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/orders/place/'.$cartId);
        $place->assertOk();
        $this->assertTrue((bool) $place->json('success'));
        $this->assertNotSame('', (string) $place->json('orderId'));
        $this->assertSame($customerId, (string) $place->json('customerId'));

    }

    public function test_rest_shipping_before_addresses_returns_conflict(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $email = 'w166b-'.strtolower(substr(uniqid(), -6)).'@example.test';
        $customerId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createCustomer(email: $e, firstName: "Wave", lastName: "166b", password: "Password1!") { id }
            }',
            'variables' => ['e' => $email],
        ])->json('data.createCustomer.id');

        $cartId = (string) $this->actingAs($admin, 'admin')
            ->postJson('/api/v1/console/customers/'.$customerId.'/order-composer/drafts')
            ->json('cartId');

        $this->actingAs($admin, 'admin')
            ->getJson('/api/v1/console/carts/'.$cartId.'/shipping-methods')
            ->assertStatus(409);
    }
}
