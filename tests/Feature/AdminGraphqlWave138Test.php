<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminGraphqlWave138Test extends TestCase
{
    use RefreshDatabase;

    public function test_create_social_login_linkedin_provider(): void
    {
        $this->seed();

        $disabled = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                createSocialLogin(input: {
                    provider: "linkedin"
                    accessToken: "li-token"
                }) {
                    success
                    code
                    socialLogin { code }
                }
            }',
        ]);
        $disabled->assertOk();
        $this->assertArrayNotHasKey('errors', $disabled->json() ?? [], json_encode($disabled->json()));
        $this->assertFalse((bool) $disabled->json('data.createSocialLogin.success'));
        $this->assertSame('PROVIDER_DISABLED', (string) $disabled->json('data.createSocialLogin.code'));

        ShopSetting::put('customers.social.linkedin.enabled', true);
        ShopSetting::put('customers.social.linkedin.client_id', 'wento-linkedin-client');
        ShopSetting::put('customers.social.linkedin.secret', 'wento-linkedin-secret');

        $email = 'w138-'.uniqid().'@example.com';
        Http::fake([
            'api.linkedin.com/v2/userinfo' => Http::response([
                'sub' => 'linkedin-sub-w138',
                'email' => $email,
                'given_name' => 'Wave',
                'family_name' => '138',
                'name' => 'Wave 138',
            ], 200),
        ]);

        $ok = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                createSocialLogin(input: {
                    provider: "linkedin"
                    accessToken: "test-linkedin-access-token"
                }) {
                    success
                    code
                    isNewCustomer
                    token
                    email
                    firstName
                    lastName
                    socialLogin {
                        success
                        token
                        isNewCustomer
                        email
                    }
                    customer { id email firstName }
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

        $customer = Customer::query()->where('email', $email)->first();
        $this->assertNotNull($customer);
        $this->assertSame('linkedin-sub-w138', (string) $customer->linkedin_id);

        Http::fake([
            'api.linkedin.com/v2/userinfo' => Http::response([
                'sub' => 'linkedin-sub-w138',
                'email' => $email,
                'given_name' => 'Wave',
                'family_name' => '138',
            ], 200),
        ]);

        $again = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                createSocialLogin(input: {
                    provider: "linkedin"
                    accessToken: "test-linkedin-access-token-2"
                }) {
                    success
                    isNewCustomer
                    email
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
