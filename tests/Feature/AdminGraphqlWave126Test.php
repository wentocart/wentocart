<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave126Test extends TestCase
{
    use RefreshDatabase;

    public function test_shop_home_categories_default_store_and_store_settings(): void
    {
        $this->seed();

        $home = $this->postJson('/api/v1/graphql', [
            'query' => '{ homeCategories(limit: 5) { id name slug translation { name slug } } }',
        ]);
        $home->assertOk();
        $this->assertArrayNotHasKey('errors', $home->json() ?? [], json_encode($home->json()));
        $this->assertIsArray($home->json('data.homeCategories'));

        $channel = $this->postJson('/api/v1/graphql', [
            'query' => '{ defaultStore { id code name currencyCode localeCode } }',
        ]);
        $channel->assertOk();
        $this->assertArrayNotHasKey('errors', $channel->json() ?? [], json_encode($channel->json()));
        $this->assertNotEmpty((string) $channel->json('data.defaultStore.code'));

        $configs = $this->postJson('/api/v1/graphql', [
            'query' => '{ storeSettings(first: 10) { totalCount edges { node { code value storeCode } } } }',
        ]);
        $configs->assertOk();
        $this->assertArrayNotHasKey('errors', $configs->json() ?? [], json_encode($configs->json()));
        $this->assertGreaterThanOrEqual(1, (int) $configs->json('data.storeSettings.totalCount'));
        $this->assertNotEmpty((string) $configs->json('data.storeSettings.edges.0.node.code'));
    }

    public function test_rest_add_update_customer_address_and_compare_item(): void
    {
        $this->seed();

        $customer = Customer::factory()->create();
        $product = Product::query()->where('is_enabled', true)->firstOrFail();

        $add = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                addCustomerAddress(input: {
                    firstName: "Wave"
                    lastName: "126"
                    address: "42 Parity Lane"
                    city: "Leeds"
                    country: "GB"
                    zipCode: "LS1 1AA"
                    phoneNumber: "07001112233"
                    defaultBilling: true
                    defaultShipping: true
                }) {
                    address {
                        id firstName address city zipCode phoneNumber defaultBilling defaultShipping
                    }
                }
            }',
        ]);
        $add->assertOk();
        $this->assertArrayNotHasKey('errors', $add->json() ?? [], json_encode($add->json()));
        $addressId = (string) $add->json('data.addCustomerAddress.address.id');
        $this->assertNotEmpty($addressId);
        $this->assertSame('42 Parity Lane', (string) $add->json('data.addCustomerAddress.address.address'));
        $this->assertSame('LS1 1AA', (string) $add->json('data.addCustomerAddress.address.zipCode'));
        $this->assertTrue((bool) $add->json('data.addCustomerAddress.address.defaultBilling'));

        $update = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateCustomerAddress(input: {
                    addressId: $id
                    firstName: "Wave"
                    lastName: "Updated"
                    address: "99 Ahead Street"
                    city: "York"
                    country: "GB"
                    zipCode: "YO1 7HH"
                    phoneNumber: "07009998877"
                    defaultShipping: true
                }) {
                    address { id city address zipCode lastName }
                }
            }',
            'variables' => ['id' => $addressId],
        ]);
        $update->assertOk();
        $this->assertArrayNotHasKey('errors', $update->json() ?? [], json_encode($update->json()));
        $this->assertSame('York', (string) $update->json('data.updateCustomerAddress.address.city'));
        $this->assertSame('99 Ahead Street', (string) $update->json('data.updateCustomerAddress.address.address'));

        $compare = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) {
                createCompareItem(productId: $id) { compared product_id }
            }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $compare->assertOk();
        $this->assertArrayNotHasKey('errors', $compare->json() ?? [], json_encode($compare->json()));
        $this->assertTrue((bool) $compare->json('data.createCompareItem.compared'));

        $one = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'query ($id: ID!) {
                compareItem(id: $id) { id product { id sku name } }
            }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $one->assertOk();
        $this->assertArrayNotHasKey('errors', $one->json() ?? [], json_encode($one->json()));
        $this->assertSame((string) $product->id, (string) $one->json('data.compareItem.id'));
        $this->assertSame((string) $product->id, (string) $one->json('data.compareItem.product.id'));
    }

    public function test_admin_rest_short_settings_and_dashboard_aliases(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $response = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{
                stores(limit: 5) { id code name }
                locales(limit: 5) { id code name }
                currencies(limit: 5) { id code name }
                warehouses(limit: 5) { id code name }
                taxRates(limit: 5) { id name }
                taxCategories(limit: 5) { id name }
                exchangeRates(limit: 5) { id rate }
                dashboard { type dateRange statistics }
                salesReports(days: 7) { orders total_revenue avg_order_value }
            }',
        ]);
        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? [], json_encode($response->json()));
        $this->assertIsArray($response->json('data.stores'));
        $this->assertIsArray($response->json('data.locales'));
        $this->assertIsArray($response->json('data.currencies'));
        $this->assertSame('overview', (string) $response->json('data.dashboard.type'));
        $this->assertGreaterThanOrEqual(0, (int) $response->json('data.salesReports.orders'));
    }
}
