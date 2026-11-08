<?php

namespace Tests\Feature;

use App\Domain\Order\Models\Order;
use App\Domain\Payment\PaymentSettings;
use App\Domain\Security\CustomerPassword;
use App\Domain\Security\HtmlSanitizer;
use App\Domain\Security\SafeRedirect;
use App\Domain\Settings\ShopSetting;
use App\Domain\Customer\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SecurityHardeningLoopTest extends TestCase
{
    use RefreshDatabase;

    public function test_safe_redirect_blocks_open_redirects(): void
    {
        $this->assertNull(SafeRedirect::sanitize('//evil.example/phish'));
        $this->assertNull(SafeRedirect::sanitize('javascript:alert(1)'));
        $this->assertNull(SafeRedirect::sanitize('https://evil.example/pay'));
        $this->assertSame('/order/W-1', SafeRedirect::sanitize('/order/W-1'));
        $this->assertNotNull(SafeRedirect::sanitize('https://www.paypal.com/checkoutnow'));
    }

    public function test_html_sanitizer_used_for_catalog_short_description_xss(): void
    {
        $this->seed();
        $product = \App\Domain\Catalog\Models\Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $t = $product->translation('en');
        $t->forceFill([
            'short_description' => '<p>Nice <strong>sale</strong></p><script>alert(1)</script><img src=x onerror=alert(1)>',
            'description' => '<p>Body</p><script>alert(1)</script>',
        ])->save();

        $json = $this->getJson('/api/v1/catalog/product/'.$t->slug)->assertOk()->json('product');
        $short = (string) ($json['short_description'] ?? '');
        $this->assertStringNotContainsString('<script', strtolower($short));
        $this->assertStringNotContainsString('onerror', strtolower((string) ($json['description'] ?? '')));
        $this->assertStringContainsString('<p>', $short);
        $this->assertStringContainsString('<strong>sale</strong>', $short);
        $this->assertStringContainsString('Nice', $short);
        // Meta fallback must be plain text (no visible tags in SEO description).
        $seoDesc = (string) ($json['seo']['description'] ?? '');
        if ($seoDesc !== '') {
            $this->assertStringNotContainsString('<p>', $seoDesc);
            $this->assertStringNotContainsString('<strong>', $seoDesc);
        }
    }

    public function test_guest_lookup_defaults_off_and_requires_postal(): void
    {
        $this->seed();
        $this->assertFalse(\App\Domain\Settings\CommunityFeatures::guestOrderLookup());

        $this->postJson('/api/v1/orders/lookup', [
            'number' => 'NOPE',
            'email' => 'a@example.com',
        ])->assertNotFound();

        ShopSetting::put('orders.guest_lookup', true);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $order = Order::createTrusted([
            'number' => 'SEC-LOOKUP-1',
            'email' => 'buyer@example.com',
            'status' => 'pending',
            'currency_code' => 'USD',
            'subtotal' => 10,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 10,
            'payment_status' => 'pending',
            'billing_address' => ['postal_code' => '10001', 'city' => 'NYC', 'country_code' => 'US'],
            'shipping_address' => ['postal_code' => '10001', 'city' => 'NYC', 'country_code' => 'US'],
        ]);

        $this->postJson('/api/v1/orders/lookup', [
            'number' => $order->number,
            'email' => 'buyer@example.com',
        ])->assertStatus(422);

        $this->postJson('/api/v1/orders/lookup', [
            'number' => $order->number,
            'email' => 'buyer@example.com',
            'postal_code' => '99999',
        ])->assertNotFound();

        $this->postJson('/api/v1/orders/lookup', [
            'number' => $order->number,
            'email' => 'buyer@example.com',
            'postal_code' => '10001',
        ])->assertOk()->assertJsonPath('order.number', 'SEC-LOOKUP-1');
    }

    public function test_sandbox_pay_requires_signature_and_is_off_in_production_config(): void
    {
        $this->seed();
        $previous = $this->app['env'];
        $this->app['env'] = 'production';
        $this->assertFalse(PaymentSettings::sandboxPay());
        $this->app['env'] = $previous;

        ShopSetting::put('payments.sandbox_pay', true);
        $this->assertTrue(PaymentSettings::sandboxPay());

        $this->get('/pay/paypal/DOES-NOT-EXIST')->assertForbidden();

        $method = \App\Domain\Payment\Models\PaymentMethod::query()->firstOrCreate(
            ['code' => 'paypal-sec-test'],
            [
                'name' => 'PayPal',
                'driver' => 'paypal',
                'is_enabled' => true,
            ]
        );
        $method->forceFill(['driver' => 'paypal', 'is_enabled' => true])->save();

        $order = Order::createTrusted([
            'number' => 'SEC-PAY-1',
            'email' => 'pay@example.com',
            'status' => 'pending',
            'currency_code' => 'USD',
            'subtotal' => 10,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 10,
            'payment_status' => 'pending',
            'payment_method_id' => $method->id,
            'billing_address' => ['country_code' => 'US'],
            'shipping_address' => ['country_code' => 'US'],
        ]);

        $signed = URL::temporarySignedRoute('pay.show', now()->addHour(), [
            'driver' => 'paypal',
            'number' => $order->number,
        ]);
        $this->get($signed)->assertOk();
    }

    public function test_customer_password_rules_reject_weak_reset_style_passwords(): void
    {
        $rules = CustomerPassword::rules();
        $validator = validator(['password' => 'password', 'password_confirmation' => 'password'], [
            'password' => $rules,
        ]);
        $this->assertTrue($validator->fails());

        $ok = validator(['password' => 'Secure1x', 'password_confirmation' => 'Secure1x'], [
            'password' => $rules,
        ]);
        $this->assertFalse($ok->fails());

        $passphrase = str_repeat('Secure1x', 4);
        $long = validator(['password' => $passphrase, 'password_confirmation' => $passphrase], [
            'password' => $rules,
        ]);
        $this->assertFalse($long->fails());

        $tooLong = str_repeat('A', CustomerPassword::MAX_LENGTH).'1';
        $over = validator(['password' => $tooLong, 'password_confirmation' => $tooLong], [
            'password' => $rules,
        ]);
        $this->assertTrue($over->fails());
    }

    public function test_csp_uses_nonce_without_unsafe_script_eval(): void
    {
        $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString("'unsafe-eval'", $csp);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
        $this->assertMatchesRegularExpression("/script-src[^;]*'nonce-[A-Za-z0-9+\/=]+'/", $csp);
        $this->assertStringContainsString("frame-src 'none'", $csp);
    }

    public function test_customer_fillable_rejects_privilege_escalation_fields(): void
    {
        $customer = Customer::factory()->create(['is_enabled' => true, 'credit_limit' => 0]);
        $customer->fill([
            'is_enabled' => false,
            'credit_limit' => 99999,
            'name' => 'Safe Name',
        ])->save();
        $customer->refresh();
        $this->assertTrue((bool) $customer->is_enabled);
        $this->assertSame(0.0, (float) $customer->credit_limit);
        $this->assertSame('Safe Name', $customer->name);
    }

    public function test_safe_redirect_allows_same_app_http_signed_pay_urls(): void
    {
        config(['app.url' => 'http://localhost']);
        $url = 'http://localhost/pay/paypal/W-1?expires=1&signature=abc';
        $this->assertSame($url, SafeRedirect::sanitize($url));
    }

    public function test_maintenance_html_is_sanitized(): void
    {
        $dirty = '<p>Hi</p><img src=x onerror=alert(1)><a href="javascript:alert(1)">x</a>';
        $clean = \App\Domain\Settings\MaintenanceMessage::sanitize($dirty);
        $this->assertStringContainsString('Hi', $clean);
        $this->assertStringNotContainsString('onerror', strtolower($clean));
        $this->assertStringNotContainsString('javascript:', strtolower($clean));
    }

    public function test_sms_otp_not_revealed_when_debug_only(): void
    {
        config(['app.debug' => true]);
        $this->app['env'] = 'production';
        $this->assertFalse((new \App\Domain\Auth\SmsOtp)->revealInResponse());
        $this->app['env'] = 'testing';
        $this->assertTrue((new \App\Domain\Auth\SmsOtp)->revealInResponse());
    }

    public function test_customer_mfa_required_blocks_account_until_enrolled(): void
    {
        $this->seed();
        \App\Domain\Settings\ShopSetting::put('privacy.customer_mfa', true);
        $this->assertTrue(\App\Domain\Settings\PrivacySettings::customerMfaRequired());

        $customer = Customer::factory()->create([
            'totp_secret' => null,
            'totp_confirmed_at' => null,
        ]);
        $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/orders')
            ->assertForbidden()
            ->assertJsonPath('enroll_required', true);

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/auth/mfa/enroll')
            ->assertOk();
    }

    public function test_raster_image_upload_rejects_svg_extension(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'svg');
        file_put_contents($tmp, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $file = new \Illuminate\Http\UploadedFile($tmp, 'evil.svg', 'image/svg+xml', null, true);
        try {
            \App\Domain\Security\RasterImageUpload::assert($file);
            $this->fail('SVG should be rejected');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        } finally {
            @unlink($tmp);
        }
    }
}
