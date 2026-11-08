<?php

namespace Tests\Feature;

use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave106Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_nested_input_login_verify_cart_token(): void
    {
        $this->seed();
        ShopSetting::put('customers.registration', true);
        ShopSetting::put('customers.account_activation', 'automatic');
        ShopSetting::put('customers.email_verification', 'disabled');

        $register = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                createCustomer(
                    email: "wave106@example.com"
                    password: "Password123"
                    passwordConfirmation: "Password123"
                    firstName: "Wave"
                    lastName: "Input"
                ) { success }
            }',
        ]);
        $register->assertOk();
        $this->assertArrayNotHasKey('errors', $register->json() ?? [], json_encode($register->json()));

        $login = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation ($input: CreateCustomerLoginInput!) {
                createCustomerLogin(input: $input) {
                    success
                    apiToken
                    customerLogin {
                        token
                        apiToken
                        success
                        customer { id email firstName }
                    }
                }
            }',
            'variables' => [
                'input' => [
                    'email' => 'wave106@example.com',
                    'password' => 'Password123',
                ],
            ],
        ]);
        $this->assertSame(200, $login->status(), json_encode($login->json()));
        $this->assertArrayNotHasKey('errors', $login->json() ?? [], json_encode($login->json()));
        $token = (string) $login->json('data.createCustomerLogin.customerLogin.token');
        $this->assertNotSame('', $token);
        $this->assertSame($token, (string) $login->json('data.createCustomerLogin.apiToken'));

        $verify = $this->withToken($token)->postJson('/api/v1/graphql', [
            'query' => 'mutation ($input: CreateVerifyTokenInput) {
                createVerifyToken(input: $input) {
                    success valid isValid
                    verifyToken { isValid message }
                }
            }',
            'variables' => [
                'input' => ['token' => $token],
            ],
        ]);
        $verify->assertOk();
        $this->assertArrayNotHasKey('errors', $verify->json() ?? [], json_encode($verify->json()));
        $this->assertTrue((bool) $verify->json('data.createVerifyToken.verifyToken.isValid'));
        $this->assertTrue((bool) $verify->json('data.createVerifyToken.isValid'));

        $cart = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation ($input: CreateCartTokenInput) {
                createCartToken(input: $input) {
                    cart_token
                    cartToken { id cartToken token }
                }
            }',
            'variables' => [
                'input' => ['clientMutationId' => 'wave106'],
            ],
        ]);
        $cart->assertOk();
        $this->assertArrayNotHasKey('errors', $cart->json() ?? [], json_encode($cart->json()));
        $cartToken = (string) $cart->json('data.createCartToken.cart_token');
        $this->assertNotSame('', $cartToken);
        $this->assertSame($cartToken, (string) $cart->json('data.createCartToken.cartToken.cartToken'));
    }
}
