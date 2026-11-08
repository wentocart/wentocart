<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\GiftCard;
use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Order\Models\ShopReturn;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Security\Totp;
use App\Domain\Settings\ShopSetting;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MustHaveGapsTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_quotes_price_based_shipping_tiers(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $method = ShippingMethod::query()->create([
            'code' => 'price-tiers',
            'name' => 'Price tiers',
            'type' => 'price_based',
            'driver' => 'offline',
            'price' => 99,
            'is_enabled' => true,
            'position' => 40,
            'conditions' => ShippingMethod::emptyConditions(),
            'rate_config' => [
                'tiers' => [
                    ['min' => 0, 'max' => 20, 'price' => 12],
                    ['min' => 20, 'max' => 9999, 'price' => 3],
                ],
            ],
        ]);

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $options = $this->getJson('/api/v1/checkout/options')->assertOk();
        $row = collect($options->json('shipping_methods'))->firstWhere('id', $method->id);
        $this->assertNotNull($row);
        $quoted = (float) ($row['price'] ?? 0);
        $this->assertContains($quoted, [3.0, 12.0]);

        $totals = $this->getJson('/api/v1/cart?shipping_method_id='.$method->id)->assertOk()->json('totals');
        $this->assertEquals($quoted, (float) $totals['shipping']);
    }

    public function test_group_discount_reduces_cart_subtotal(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        ShopSetting::put('customers.group_pricing', true);
        CustomerGroup::query()->where('code', 'vip')->update(['discount_percent' => 50]);
        $customer = Customer::factory()->create(['customer_group' => 'vip']);

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();
        $subtotal = (float) $this->actingAs($customer, 'customer')->getJson('/api/v1/cart')->json('totals.subtotal');
        $this->assertEqualsWithDelta((float) $product->price * 0.5, $subtotal, 0.05);
    }

    public function test_gift_card_code_credits_the_cart(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        GiftCard::query()->create([
            'product_id' => $product->id,
            'code' => 'TESTCARD1',
            'initial_balance' => 10,
            'current_balance' => 10,
            'selected_amount' => 10,
            'status' => 'active',
            'issued_at' => now(),
        ]);

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart/gift-card', ['code' => 'TESTCARD1'])->assertOk();
        $credit = (float) $this->getJson('/api/v1/cart')->json('totals.gift_card_credit');
        $this->assertGreaterThan(0, $credit);
    }

    public function test_customer_can_request_a_shop_return(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $payment = PaymentMethod::query()->firstOrFail();
        $shipping = ShippingMethod::query()->where('is_enabled', true)->firstOrFail();
        $customer = Customer::factory()->create();

        $this->actingAs($customer, 'customer')->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $placed = $this->actingAs($customer, 'customer')->postJson('/api/v1/checkout', [
            'email' => $customer->email,
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated();

        $number = $placed->json('order.number');
        $order = $this->actingAs($customer, 'customer')->getJson('/api/v1/account/orders/'.$number)->assertOk();
        $itemId = (int) ($order->json('order.items.0.id') ?? 0);
        $this->assertGreaterThan(0, $itemId);

        $this->actingAs($customer, 'customer')->postJson('/api/v1/account/orders/'.$number.'/returns', [
            'reason' => 'Did not fit',
            'items' => [['order_item_id' => $itemId, 'qty' => 1]],
        ])->assertCreated();

        $this->assertSame(1, ShopReturn::query()->count());
    }

    public function test_shop_payload_includes_privacy_and_merchant_feed_is_public(): void
    {
        $this->seed();
        $this->getJson('/api/v1/store')->assertOk()->assertJsonPath('commerce.privacy.cookie_consent', true);
        $this->get('/feeds/google-merchant.xml')->assertOk();
    }

    public function test_admin_with_totp_is_sent_to_mfa_challenge(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $secret = Totp::secret();
        $admin->forceFill([
            'totp_secret' => $secret,
            'totp_confirmed_at' => now(),
        ])->save();

        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect('/admin/mfa/challenge');
        $this->assertGuest('admin');
        $this->assertNotNull(session('admin.mfa_pending_id'));

        $this->post('/admin/mfa/challenge', [
            'code' => Totp::at($secret, (int) floor(time() / 30)),
        ])->assertRedirect('/admin/dashboards');
        $this->assertAuthenticatedAs($admin->fresh(), 'admin');
    }

    public function test_account_export_is_available(): void
    {
        $this->seed();
        $customer = Customer::factory()->create();
        $this->actingAs($customer, 'customer')->getJson('/api/v1/account/export')->assertOk()->assertJsonPath('customer.email', $customer->email);
    }
}
