<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave128Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_create_update_delete_customer_rest_aliases(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $email = 'wave128-'.uniqid().'@example.com';

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($email: String!) {
                adminCreateCustomer(input: {
                    firstName: "Wave"
                    lastName: "128"
                    email: $email
                    password: "SecurePass123!"
                    status: true
                }) {
                    customer { id firstName lastName email status }
                }
            }',
            'variables' => ['email' => $email],
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? [], json_encode($create->json()));
        $id = (string) $create->json('data.adminCreateCustomer.customer.id');
        $this->assertNotEmpty($id);
        $this->assertSame('Wave', (string) $create->json('data.adminCreateCustomer.customer.firstName'));
        $this->assertSame($email, (string) $create->json('data.adminCreateCustomer.customer.email'));

        $update = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                adminUpdateCustomer(input: {
                    id: $id
                    firstName: "Ahead"
                    lastName: "Far"
                    status: true
                }) {
                    customer { id firstName lastName }
                }
            }',
            'variables' => ['id' => $id],
        ]);
        $update->assertOk();
        $this->assertArrayNotHasKey('errors', $update->json() ?? [], json_encode($update->json()));
        $this->assertSame('Ahead', (string) $update->json('data.adminUpdateCustomer.customer.firstName'));
        $this->assertSame('Far', (string) $update->json('data.adminUpdateCustomer.customer.lastName'));

        $delete = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                adminDeleteCustomer(input: { id: $id }) { status message ok }
            }',
            'variables' => ['id' => $id],
        ]);
        $delete->assertOk();
        $this->assertArrayNotHasKey('errors', $delete->json() ?? [], json_encode($delete->json()));
        $this->assertTrue((bool) $delete->json('data.adminDeleteCustomer.status'));
    }

    public function test_update_product_prices_rest_alias(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $product = Product::query()->where('is_enabled', true)->firstOrFail();

        $response = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateProductPrices(input: {
                    productId: $id
                    prices: [{ channel: "default", price: 88.5, cost: 40, specialPrice: 79.9 }]
                }) {
                    product { id price }
                }
            }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? [], json_encode($response->json()));
        $this->assertEqualsWithDelta(88.5, (float) $response->json('data.updateProductPrices.product.price'), 0.001);
        $this->assertEqualsWithDelta(88.5, (float) $product->fresh()->price, 0.001);
    }
}
