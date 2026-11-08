<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Attachment;
use App\Domain\Catalog\Models\PriceAlert;
use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Customer\Services\StoreCreditService;
use App\Domain\Marketing\Models\NewsletterSubscriber;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderDownload;
use App\Domain\Order\Models\ShopReturn;
use App\Domain\Order\Services\ShopReturnService;
use App\Domain\Promo\Models\DiscountRule;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Settings\DocumentCommerceSettings;
use App\Domain\Settings\ShopSetting;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Mail\ShopHtmlMail;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BeatThemCommerceTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_numbers_use_an_independent_sequence(): void
    {
        $this->seed();
        $first = $this->paidOrder('SEQ-A');
        $second = $this->paidOrder('SEQ-B');
        $a = DocumentCommerceSettings::formatInvoiceNumber($first);
        $b = DocumentCommerceSettings::formatInvoiceNumber($second);
        $this->assertNotSame($a, $b);
        $this->assertMatchesRegularExpression('/INV-\d+/', $a);
        $this->assertSame($a, DocumentCommerceSettings::formatInvoiceNumber($first->fresh()));
    }

    public function test_shop_payload_exposes_checkout_layout_and_gift_wrap(): void
    {
        $this->seed();
        ShopSetting::put('checkout.layout', 'stepped');
        ShopSetting::put('checkout.gift_wrap_enabled', true);
        ShopSetting::put('checkout.gift_wrap_fee', 4.5);
        $this->getJson('/api/v1/store')
            ->assertOk()
            ->assertJsonPath('commerce.cart.layout', 'stepped')
            ->assertJsonPath('commerce.cart.gift_wrap_enabled', true)
            ->assertJsonPath('commerce.cart.gift_wrap_fee', 4.5);
    }

    public function test_return_refund_issues_a_voucher_instead_of_store_credit(): void
    {
        $this->seed();
        ShopSetting::put('returns.refund_method', 'voucher');
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Credit Buyer',
            'email' => 'credit.buyer@example.test',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
        ])->assertOk();
        $customer = Customer::query()->where('email', 'credit.buyer@example.test')->firstOrFail();
        $order = $this->placeDemoOrder($customer);

        $this->postJson('/api/v1/account/orders/'.$order->number.'/returns', [
            'items' => [['order_item_id' => $order->items()->first()->id, 'qty' => 1]],
            'reason' => 'Too big',
        ])->assertCreated();

        $return = ShopReturn::query()->where('order_id', $order->id)->firstOrFail();
        $return->update(['status' => ShopReturn::STATUS_APPROVED]);
        app(ShopReturnService::class)->refund($return, Admin::query()->first()?->id);

        $voucher = DiscountRule::query()->where('code', 'like', 'RFND-%')->first();
        $this->assertNotNull($voucher);
        $this->assertGreaterThan(0, (float) $voucher->value);
        $this->assertSame(0.0, app(StoreCreditService::class)->balance($customer->fresh()));
    }

    public function test_gift_wrap_adds_a_fee_to_cart_totals(): void
    {
        $this->seed();
        ShopSetting::put('checkout.gift_wrap_enabled', true);
        ShopSetting::put('checkout.gift_wrap_fee', 3.25);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $before = $this->getJson('/api/v1/cart')->assertOk()->json('totals.grand_total');
        $this->postJson('/api/v1/cart/gift-wrap', ['enabled' => true])->assertOk()
            ->assertJsonPath('totals.gift_wrap_fee', 3.25);
        $after = $this->getJson('/api/v1/cart')->json('totals.grand_total');
        $this->assertEqualsWithDelta((float) $before + 3.25, (float) $after, 0.02);
    }

    public function test_guest_prices_hide_when_configured(): void
    {
        $this->seed();
        ShopSetting::put('products.hide_prices_guests', true);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $this->getJson('/api/v1/catalog/product/'.$product->translation()?->slug)
            ->assertOk()
            ->assertJsonPath('product.show_price', false)
            ->assertJsonPath('product.price', null);

        $category = $product->categories()->with('translations')->first();
        $this->assertNotNull($category);
        $listing = $this->getJson('/api/v1/catalog/category/'.$category->translation()?->slug)
            ->assertOk()
            ->json('products.data');
        $this->assertNotEmpty($listing);
        foreach ($listing as $row) {
            $this->assertFalse($row['show_price']);
            $this->assertNull($row['price']);
            $this->assertNull($row['price_formatted']);
        }
    }

    public function test_catalog_mode_hides_prices_on_lists_when_show_price_is_off(): void
    {
        $this->seed();
        ShopSetting::put('products.hide_prices_guests', false);
        ShopSetting::put('products.catalog_mode', true);
        ShopSetting::put('products.catalog.show_price', false);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $category = $product->categories()->with('translations')->first();
        $this->assertNotNull($category);

        $this->getJson('/api/v1/catalog/product/'.$product->translation()?->slug)
            ->assertOk()
            ->assertJsonPath('product.show_price', false)
            ->assertJsonPath('product.price', null);

        $listing = $this->getJson('/api/v1/catalog/category/'.$category->translation()?->slug)
            ->assertOk()
            ->json('products.data');
        $this->assertNotEmpty($listing);
        foreach ($listing as $row) {
            $this->assertFalse($row['show_price']);
            $this->assertNull($row['price']);
        }
    }

    public function test_account_deletion_anonymizes_instead_of_dropping_orders(): void
    {
        $this->seed();
        ShopSetting::put('customers.allow_account_deletion', true);
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Erase Me',
            'email' => 'erase.me@example.test',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
        ])->assertOk();
        $customer = Customer::query()->where('email', 'erase.me@example.test')->firstOrFail();
        $order = $this->placeDemoOrder($customer);

        $this->deleteJson('/api/v1/account', ['password' => 'Password1a'])->assertOk();
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => 'Deleted customer']);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        $this->assertStringContainsString('anonymized.invalid', (string) $order->fresh()->email);
    }

    public function test_newsletter_campaign_emails_subscribers(): void
    {
        $this->seed();
        Mail::fake();
        NewsletterSubscriber::query()->create(['email' => 'list@example.test']);
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/newsletter/campaigns', [
                'subject' => 'Spring sale',
                'html_body' => '<p>20% off</p>',
            ])
            ->assertRedirect();
        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('list@example.test'));
    }

    public function test_wishlist_share_token_is_public(): void
    {
        $this->seed();
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wish',
            'email' => 'wish@example.test',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
        ])->assertOk();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $this->postJson('/api/v1/account/wishlist', ['product_id' => $product->id])->assertOk();
        $token = $this->postJson('/api/v1/account/wishlist/share')->assertOk()->json('token');
        $this->postJson('/api/v1/auth/logout');
        $this->getJson('/api/v1/wishlist/shared/'.$token)
            ->assertOk()
            ->assertJsonPath('wishlist.0.id', $product->id);
    }

    public function test_virtual_order_issues_download_tokens(): void
    {
        $this->seed();
        Storage::fake('local');
        Storage::disk('local')->put('downloads/key.txt', 'LICENSE');
        $this->actingAs(Admin::query()->first(), 'admin');
        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'virtual',
            'name' => 'PDF License',
            'slug' => 'pdf-license',
            'sku' => 'PDF-LIC',
            'price' => 12,
            'is_enabled' => true,
            'manage_stock' => false,
        ]))->assertRedirect();
        $this->post('/admin/logout');
        $this->flushSession();
        $product = Product::query()->where('sku', 'PDF-LIC')->firstOrFail();
        $file = Attachment::query()->create([
            'name' => 'License',
            'original_filename' => 'key.txt',
            'stored_filename' => 'key.txt',
            'storage_path' => 'downloads/key.txt',
            'disk' => 'local',
            'mime_type' => 'text/plain',
            'file_size' => 7,
        ]);
        $product->attachments()->attach($file->id);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Downloader',
            'email' => 'dl@example.test',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
        ])->assertOk();
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $payment = PaymentMethod::query()->firstOrFail();
        $this->postJson('/api/v1/checkout', [
            'email' => 'dl@example.test',
            'payment_method_id' => $payment->id,
            'billing_address' => ['first_name' => 'Dee', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated();

        $this->assertTrue(OrderDownload::query()->where('product_id', $product->id)->exists());
        $order = \App\Domain\Order\Models\Order::query()->whereHas('items', fn ($q) => $q->where('product_id', $product->id))->firstOrFail();
        // Offline checkout stays pending until paid — downloads unlock after payment.
        $order->updateTrusted([
            'payment_status' => 'paid',
            'amount_paid' => (float) $order->grand_total,
            'amount_due' => 0,
            'status' => 'completed',
        ]);
        $this->getJson('/api/v1/account/downloads')->assertOk()->assertJsonCount(1, 'downloads');
    }

    public function test_quote_from_cart_and_admin_accept(): void
    {
        $this->seed();
        $this->postJson('/api/v1/auth/register', [
            'name' => 'B2B',
            'email' => 'b2b@example.test',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
        ])->assertOk();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 2])->assertOk();
        $number = $this->postJson('/api/v1/account/quotes', ['note' => 'Net 30'])->assertCreated()->json('quote.number');
        $this->actingAs(Admin::query()->first(), 'admin')
            ->get('/admin/quotes')
            ->assertOk();
        $quoteId = \App\Domain\Customer\Models\B2bQuote::query()->where('number', $number)->value('id');
        $this->post('/admin/quotes/'.$quoteId.'/accept')->assertRedirect();
        $this->assertDatabaseHas('b2b_quotes', ['number' => $number, 'status' => 'accepted']);
    }

    public function test_price_alert_tick_emails_on_drop(): void
    {
        $this->seed();
        Mail::fake();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $customer = Customer::factory()->create(['email' => 'alert@example.test']);
        PriceAlert::query()->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'last_price' => (float) $product->sellingPrice() + 20,
            'email' => $customer->email,
        ]);
        $this->artisan('wento:price-alerts-tick')->assertSuccessful();
        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('alert@example.test'));
    }

    public function test_stock_journal_page_renders(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin')
            ->get('/admin/inventory/journal')
            ->assertOk();
    }

    public function test_customer_group_can_hide_prices(): void
    {
        $this->seed();
        $group = CustomerGroup::query()->firstOrFail();
        $this->actingAs(Admin::query()->first(), 'admin')
            ->put('/admin/customer-groups/'.$group->id, [
                'name' => $group->name,
                'color' => $group->color ?: 'primary',
                'discount_percent' => $group->discount_percent,
                'tax_exempt' => $group->tax_exempt,
                'hide_prices' => 1,
                'is_enabled' => 1,
            ])
            ->assertRedirect();
        $group->refresh();
        $this->assertTrue((bool) $group->hide_prices);
    }

    public function test_admin_refund_can_issue_store_credit(): void
    {
        $this->seed();
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Refundee',
            'email' => 'refundee@example.test',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
        ])->assertOk();
        $customer = Customer::query()->where('email', 'refundee@example.test')->firstOrFail();
        $order = $this->placeDemoOrder($customer);
        $order->forceFill(['payment_status' => 'paid'])->save();
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/orders/'.$order->id.'/refund', ['issue_credit' => 1])
            ->assertRedirect();
        $this->assertGreaterThan(0, app(StoreCreditService::class)->balance($customer->fresh()));
    }

    protected function paidOrder(string $number): Order
    {
        return Order::createTrusted([
            'number' => $number,
            'email' => 'seq@example.test',
            'status' => 'pending',
            'currency_code' => 'USD',
            'currency_rate' => 1,
            'subtotal' => 10,
            'tax_total' => 0,
            'tax_details' => [],
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 10,
            'payment_status' => 'paid',
            'billing_address' => ['first_name' => 'A', 'line1' => '1', 'city' => 'Austin', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'A', 'line1' => '1', 'city' => 'Austin', 'country_code' => 'US'],
        ]);
    }

    protected function placeDemoOrder(Customer $customer): Order
    {
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $payment = PaymentMethod::query()->firstOrFail();
        $shipping = ShippingMethod::query()->firstOrFail();
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', [
            'email' => $customer->email,
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated();

        return Order::query()->where('email', $customer->email)->latest('id')->firstOrFail();
    }
}
