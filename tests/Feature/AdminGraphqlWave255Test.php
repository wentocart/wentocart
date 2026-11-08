<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminGraphqlWave255Test extends TestCase
{
    use RefreshDatabase;

    public function test_shop_social_logins_rest_beat_rest_graphql_only(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $disabled = $this->postJson('/api/v1/store/social-logins', [
            'provider' => 'linkedin',
            'accessToken' => 'li-token',
        ]);
        $disabled->assertStatus(400);
        $this->assertFalse((bool) $disabled->json('success'));
        $this->assertSame('PROVIDER_DISABLED', (string) $disabled->json('code'));

        ShopSetting::put('customers.social.linkedin.enabled', true);
        ShopSetting::put('customers.social.linkedin.client_id', 'wento-linkedin-client');
        ShopSetting::put('customers.social.linkedin.secret', 'wento-linkedin-secret');

        $email = 'w255-'.uniqid().'@example.com';
        Http::fake([
            'api.linkedin.com/v2/userinfo' => Http::response([
                'sub' => 'linkedin-sub-w255',
                'email' => $email,
                'given_name' => 'Wave',
                'family_name' => '255',
                'name' => 'Wave 255',
            ], 200),
        ]);

        $ok = $this->postJson('/api/v1/store/social-logins', [
            'provider' => 'linkedin',
            'accessToken' => 'test-linkedin-access-token',
        ]);
        $ok->assertCreated();
        $this->assertTrue((bool) $ok->json('success'));
        $this->assertNull($ok->json('code'));
        $this->assertTrue((bool) $ok->json('isNewCustomer'));
        $this->assertSame($email, (string) $ok->json('email'));
        $this->assertSame('Wave', (string) $ok->json('firstName'));
        $this->assertNotEmpty((string) $ok->json('token'));

        $customer = Customer::query()->where('email', $email)->first();
        $this->assertNotNull($customer);
        $this->assertSame('linkedin-sub-w255', (string) $customer->linkedin_id);

        $again = $this->postJson('/api/v1/store/social-logins', [
            'provider' => 'linkedin',
            'accessToken' => 'test-linkedin-access-token',
        ]);
        $again->assertCreated();
        $this->assertTrue((bool) $again->json('success'));
        $this->assertFalse((bool) $again->json('isNewCustomer'));
    }
}
