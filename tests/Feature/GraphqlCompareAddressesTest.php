<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GraphqlCompareAddressesTest extends TestCase
{
    use RefreshDatabase;

    public function test_compare_products_returns_requested_ids(): void
    {
        $this->seed();
        $product = Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);
        $ids = [(string) $product->id];

        $response = $this->postJson('/api/v1/graphql', [
            'query' => 'query ($ids: [ID!]!) { compareProducts(ids: $ids) { id sku } }',
            'variables' => ['ids' => $ids],
        ]);

        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? []);
        $returned = collect($response->json('data.compareProducts') ?? [])->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->assertContains((string) $product->id, $returned);
    }

    public function test_customer_addresses_requires_auth(): void
    {
        $this->seed();

        $response = $this->postJson('/api/v1/graphql', [
            'query' => '{ customerAddresses { id city } }',
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('errors'));
    }

    public function test_customer_addresses_lists_saved(): void
    {
        $this->seed();
        $customer = Customer::factory()->create();
        $customer->addresses()->create([
            'label' => 'Home',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'line1' => '1 Analytical Engine Way',
            'city' => 'London',
            'postal_code' => 'SW1A',
            'country_code' => 'GB',
            'is_default_shipping' => true,
            'is_default_billing' => true,
        ]);

        $response = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => '{ customerAddresses { id label city country_code is_default_shipping } }',
        ]);

        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? []);
        $this->assertSame('Home', $response->json('data.customerAddresses.0.label'));
        $this->assertSame('London', $response->json('data.customerAddresses.0.city'));
        $this->assertTrue((bool) $response->json('data.customerAddresses.0.is_default_shipping'));
    }
}
