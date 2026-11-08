<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AdminGraphqlWave248Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_shop_forgot_and_reset_passwords_rest(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);
        Notification::fake();

        $storeId = app(StoreContext::class)->requireId();
        $customer = Customer::factory()->create([
            'store_id' => $storeId,
            'email' => 'reset.w248@example.com',
            'password' => Hash::make('OldPass123!'),
        ]);

        $unknown = $this->postJson('/api/v1/store/forgot-passwords', [
            'email' => 'nobody.w248@example.com',
        ]);
        $unknown->assertOk();
        // Anti-enumeration: same success body as a registered address
        $this->assertTrue((bool) $unknown->json('success'));
        $this->assertStringContainsString('Reset link', (string) $unknown->json('message'));

        $sent = $this->postJson('/api/v1/store/forgot-passwords', [
            'email' => $customer->email,
        ]);
        $sent->assertOk();
        $this->assertTrue((bool) $sent->json('success'));

        $throttled = $this->postJson('/api/v1/store/forgot-passwords', [
            'email' => $customer->email,
        ]);
        $throttled->assertOk();
        $this->assertFalse((bool) $throttled->json('success'));

        $badEmail = $this->postJson('/api/v1/store/forgot-passwords', [
            'email' => 'not-an-email',
        ]);
        $badEmail->assertOk();
        $this->assertFalse((bool) $badEmail->json('success'));

        $token = Password::broker('customers')->createToken($customer);
        $reset = $this->postJson('/api/v1/store/reset-passwords', [
            'email' => $customer->email,
            'token' => $token,
            'password' => 'NewPass123!',
            'confirmPassword' => 'NewPass123!',
        ]);
        $reset->assertOk();
        $this->assertTrue((bool) $reset->json('success'));
        $this->assertTrue(Hash::check('NewPass123!', (string) $customer->fresh()->password));

        $badToken = $this->postJson('/api/v1/store/reset-passwords', [
            'email' => $customer->email,
            'token' => 'bogus-token',
            'password' => 'AnotherPass123!',
            'confirmPassword' => 'AnotherPass123!',
        ]);
        $badToken->assertStatus(400);
        $this->assertFalse((bool) $badToken->json('success'));

        $landing = $this->getJson('/api/json');
        $landing->assertOk();
        $highlights = $landing->json('highlights_wento') ?? [];
        $this->assertTrue(
            collect($highlights)->contains(fn ($h) => str_contains((string) $h, 'reset-passwords')),
            'landing should highlight headless reset-passwords'
        );
    }
}
