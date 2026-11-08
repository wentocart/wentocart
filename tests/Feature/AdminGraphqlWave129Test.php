<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave129Test extends TestCase
{
    use RefreshDatabase;

    public function test_shop_downloadable_booking_and_login_alias_fields(): void
    {
        $this->seed();
        $customer = Customer::factory()->create(['is_enabled' => true]);

        $downloads = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => '{ downloadableProducts(limit: 5) { id } downloadableLinks(limit: 5) { id } bookingProducts(limit: 5) { id } }',
        ]);
        $downloads->assertOk();
        $this->assertArrayNotHasKey('errors', $downloads->json() ?? [], json_encode($downloads->json()));
        $this->assertIsArray($downloads->json('data.downloadableProducts'));
        $this->assertIsArray($downloads->json('data.downloadableLinks'));
        $this->assertIsArray($downloads->json('data.bookingProducts'));

        // Field exists and rejects empty credentials (alias wired to createCustomerLogin).
        $login = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation { loginCustomer(email: "", password: "") { success } }',
        ]);
        $login->assertOk();
        $this->assertNotEmpty($login->json('errors'));
        $this->assertSame('loginCustomer', (string) ($login->json('errors.0.path.0') ?? ''));
    }

    public function test_admin_create_and_update_customer_address_aliases(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $customer = Customer::factory()->create();

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!) {
                adminCreateCustomerAddress(input: {
                    customerId: $cid
                    firstName: "Wave"
                    lastName: "129"
                    address: "10 Ahead Ave"
                    city: "Bath"
                    country: "GB"
                    zipCode: "BA1 1AA"
                    phoneNumber: "07001234567"
                    defaultBilling: true
                    defaultShipping: true
                }) {
                    address { id firstName address city zipCode defaultBilling }
                }
            }',
            'variables' => ['cid' => (string) $customer->id],
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? [], json_encode($create->json()));
        $addressId = (string) $create->json('data.adminCreateCustomerAddress.address.id');
        $this->assertNotEmpty($addressId);
        $this->assertSame('10 Ahead Ave', (string) $create->json('data.adminCreateCustomerAddress.address.address'));

        $update = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateAdminCustomerAddress(input: {
                    addressId: $id
                    address: "20 Far Street"
                    city: "Bristol"
                    zipCode: "BS1 4DJ"
                }) {
                    address { id city address zipCode }
                }
            }',
            'variables' => ['id' => $addressId],
        ]);
        $update->assertOk();
        $this->assertArrayNotHasKey('errors', $update->json() ?? [], json_encode($update->json()));
        $this->assertSame('Bristol', (string) $update->json('data.updateAdminCustomerAddress.address.city'));
        $this->assertSame('20 Far Street', (string) $update->json('data.updateAdminCustomerAddress.address.address'));
    }
}
