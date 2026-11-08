<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\Models\Store;
use App\Mail\ShopHtmlMail;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CommunityLaunchTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_payload_includes_community_features(): void
    {
        $this->seed();
        $this->getJson('/api/v1/store')
            ->assertOk()
            ->assertJsonPath('features.contact_form', true)
            ->assertJsonPath('features.guest_lookup', false)
            ->assertJsonPath('features.product_video', true)
            ->assertJsonMissingPath('features.back_in_stock');
    }

    public function test_contact_form_emails_the_shop(): void
    {
        $this->seed();
        Mail::fake();
        Store::query()->first()?->update(['email' => 'merchant@example.test']);

        $this->postJson('/api/v1/contact', [
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'message' => 'Need help with an order.',
            'order_number' => 'INV-1',
        ])->assertCreated()->assertJsonPath('ok', true)->assertJsonPath('success', true);

        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('merchant@example.test')
            && str_contains($mail->emailSubject, 'Ada'));
        $this->assertSame(1, \App\Domain\Customer\Models\SupportTicket::query()->count());
    }

    public function test_contact_form_can_be_turned_off(): void
    {
        $this->seed();
        ShopSetting::put('shop.contact_form', false);
        $this->postJson('/api/v1/contact', [
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'message' => 'Hello',
        ])->assertNotFound();
    }

    public function test_guest_can_look_up_own_order_and_not_others(): void
    {
        $this->seed();
        ShopSetting::put('orders.guest_lookup', true);
        $order = $this->makeOrder('paid', 'LOOK-1', 'buyer.lookup@example.test');

        $this->postJson('/api/v1/orders/lookup', [
            'number' => $order->number,
            'email' => 'buyer.lookup@example.test',
            'postal_code' => '78701',
        ])->assertOk()
            ->assertJsonPath('order.number', 'LOOK-1')
            ->assertJsonPath('order.can_invoice', true)
            ->assertJsonPath('order.shipping_address.postal_code', '78701')
            ->assertJsonPath('order.billing_address.line1', '1 Main')
            ->assertJsonPath('order.items.0.name', 'Wireless Headphones')
            ->assertJsonPath('order.subtotal', 10)
            ->assertJsonMissingPath('order.payment_details')
            ->assertJsonMissingPath('order.customer_id');

        $this->postJson('/api/v1/orders/lookup', [
            'number' => $order->number,
            'email' => 'other@example.test',
            'postal_code' => '78701',
        ])->assertNotFound();
    }

    public function test_customer_can_reorder_and_download_invoice(): void
    {
        $this->seed();
        $customer = Customer::factory()->create();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $order = $this->makeOrder('paid', 'REO-1', $customer->email, $customer->id, $product);

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/account/orders/REO-1/reorder')
            ->assertOk()
            ->assertJsonPath('added', 1);

        $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('cart.items.0.product_id', $product->id);

        $this->actingAs($customer, 'customer')
            ->get('/api/v1/account/orders/REO-1/invoice')
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="invoice-REO-1.html"')
            ->assertSee('INVOICE', false);
    }

    public function test_product_video_embed_is_allowlisted(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->update(['video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);
        $slug = $product->translation()?->slug;
        $this->assertNotEmpty($slug);

        $this->getJson('/api/v1/catalog/product/'.$slug)
            ->assertOk()
            ->assertJsonPath('product.video_embed', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ');

        ShopSetting::put('catalog.product_video', false);
        $this->getJson('/api/v1/catalog/product/'.$slug)
            ->assertOk()
            ->assertJsonPath('product.video_embed', null);
    }

    public function test_guest_order_tracking_saves_from_order_settings(): void
    {
        $this->seed();
        ShopSetting::put('orders.guest_lookup', true);
        $order = \App\Domain\Settings\OrderSettings::payload();
        $payload = array_merge($order['defaults'], $order['checkout'], $order['payment'], $order['notifications']);
        $payload['guest_lookup'] = false;
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin')
            ->put('/admin/order-settings', $payload)
            ->assertRedirect();
        $this->assertFalse(ShopSetting::bool('orders.guest_lookup', false));
        $this->actingAs($admin, 'admin')
            ->post('/admin/store/community', ['key' => 'guest_lookup', 'enabled' => 1])
            ->assertSessionHasErrors('key');
        $this->postJson('/api/v1/orders/lookup', [
            'number' => 'X',
            'email' => 'a@b.co',
        ])->assertNotFound();
    }

    protected function makeOrder(
        string $paymentStatus,
        string $number,
        string $email,
        ?int $customerId = null,
        ?Product $product = null,
    ): Order {
        $order = Order::createTrusted([
            'number' => $number,
            'customer_id' => $customerId,
            'email' => $email,
            'status' => 'processing',
            'currency_code' => 'USD',
            'currency_rate' => 1,
            'subtotal' => 10,
            'tax_total' => 0,
            'tax_details' => [],
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 10,
            'payment_status' => $paymentStatus,
            'billing_address' => ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ]);
        $order->items()->create([
            'product_id' => $product?->id,
            'sku' => $product?->sku ?: 'WH-100',
            'name' => $product?->translation()?->name ?: 'Wireless Headphones',
            'qty' => 1,
            'unit_price' => 10,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total' => 10,
        ]);

        return $order;
    }
}
