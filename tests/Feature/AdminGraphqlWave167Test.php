<?php

namespace Tests\Feature;

use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave167Test extends TestCase
{
    use RefreshDatabase;

    public function test_slim_add_product_search_and_rest_reorder(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $ship = ShippingMethod::query()->where('is_enabled', true)->firstOrFail();
        $pay = PaymentMethod::query()->where('is_enabled', true)->firstOrFail();

        $sku = 'W167-'.strtoupper(substr(uniqid(), -6));
        $productId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createSimpleProduct(sku: $sku, name: "Wave167 Pick", price: 19, stockQty: 6, enabled: true) { id }
            }',
            'variables' => ['sku' => $sku],
        ])->json('data.createSimpleProduct.id');
        $this->assertNotSame('', $productId);

        $search = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/order-composer/products?query=Wave167&per_page=30');
        $search->assertOk();
        $search->assertJsonStructure(['data', 'meta' => ['current_page', 'per_page', 'total', 'last_page']]);
        $row = collect($search->json('data'))->firstWhere('sku', $sku);
        $this->assertNotNull($row);
        $this->assertSame((int) $productId, (int) $row['id']);
        $this->assertArrayHasKey('isSaleable', $row);
        $this->assertArrayHasKey('baseImageUrl', $row);
        $this->assertNotSame('', (string) $row['baseImageUrl']);

        $exact = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/order-composer/products?sku='.$sku);
        $exact->assertOk();
        $this->assertSame(1, (int) $exact->json('meta.total'));

        $email = 'w167-'.strtolower(substr(uniqid(), -6)).'@example.test';
        $customerId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createCustomer(email: $e, firstName: "Wave", lastName: "167", password: "Password1!") { id }
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
                'firstName' => 'Wave', 'lastName' => '167', 'line1' => '167 Reorder Rd',
                'city' => 'Austin', 'state' => 'TX', 'postalCode' => '78701',
                'countryCode' => 'US', 'phone' => '5550167', 'useForShipping' => true,
            ],
        ])->assertOk();
        $this->actingAs($admin, 'admin')->postJson('/api/v1/console/carts/'.$cartId.'/shipping-methods', [
            'shippingMethod' => $ship->code,
        ])->assertOk();
        $this->actingAs($admin, 'admin')->postJson('/api/v1/console/carts/'.$cartId.'/payment-methods', [
            'payment' => $pay->code,
        ])->assertOk();
        $place = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/orders/place/'.$cartId);
        $place->assertOk();
        $orderId = (string) $place->json('orderId');
        $this->assertNotSame('', $orderId);

        $reorder = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/orders/'.$orderId.'/reorder');
        $reorder->assertCreated();
        $this->assertNotSame('', (string) $reorder->json('cartId'));
        $this->assertSame($customerId, (string) $reorder->json('customerId'));
        $this->assertGreaterThanOrEqual(1, (int) $reorder->json('itemsCount'));

    }
}
