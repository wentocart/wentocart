<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminGraphqlWave137Test extends TestCase
{
    use RefreshDatabase;

    public function test_create_social_login_provider_disabled_and_google_success(): void
    {
        $this->seed();

        $disabled = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                createSocialLogin(input: {
                    provider: "google"
                    idToken: "fake-token"
                }) {
                    success
                    code
                    message
                    socialLogin { success code }
                }
            }',
        ]);
        $disabled->assertOk();
        $this->assertArrayNotHasKey('errors', $disabled->json() ?? [], json_encode($disabled->json()));
        $this->assertFalse((bool) $disabled->json('data.createSocialLogin.success'));
        $this->assertSame('PROVIDER_DISABLED', (string) $disabled->json('data.createSocialLogin.code'));
        $this->assertSame('PROVIDER_DISABLED', (string) $disabled->json('data.createSocialLogin.socialLogin.code'));

        $unsupported = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                createSocialLogin(input: {
                    provider: "twitter"
                    accessToken: "x"
                }) {
                    success
                    code
                }
            }',
        ]);
        $unsupported->assertOk();
        $this->assertArrayNotHasKey('errors', $unsupported->json() ?? [], json_encode($unsupported->json()));
        $this->assertSame('PROVIDER_NOT_SUPPORTED', (string) $unsupported->json('data.createSocialLogin.code'));

        ShopSetting::put('customers.social.google.enabled', true);
        ShopSetting::put('customers.social.google.client_id', 'wento-google-client');
        ShopSetting::put('customers.social.google.secret', 'wento-google-secret');

        $missing = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                createSocialLogin(input: { provider: "google" }) {
                    success
                    code
                }
            }',
        ]);
        $missing->assertOk();
        $this->assertArrayNotHasKey('errors', $missing->json() ?? [], json_encode($missing->json()));
        $this->assertSame('SOCIAL_TOKEN_REQUIRED', (string) $missing->json('data.createSocialLogin.code'));

        $email = 'w137-'.uniqid().'@example.com';
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response([
                'sub' => 'google-sub-w137',
                'email' => $email,
                'given_name' => 'Wave',
                'family_name' => '137',
                'name' => 'Wave 137',
                'aud' => 'wento-google-client',
            ], 200),
        ]);

        $ok = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                createSocialLogin(input: {
                    provider: "google"
                    idToken: "test-google-id-token"
                }) {
                    success
                    code
                    message
                    token
                    apiToken
                    accessToken
                    isNewCustomer
                    firstName
                    lastName
                    email
                    id
                    id
                    socialLogin {
                        success
                        token
                        isNewCustomer
                        email
                        firstName
                    }
                    customer { id email firstName lastName }
                }
            }',
        ]);
        $ok->assertOk();
        $this->assertArrayNotHasKey('errors', $ok->json() ?? [], json_encode($ok->json()));
        $this->assertTrue((bool) $ok->json('data.createSocialLogin.success'));
        $this->assertNull($ok->json('data.createSocialLogin.code'));
        $this->assertTrue((bool) $ok->json('data.createSocialLogin.isNewCustomer'));
        $this->assertSame($email, (string) $ok->json('data.createSocialLogin.email'));
        $this->assertSame('Wave', (string) $ok->json('data.createSocialLogin.firstName'));
        $this->assertNotEmpty((string) $ok->json('data.createSocialLogin.token'));
        $this->assertSame(
            (string) $ok->json('data.createSocialLogin.token'),
            (string) $ok->json('data.createSocialLogin.socialLogin.token')
        );
        $this->assertTrue((bool) $ok->json('data.createSocialLogin.socialLogin.isNewCustomer'));

        $customer = Customer::query()->where('email', $email)->first();
        $this->assertNotNull($customer);
        $this->assertSame('google-sub-w137', (string) $customer->google_id);

        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response([
                'sub' => 'google-sub-w137',
                'email' => $email,
                'given_name' => 'Wave',
                'family_name' => '137',
                'aud' => 'wento-google-client',
            ], 200),
        ]);

        $again = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                createSocialLogin(input: {
                    provider: "google"
                    idToken: "test-google-id-token-2"
                }) {
                    success
                    isNewCustomer
                    email
                    token
                }
            }',
        ]);
        $again->assertOk();
        $this->assertArrayNotHasKey('errors', $again->json() ?? [], json_encode($again->json()));
        $this->assertTrue((bool) $again->json('data.createSocialLogin.success'));
        $this->assertFalse((bool) $again->json('data.createSocialLogin.isNewCustomer'));
        $this->assertSame(1, Customer::query()->where('email', $email)->count());
    }
}
