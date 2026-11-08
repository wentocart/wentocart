<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\CustomerStockAlert;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\CustomerStockAlertService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Settings\ProductSettings;
use App\Domain\Settings\ShopMailer;
use App\Domain\Settings\ShopSetting;
use App\Mail\ShopHtmlMail;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class CustomerStockAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Product::query()->update([
            'notify_low_stock' => false,
            'notify_out_of_stock' => false,
            'low_stock_notified_at' => null,
        ]);
    }

    protected function enableStockAlerts(): void
    {
        ShopSetting::put('products.out_of_stock_notification', true);
    }

    protected function disableStockAlerts(): void
    {
        ShopSetting::put('products.out_of_stock_notification', false);
    }

    protected function markOutOfStock(Product $product): Product
    {
        $product->update([
            'stock_qty' => 0,
            'stock_status' => 'out_of_stock',
            'manage_stock' => true,
            'allow_backorder' => false,
            'notify_low_stock' => false,
            'notify_out_of_stock' => false,
        ]);

        return $product->fresh();
    }

    protected function markInStock(Product $product, float $qty = 5): Product
    {
        $product->update([
            'stock_qty' => $qty,
            'stock_status' => 'in_stock',
            'manage_stock' => true,
            'notify_low_stock' => false,
            'notify_out_of_stock' => false,
        ]);

        return $product->fresh();
    }

    public function test_global_stock_alert_setting_defaults_to_off(): void
    {
        $this->assertFalse(ProductSettings::outOfStockNotification());
        $this->getJson('/api/v1/store')
            ->assertOk()
            ->assertJsonPath('products.out_of_stock_notification', false);
    }

    public function test_setting_can_be_enabled_disabled_and_persists(): void
    {
        $admin = Admin::query()->firstOrFail();
        $payload = array_merge(
            ProductSettings::defaultsPayload(),
            ProductSettings::catalogPayload(),
            ProductSettings::contentPayload(),
            [
                'row_highlight_color' => ProductSettings::color(),
                'row_highlight_ms' => ProductSettings::durationMs(),
                'out_of_stock_notification' => true,
            ],
        );

        $this->actingAs($admin, 'admin')->put('/admin/product-settings', $payload)->assertRedirect();
        $this->assertTrue(ProductSettings::outOfStockNotification());

        $payload['out_of_stock_notification'] = false;
        $this->actingAs($admin, 'admin')->put('/admin/product-settings', $payload)->assertRedirect();
        $this->assertFalse(ProductSettings::outOfStockNotification());
    }

    public function test_notify_payload_disabled_when_feature_off(): void
    {
        $this->disableStockAlerts();
        $product = $this->markOutOfStock(Product::query()->where('sku', 'DEMO-001')->firstOrFail());
        $slug = $product->translation()?->slug ?: 'demo-001';

        $this->getJson('/api/v1/catalog/product/'.$slug)
            ->assertOk()
            ->assertJsonPath('product.stock_alert.enabled', false);
    }

    public function test_in_stock_product_rejects_subscription(): void
    {
        $this->enableStockAlerts();
        $product = $this->markInStock(Product::query()->where('sku', 'DEMO-001')->firstOrFail());
        $customer = Customer::factory()->create();

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert')
            ->assertStatus(422);

        $this->assertDatabaseCount('customer_stock_alerts', 0);
    }

    public function test_out_of_stock_product_exposes_stock_alert_when_enabled(): void
    {
        $this->enableStockAlerts();
        $product = $this->markOutOfStock(Product::query()->where('sku', 'DEMO-001')->firstOrFail());
        $slug = $product->translation()?->slug ?: 'demo-001';

        $this->getJson('/api/v1/catalog/product/'.$slug)
            ->assertOk()
            ->assertJsonPath('product.stock_alert.enabled', true)
            ->assertJsonPath('product.stock_alert.subscribed', false)
            ->assertJsonPath('product.stock_alert.out_of_stock', true);
    }

    public function test_zero_qty_with_in_stock_status_is_treated_as_out_of_stock_for_alerts(): void
    {
        $this->enableStockAlerts();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->update([
            'stock_qty' => 0,
            'stock_status' => 'in_stock',
            'manage_stock' => true,
            'allow_backorder' => false,
            'notify_low_stock' => false,
        ]);
        $slug = $product->translation()?->slug ?: 'demo-001';
        $customer = Customer::factory()->create();

        $this->getJson('/api/v1/catalog/product/'.$slug)
            ->assertOk()
            ->assertJsonPath('product.stock_alert.enabled', true)
            ->assertJsonPath('product.stock_alert.out_of_stock', true)
            ->assertJsonPath('product.stock_qty', '0.000');

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert')
            ->assertOk()
            ->assertJsonPath('subscribed', true);
    }

    public function test_authenticated_customer_can_subscribe(): void
    {
        $this->enableStockAlerts();
        $product = $this->markOutOfStock(Product::query()->where('sku', 'DEMO-001')->firstOrFail());
        $customer = Customer::factory()->create(['email' => 'buyer@example.test']);

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert')
            ->assertOk()
            ->assertJsonPath('subscribed', true);

        $this->assertDatabaseHas('customer_stock_alerts', [
            'product_id' => $product->id,
            'customer_id' => $customer->id,
            'product_variant_id' => 0,
            'email' => 'buyer@example.test',
        ]);
        $this->assertNull(CustomerStockAlert::query()->where('customer_id', $customer->id)->value('notified_at'));
    }

    public function test_guest_can_subscribe_with_name_and_email(): void
    {
        $this->enableStockAlerts();
        $product = $this->markOutOfStock(Product::query()->where('sku', 'DEMO-001')->firstOrFail());

        $this->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'Guest.Ada@Example.test',
        ])
            ->assertOk()
            ->assertJsonPath('subscribed', true)
            ->assertJsonPath('guest', true);

        $this->assertDatabaseHas('customer_stock_alerts', [
            'product_id' => $product->id,
            'customer_id' => null,
            'product_variant_id' => 0,
            'email' => 'guest.ada@example.test',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ]);
    }

    public function test_guest_subscribe_requires_valid_fields(): void
    {
        $this->enableStockAlerts();
        $product = $this->markOutOfStock(Product::query()->where('sku', 'DEMO-001')->firstOrFail());

        $this->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert', [
            'first_name' => '',
            'last_name' => '',
            'email' => 'not-an-email',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['first_name', 'last_name', 'email']);

        $this->assertDatabaseCount('customer_stock_alerts', 0);
    }

    public function test_guest_duplicate_subscription_is_rejected(): void
    {
        $this->enableStockAlerts();
        $product = $this->markOutOfStock(Product::query()->where('sku', 'DEMO-001')->firstOrFail());
        $payload = [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'dup.guest@example.test',
        ];

        $this->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert', $payload)->assertOk();
        $this->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->assertSame(1, CustomerStockAlert::query()->where('email', 'dup.guest@example.test')->count());
    }

    public function test_guest_subscriptions_are_not_listed_on_customer_account_api(): void
    {
        $this->enableStockAlerts();
        $product = $this->markOutOfStock(Product::query()->where('sku', 'DEMO-001')->firstOrFail());
        $customer = Customer::factory()->create(['email' => 'owner@example.test']);

        $this->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert', [
            'first_name' => 'Guest',
            'last_name' => 'User',
            'email' => 'other.guest@example.test',
        ])->assertOk();

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert')
            ->assertOk();

        $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/stock-alerts')
            ->assertOk()
            ->assertJsonCount(1, 'alerts')
            ->assertJsonPath('alerts.0.email', 'owner@example.test');
    }

    public function test_guest_receives_back_in_stock_email(): void
    {
        $this->enableStockAlerts();
        Mail::fake();
        $product = $this->markOutOfStock(Product::query()->where('sku', 'DEMO-001')->firstOrFail());

        $this->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'guest.back@example.test',
        ])->assertOk();

        $this->markInStock($product);

        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('guest.back@example.test'));
        $this->assertNotNull(CustomerStockAlert::query()->where('email', 'guest.back@example.test')->value('notified_at'));
    }

    public function test_duplicate_subscription_is_prevented(): void
    {
        $this->enableStockAlerts();
        $product = $this->markOutOfStock(Product::query()->where('sku', 'DEMO-001')->firstOrFail());
        $customer = Customer::factory()->create();

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert')
            ->assertOk();
        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert')
            ->assertOk();

        $this->assertSame(1, CustomerStockAlert::query()
            ->where('customer_id', $customer->id)
            ->where('product_id', $product->id)
            ->count());
    }

    public function test_customer_can_unsubscribe(): void
    {
        $this->enableStockAlerts();
        $product = $this->markOutOfStock(Product::query()->where('sku', 'DEMO-001')->firstOrFail());
        $customer = Customer::factory()->create();

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert')
            ->assertOk();
        $this->actingAs($customer, 'customer')
            ->deleteJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert')
            ->assertOk()
            ->assertJsonPath('subscribed', false);

        $this->assertDatabaseMissing('customer_stock_alerts', [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_customer_can_only_see_own_subscriptions(): void
    {
        $this->enableStockAlerts();
        $product = $this->markOutOfStock(Product::query()->where('sku', 'DEMO-001')->firstOrFail());
        $alice = Customer::factory()->create(['email' => 'alice@example.test']);
        $bob = Customer::factory()->create(['email' => 'bob@example.test']);

        $this->actingAs($alice, 'customer')
            ->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert')
            ->assertOk();
        $this->actingAs($bob, 'customer')
            ->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert')
            ->assertOk();

        $aliceAlertId = (int) CustomerStockAlert::query()->where('customer_id', $alice->id)->value('id');
        $bobAlertId = (int) CustomerStockAlert::query()->where('customer_id', $bob->id)->value('id');

        $this->actingAs($alice, 'customer')
            ->getJson('/api/v1/account/stock-alerts')
            ->assertOk()
            ->assertJsonCount(1, 'alerts')
            ->assertJsonPath('alerts.0.id', $aliceAlertId);

        $this->actingAs($alice, 'customer')
            ->deleteJson('/api/v1/account/stock-alerts/'.$bobAlertId)
            ->assertNotFound();
        $this->assertDatabaseHas('customer_stock_alerts', ['id' => $bobAlertId]);
    }

    public function test_admin_can_view_stock_alert_subscriptions(): void
    {
        $this->enableStockAlerts();
        $product = $this->markOutOfStock(Product::query()->where('sku', 'DEMO-001')->firstOrFail());
        $customer = Customer::factory()->create([
            'email' => 'wait@example.test',
            'first_name' => 'Wait',
            'last_name' => 'List',
        ]);
        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert')
            ->assertOk();

        $this->actingAs(Admin::query()->firstOrFail(), 'admin')
            ->get('/admin/stock-alerts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/StockAlerts')
                ->where('enabled', true)
                ->has('alerts.data', 1)
                ->where('alerts.data.0.email', 'wait@example.test')
                ->where('alerts.data.0.status', 'pending')
                ->where('alerts.data.0.notified_at', null)
                ->where('alerts.data.0.product.name', $product->translation()?->name ?: $product->sku)
                ->where('alerts.data.0.product.sku', $product->sku)
                ->where('alerts.data.0.customer.email', 'wait@example.test')
                ->where('alerts.data.0.customer.admin_url', '/admin/customers/'.$customer->id)
                ->where('alerts.data.0.customer.is_guest', false)
                ->where('alerts.data.0.is_guest', false)
                ->etc());

        $this->actingAs(Admin::query()->firstOrFail(), 'admin')
            ->get('/admin/stock-alerts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/StockAlerts')
                ->has('alerts.data.0.product.url')
                ->where('alerts.data.0.product.url', fn ($url) => is_string($url) && $url !== '' && str_starts_with($url, '/')));
    }

    public function test_admin_stock_alert_list_presents_guest_without_customer_link(): void
    {
        $this->enableStockAlerts();
        $product = $this->markOutOfStock(Product::query()->where('sku', 'DEMO-001')->firstOrFail());

        $this->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert', [
            'first_name' => 'Guest',
            'last_name' => 'Buyer',
            'email' => 'guest.list@example.test',
        ])->assertOk();

        $this->actingAs(Admin::query()->firstOrFail(), 'admin')
            ->get('/admin/stock-alerts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/StockAlerts')
                ->has('alerts.data', 1)
                ->where('alerts.data.0.is_guest', true)
                ->where('alerts.data.0.customer_id', null)
                ->where('alerts.data.0.customer.name', 'Guest Buyer')
                ->where('alerts.data.0.customer.email', 'guest.list@example.test')
                ->where('alerts.data.0.customer.admin_url', null)
                ->where('alerts.data.0.customer.is_guest', true)
                ->where('alerts.data.0.notified_at', null)
                ->where('alerts.data.0.status', 'pending'));
    }

    public function test_admin_stock_alert_list_shows_notified_at_after_email(): void
    {
        $this->enableStockAlerts();
        Mail::fake();
        $product = $this->markOutOfStock(Product::query()->where('sku', 'DEMO-001')->firstOrFail());
        $customer = Customer::factory()->create(['email' => 'admin.notified@example.test']);

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert')
            ->assertOk();

        $this->markInStock($product);

        $notifiedAt = CustomerStockAlert::query()->where('customer_id', $customer->id)->value('notified_at');
        $this->assertNotNull($notifiedAt);

        $this->actingAs(Admin::query()->firstOrFail(), 'admin')
            ->get('/admin/stock-alerts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/StockAlerts')
                ->has('alerts.data', 1)
                ->where('alerts.data.0.status', 'notified')
                ->where('alerts.data.0.notified_at', fn ($value) => is_string($value) && $value !== ''));
    }

    public function test_variant_is_stored_on_subscription(): void
    {
        $this->enableStockAlerts();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->update(['type' => 'combination', 'manage_stock' => true, 'stock_qty' => 0, 'stock_status' => 'out_of_stock']);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'DEMO-001-RED',
            'stock_qty' => 0,
            'stock_status' => 'out_of_stock',
            'manage_stock' => true,
            'allow_backorder' => false,
            'is_enabled' => true,
            'is_default' => true,
            'combination_key' => 'red',
            'position' => 0,
        ]);
        $customer = Customer::factory()->create();

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert', [
                'product_variant_id' => $variant->id,
            ])
            ->assertOk();

        $this->actingAs(Admin::query()->firstOrFail(), 'admin')
            ->get('/admin/stock-alerts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/StockAlerts')
                ->where('alerts.data.0.product_variant_id', $variant->id)
                ->where('alerts.data.0.variant.sku', 'DEMO-001-RED')
                ->where('alerts.data.0.variant.label', fn ($label) => is_string($label) && $label !== ''));

        $this->assertDatabaseHas('customer_stock_alerts', [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
        ]);
    }

    public function test_no_email_while_product_remains_out_of_stock(): void
    {
        $this->enableStockAlerts();
        Mail::fake();
        $product = $this->markOutOfStock(Product::query()->where('sku', 'DEMO-001')->firstOrFail());
        $customer = Customer::factory()->create(['email' => 'wait@example.test']);

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert')
            ->assertOk();

        app(CustomerStockAlertService::class)->notifyPendingFor($product, null);
        Mail::assertNotSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('wait@example.test'));
        $this->assertNull(CustomerStockAlert::query()->where('customer_id', $customer->id)->value('notified_at'));
    }

    public function test_email_sent_immediately_when_stock_becomes_available(): void
    {
        $this->enableStockAlerts();
        Mail::fake();
        $product = $this->markOutOfStock(Product::query()->where('sku', 'DEMO-001')->firstOrFail());
        $customer = Customer::factory()->create(['email' => 'back@example.test']);

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert')
            ->assertOk();

        $this->markInStock($product, 5);

        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('back@example.test'));
        $this->assertNotNull(CustomerStockAlert::query()->where('customer_id', $customer->id)->value('notified_at'));
    }

    public function test_further_positive_stock_increase_does_not_resend(): void
    {
        $this->enableStockAlerts();
        Mail::fake();
        $product = $this->markOutOfStock(Product::query()->where('sku', 'DEMO-001')->firstOrFail());
        $customer = Customer::factory()->create(['email' => 'once@example.test']);

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert')
            ->assertOk();

        $this->markInStock($product, 5);
        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('once@example.test'));

        Mail::fake();
        $this->markInStock($product, 10);
        Mail::assertNothingSent();
    }

    public function test_stock_decrease_to_zero_sends_nothing(): void
    {
        $this->enableStockAlerts();
        Mail::fake();
        $product = $this->markOutOfStock(Product::query()->where('sku', 'DEMO-001')->firstOrFail());
        $customer = Customer::factory()->create(['email' => 'down@example.test']);

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert')
            ->assertOk();

        $this->markInStock($product, 5);
        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('down@example.test'));

        Mail::fake();
        $this->markOutOfStock($product);
        Mail::assertNothingSent();
    }

    public function test_multiple_customers_receive_notifications(): void
    {
        $this->enableStockAlerts();
        Mail::fake();
        $product = $this->markOutOfStock(Product::query()->where('sku', 'DEMO-001')->firstOrFail());
        $a = Customer::factory()->create(['email' => 'a@example.test']);
        $b = Customer::factory()->create(['email' => 'b@example.test']);

        $this->actingAs($a, 'customer')->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert')->assertOk();
        $this->actingAs($b, 'customer')->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert')->assertOk();

        $this->markInStock($product);

        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('a@example.test'));
        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('b@example.test'));
    }

    public function test_failed_email_does_not_mark_notified(): void
    {
        $this->enableStockAlerts();
        $product = $this->markOutOfStock(Product::query()->where('sku', 'DEMO-001')->firstOrFail());
        $customer = Customer::factory()->create(['email' => 'fail@example.test']);

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert')
            ->assertOk();

        $this->mock(ShopMailer::class, function ($mock) {
            $mock->shouldIgnoreMissing();
            $mock->shouldReceive('backInStock')->once()->andThrow(new RuntimeException('smtp down'));
        });

        $this->markInStock($product);
        $this->assertNull(CustomerStockAlert::query()->where('customer_id', $customer->id)->value('notified_at'));
    }

    public function test_concurrent_notify_calls_do_not_duplicate_email(): void
    {
        $this->enableStockAlerts();
        Mail::fake();
        $product = $this->markOutOfStock(Product::query()->where('sku', 'DEMO-001')->firstOrFail());
        $customer = Customer::factory()->create(['email' => 'race@example.test']);

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert')
            ->assertOk();

        Product::withoutEvents(function () use ($product) {
            $product->update([
                'stock_qty' => 5,
                'stock_status' => 'in_stock',
                'manage_stock' => true,
                'allow_backorder' => false,
            ]);
        });
        $product = $product->fresh();

        $alerts = app(CustomerStockAlertService::class);
        $first = $alerts->notifyPendingFor($product, null);
        $second = $alerts->notifyPendingFor($product, null);

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $sent = Mail::sent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('race@example.test'));
        $this->assertCount(1, $sent);
    }

    public function test_tick_command_does_not_send_customer_stock_alerts(): void
    {
        $this->enableStockAlerts();
        Mail::fake();
        $product = $this->markOutOfStock(Product::query()->where('sku', 'DEMO-001')->firstOrFail());
        $customer = Customer::factory()->create(['email' => 'cron.skip@example.test']);

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert')
            ->assertOk();

        Product::withoutEvents(function () use ($product) {
            $product->update([
                'stock_qty' => 5,
                'stock_status' => 'in_stock',
                'manage_stock' => true,
                'allow_backorder' => false,
                'notify_low_stock' => false,
            ]);
        });

        $this->artisan('wento:stock-alerts-tick')->assertSuccessful();
        Mail::assertNotSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('cron.skip@example.test'));
        $this->assertNull(CustomerStockAlert::query()->where('customer_id', $customer->id)->value('notified_at'));
    }

    public function test_legacy_waitlist_endpoint_is_gone(): void
    {
        $this->disableStockAlerts();
        $product = $this->markOutOfStock(Product::query()->where('sku', 'DEMO-001')->firstOrFail());

        $this->postJson('/api/v1/catalog/product/'.$product->id.'/stock-alert', [
            'email' => 'legacy@example.test',
        ])->assertStatus(405);
    }

    public function test_variant_restock_notifies_only_matching_subscribers(): void
    {
        $this->enableStockAlerts();
        Mail::fake();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->update([
            'type' => 'combination',
            'manage_stock' => true,
            'stock_qty' => 10,
            'stock_status' => 'in_stock',
            'notify_low_stock' => false,
            'notify_out_of_stock' => false,
        ]);
        $blue = ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'DEMO-001-BLUE',
            'stock_qty' => 0,
            'stock_status' => 'out_of_stock',
            'manage_stock' => true,
            'allow_backorder' => false,
            'is_enabled' => true,
            'is_default' => true,
            'combination_key' => 'blue',
            'position' => 0,
        ]);
        $red = ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'DEMO-001-RED2',
            'stock_qty' => 0,
            'stock_status' => 'out_of_stock',
            'manage_stock' => true,
            'allow_backorder' => false,
            'is_enabled' => true,
            'is_default' => false,
            'combination_key' => 'red2',
            'position' => 1,
        ]);
        $blueCustomer = Customer::factory()->create(['email' => 'blue@example.test']);
        $redCustomer = Customer::factory()->create(['email' => 'red@example.test']);

        $this->actingAs($blueCustomer, 'customer')
            ->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert', [
                'product_variant_id' => $blue->id,
            ])
            ->assertOk();
        $this->actingAs($redCustomer, 'customer')
            ->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert', [
                'product_variant_id' => $red->id,
            ])
            ->assertOk();

        $blue->update(['stock_qty' => 3, 'stock_status' => 'in_stock']);

        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('blue@example.test'));
        Mail::assertNotSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('red@example.test'));
        $this->assertNotNull(CustomerStockAlert::query()->where('customer_id', $blueCustomer->id)->value('notified_at'));
        $this->assertNull(CustomerStockAlert::query()->where('customer_id', $redCustomer->id)->value('notified_at'));
    }

    public function test_subscribe_rejected_when_feature_disabled(): void
    {
        $this->disableStockAlerts();
        $product = $this->markOutOfStock(Product::query()->where('sku', 'DEMO-001')->firstOrFail());
        $customer = Customer::factory()->create();

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert')
            ->assertStatus(422);
        $this->assertDatabaseCount('customer_stock_alerts', 0);
    }

    public function test_admin_can_resend_email_when_product_available(): void
    {
        $this->enableStockAlerts();
        Mail::fake();
        $product = $this->markOutOfStock(Product::query()->where('sku', 'DEMO-001')->firstOrFail());
        $customer = Customer::factory()->create(['email' => 'resend@example.test']);

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert')
            ->assertOk();

        $this->markInStock($product);
        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('resend@example.test'));

        $alertId = (int) CustomerStockAlert::query()->where('customer_id', $customer->id)->value('id');
        Mail::fake();

        $this->actingAs(Admin::query()->firstOrFail(), 'admin')
            ->post('/admin/stock-alerts/'.$alertId.'/resend')
            ->assertRedirect();

        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('resend@example.test'));
        $this->assertNotNull(CustomerStockAlert::query()->whereKey($alertId)->value('notified_at'));
    }

    public function test_admin_cannot_resend_when_product_unavailable(): void
    {
        $this->enableStockAlerts();
        Mail::fake();
        $product = $this->markOutOfStock(Product::query()->where('sku', 'DEMO-001')->firstOrFail());
        $customer = Customer::factory()->create(['email' => 'noresend@example.test']);

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert')
            ->assertOk();

        $alertId = (int) CustomerStockAlert::query()->where('customer_id', $customer->id)->value('id');

        $this->actingAs(Admin::query()->firstOrFail(), 'admin')
            ->post('/admin/stock-alerts/'.$alertId.'/resend')
            ->assertRedirect();

        Mail::assertNotSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('noresend@example.test'));
    }

    public function test_admin_bulk_delete_and_resend(): void
    {
        $this->enableStockAlerts();
        Mail::fake();
        $product = $this->markOutOfStock(Product::query()->where('sku', 'DEMO-001')->firstOrFail());
        $a = Customer::factory()->create(['email' => 'bulk.a@example.test']);
        $b = Customer::factory()->create(['email' => 'bulk.b@example.test']);

        $this->actingAs($a, 'customer')->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert')->assertOk();
        $this->actingAs($b, 'customer')->postJson('/api/v1/catalog/product/'.$product->id.'/customer-stock-alert')->assertOk();

        $ids = CustomerStockAlert::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertCount(2, $ids);

        $this->markInStock($product);
        Mail::fake();

        $this->actingAs(Admin::query()->firstOrFail(), 'admin')
            ->post('/admin/stock-alerts/bulk', ['action' => 'resend', 'ids' => $ids])
            ->assertRedirect();

        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('bulk.a@example.test'));
        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('bulk.b@example.test'));

        $deleteId = $ids[0];
        $this->actingAs(Admin::query()->firstOrFail(), 'admin')
            ->post('/admin/stock-alerts/bulk', ['action' => 'delete', 'ids' => [$deleteId]])
            ->assertRedirect();

        $this->assertDatabaseMissing('customer_stock_alerts', ['id' => $deleteId]);
        $this->assertDatabaseHas('customer_stock_alerts', ['id' => $ids[1]]);
    }
}
