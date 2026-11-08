<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Settings\CustomerSettings;
use App\Domain\Settings\ShopSetting;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLoginMethodsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
    }

    public function test_admin_can_toggle_login_methods_with_last_method_guard(): void
    {
        $this->admin();

        // Email is the only method on: disabling it is refused.
        $this->post('/admin/store/community', ['key' => 'login_email_password', 'enabled' => 0])
            ->assertSessionHasErrors('enabled');
        $this->assertTrue(CustomerSettings::loginEmailPassword());

        $this->post('/admin/store/community', ['key' => 'login_mobile_otp', 'enabled' => 1])
            ->assertRedirect();
        $this->assertTrue(CustomerSettings::loginMobileOtp());

        // With two methods on, one can be switched off.
        $this->post('/admin/store/community', ['key' => 'login_mobile_password', 'enabled' => 1])
            ->assertRedirect();
        $this->post('/admin/store/community', ['key' => 'login_email_password', 'enabled' => 0])
            ->assertRedirect();
        $this->assertFalse(CustomerSettings::loginEmailPassword());
        $this->assertSame(['mobile_password', 'mobile_otp'], CustomerSettings::loginMethods());
    }

    public function test_admin_sms_provider_and_policy_are_validated_and_clamped(): void
    {
        $this->admin();

        $this->post('/admin/store/community', ['key' => 'sms_driver', 'value' => 'smoke'])
            ->assertSessionHasErrors('value');

        $this->post('/admin/store/community', ['key' => 'sms_driver', 'value' => 'twilio'])
            ->assertRedirect();
        $this->assertSame('twilio', ShopSetting::get('auth.sms_driver'));

        $this->post('/admin/store/community', ['key' => 'sms_twilio_sid', 'value' => 'AC123'])
            ->assertRedirect();
        $this->post('/admin/store/community', ['key' => 'sms_twilio_from', 'value' => '+15550001111'])
            ->assertRedirect();
        $this->post('/admin/store/community', ['key' => 'sms_twilio_token', 'value' => 'secret-token'])
            ->assertRedirect();
        $this->assertSame('secret-token', ShopSetting::get('auth.sms_twilio_token'));

        // Blank token keeps the stored secret.
        $this->post('/admin/store/community', ['key' => 'sms_twilio_token', 'value' => ''])
            ->assertRedirect();
        $this->assertSame('secret-token', ShopSetting::get('auth.sms_twilio_token'));

        // Out-of-range policy values are clamped server-side.
        $this->post('/admin/store/community', ['key' => 'otp_expiry_minutes', 'value' => '99'])
            ->assertRedirect();
        $this->post('/admin/store/community', ['key' => 'otp_max_attempts', 'value' => '1'])
            ->assertRedirect();
        $this->post('/admin/store/community', ['key' => 'otp_resend_seconds', 'value' => '9999'])
            ->assertRedirect();
        $this->assertSame(10, CustomerSettings::otpExpiryMinutes());
        $this->assertSame(3, CustomerSettings::otpMaxAttempts());
        $this->assertSame(300, CustomerSettings::otpResendSeconds());
    }

    public function test_email_password_login_finds_customers_without_store_id(): void
    {
        $this->seed();
        $customer = Customer::factory()->create();
        $customer->forceFill(['store_id' => null])->save();

        $this->postJson('/api/v1/auth/login', [
            'email' => $customer->email,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('customer.email', $customer->email);
    }

    public function test_email_password_login_is_rejected_when_method_is_off(): void
    {
        $this->admin();
        $this->post('/admin/store/community', ['key' => 'login_mobile_password', 'enabled' => 1])
            ->assertRedirect();
        $this->post('/admin/store/community', ['key' => 'login_email_password', 'enabled' => 0])
            ->assertRedirect();

        $customer = Customer::factory()->create();
        $this->postJson('/api/v1/auth/login', [
            'email' => $customer->email,
            'password' => 'password',
        ])->assertStatus(403);
    }
}
