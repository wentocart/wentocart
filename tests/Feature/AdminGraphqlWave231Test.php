<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminGraphqlWave231Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_shop_customer_login_and_profile_rest(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $storeId = app(StoreContext::class)->requireId();
        Customer::createTrusted([
            'store_id' => $storeId,
            'name' => 'Ada Lovelace',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada231@example.test',
            'password' => 'Password123',
            'is_enabled' => true,
            'email_verified_at' => now(),
        ]);

        $this->postJson('/api/v1/store/customer/login', [
            'email' => 'ada231@example.test',
            'password' => 'wrong',
        ])->assertStatus(401)->assertJsonPath('success', false);

        $login = $this->postJson('/api/v1/store/customer/login', [
            'email' => 'ada231@example.test',
            'password' => 'Password123',
        ]);
        $login->assertCreated();
        $token = (string) $login->json('token');
        $this->assertStringContainsString('|', $token);

        $profile = $this->withToken($token)->getJson('/api/v1/store/customer-profile');
        $profile->assertOk();
        $this->assertSame('Ada', $profile->json('0.firstName'));
        $this->assertSame('ada231@example.test', $profile->json('0.email'));

        $this->withToken($token)->getJson('/api/v1/store/customer-orders')
            ->assertOk()
            ->assertExactJson([]);

        $logout = $this->withToken($token)->postJson('/api/v1/store/customer/logout', []);
        $logout->assertOk()->assertJsonPath('message', 'Logged out successfully.');
    }

    public function test_rest_shop_customer_register_rest(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);
        ShopSetting::put('customers.registration_enabled', true);
        Auth::guard('customer')->logout();
        $this->app['auth']->forgetGuards();

        $reg = $this->postJson('/api/v1/store/customers', [
            'firstName' => 'Grace',
            'lastName' => 'Hopper',
            'email' => 'grace231@example.test',
            'password' => 'Password123',
            'confirmPassword' => 'Password123',
            'subscribedToNewsLetter' => true,
        ]);
        $reg->assertCreated((string) $reg->getContent());
        $token = (string) $reg->json('token');
        $this->assertNotEmpty($token);
        $this->assertSame('Grace', $reg->json('firstName'));
        $this->assertTrue((bool) $reg->json('subscribedToNewsLetter'));

        $profile = $this->withToken($token)->getJson('/api/v1/store/customer-profile');
        $profile->assertOk();
        $this->assertSame('Grace', $profile->json('0.firstName'));
    }
}
