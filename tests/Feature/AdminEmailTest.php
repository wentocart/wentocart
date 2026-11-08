<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Settings\SendmailPath;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Settings\MailEvents;
use App\Domain\Settings\Models\EmailEventSetting;
use App\Domain\Settings\Models\EmailTemplate;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Mail\ShopHtmlMail;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_admin_can_open_email_settings(): void
    {
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->get('/admin/email')
            ->assertOk()
            ->assertSee('New customer created')
            ->assertSee('Forgot password');
    }

    public function test_admin_can_save_event_switches_but_cannot_disable_password_recovery(): void
    {
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->put('/admin/email/events', [
            'settings' => [
                ['event_code' => 'customer.created', 'recipient' => 'customer', 'is_enabled' => 0],
                ['event_code' => 'customer.created', 'recipient' => 'admin', 'is_enabled' => 1],
                ['event_code' => 'customer.forgot_password', 'recipient' => 'customer', 'is_enabled' => 0],
                ['event_code' => 'admin.forgot_password', 'recipient' => 'staff', 'is_enabled' => 1],
                ['event_code' => 'order.created', 'recipient' => 'customer', 'is_enabled' => 1],
                ['event_code' => 'order.created', 'recipient' => 'admin', 'is_enabled' => 1],
                ['event_code' => 'order.paid', 'recipient' => 'customer', 'is_enabled' => 1],
                ['event_code' => 'order.paid', 'recipient' => 'admin', 'is_enabled' => 1],
            ],
        ])->assertRedirect();

        $this->assertFalse(EmailEventSetting::enabled(MailEvents::CUSTOMER_CREATED, 'customer'));
        $this->assertTrue(EmailEventSetting::enabled(MailEvents::CUSTOMER_FORGOT_PASSWORD, 'customer'));
    }

    public function test_admin_can_toggle_seller_marketplace_mail(): void
    {
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->put('/admin/email/events', [
            'settings' => [
                ['event_code' => MailEvents::SELLER_APPROVED, 'recipient' => 'seller', 'is_enabled' => 0],
            ],
        ])->assertRedirect();

        $this->assertFalse(EmailEventSetting::enabled(MailEvents::SELLER_APPROVED, 'seller'));
    }

    public function test_old_email_settings_link_opens_the_email_page(): void
    {
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->get('/admin/email?tab=settings')
            ->assertRedirect(route('admin.email.index', ['tab' => 'smtp']));
    }

    public function test_admin_can_open_email_templates_tab(): void
    {
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->get('/admin/email?tab=templates')
            ->assertOk()
            ->assertSee('welcome');
    }

    public function test_admin_can_create_a_custom_template(): void
    {
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->post('/admin/email/templates', [
            'name' => 'Spring sale',
            'subject' => 'Hi {{customer_name}}',
            'body' => '<p>Hello {{customer_name}}</p>',
            'design_json' => '{"pages":[]}',
        ])->assertRedirect();

        $this->assertDatabaseHas('email_templates', [
            'name' => 'Spring sale',
            'is_custom' => 1,
            'recipient' => 'custom',
        ]);
    }

    public function test_admin_can_toggle_a_template(): void
    {
        $this->actingAs(Admin::query()->first(), 'admin');
        $welcome = EmailTemplate::query()->where('code', 'welcome')->firstOrFail();

        $this->post("/admin/email/templates/{$welcome->id}/toggle", ['is_enabled' => 0])->assertRedirect();
        $this->assertFalse((bool) $welcome->fresh()->is_enabled);

        $this->get('/admin/email?tab=templates')->assertOk();
        $this->assertFalse((bool) $welcome->fresh()->is_enabled);

        $this->post("/admin/email/templates/{$welcome->id}/toggle", ['is_enabled' => 1])->assertRedirect();
        $this->assertTrue((bool) $welcome->fresh()->is_enabled);

        $this->post("/admin/email/templates/{$welcome->id}/toggle")->assertRedirect();
        $this->assertFalse((bool) $welcome->fresh()->is_enabled);
    }

    public function test_admin_cannot_disable_password_recovery_template(): void
    {
        $this->actingAs(Admin::query()->first(), 'admin');
        $recovery = EmailTemplate::query()->where('code', 'forgot_password')->firstOrFail();

        $this->post("/admin/email/templates/{$recovery->id}/toggle", ['is_enabled' => 0])->assertRedirect();
        $this->assertTrue((bool) $recovery->fresh()->is_enabled);
    }

    public function test_admin_bulk_delete_skips_system_templates(): void
    {
        $this->actingAs(Admin::query()->first(), 'admin');
        $welcome = EmailTemplate::query()->where('code', 'welcome')->firstOrFail();
        $custom = EmailTemplate::query()->create([
            'code' => 'spring-sale-bulk',
            'name' => 'Spring sale bulk',
            'subject' => 'Hi',
            'body' => '<p>Hi</p>',
            'is_custom' => true,
            'is_enabled' => true,
            'recipient' => 'custom',
        ]);

        $this->post('/admin/email/templates/bulk-delete', [
            'ids' => [$welcome->id, $custom->id],
        ])->assertRedirect();

        $this->assertDatabaseHas('email_templates', ['id' => $welcome->id]);
        $this->assertDatabaseMissing('email_templates', ['id' => $custom->id]);
    }

    public function test_register_sends_welcome_and_admin_mail_when_enabled(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Pat Shopper',
            'email' => 'pat.mail@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertOk();

        Mail::assertSent(ShopHtmlMail::class, 2);
        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('pat.mail@example.com')
            && str_contains($mail->emailSubject, 'Welcome'));
        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('admin@wentocart.test'));
    }

    public function test_welcome_email_is_skipped_when_customer_toggle_is_off(): void
    {
        EmailEventSetting::query()->updateOrCreate(
            ['event_code' => MailEvents::CUSTOMER_CREATED, 'recipient' => 'customer'],
            ['is_enabled' => false],
        );
        Mail::fake();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Pat Shopper',
            'email' => 'pat.off@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertOk();

        Mail::assertSent(ShopHtmlMail::class, 1);
        Mail::assertNotSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('pat.off@example.com'));
        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('admin@wentocart.test'));
    }

    public function test_forgot_password_still_sends_when_admin_tries_to_disable_it(): void
    {
        EmailEventSetting::query()->updateOrCreate(
            ['event_code' => MailEvents::CUSTOMER_FORGOT_PASSWORD, 'recipient' => 'customer'],
            ['is_enabled' => false],
        );
        $customer = Customer::factory()->create(['email' => 'resetme@example.com']);
        Mail::fake();

        $this->postJson('/api/v1/auth/forgot', ['email' => $customer->email])->assertOk();

        Mail::assertSent(ShopHtmlMail::class, 1);
        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('resetme@example.com')
            && str_contains($mail->emailSubject, 'Reset'));
    }

    public function test_storefront_checkout_sends_order_created_mail(): void
    {
        Mail::fake();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $payment = PaymentMethod::query()->firstOrFail();
        $shipping = ShippingMethod::query()->firstOrFail();

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', [
            'email' => 'guest.mail@example.com',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated();

        Mail::assertSent(ShopHtmlMail::class, 2);
        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('guest.mail@example.com')
            && str_contains($mail->emailSubject, 'Order'));
        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('admin@wentocart.test'));
    }

    public function test_marking_an_order_paid_sends_payment_mail(): void
    {
        Mail::fake();

        $order = Order::createTrusted([
            'number' => 'WENTO-MAIL-1',
            'email' => 'buyer.mail@example.com',
            'status' => 'pending',
            'currency_code' => 'USD',
            'currency_rate' => 1,
            'subtotal' => 10,
            'tax_total' => 0,
            'tax_details' => [],
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 10,
            'payment_status' => 'pending',
            'billing_address' => ['first_name' => 'Ada'],
            'shipping_address' => [],
        ]);

        Mail::assertNothingSent();

        $order->update(['payment_status' => 'paid']);

        Mail::assertSent(ShopHtmlMail::class, 2);
        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('buyer.mail@example.com')
            && str_contains($mail->emailSubject, 'Payment'));
        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('admin@wentocart.test'));
    }

    public function test_email_logs_live_on_the_email_page(): void
    {
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->get('/admin/email?tab=logs')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Email')
                ->where('tab', 'logs')
                ->has('email_logs'));

        $this->get('/admin/advanced?tab=email')
            ->assertRedirect('/admin/email?tab=logs');
    }

    public function test_template_test_sends_the_current_html(): void
    {
        Mail::fake();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->post('/admin/email/smtp/test', [
            'to' => 'buyer.mail@example.com',
            'subject' => 'Hello {{customer_name}}',
            'html' => '<p>Template body</p>',
        ])->assertRedirect();

        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('buyer.mail@example.com')
            && $mail->emailSubject === 'Hello {{customer_name}}'
            && str_contains($mail->htmlBody, 'Template body'));
    }

    public function test_sendmail_without_a_server_program_explains_the_failure(): void
    {
        if (SendmailPath::resolve() !== null) {
            $this->markTestSkipped('This PHP already has a sendmail program.');
        }

        $this->actingAs(Admin::query()->first(), 'admin');

        $this->put('/admin/email/smtp', [
            'mailer' => 'sendmail',
            'from_address' => 'shop@example.com',
            'from_name' => 'Shop',
        ])->assertRedirect();

        $this->post('/admin/email/smtp/test', [
            'to' => 'buyer.mail@example.com',
        ])->assertRedirect()->assertSessionHas('error');
    }

}
