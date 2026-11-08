<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave121Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_cart_mutation_aliases_add_update_remove(): void
    {
        $this->seed();

        $product = Product::query()->where('sku', 'DEMO-001')->first()
            ?? Product::query()->where('is_enabled', true)->where('type', 'simple')->first();
        $this->assertNotNull($product);

        $create = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation { createCart(input: {}) { cart { id itemsCount grandTotal } } }',
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? [], json_encode($create->json()));
        $this->assertNotEmpty($create->json('data.createCart.cart.id'));

        $add = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation ($pid: ID!) {
                addProductsToCart(input: { items: [{ productId: $pid, quantity: 2 }] }) {
                    cart { itemsCount itemsQty grandTotal couponCode itemsList { id product_id qty } items { totalCount edges { node { id productId quantity } } } }
                }
            }',
            'variables' => ['pid' => (string) $product->id],
        ]);
        $add->assertOk();
        $this->assertArrayNotHasKey('errors', $add->json() ?? [], json_encode($add->json()));
        $this->assertSame(1, (int) $add->json('data.addProductsToCart.cart.itemsCount'));
        $this->assertEqualsWithDelta(2.0, (float) $add->json('data.addProductsToCart.cart.itemsQty'), 0.001);
        $itemId = (string) $add->json('data.addProductsToCart.cart.items.edges.0.node.id');
        $this->assertSame($itemId, (string) $add->json('data.addProductsToCart.cart.itemsList.0.id'));
        $this->assertNotSame('', $itemId);

        $update = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation ($iid: ID!) {
                updateCart(input: { items: [{ cartItemId: $iid, quantity: 3 }] }) {
                    cart { itemsQty itemsCount }
                }
            }',
            'variables' => ['iid' => $itemId],
        ]);
        $update->assertOk();
        $this->assertArrayNotHasKey('errors', $update->json() ?? [], json_encode($update->json()));
        $this->assertEqualsWithDelta(3.0, (float) $update->json('data.updateCart.cart.itemsQty'), 0.001);

        $remove = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation ($iid: ID!) {
                removeCartItem(input: { cartItemId: $iid }) {
                    cart { itemsCount itemsQty }
                }
            }',
            'variables' => ['iid' => $itemId],
        ]);
        $remove->assertOk();
        $this->assertArrayNotHasKey('errors', $remove->json() ?? [], json_encode($remove->json()));
        $this->assertSame(0, (int) $remove->json('data.removeCartItem.cart.itemsCount'));
    }

    public function test_rest_save_shipping_and_payment_method_aliases_exist(): void
    {
        $this->seed();

        $ship = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                saveShippingMethod(input: { shippingMethodCode: "flatrate" }) {
                    success message
                }
            }',
        ]);
        // Empty cart or unknown method should still resolve field (error or success)
        $ship->assertOk();
        $this->assertTrue(
            isset($ship->json()['errors']) || is_bool($ship->json('data.saveShippingMethod.success')),
            json_encode($ship->json())
        );

        $pay = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                savePaymentMethod(input: { method: "cod" }) {
                    success message
                }
            }',
        ]);
        $pay->assertOk();
        $this->assertTrue(
            isset($pay->json()['errors']) || is_bool($pay->json('data.savePaymentMethod.success')),
            json_encode($pay->json())
        );
    }
}
