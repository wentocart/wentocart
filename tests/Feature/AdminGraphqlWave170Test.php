<?php

namespace Tests\Feature;

use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Settings\ShopSetting;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave170Test extends TestCase
{
    use RefreshDatabase;

    public function test_minimum_order_amount_blocks_place_and_admin_reorder_setting(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $ship = ShippingMethod::query()->where('is_enabled', true)->firstOrFail();
        $pay = PaymentMethod::query()->where('code', 'bank_transfer')->where('is_enabled', true)->firstOrFail();

        ShopSetting::put('orders.minimum_order_enabled', '1');
        ShopSetting::put('orders.min_amount', '100');

        $sku = 'W170-'.strtoupper(substr(uniqid(), -6));
        $productId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createSimpleProduct(sku: $sku, name: "Wave170", price: 12, stockQty: 5, enabled: true) { id }
            }',
            'variables' => ['sku' => $sku],
        ])->json('data.createSimpleProduct.id');

        $email = 'w170-'.strtolower(substr(uniqid(), -6)).'@example.test';
        $customerId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createCustomer(email: $e, firstName: "Wave", lastName: "170", password: "Password1!") { id }
            }',
            'variables' => ['e' => $email],
        ])->json('data.createCustomer.id');

        $cartId = (string) $this->actingAs($admin, 'admin')
            ->postJson('/api/v1/console/customers/'.$customerId.'/order-composer/drafts')
            ->json('cartId');
        $this->actingAs($admin, 'admin')->postJson('/api/v1/console/carts/'.$cartId.'/items', [
            'productId' => (int) $productId,
            'quantity' => 1,
        ])->assertOk();
        $this->actingAs($admin, 'admin')->postJson('/api/v1/console/carts/'.$cartId.'/addresses', [
            'billing' => [
                'firstName' => 'Wave', 'lastName' => '170', 'line1' => '170 Min St',
                'city' => 'Austin', 'state' => 'TX', 'postalCode' => '78701',
                'countryCode' => 'US', 'phone' => '5550170', 'useForShipping' => true,
            ],
        ])->assertOk();
        $this->actingAs($admin, 'admin')->postJson('/api/v1/console/carts/'.$cartId.'/shipping-methods', [
            'shippingMethod' => $ship->code,
        ])->assertOk();
        $this->actingAs($admin, 'admin')->postJson('/api/v1/console/carts/'.$cartId.'/payment-methods', [
            'payment' => $pay->code,
        ])->assertOk();

        $blocked = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/orders/place/'.$cartId);
        $blocked->assertStatus(422);
        $this->assertStringContainsString('minimum order', strtolower((string) $blocked->json('message')));

        // Clear minimum and place successfully
        ShopSetting::put('orders.minimum_order_enabled', '0');
        ShopSetting::put('orders.min_amount', '0');
        $placed = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/orders/place/'.$cartId);
        $placed->assertOk();
        $orderId = (string) $placed->json('orderId');
        $this->assertNotSame('', $orderId);

        // Admin reorder disabled
        ShopSetting::put('orders.admin_reorder_enabled', '0');
        $this->actingAs($admin, 'admin')
            ->postJson('/api/v1/console/orders/'.$orderId.'/reorder')
            ->assertStatus(422)
            ->assertJsonFragment(['detail' => 'Reorder by admin is disabled in store settings.']);

    }
}
