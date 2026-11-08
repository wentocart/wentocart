<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave100Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_customer_profile_orders_logout_verify(): void
    {
        $this->seed();
        ShopSetting::put('customers.registration', true);
        ShopSetting::put('customers.account_activation', 'automatic');
        ShopSetting::put('customers.email_verification', 'disabled');
        ShopSetting::put('customers.allow_account_deletion', true);
        ShopSetting::put('customers.allow_profile_editing', true);

        $register = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                createCustomer(
                    email: "wave100@example.com"
                    password: "Password123"
                    passwordConfirmation: "Password123"
                    firstName: "Wave"
                    lastName: "Hundred"
                ) { success accessToken customer { id email } }
            }',
        ]);
        $register->assertOk();
        $this->assertArrayNotHasKey('errors', $register->json() ?? [], json_encode($register->json()));
        $token = (string) $register->json('data.createCustomer.accessToken');
        $this->assertNotSame('', $token);
        $customer = Customer::query()->where('email', 'wave100@example.com')->firstOrFail();

        $order = Order::createTrusted([
            'number' => 'W100-ORD-1',
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'completed',
            'currency_code' => 'USD',
            'payment_status' => 'paid',
            'grand_total' => 42,
            'billing_address' => ['country_code' => 'US', 'first_name' => 'W', 'line1' => '1 Main'],
            'shipping_address' => ['country_code' => 'US', 'first_name' => 'W', 'line1' => '1 Main'],
        ]);

        $profile = $this->withToken($token)->postJson('/api/v1/graphql', [
            'query' => '{ readCustomerProfile { id firstName lastName email success } }',
        ]);
        $this->assertSame(200, $profile->status(), json_encode($profile->json()));
        $this->assertArrayNotHasKey('errors', $profile->json() ?? [], json_encode($profile->json()));
        $this->assertSame('Wave', (string) $profile->json('data.readCustomerProfile.firstName'));

        $update = $this->withToken($token)->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                createCustomerProfileUpdate(
                    firstName: "Wave100"
                    phone: "555-0100"
                    subscribedToNewsLetter: true
                ) {
                    customerProfileUpdate {
                        firstName phone subscribedToNewsLetter success message
                    }
                }
            }',
        ]);
        $update->assertOk();
        $this->assertArrayNotHasKey('errors', $update->json() ?? [], json_encode($update->json()));
        $this->assertSame('Wave100', (string) $update->json('data.createCustomerProfileUpdate.customerProfileUpdate.firstName'));
        $this->assertTrue((bool) $update->json('data.createCustomerProfileUpdate.customerProfileUpdate.subscribedToNewsLetter'));

        $orders = $this->withToken($token)->postJson('/api/v1/graphql', [
            'query' => '{
                customerOrders(first: 10, status: "completed") {
                    edges { cursor node { id incrementId status grandTotal } }
                    pageInfo { hasNextPage endCursor }
                }
            }',
        ]);
        $orders->assertOk();
        $this->assertArrayNotHasKey('errors', $orders->json() ?? [], json_encode($orders->json()));
        $nodes = collect($orders->json('data.customerOrders.edges') ?? [])->pluck('node.incrementId')->all();
        $this->assertContains('W100-ORD-1', $nodes);

        $one = $this->withToken($token)->postJson('/api/v1/graphql', [
            'query' => 'query ($id: ID!) {
                customerOrder(id: $id) { id incrementId grandTotal }
            }',
            'variables' => ['id' => (string) $order->id],
        ]);
        $one->assertOk();
        $this->assertArrayNotHasKey('errors', $one->json() ?? [], json_encode($one->json()));
        $this->assertSame('W100-ORD-1', (string) $one->json('data.customerOrder.incrementId'));

        $verify = $this->withToken($token)->postJson('/api/v1/graphql', [
            'query' => 'mutation { createVerifyToken { success valid message } }',
        ]);
        $verify->assertOk();
        $this->assertTrue((bool) $verify->json('data.createVerifyToken.valid'));

        $logout = $this->withToken($token)->postJson('/api/v1/graphql', [
            'query' => 'mutation { createLogout { success message } }',
        ]);
        $logout->assertOk();
        $this->assertTrue((bool) $logout->json('data.createLogout.success'));

        $verifyAfter = $this->withToken($token)->postJson('/api/v1/graphql', [
            'query' => 'mutation { createVerifyToken { success valid } }',
        ]);
        $verifyAfter->assertOk();
        $this->assertFalse((bool) $verifyAfter->json('data.createVerifyToken.valid'));

        // Re-login for delete
        $login2 = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                createLogin(email: "'.$customer->email.'", password: "Password123") {
                    accessToken
                }
            }',
        ]);
        $login2->assertOk();
        $this->assertArrayNotHasKey('errors', $login2->json() ?? [], json_encode($login2->json()));
        $token2 = (string) $login2->json('data.createLogin.accessToken');
        $this->assertNotSame('', $token2);

        $delete = $this->withToken($token2)->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                createCustomerProfileDelete(password: "Password123") { success message }
            }',
        ]);
        $this->assertSame(200, $delete->status(), json_encode($delete->json()));
        $this->assertArrayNotHasKey('errors', $delete->json() ?? [], json_encode($delete->json()));
        $this->assertTrue((bool) $delete->json('data.createCustomerProfileDelete.success'));
        $customer->refresh();
        $this->assertStringContainsString('anonymized', (string) $customer->email);
    }
}
