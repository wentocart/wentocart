<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave130Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_customer_address_menu_rest_ops(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $customer = Customer::factory()->create();

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!) {
                createAdminCustomerAddress(input: {
                    customerId: $cid
                    firstName: "Wave"
                    lastName: "130"
                    address: "1 Default Way"
                    city: "Oxford"
                    country: "GB"
                    zipCode: "OX1 1AA"
                    defaultBilling: true
                    defaultShipping: true
                }) { address { id city } }
            }',
            'variables' => ['cid' => (string) $customer->id],
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? [], json_encode($create->json()));
        $id = (string) $create->json('data.createAdminCustomerAddress.address.id');
        $this->assertNotEmpty($id);

        $list = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($cid: ID!) {
                adminCustomerAddresses(customerId: $cid) { id city defaultBilling }
                adminCustomerAddress(customerId: $cid, id: "'.$id.'") { id address city }
            }',
            'variables' => ['cid' => (string) $customer->id],
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? [], json_encode($list->json()));
        $this->assertGreaterThanOrEqual(1, count($list->json('data.adminCustomerAddresses') ?? []));
        $this->assertSame('Oxford', (string) $list->json('data.adminCustomerAddress.city'));

        $second = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!) {
                createAdminCustomerAddress(input: {
                    customerId: $cid
                    firstName: "Wave"
                    lastName: "Alt"
                    address: "2 Other Rd"
                    city: "Cambridge"
                    country: "GB"
                    zipCode: "CB1 1AA"
                }) { address { id } }
            }',
            'variables' => ['cid' => (string) $customer->id],
        ]);
        $second->assertOk();
        $this->assertArrayNotHasKey('errors', $second->json() ?? [], json_encode($second->json()));
        $id2 = (string) $second->json('data.createAdminCustomerAddress.address.id');

        $def = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!, $id: ID!) {
                setDefaultAdminCustomerAddress(input: { customerId: $cid, id: $id }) {
                    address { id defaultBilling defaultShipping }
                }
            }',
            'variables' => ['cid' => (string) $customer->id, 'id' => $id2],
        ]);
        $def->assertOk();
        $this->assertArrayNotHasKey('errors', $def->json() ?? [], json_encode($def->json()));
        $this->assertTrue((bool) $def->json('data.setDefaultAdminCustomerAddress.address.defaultBilling'));

        $del = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!, $id: ID!) {
                deleteAdminCustomerAddress(input: { customerId: $cid, id: $id }) { status message }
            }',
            'variables' => ['cid' => (string) $customer->id, 'id' => $id],
        ]);
        $del->assertOk();
        $this->assertArrayNotHasKey('errors', $del->json() ?? [], json_encode($del->json()));
        $this->assertTrue((bool) $del->json('data.deleteAdminCustomerAddress.status'));
    }

    public function test_forgot_customer_password_and_mass_update_aliases(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $customer = Customer::factory()->create(['is_enabled' => true]);
        $product = Product::query()->where('is_enabled', true)->firstOrFail();

        $forgot = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation { forgotCustomerPassword(email: "'.$customer->email.'") { ok status message } }',
        ]);
        $forgot->assertOk();
        $this->assertArrayNotHasKey('errors', $forgot->json() ?? [], json_encode($forgot->json()));
        $this->assertTrue((bool) ($forgot->json('data.forgotCustomerPassword.ok') ?? $forgot->json('data.forgotCustomerPassword.status')));

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                bulkUpdateProducts(input: { productIds: [$id], status: true, weight: 1.25 }) {
                    status updatedCount
                }
            }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $mass->assertOk();
        $this->assertArrayNotHasKey('errors', $mass->json() ?? [], json_encode($mass->json()));
        $this->assertGreaterThanOrEqual(1, (int) ($mass->json('data.bulkUpdateProducts.updatedCount') ?? 0));
    }
}
