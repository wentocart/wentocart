<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Store\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GraphqlCustomerLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_login_mutation_issues_bearer_token(): void
    {
        $this->seed();
        $customer = Customer::factory()->create([
            'store_id' => app(StoreContext::class)->requireId(),
            'email' => 'gql-login@example.test',
            'password' => 'Password1a',
        ]);

        $response = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation ($email: String!, $password: String!) {
                customerLogin(email: $email, password: $password, deviceName: "phpunit") {
                    access_token token_type customer { id email }
                }
            }',
            'variables' => [
                'email' => $customer->email,
                'password' => 'Password1a',
            ],
        ]);

        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? []);
        $this->assertNotEmpty($response->json('data.customerLogin.access_token'));
        $this->assertSame('Bearer', $response->json('data.customerLogin.token_type'));
        $this->assertSame((string) $customer->id, (string) $response->json('data.customerLogin.customer.id'));
    }

    public function test_customer_login_rejects_bad_password(): void
    {
        $this->seed();
        $customer = Customer::factory()->create([
            'store_id' => app(StoreContext::class)->requireId(),
            'email' => 'gql-bad@example.test',
            'password' => 'Password1a',
        ]);

        $response = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation { customerLogin(email: "gql-bad@example.test", password: "wrong") { access_token } }',
        ]);
        $response->assertOk();
        $this->assertNotEmpty($response->json('errors'));
    }
}
