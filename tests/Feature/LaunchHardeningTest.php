<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\CustomizationService;
use App\Domain\Plugin\PluginManifest;
use App\Domain\Plugin\SecurityScanner;
use App\Domain\Security\CsvSafe;
use App\Domain\Security\HtmlSanitizer;
use App\Domain\Security\SafeUrl;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class LaunchHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_are_present(): void
    {
        $this->get('/install')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_graphql_endpoints_carry_security_headers(): void
    {
        $this->postJson('/api/v1/graphql', ['query' => '{ __typename }'])
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->postJson('/api/v1/console/graphql', ['query' => '{ __typename }'])
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_safe_url_rejects_ssrf_targets(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SafeUrl::assertPublicHttp('http://127.0.0.1/latest/meta-data');
    }

    public function test_safe_url_rejects_localhost_https(): void
    {
        $this->assertFalse(SafeUrl::isSafe('https://localhost/admin'));
        $this->assertFalse(SafeUrl::isSafe('https://169.254.169.254/latest/meta-data'));
        $this->assertFalse(SafeUrl::isSafe('http://192.168.1.1/'));
        $this->assertFalse(SafeUrl::isSafe('https://user:pass@example.com/hook'));
        $this->assertFalse(SafeUrl::isSafe('https://2130706433/hook'));
        $this->assertFalse(SafeUrl::isSafe('https://[fc00::1]/hook'));
        $this->assertTrue(SafeUrl::isSafe('https://example.com/hook'));
    }

    public function test_html_sanitizer_strips_scripts_and_handlers(): void
    {
        $clean = HtmlSanitizer::clean('<p>Hi</p><script>alert(1)</script><a href="javascript:alert(1)">x</a><img src=x onerror=alert(1)>');
        $this->assertStringContainsString('<p>Hi</p>', $clean);
        $this->assertStringNotContainsString('script', strtolower($clean));
        $this->assertStringNotContainsString('javascript:', strtolower($clean));
        $this->assertStringNotContainsString('onerror', strtolower($clean));
    }

    public function test_csv_safe_prefixes_formula_cells(): void
    {
        $this->assertSame("'=cmd|'/C calc'!A0", CsvSafe::cell('=cmd|\'/C calc\'!A0'));
        $this->assertSame('Hello', CsvSafe::cell('Hello'));
        $row = CsvSafe::row(['id' => 1, 'name' => '+1234', 'note' => 'ok']);
        $this->assertSame("'+1234", $row['name']);
        $this->assertSame('ok', $row['note']);
    }

    public function test_csv_safe_quotes_comma_values_for_spreadsheet_compat(): void
    {
        $handle = fopen('php://temp', 'r+');
        CsvSafe::put($handle, ['meta_keywords' => 'wholesale,supply', 'email' => 'a@example.com'], ';');
        rewind($handle);
        $line = trim((string) stream_get_contents($handle));
        fclose($handle);

        $this->assertStringContainsString('"wholesale,supply"', $line);
        $cells = str_getcsv($line, ';');
        $this->assertSame('wholesale,supply', $cells[0]);
        $this->assertSame('a@example.com', $cells[1]);
    }

    public function test_plugin_scanner_rejects_call_user_func(): void
    {
        $scanner = new SecurityScanner;
        $manifest = PluginManifest::fromArray([
            'vendor' => 'acme',
            'name' => 'safeapp',
            'type' => 'safe',
            'capabilities' => ['hook.listen'],
            'hooks' => ['storefront.header.notice'],
        ], sys_get_temp_dir());

        try {
            $scanner->assertPhp("<?php\ncall_user_func('phpinfo');\n", 'src/Helper.php', $manifest);
            $this->fail('call_user_func should be rejected');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    public function test_cart_summary_ignores_client_shipping_amount(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $shipping = ShippingMethod::query()->where('is_enabled', true)->firstOrFail();

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();

        $spoofed = $this->getJson('/api/v1/cart?shipping=9999')->assertOk();
        $this->assertSame(0, (int) $spoofed->json('totals.shipping'));

        $quoted = $this->getJson('/api/v1/cart?shipping_method_id='.$shipping->id)->assertOk();
        $shippingTotal = (float) $quoted->json('totals.shipping');
        $this->assertGreaterThan(0, $shippingTotal);
        $this->assertNotSame(9999.0, $shippingTotal);
    }

    public function test_customization_rejects_php_upload_even_if_allowlisted(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->update(['customization_enabled' => true]);
        $field = $product->customizationFields()->create([
            'type' => 'file',
            'label' => 'Artwork',
            'is_required' => false,
            'position' => 0,
            'config' => ['allowed_file_types' => 'php,pdf'],
        ]);

        $file = UploadedFile::fake()->create('shell.php', 20, 'application/x-php');

        try {
            app(CustomizationService::class)->resolve($product->fresh(), [], [$field->id => $file]);
            $this->fail('php upload should be rejected');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    public function test_unsigned_stripe_webhook_is_rejected(): void
    {
        $path = base_path('extensions/weblytic/wtsstripe/src/Support/StripeGateway.php');
        if (! is_file($path)) {
            $this->markTestSkipped('Stripe is installed from the marketplace, not this source tree.');
        }
        $src = (string) file_get_contents($path);
        $this->assertStringContainsString("webhookSecret === ''", $src);
        $this->assertStringContainsString('Webhook secret is not configured.', $src);
        $this->assertStringNotContainsString('if ($this->webhookSecret !== \'\')', $src);
    }

    public function test_webhook_localhost_is_rejected(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->from('/admin/ops-tools')->post('/admin/ops-tools/webhooks', [
            'name' => 'Local',
            'url' => 'http://127.0.0.1/steal',
            'events' => ['*'],
        ])->assertSessionHasErrors('url');
    }

    public function test_release_check_passes_outside_production(): void
    {
        $this->artisan('wento:release-check')->assertSuccessful();
    }
}
