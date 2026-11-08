<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave101Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_customer_address_book_aliases(): void
    {
        $this->seed();
        ShopSetting::put('customers.registration', true);
        ShopSetting::put('customers.account_activation', 'automatic');
        ShopSetting::put('customers.email_verification', 'disabled');
        ShopSetting::put('customers.allow_multiple_addresses', true);

        $register = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                createCustomer(
                    email: "wave101@example.com"
                    password: "Password123"
                    passwordConfirmation: "Password123"
                    firstName: "Wave"
                    lastName: "Address"
                ) { success accessToken }
            }',
        ]);
        $register->assertOk();
        $this->assertArrayNotHasKey('errors', $register->json() ?? [], json_encode($register->json()));
        $token = (string) $register->json('data.createCustomer.accessToken');
        $this->assertNotSame('', $token);

        $create = $this->withToken($token)->postJson('/api/v1/graphql', [
            'query' => 'mutation ($input: CreateAddUpdateCustomerAddressInput!) {
                createAddUpdateCustomerAddress(input: $input) {
                    addUpdateCustomerAddress {
                        id addressId firstName lastName address1 city country postcode
                        defaultAddress useForShipping phone
                    }
                }
            }',
            'variables' => [
                'input' => [
                    'firstName' => 'Wave',
                    'lastName' => 'Address',
                    'email' => 'wave101@example.com',
                    'phone' => '555-0101',
                    'address1' => '101 API Way',
                    'city' => 'Austin',
                    'state' => 'TX',
                    'country' => 'US',
                    'postcode' => '78701',
                    'defaultAddress' => true,
                    'useForShipping' => true,
                ],
            ],
        ]);
        $this->assertSame(200, $create->status(), json_encode($create->json()));
        $this->assertArrayNotHasKey('errors', $create->json() ?? [], json_encode($create->json()));
        $addrId = (string) $create->json('data.createAddUpdateCustomerAddress.addUpdateCustomerAddress.id');
        $this->assertNotSame('', $addrId);
        $this->assertSame('Austin', (string) $create->json('data.createAddUpdateCustomerAddress.addUpdateCustomerAddress.city'));
        $this->assertTrue((bool) $create->json('data.createAddUpdateCustomerAddress.addUpdateCustomerAddress.defaultAddress'));

        $list = $this->withToken($token)->postJson('/api/v1/graphql', [
            'query' => '{
                getCustomerAddresses(first: 10) {
                    totalCount
                    edges { cursor node { id firstName city defaultAddress } }
                    pageInfo { hasNextPage endCursor }
                }
            }',
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? [], json_encode($list->json()));
        $this->assertSame(1, (int) $list->json('data.getCustomerAddresses.totalCount'));
        $ids = collect($list->json('data.getCustomerAddresses.edges') ?? [])->pluck('node.id')->map(fn ($id) => (string) $id)->all();
        $this->assertContains($addrId, $ids);

        $update = $this->withToken($token)->postJson('/api/v1/graphql', [
            'query' => 'mutation ($input: CreateAddUpdateCustomerAddressInput!) {
                createAddUpdateCustomerAddress(input: $input) {
                    addUpdateCustomerAddress { id city address1 defaultAddress }
                }
            }',
            'variables' => [
                'input' => [
                    'addressId' => $addrId,
                    'firstName' => 'Wave',
                    'lastName' => 'Updated',
                    'address1' => '202 Updated Ave',
                    'city' => 'Dallas',
                    'state' => 'TX',
                    'country' => 'US',
                    'postcode' => '75201',
                    'phone' => '555-0102',
                    'defaultAddress' => true,
                    'useForShipping' => true,
                ],
            ],
        ]);
        $update->assertOk();
        $this->assertArrayNotHasKey('errors', $update->json() ?? [], json_encode($update->json()));
        $this->assertSame('Dallas', (string) $update->json('data.createAddUpdateCustomerAddress.addUpdateCustomerAddress.city'));

        $delete = $this->withToken($token)->postJson('/api/v1/graphql', [
            'query' => 'mutation ($input: CreateDeleteCustomerAddressInput) {
                createDeleteCustomerAddress(input: $input) {
                    deleteCustomerAddress { id success message }
                }
            }',
            'variables' => [
                'input' => ['id' => $addrId],
            ],
        ]);
        $delete->assertOk();
        $this->assertArrayNotHasKey('errors', $delete->json() ?? [], json_encode($delete->json()));
        $this->assertTrue((bool) $delete->json('data.createDeleteCustomerAddress.deleteCustomerAddress.success'));

        $customer = Customer::query()->where('email', 'wave101@example.com')->firstOrFail();
        $this->assertSame(0, $customer->addresses()->count());

        $empty = $this->withToken($token)->postJson('/api/v1/graphql', [
            'query' => '{ getCustomerAddresses(first: 5) { totalCount edges { node { id } } } }',
        ]);
        $empty->assertOk();
        $this->assertSame(0, (int) $empty->json('data.getCustomerAddresses.totalCount'));
    }
}
