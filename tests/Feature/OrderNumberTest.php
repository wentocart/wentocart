<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Services\OrderNumberService;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Settings\Models\Setting;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;

class OrderNumberTest extends MarketplaceTestCase
{

    /** @return array{0: Seller, 1: Product} */
    protected function approvedSellerProduct(string $shopSlug = 'shop-a'): array
    {
        MarketplaceSettings::save(['auto_approve_products' => true]);
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Seller Owner',
            'email' => 'seller-owner@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'seller-owner@example.com')->firstOrFail();
        $this->actingAs($owner, 'customer');
        $this->postJson('/api/v1/account/seller/apply', [
            'first_name' => 'Ada',
            'last_name' => 'Seller',
            'shop_name' => 'Ada Market',
            'shop_slug' => $shopSlug,
            'profile_name' => 'Ada Shop',
            'email' => 'ada.seller@example.com',
            'phone' => '+1 555 0100',
            'contact_name' => 'Ada Seller',
            'contact_phone' => '+1 555 0100',
            'address_line1' => '12 Market Street',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
        ])->assertCreated();
        $seller = Seller::query()->where('customer_id', $owner->id)->firstOrFail();
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', ['action' => 'approve'])
            ->assertRedirect();
        \Illuminate\Support\Facades\Auth::guard('admin')->logout();
        $this->actingAs($owner, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Mixed mug',
            'sku' => 'mixed-mug',
            'price' => 20,
            'stock_qty' => 10,
            'is_enabled' => true,
        ])->assertCreated();
        $product = Product::query()->where('seller_id', $seller->id)->firstOrFail();
        SellerProduct::query()->where('product_id', $product->id)->update([
            'approval_status' => SellerProduct::STATUS_APPROVED,
            'is_enabled' => true,
        ]);
        $product->update(['is_enabled' => true, 'available_for_order' => true]);

        return [$seller->fresh(), $product->fresh()];
    }

    public function test_checkout_skips_existing_seeded_order_numbers(): void
    {
        $this->seed();

        $product = Product::query()->whereNull('seller_id')->where('is_enabled', true)->firstOrFail();
        $payment = PaymentMethod::query()->firstOrFail();
        $shipping = ShippingMethod::query()->firstOrFail();

        Order::createTrusted([
            'number' => 'WENTO-10001',
            'email' => 'existing@example.com',
            'status' => 'pending',
            'currency_code' => 'USD',
            'currency_rate' => 1,
            'subtotal' => 10,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 10,
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'payment_status' => 'pending',
            'billing_address' => ['first_name' => 'Old', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Old', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'store_id' => 1,
        ]);
        Setting::setValue('order_sequence', 10000);

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $response = $this->postJson('/api/v1/checkout', [
            'email' => 'fresh-buyer@example.com',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated();

        $number = $response->json('order.number');
        $this->assertNotSame('WENTO-10001', $number);
        $this->assertSame(1, Order::query()->where('number', $number)->count());
        $this->assertSame('WENTO-10002', $number);
    }

    public function test_mixed_platform_and_seller_checkout_after_seed(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);

        $platform = Product::query()->whereNull('seller_id')->where('is_enabled', true)->firstOrFail();
        [, $sellerProduct] = $this->approvedSellerProduct('shop-a');

        $payment = PaymentMethod::query()->whereNull('seller_id')->where('is_enabled', true)->firstOrFail();
        $shipping = ShippingMethod::query()->whereNull('seller_id')->where('is_enabled', true)->firstOrFail();

        Order::createTrusted([
            'number' => 'WENTO-10001',
            'email' => 'existing@example.com',
            'status' => 'pending',
            'currency_code' => 'USD',
            'currency_rate' => 1,
            'subtotal' => 10,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 10,
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'payment_status' => 'pending',
            'billing_address' => ['first_name' => 'Old', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Old', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'store_id' => 1,
        ]);
        Setting::setValue('order_sequence', 10000);

        $this->postJson('/api/v1/auth/logout');
        $this->postJson('/api/v1/cart', ['product_id' => $platform->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart', ['product_id' => $sellerProduct->id, 'qty' => 1])->assertOk();

        $before = Order::query()->count();
        $response = $this->postJson('/api/v1/checkout', [
            'email' => 'mixed@example.com',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'customer_note' => 'Mixed cart order',
        ])->assertCreated();

        $this->assertSame($before + 1, Order::query()->count());
        $this->assertSame('WENTO-10002', $response->json('order.number'));
        $this->assertGreaterThanOrEqual(1, SellerOrder::query()->count());
    }

    public function test_order_number_service_syncs_from_existing_orders(): void
    {
        $this->seed();

        $payment = PaymentMethod::query()->firstOrFail();
        $shipping = ShippingMethod::query()->firstOrFail();
        Order::createTrusted([
            'number' => 'WENTO-10001',
            'email' => 'existing@example.com',
            'status' => 'pending',
            'currency_code' => 'USD',
            'currency_rate' => 1,
            'subtotal' => 10,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 10,
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'payment_status' => 'pending',
            'billing_address' => ['first_name' => 'Old', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Old', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'store_id' => 1,
        ]);
        Setting::setValue('order_sequence', 10000);

        $next = app(OrderNumberService::class)->next();

        $this->assertSame('WENTO-10002', $next);
    }

    public function test_lowering_the_starting_number_issues_that_number(): void
    {
        $this->seed();

        $payment = PaymentMethod::query()->firstOrFail();
        $shipping = ShippingMethod::query()->firstOrFail();
        $address = ['first_name' => 'Old', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'];
        Order::createTrusted([
            'number' => 'WENTO-10021',
            'email' => 'existing@example.com',
            'status' => 'pending',
            'currency_code' => 'USD',
            'currency_rate' => 1,
            'subtotal' => 10,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 10,
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'payment_status' => 'pending',
            'billing_address' => $address,
            'shipping_address' => $address,
            'store_id' => 1,
        ]);
        Order::createTrusted([
            'number' => 'WENTO-1',
            'email' => 'taken@example.com',
            'status' => 'pending',
            'currency_code' => 'USD',
            'currency_rate' => 1,
            'subtotal' => 10,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 10,
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'payment_status' => 'pending',
            'billing_address' => $address,
            'shipping_address' => $address,
            'store_id' => 1,
        ]);
        Setting::setValue('order_sequence', 10021);
        \App\Domain\Settings\ShopSetting::put('orders.number_start', 1);

        \App\Domain\Settings\OrderSettings::syncSequenceFloor(1, 10001);

        $service = app(OrderNumberService::class);
        $this->assertSame('WENTO-2', $service->next());
        $this->assertSame('WENTO-3', $service->next());
    }

    public function test_shop_start_is_not_pulled_up_by_the_shared_counter(): void
    {
        $this->seed();
        Setting::setValue('order_sequence', 10024);
        \App\Domain\Settings\ShopSetting::put('orders.number_start', 1);

        $service = app(OrderNumberService::class);
        $this->assertSame('WENTO-1', $service->next());
        $this->assertSame('WENTO-2', $service->next());
    }
}
