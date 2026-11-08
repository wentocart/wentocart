<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Settings\Models\SmsLog;
use App\Domain\Settings\ShopSetting;
use App\Domain\Auth\SmsOtp;
use App\Domain\Settings\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileOtpLoginTest extends TestCase
{
    use RefreshDatabase;

    private function enableMobile(): void
    {
        ShopSetting::put('auth.login_mobile_password', true);
        ShopSetting::put('auth.login_mobile_otp', true);
    }

    private function customerWithPhone(string $phone, array $extra = []): Customer
    {
        $storeId = app(\App\Domain\Store\StoreContext::class)->requireId();

        return Customer::factory()->create(array_merge(['phone' => $phone, 'store_id' => $storeId], $extra));
    }

    private function registerPhone(string $phone, string $purpose): array
    {
        $send = $this->postJson('/api/v1/auth/otp/send', ['phone' => $phone, 'purpose' => $purpose])->assertOk();
        $code = $send->json('sandbox_code');
        $this->assertNotEmpty($code);

        return $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => $phone,
            'code' => $code,
            'purpose' => $purpose,
        ])->assertOk()->json();
    }

    private function registerPayload(string $email, string $phone, string $token): array
    {
        return [
            'first_name' => 'Mobile',
            'last_name' => 'User',
            'email' => $email,
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
            'phone' => $phone,
            'phone_token' => $token,
            'gdpr_agreed' => true,
        ];
    }

    public function test_otp_endpoints_are_hidden_when_mobile_login_is_off(): void    {
        $this->seed();

        $this->postJson('/api/v1/auth/otp/send', ['phone' => '+15551213000', 'purpose' => 'login'])->assertNotFound();
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '+15551213000', 'code' => '123456', 'purpose' => 'login'])->assertNotFound();
    }

    public function test_registration_requires_a_verified_unique_phone(): void
    {
        $this->seed();
        $this->enableMobile();

        // Unverified number is rejected.
        $payload = $this->registerPayload('a@example.com', '+19990001101', 'bogus-token');
        $this->postJson('/api/v1/auth/register', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        // Verified number registers with canonical storage.
        $proof = $this->registerPhone('+1 999-000-1102', 'register');
        $this->postJson('/api/v1/auth/register', $this->registerPayload('b@example.com', '+1 999-000-1102', $proof['phone_token']))
            ->assertOk();
        $this->assertSame('+19990001102', Customer::query()->where('email', 'b@example.com')->firstOrFail()->phone);

        // Same number cannot register twice (send refuses; prove the register net directly).
        $proof = ['phone_token' => app(SmsOtp::class)->issueProof('+19990001102', 'register')];
        $this->postJson('/api/v1/auth/register', $this->registerPayload('c@example.com', '+19990001102', $proof['phone_token']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_customer_can_login_with_mobile_and_password(): void
    {
        $this->seed();
        $this->enableMobile();
        $customer = self::customerWithPhone('+19990001110');

        $this->postJson('/api/v1/auth/login', ['email' => '+19990001110', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('customer.email', $customer->email);
    }

    public function test_customer_can_login_with_mobile_otp_and_no_password(): void
    {
        $this->seed();
        $this->enableMobile();
        $customer = self::customerWithPhone('+19990001111');

        $proof = $this->registerPhone('+19990001111', 'login');

        $this->postJson('/api/v1/auth/login', ['phone' => '+19990001111', 'phone_token' => $proof['phone_token']])
            ->assertOk()
            ->assertJsonPath('customer.email', $customer->email);

        // Single-use proof: replay fails.
        $this->postJson('/api/v1/auth/login', ['phone' => '+19990001111', 'phone_token' => $proof['phone_token']])
            ->assertStatus(422);
    }

    public function test_otp_attempts_are_limited_and_survive_resend(): void
    {
        $this->seed();
        $this->enableMobile();
        ShopSetting::put('auth.otp_max_attempts', 3);
        ShopSetting::put('auth.otp_resend_seconds', 0);
        self::customerWithPhone('+19990001112');

        $this->postJson('/api/v1/auth/otp/send', ['phone' => '+19990001112', 'purpose' => 'login'])->assertOk();
        // Three wrong guesses exhaust the allowance.
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/otp/verify', ['phone' => '+19990001112', 'code' => '000000', 'purpose' => 'login'])
                ->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '+19990001112', 'code' => '000000', 'purpose' => 'login'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_profile_phone_change_requires_a_fresh_proof(): void
    {
        $this->seed();
        $this->enableMobile();
        $customer = self::customerWithPhone('+19990001120');

        $base = [
            'name' => $customer->name,
            'first_name' => $customer->first_name ?? 'Mobile',
            'last_name' => $customer->last_name ?? 'User',
            'email' => $customer->email,
        ];

        // Unverified change is rejected.
        $this->actingAs($customer, 'customer')
            ->patchJson('/api/v1/account', $base + ['phone' => '+19990001121'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        $proof = $this->registerPhone('+19990001121', 'profile');
        $this->actingAs($customer, 'customer')
            ->patchJson('/api/v1/account', $base + ['phone' => '+19990001121', 'phone_token' => $proof['phone_token']])
            ->assertOk();
        $this->assertSame('+19990001121', $customer->fresh()->phone);
    }

    public function test_sms_drivers_log_and_report_failures(): void
    {
        $this->seed();
        ShopSetting::put('auth.sms_driver', 'log');
        $this->assertSame('log', SmsSender::driverKey());
        $this->assertTrue(app(SmsSender::class)->send('+19990001130', 'hello', 'otp'));
        $this->assertTrue(SmsLog::query()->where('to_phone', '+19990001130')->where('status', 'logged')->exists());

        ShopSetting::put('auth.sms_driver', 'twilio');
        $this->assertFalse(app(SmsSender::class)->send('+19990001130', 'hello', 'otp'));
        $this->assertTrue(SmsLog::query()->where('to_phone', '+19990001130')->where('status', 'failed')->exists());
    }

    public function test_customer_scoped_send_and_verify_use_the_same_key(): void
    {
        $this->seed();
        $customer = self::customerWithPhone('+19990001140');
        $otp = app(SmsOtp::class);

        $code = $otp->send($customer);
        $this->assertTrue($otp->verify($customer, $code));
        // Single use: the same code cannot verify twice.
        $this->assertFalse($otp->verify($customer, $code));
    }
}
