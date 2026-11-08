<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AdminGraphqlWave99Test extends TestCase
{
    use RefreshDatabase;

    public function test_create_customer_and_forgot_password_aliases(): void
    {
        $this->seed();
        ShopSetting::put('customers.registration', true);

        $register = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                createCustomer(
                    email: "wave99@example.com"
                    password: "Password123"
                    passwordConfirmation: "Password123"
                    firstName: "Wave"
                    lastName: "NinetyNine"
                ) {
                    success message pending accessToken token customer { id email name }
                }
            }',
        ]);
        $this->assertSame(200, $register->status(), json_encode($register->json()));
        $this->assertArrayNotHasKey('errors', $register->json() ?? [], json_encode($register->json()));
        $this->assertTrue((bool) $register->json('data.createCustomer.success'));
        $this->assertTrue(
            Customer::query()->where('email', 'wave99@example.com')->exists()
        );

        $forgot = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                createForgotPassword(email: "wave99@example.com") { ok message }
            }',
        ]);
        $forgot->assertOk();
        $this->assertArrayNotHasKey('errors', $forgot->json() ?? [], json_encode($forgot->json()));
        $this->assertTrue((bool) $forgot->json('data.createForgotPassword.ok'));

        $customer = Customer::query()->where('email', 'wave99@example.com')->firstOrFail();
        $token = Password::broker('customers')->createToken($customer);

        $reset = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation ($token: String!) {
                createResetPassword(
                    email: "wave99@example.com"
                    token: $token
                    password: "Password456"
                    passwordConfirmation: "Password456"
                ) { ok message }
            }',
            'variables' => ['token' => $token],
        ]);
        $this->assertSame(200, $reset->status(), json_encode($reset->json()));
        $this->assertArrayNotHasKey('errors', $reset->json() ?? [], json_encode($reset->json()));
        $this->assertTrue((bool) $reset->json('data.createResetPassword.ok'));

        $login = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                createLogin(email: "wave99@example.com", password: "Password456") {
                    success accessToken
                }
            }',
        ]);
        $login->assertOk();
        $this->assertArrayNotHasKey('errors', $login->json() ?? [], json_encode($login->json()));
        $this->assertTrue((bool) $login->json('data.createLogin.success'));
        $this->assertNotEmpty($login->json('data.createLogin.accessToken'));
    }
}
