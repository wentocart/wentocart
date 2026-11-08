<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Plugin\PluginManifest;
use App\Domain\Plugin\Support\ModuleAuth;
use App\Domain\Plugin\Support\ModuleBuses;
use App\Domain\Plugin\Support\ModuleCheckout;
use App\Domain\Plugin\Support\ModulePdf;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Mail\ShopHtmlMail;
use App\Domain\Settings\Models\EmailTemplate;
use App\Support\Hooks\HookManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ModuleCheckoutFiltersAuthPdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        HookManager::flush();
        ModuleBuses::flush();
    }

    public function test_payment_rules_and_extension_filter_hide_methods(): void
    {
        $cash = PaymentMethod::query()->where('code', 'cash')->first()
            ?? PaymentMethod::query()->firstOrFail();
        $other = PaymentMethod::query()->where('id', '!=', $cash->id)->firstOrFail();

        $cash->update([
            'rules' => array_merge(PaymentMethod::emptyRules(), [
                'customer_group_ids' => [999999],
            ]),
        ]);

        $this->checkoutApi(['checkout.payment.filter'])->filterPayments(function () use ($other) {
            return ['hide_ids' => [(int) $other->id]];
        });

        $customer = Customer::factory()->create(['customer_group' => 'retailer']);
        $product = Product::query()->where('is_enabled', true)->firstOrFail();
        $carts = app(CartService::class);
        $cart = $carts->current($customer);
        $carts->add($cart, $product, 1);

        $this->actingAs($customer, 'customer');
        $ids = collect($this->getJson('/api/v1/checkout/options')->json('payment_methods'))->pluck('id')->all();

        $this->assertNotContains((int) $cash->id, $ids);
        $this->assertNotContains((int) $other->id, $ids);
    }

    public function test_username_login_via_auth_resolve(): void
    {
        $customer = Customer::factory()->create([
            'email' => 'ada@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->authApi(['auth.login.resolve'])->resolveLogin(function (string $id) {
            return $id === 'ada' ? 'ada@example.com' : null;
        });

        // Boot resolvers are registered; PluginKernel not booting apps in unit style —
        // resolveLogin registers on ModuleBuses directly via ModuleAuth.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'ada',
            'password' => 'password123',
        ])->assertOk()->assertJsonPath('customer.email', 'ada@example.com');

        $this->assertNotNull($customer->fresh());
    }

    public function test_pdf_render_and_mail(): void
    {
        Mail::fake();
        EmailTemplate::query()->create([
            'code' => 'product_pdf',
            'name' => 'Product PDF',
            'subject' => 'Your brochure',
            'body' => '<p>Attached</p>',
            'is_enabled' => true,
            'is_custom' => true,
            'recipient' => 'custom',
        ]);

        $pdf = $this->pdfApi(['documents.pdf']);
        $binary = $pdf->renderHtml('<h1>Mug</h1><p>SKU DEMO</p>');
        $this->assertNotSame('', $binary);

        $pdf->mail('product_pdf', 'buyer@example.com', ['product_name' => 'Mug'], '<h1>Mug</h1>', 'mug.pdf');
        Mail::assertSent(ShopHtmlMail::class, function (ShopHtmlMail $mail) {
            return $mail->hasTo('buyer@example.com') && count($mail->attachments()) === 1;
        });
    }

    public function test_shipping_filter_hides_by_code(): void
    {
        $pickup = ShippingMethod::query()->where('type', 'local_pickup')->first()
            ?? ShippingMethod::query()->firstOrFail();

        $this->checkoutApi(['checkout.shipping.filter'])->filterShipping(function () use ($pickup) {
            return ['hide_codes' => [(string) $pickup->code]];
        });

        $this->getJson('/api/v1/checkout/options')
            ->assertOk();

        $codes = collect($this->getJson('/api/v1/checkout/options')->json('shipping_methods'))->pluck('code')->all();
        $this->assertNotContains($pickup->code, $codes);
    }

    /**
     * @param  list<string>  $capabilities
     */
    protected function checkoutApi(array $capabilities): ModuleCheckout
    {
        return new ModuleCheckout($this->manifest($capabilities));
    }

    /**
     * @param  list<string>  $capabilities
     */
    protected function authApi(array $capabilities): ModuleAuth
    {
        return new ModuleAuth($this->manifest($capabilities));
    }

    /**
     * @param  list<string>  $capabilities
     */
    protected function pdfApi(array $capabilities): ModulePdf
    {
        return new ModulePdf($this->manifest($capabilities));
    }

    /**
     * @param  list<string>  $capabilities
     */
    protected function manifest(array $capabilities): PluginManifest
    {
        $dir = storage_path('framework/testing/'.uniqid('flt_', true));
        mkdir($dir);
        file_put_contents($dir.'/app.json', json_encode([
            'vendor' => 'acme',
            'name' => 'filters',
            'display_name' => 'Filters',
            'version' => '1.0.0',
            'author' => 'test',
            'type' => 'safe',
            'description' => 'test',
            'capabilities' => $capabilities,
        ]));

        return PluginManifest::fromArray(
            json_decode((string) file_get_contents($dir.'/app.json'), true, 512, JSON_THROW_ON_ERROR),
            $dir,
            false
        );
    }
}
