<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\PreorderNotifySubscription;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPreorderSetting;
use App\Domain\Catalog\Services\PreorderService;
use App\Domain\Customer\Models\Customer;
use App\Mail\ShopHtmlMail;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PreorderNotifyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makePreorder(array $overrides = []): Product
    {
        $this->actingAs(Admin::query()->first(), 'admin');
        $preorder = $overrides['preorder'] ?? [];
        unset($overrides['preorder']);

        $sku = (string) ($overrides['sku'] ?? 'NOTIFY-PRE');
        $this->post('/admin/products', $this->withProductCategories(array_merge([
            'type' => 'simple',
            'name' => $sku,
            'slug' => strtolower($sku),
            'sku' => $sku,
            'price' => 100,
            'stock_qty' => 0,
            'manage_stock' => true,
            'is_enabled' => true,
            'available_for_order' => true,
            'preorder_enabled' => true,
            'preorder' => array_merge([
                'start_at' => '2026-10-01T00:00',
                'end_at' => '2026-10-30T23:59',
                'release_at' => '2026-11-01T00:00',
                'payment_type' => 'full',
                'min_quantity' => 1,
                'show_badge' => true,
                'show_release_date' => true,
                'notify_me_enabled' => true,
                'fulfillment_rule' => 'auto',
            ], $preorder),
        ], $overrides)))->assertProductSaveRedirect();

        return Product::query()->where('sku', $sku)->firstOrFail();
    }

    public function test_backoffice_notify_me_defaults_disabled_and_can_toggle(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'simple',
            'name' => 'Default Notify Off',
            'slug' => 'default-notify-off',
            'sku' => 'NOTIFY-DEFAULT',
            'price' => 50,
            'stock_qty' => 0,
            'manage_stock' => true,
            'is_enabled' => true,
            'preorder_enabled' => true,
            'preorder' => [
                'start_at' => '2026-10-01T00:00',
                'end_at' => '2026-10-30T23:59',
                'payment_type' => 'full',
                'min_quantity' => 1,
            ],
        ]))->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'NOTIFY-DEFAULT')->firstOrFail();
        $config = app(PreorderService::class)->presentConfig($product);
        $this->assertFalse((bool) ($config['notify_me_enabled'] ?? true));
        $this->assertFalse((bool) ProductPreorderSetting::query()->where('product_id', $product->id)->value('notify_me_enabled'));

        $this->put('/admin/products/'.$product->id, $this->withProductCategories([
            'type' => 'simple',
            'name' => 'Default Notify Off',
            'slug' => 'default-notify-off',
            'sku' => 'NOTIFY-DEFAULT',
            'price' => 50,
            'stock_qty' => 0,
            'manage_stock' => true,
            'is_enabled' => true,
            'preorder_enabled' => true,
            'preorder' => [
                'start_at' => '2026-10-01T00:00',
                'end_at' => '2026-10-30T23:59',
                'payment_type' => 'full',
                'min_quantity' => 1,
                'notify_me_enabled' => true,
            ],
        ]))->assertRedirect('/admin/products/'.$product->id.'/edit');

        $product->refresh();
        $config = app(PreorderService::class)->presentConfig($product);
        $this->assertTrue((bool) $config['notify_me_enabled']);
    }

    public function test_preorder_tab_documents_scheduler_cron_for_notify_me(): void
    {
        $source = (string) file_get_contents(resource_path('js/admin/Pages/Catalog/Products/PreorderTab.jsx'));

        $this->assertStringContainsString(
            '* * * * * cd /path/to/wentocart && php artisan schedule:run >> /dev/null 2>&1',
            $source,
        );
        $this->assertStringContainsString('Laravel scheduler cron', $source);
        $this->assertStringContainsString('no separate Notify Me cron is required', $source);
        $this->assertStringNotContainsString('/opt/lampp/', $source);
    }

    public function test_notify_me_visibility_on_storefront_payload(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));

        $hidden = $this->makePreorder([
            'sku' => 'NOTIFY-HIDE',
            'preorder' => ['notify_me_enabled' => false],
        ]);
        $shown = $this->makePreorder([
            'sku' => 'NOTIFY-SHOW',
            'preorder' => ['notify_me_enabled' => true],
        ]);

        $service = app(PreorderService::class);
        $hiddenPayload = $service->presentForStorefront($hidden);
        $shownPayload = $service->presentForStorefront($shown);

        $this->assertNotNull($hiddenPayload);
        $this->assertFalse((bool) $hiddenPayload['notify_me_enabled']);
        $this->assertNotNull($shownPayload);
        $this->assertTrue((bool) $shownPayload['notify_me_enabled']);
        $this->assertFalse((bool) $shownPayload['notify_me_subscribed']);

        $this->getJson('/api/v1/catalog/product/notify-show')
            ->assertOk()
            ->assertJsonPath('product.preorder.notify_me_enabled', true);

        $this->getJson('/api/v1/catalog/product/notify-hide')
            ->assertOk()
            ->assertJsonPath('product.preorder.notify_me_enabled', false);
    }

    public function test_subscribe_unsubscribe_and_already_subscribed_state(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $product = $this->makePreorder(['sku' => 'NOTIFY-SUB']);
        $customer = Customer::factory()->create();

        $this->postJson('/api/v1/catalog/product/'.$product->id.'/preorder-notify')
            ->assertUnauthorized();

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/catalog/product/'.$product->id.'/preorder-notify')
            ->assertOk()
            ->assertJsonPath('subscribed', true)
            ->assertJsonPath('notify_me_subscribed', true);

        $this->assertSame(1, PreorderNotifySubscription::query()->where('product_id', $product->id)->count());

        $payload = app(PreorderService::class)->presentForStorefront($product, $customer);
        $this->assertTrue((bool) $payload['notify_me_subscribed']);

        $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/catalog/product/notify-sub')
            ->assertOk()
            ->assertJsonPath('product.preorder.notify_me_subscribed', true);

        $this->actingAs($customer, 'customer')
            ->deleteJson('/api/v1/catalog/product/'.$product->id.'/preorder-notify')
            ->assertOk()
            ->assertJsonPath('subscribed', false)
            ->assertJsonPath('notify_me_subscribed', false);

        $this->assertSame(0, PreorderNotifySubscription::query()->where('product_id', $product->id)->count());
    }

    public function test_duplicate_subscription_is_prevented(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $product = $this->makePreorder(['sku' => 'NOTIFY-DUP']);
        $customer = Customer::factory()->create();

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/catalog/product/'.$product->id.'/preorder-notify')
            ->assertOk();
        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/catalog/product/'.$product->id.'/preorder-notify')
            ->assertOk();

        $this->assertSame(1, PreorderNotifySubscription::query()
            ->where('product_id', $product->id)
            ->where('customer_id', $customer->id)
            ->count());
    }

    public function test_subscribe_requires_notify_me_enabled_and_open_window(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $product = $this->makePreorder([
            'sku' => 'NOTIFY-OFF',
            'preorder' => ['notify_me_enabled' => false],
        ]);
        $customer = Customer::factory()->create();

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/catalog/product/'.$product->id.'/preorder-notify')
            ->assertStatus(422);

        $enabled = $this->makePreorder(['sku' => 'NOTIFY-SCHED']);
        Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00'));
        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/catalog/product/'.$enabled->id.'/preorder-notify')
            ->assertStatus(422);
    }

    public function test_no_notification_while_unavailable_and_emails_when_available(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        Mail::fake();

        $product = $this->makePreorder([
            'sku' => 'NOTIFY-MAIL',
            'stock_qty' => 0,
            'manage_stock' => true,
        ]);
        $alice = Customer::factory()->create(['email' => 'alice.notify@example.com']);
        $bob = Customer::factory()->create(['email' => 'bob.notify@example.com']);

        app(PreorderService::class)->subscribeNotify($product, $alice);
        app(PreorderService::class)->subscribeNotify($product, $bob);

        Artisan::call('wento:preorder-notify-tick');
        Mail::assertNothingSent();
        $this->assertSame(2, PreorderNotifySubscription::query()->whereNull('notified_at')->count());

        $product->update(['stock_qty' => 5, 'stock_status' => 'in_stock']);
        Artisan::call('wento:preorder-notify-tick');
        Mail::assertNothingSent();

        Carbon::setTestNow(Carbon::parse('2026-11-05 12:00:00'));
        $product->update(['stock_qty' => 5, 'stock_status' => 'in_stock']);
        Artisan::call('wento:preorder-notify-tick');

        Mail::assertSent(ShopHtmlMail::class, 2);
        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('alice.notify@example.com'));
        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('bob.notify@example.com'));
        $this->assertSame(0, PreorderNotifySubscription::query()->whereNull('notified_at')->count());

        Artisan::call('wento:preorder-notify-tick');
        Mail::assertSent(ShopHtmlMail::class, 2);
    }

    public function test_guest_cannot_subscribe_and_customer_cannot_act_for_others(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $product = $this->makePreorder(['sku' => 'NOTIFY-AUTH']);
        $owner = Customer::factory()->create();
        $other = Customer::factory()->create();

        $this->deleteJson('/api/v1/catalog/product/'.$product->id.'/preorder-notify')
            ->assertUnauthorized();

        $this->actingAs($owner, 'customer')
            ->postJson('/api/v1/catalog/product/'.$product->id.'/preorder-notify')
            ->assertOk();

        $this->assertSame(1, PreorderNotifySubscription::query()
            ->where('product_id', $product->id)
            ->where('customer_id', $owner->id)
            ->count());

        $this->actingAs($other, 'customer')
            ->deleteJson('/api/v1/catalog/product/'.$product->id.'/preorder-notify')
            ->assertOk();

        $this->assertSame(1, PreorderNotifySubscription::query()
            ->where('product_id', $product->id)
            ->where('customer_id', $owner->id)
            ->count());
        $this->assertSame(0, PreorderNotifySubscription::query()
            ->where('product_id', $product->id)
            ->where('customer_id', $other->id)
            ->count());
    }
}
