<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Catalog\Services\StorefrontCatalogCache;
use App\Domain\Localization\Money;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Order\InvoiceDocument;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Promo\DiscountContext;
use App\Domain\Settings\CartCheckoutSettings;
use App\Domain\Settings\DocumentCommerceSettings;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Settings\CustomerSettings;
use App\Domain\Settings\InventorySettings;
use App\Domain\Settings\OrderSettings;
use App\Domain\Settings\PricingSettings;
use App\Domain\Settings\ProductSettings;
use App\Domain\Settings\ReturnSettings;
use App\Domain\Settings\ReviewSettings;
use App\Domain\Settings\SearchCatalogSettings;
use App\Domain\Settings\ShopSetting;
use App\Domain\Settings\StorePurpose;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CommerceSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_configure_commerce_pages_save(): void
    {
        $admin = Admin::query()->firstOrFail();

        $this->actingAs($admin, 'admin')->get('/admin/cart-settings')->assertRedirect('/admin/order-settings?tab=cart');
        $this->actingAs($admin, 'admin')->get('/admin/order-settings?tab=cart')->assertOk();
        $termsPageId = CmsPage::query()->value('id');
        $this->assertNotNull($termsPageId);
        $this->actingAs($admin, 'admin')->from('/admin/order-settings?tab=cart')->put('/admin/cart-settings', [
            'cart_expiry_minutes' => 90,
            'max_cart_items' => 12,
            'coupons_enabled' => 0,
            'terms_required' => 1,
            'stock_validation' => 1,
            'unavailable_items' => 'remove',
        ])->assertSessionHasErrors('terms_cms_page_id');
        $this->actingAs($admin, 'admin')->put('/admin/cart-settings', [
            'cart_expiry_minutes' => 90,
            'max_cart_items' => 12,
            'coupons_enabled' => 0,
            'terms_required' => 1,
            'terms_cms_page_id' => $termsPageId,
            'stock_validation' => 1,
            'unavailable_items' => 'remove',
        ])->assertRedirect();
        $this->assertSame(90, CartCheckoutSettings::cartExpiryMinutes());
        $this->assertFalse(CartCheckoutSettings::couponsEnabled());
        $this->assertTrue(CartCheckoutSettings::termsRequired());

        $this->actingAs($admin, 'admin')->put('/admin/inventory-settings', [
            'enabled' => 1,
            'allow_negative' => 1,
            'notifications' => 0,
            'scope' => 'shop',
        ])->assertRedirect();
        $this->assertTrue(InventorySettings::allowNegative());
        $this->assertSame('global', InventorySettings::scope());

        $this->actingAs($admin, 'admin')->put('/admin/pricing-settings', [
            'tax_inclusive_input' => 1,
            'storefront_display' => 'both',
            'decimal_precision' => 0,
            'rounding' => 'nearest',
            'zero_price' => 'contact',
            'sale_enabled' => 0,
            'compare_at_enabled' => 1,
            'shipping_tax' => 1,
        ])->assertRedirect();
        $this->assertSame('both', PricingSettings::storefrontDisplay());
        $this->assertSame('contact', PricingSettings::zeroPrice());
        $this->assertTrue(PricingSettings::shippingTax());

        $this->actingAs($admin, 'admin')->put('/admin/return-settings', [
            'enabled' => 1,
            'window_days' => 30,
            'reason_required' => 1,
            'approval' => 'automatic',
            'partial' => 0,
            'refund_method' => 'voucher',
            'refund_shipping' => 'yes',
            'restock' => 'manual',
            'restocking_fee' => 1,
        ])->assertRedirect();
        $this->assertSame(30, ReturnSettings::windowDays());
        $this->assertSame('automatic', ReturnSettings::approval());
        $this->assertSame('voucher', ReturnSettings::refundMethod());
        $this->assertSame('yes', ReturnSettings::refundShipping());

        $this->actingAs($admin, 'admin')->put('/admin/search-settings', [
            'default_sort' => 'newest',
            'per_page' => 16,
            'suggestions' => 0,
            'typo_tolerance' => 1,
            'recently_viewed' => 0,
            'wishlist_enabled' => 0,
        ])->assertRedirect();
        $this->assertSame('newest', SearchCatalogSettings::defaultSort());
        $this->assertFalse(SearchCatalogSettings::wishlistEnabled());

        $this->actingAs($admin, 'admin')->put('/admin/review-settings', [
            'enabled' => 1,
            'who' => 'verified',
            'moderation' => 'automatic',
            'merchant_replies' => 0,
            'media' => 'none',
            'min_length' => 20,
        ])->assertRedirect();
        $this->assertSame('verified', ReviewSettings::whoCanReview());
        $this->assertSame(20, ReviewSettings::minLength());

        $this->actingAs($admin, 'admin')->put('/admin/document-settings', [
            'invoices_enabled' => 1,
            'invoice_format' => 'INV-{number}',
            'invoice_start' => 5000,
            'invoice_create_statuses' => ['pending', 'shipped'],
            'invoice_auto_send' => 1,
            'credit_format' => 'CN-{number}',
            'packing_slips' => 0,
            'order_pdf' => 1,
        ])->assertRedirect();
        $this->assertSame(['pending', 'shipped'], DocumentCommerceSettings::createStatuses());

        $this->actingAs($admin, 'admin')->get('/admin/product-settings?tab=inventory')->assertOk();
        $this->actingAs($admin, 'admin')->get('/admin/customer-settings?tab=reviews')->assertOk();
        $this->actingAs($admin, 'admin')->get('/admin/order-settings?tab=invoices')->assertOk();

        $this->getJson('/api/v1/store')
            ->assertOk()
            ->assertJsonPath('commerce.cart.coupons_enabled', false)
            ->assertJsonPath('commerce.search.default_sort', 'newest')
            ->assertJsonPath('commerce.reviews.who', 'verified');
    }

    public function test_primary_order_customer_and_product_settings_forms_save(): void
    {
        $admin = Admin::query()->firstOrFail();

        $order = OrderSettings::payload();
        $orderPayload = array_merge($order['defaults'], $order['checkout'], $order['payment'], $order['notifications']);
        $orderPayload['number_format'] = 'TEST-{number}';
        $this->actingAs($admin, 'admin')->put('/admin/order-settings', $orderPayload)->assertRedirect();
        $this->assertSame('TEST-{number}', ShopSetting::get('orders.number_format'));

        $customer = CustomerSettings::payload();
        $customerPayload = array_merge($customer['accounts'], $customer['groups'], [
            'require_billing' => $customer['addresses']['require_billing'],
            'require_shipping' => $customer['addresses']['require_shipping'],
            'billing_same_default' => $customer['addresses']['billing_same_default'],
            'allow_multiple' => $customer['addresses']['allow_multiple'],
            'max_saved' => $customer['addresses']['max_saved'],
            'address_phone_mode' => $customer['addresses']['phone_mode'],
            'address_company_mode' => $customer['addresses']['company_mode'],
            'address_tax_id_mode' => $customer['addresses']['tax_id_mode'],
            'address_line2_mode' => $customer['addresses']['line2_mode'],
            'address_state_mode' => $customer['addresses']['state_mode'],
            'address_postal_mode' => $customer['addresses']['postal_mode'],
        ]);
        $customerPayload['registration_enabled'] = false;
        $this->actingAs($admin, 'admin')->put('/admin/customer-settings', $customerPayload)->assertRedirect();
        $this->assertFalse(ShopSetting::bool('customers.registration_enabled', true));

        $product = ProductSettings::payload();
        $productPayload = array_merge($product['defaults'], $product['catalog'], $product['content'], [
            'row_highlight_color' => $product['list']['color'],
            'row_highlight_ms' => $product['list']['duration_ms'],
        ]);
        $productPayload['sku_prefix'] = 'TEST-';
        $this->actingAs($admin, 'admin')->put('/admin/product-settings', $productPayload)->assertRedirect();
        $this->assertSame('TEST-', ShopSetting::get('products.sku_prefix'));
    }

    public function test_storefront_follows_commerce_toggles(): void
    {
        $admin = Admin::query()->firstOrFail();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();

        $this->actingAs($admin, 'admin')->put('/admin/search-settings', [
            'default_sort' => 'popularity',
            'per_page' => 24,
            'suggestions' => 1,
            'typo_tolerance' => 0,
            'recently_viewed' => 1,
            'wishlist_enabled' => 0,
        ])->assertRedirect();

        $this->actingAs($admin, 'admin')->put('/admin/cart-settings', [
            'cart_expiry_minutes' => 10080,
            'max_cart_items' => 50,
            'coupons_enabled' => 0,
            'terms_required' => 0,
            'stock_validation' => 1,
            'unavailable_items' => 'keep',
        ])->assertRedirect();

        $this->assertFalse(SearchCatalogSettings::wishlistEnabled());
        $this->assertFalse(CartCheckoutSettings::couponsEnabled());

        $this->actingAs($admin, 'admin')->post('/admin/shop/purpose', ['purpose' => 'catalog'])->assertRedirect();
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertStatus(422);
        $this->getJson('/api/v1/store')
            ->assertOk()
            ->assertJsonPath('products.catalog_mode', true)
            ->assertJsonPath('commerce.cart.coupons_enabled', false)
            ->assertJsonPath('commerce.search.wishlist_enabled', false);
    }

    public function test_store_purpose_catalog_applies_recommended_defaults(): void
    {
        $admin = Admin::query()->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->post('/admin/shop/purpose', ['purpose' => 'catalog'])
            ->assertRedirect();

        $this->assertSame('catalog', StorePurpose::current());
        $this->assertTrue(ProductSettings::catalogMode());
        $this->assertFalse(ProductSettings::defaultAvailableForOrder());
        $this->assertFalse(CartCheckoutSettings::couponsEnabled());
        $this->assertFalse(InventorySettings::enabled());
    }

    public function test_required_account_phone_rejects_empty_and_letters(): void
    {
        ShopSetting::put('customers.phone_mode', 'required');

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Phone',
            'email' => 'ada-phone@example.com',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
            'phone' => '',
        ])->assertJsonValidationErrors(['phone']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Phone',
            'email' => 'ada-phone@example.com',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
            'phone' => 'call-me',
        ])->assertJsonValidationErrors(['phone']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Phone',
            'email' => 'ada-phone@example.com',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
            'phone' => '+1',
        ])->assertJsonValidationErrors(['phone']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Phone',
            'email' => 'ada-phone@example.com',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
            'phone' => '+1 5551234567',
        ])->assertOk();
    }

    public function test_checkout_addresses_follow_required_toggles(): void
    {
        ShopSetting::put('customers.address.require_billing', false);
        ShopSetting::put('customers.address.require_shipping', false);
        ShopSetting::put('checkout.terms_required', false);

        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $payment = PaymentMethod::query()->where('is_enabled', true)->firstOrFail();
        $shipping = ShippingMethod::query()->where('is_enabled', true)->first();

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();

        $this->postJson('/api/v1/checkout', [
            'email' => 'guest-address@example.com',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping?->id,
            'accept_terms' => true,
        ])->assertJsonMissingValidationErrors([
            'billing_address',
            'shipping_address',
            'billing_address.line1',
            'shipping_address.line1',
            'shipping_address.line2',
        ]);
    }

    public function test_group_discount_and_tax_exempt_follow_the_assigned_group(): void
    {
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        ShopSetting::put('customers.group_pricing', true);
        ShopSetting::put('customers.group_tax', false);
        $group = CustomerGroup::query()->where('code', 'vip')->firstOrFail();
        $group->update(['discount_percent' => 20, 'tax_exempt' => true, 'is_enabled' => true]);
        $customer = Customer::factory()->create([
            'customer_group' => 'missing-code',
            'customer_group_id' => $group->id,
        ]);

        $this->assertSame(23.99, $product->sellingPrice(new DiscountContext(customer: $customer)));

        $resolved = app(\App\Domain\Tax\TaxEngine::class)->resolve(
            $product->tax_class_id ?: 1,
            'US',
            null,
            $customer,
        );
        $this->assertSame(0.0, $resolved['rate']);

        $group->update(['tax_exempt' => false]);
        $tax = app(\App\Domain\Tax\TaxEngine::class);
        $guest = $tax->resolve($product->tax_class_id ?: 1, 'US', null, null);
        $taxed = $tax->resolve($product->tax_class_id ?: 1, 'US', null, $customer->fresh());
        $this->assertSame($guest['rate'], $taxed['rate']);

        $group->update(['tax_exempt' => true]);
        ShopSetting::put('customers.groups_enabled', false);
        $ignored = $tax->resolve($product->tax_class_id ?: 1, 'US', null, $customer->fresh());
        $this->assertSame($guest['rate'], $ignored['rate']);
    }

    public function test_order_number_format_saves_when_it_includes_the_number_token(): void
    {
        $admin = Admin::query()->firstOrFail();
        $order = OrderSettings::payload();
        $payload = array_merge($order['defaults'], $order['checkout'], $order['payment'], $order['notifications']);
        $payload['number_format'] = 'ORD-{number}';

        $this->actingAs($admin, 'admin')->put('/admin/order-settings', $payload)->assertRedirect();
        $this->assertSame('ORD-{number}', OrderSettings::numberFormat());
        $this->assertSame('ORD-10001', OrderSettings::formatNumber(10001));

        $payload['number_format'] = 'ORD-1001';
        $this->actingAs($admin, 'admin')->from('/admin/order-settings')
            ->put('/admin/order-settings', $payload)
            ->assertRedirect('/admin/order-settings')
            ->assertSessionHasErrors('number_format');
        $this->assertSame('ORD-{number}', OrderSettings::numberFormat());
    }

    public function test_cart_max_blocks_extra_units_and_checkout(): void
    {
        ShopSetting::put('checkout.max_cart_items', 5);
        ShopSetting::put('checkout.terms_required', false);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->forceFill(['manage_stock' => false])->save();

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 7])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This cart can hold at most 5 items.');

        $added = $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 5])->assertOk();
        $itemId = $added->json('cart.items.0.id');
        $this->patchJson('/api/v1/cart/items/'.$itemId, ['qty' => 7])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This cart can hold at most 5 items.');

        ShopSetting::put('checkout.max_cart_items', 50);
        $this->patchJson('/api/v1/cart/items/'.$itemId, ['qty' => 7])->assertOk();
        ShopSetting::put('checkout.max_cart_items', 5);

        $payment = PaymentMethod::query()->where('is_enabled', true)->firstOrFail();
        $shipping = ShippingMethod::query()->where('is_enabled', true)->first();
        $this->postJson('/api/v1/checkout', [
            'email' => 'cart-max@example.com',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping?->id,
            'accept_terms' => true,
        ])->assertStatus(422)->assertJsonPath('message', 'This cart can hold at most 5 items.');
    }

    public function test_gift_wrap_stays_off_when_the_setting_is_off(): void
    {
        ShopSetting::put('checkout.gift_wrap_enabled', false);

        $this->getJson('/api/v1/store')
            ->assertOk()
            ->assertJsonPath('commerce.cart.gift_wrap_enabled', false)
            ->assertJsonPath('orders.customer_notes_enabled', true);

        $this->postJson('/api/v1/cart/gift-wrap', ['enabled' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Gift wrap is turned off.');
    }

    public function test_disabled_invoices_explain_themselves_instead_of_404(): void
    {
        $admin = Admin::query()->firstOrFail();
        ShopSetting::put('documents.invoices_enabled', false);
        $this->assertFalse(DocumentCommerceSettings::invoicesEnabled());

        $order = Order::createTrusted([
            'number' => 'QA-INV-1',
            'email' => 'invoice@example.com',
            'status' => 'pending',
            'currency_code' => 'USD',
            'payment_status' => 'paid',
            'billing_address' => ['first_name' => 'Ada'],
            'shipping_address' => ['first_name' => 'Ada'],
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/orders/'.$order->id.'/invoice')
            ->assertRedirect('/admin/order-settings?tab=invoices')
            ->assertSessionHas('error', InvoiceDocument::DISABLED_MESSAGE);

        $this->actingAs($admin, 'admin')
            ->get('/admin/invoices')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Invoices/Index')
                ->where('invoices_enabled', false));
    }

    public function test_search_settings_save_when_starter_rows_are_blank(): void
    {
        $admin = Admin::query()->firstOrFail();

        $this->actingAs($admin, 'admin')->from('/admin/product-settings?tab=search')->put('/admin/search-settings', [
            'default_sort' => 'price_asc',
            'per_page' => 16,
            'suggestions' => 1,
            'typo_tolerance' => 0,
            'recently_viewed' => 1,
            'wishlist_enabled' => 1,
            'synonyms' => [['term' => '', 'aliases' => '', 'is_enabled' => 1]],
            'pins' => [['query' => '', 'product_id' => '', 'position' => 0]],
            'redirects' => [['query' => '', 'target_path' => '', 'is_enabled' => 1]],
        ])->assertRedirect('/admin/product-settings?tab=search')->assertSessionHas('success');

        $this->assertSame('price_asc', SearchCatalogSettings::defaultSort());
        $this->assertSame(16, SearchCatalogSettings::perPage());
    }

    public function test_pricing_precision_and_rounding_change_displayed_prices(): void
    {
        $before = StorefrontCatalogCache::version();
        $admin = Admin::query()->firstOrFail();

        $this->actingAs($admin, 'admin')->put('/admin/pricing-settings', [
            'tax_inclusive_input' => 0,
            'storefront_display' => 'with_tax',
            'decimal_precision' => 0,
            'rounding' => 'up',
            'zero_price' => 'allow',
            'sale_enabled' => 1,
            'compare_at_enabled' => 1,
            'shipping_tax' => 1,
        ])->assertRedirect();

        $this->assertGreaterThan($before, StorefrontCatalogCache::version());
        $this->assertSame(0, PricingSettings::decimalPrecision());
        $this->assertMatchesRegularExpression('/11(?:\.0+)?(?!\d)/', Money::format(10.01));

        ShopSetting::put('pricing.rounding', 'down');
        $this->assertMatchesRegularExpression('/10(?:\.0+)?(?!\d)/', Money::format(10.99));

        ShopSetting::put('pricing.decimal_precision', 3);
        ShopSetting::put('pricing.rounding', 'nearest');
        $this->assertStringContainsString('10.125', Money::format(10.1246));
    }

    public function test_compare_keeps_both_products_and_highlight_is_on_the_shop_payload(): void
    {
        $first = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $second = Product::query()->create([
            'type' => 'simple',
            'sku' => 'COMPARE-2',
            'price' => 12,
            'is_enabled' => true,
            'manage_stock' => false,
        ]);
        $products = collect([$first, $second]);

        $this->getJson('/api/v1/catalog/compare?'.http_build_query(['ids' => $products->pluck('id')->all()]))
            ->assertOk()
            ->assertJsonCount(2, 'products')
            ->assertJsonPath('products.0.id', $products[0]->id)
            ->assertJsonPath('products.1.id', $products[1]->id);

        $this->getJson('/api/v1/store')
            ->assertOk()
            ->assertJsonPath('products.compare_enabled', true)
            ->assertJsonPath('products.list_highlight.color', ProductSettings::color())
            ->assertJsonPath('products.list_highlight.duration_ms', ProductSettings::durationMs())
            ->assertJsonPath('commerce.pricing.decimal_precision', PricingSettings::decimalPrecision());
    }

    public function test_order_amount_limits_compare_in_the_default_currency(): void
    {
        ShopSetting::put('orders.min_amount', 100);
        ShopSetting::put('orders.max_amount', 200);

        OrderSettings::assertWithinTotal(100, 'USD');
        OrderSettings::assertWithinTotal(8300, 'INR');

        try {
            OrderSettings::assertWithinTotal(4150, 'INR');
            $this->fail('A cart worth 50 dollars must miss a 100 dollar minimum.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('₹', $e->errors()['order'][0]);
        }

        try {
            OrderSettings::assertWithinTotal(20000, 'INR');
            $this->fail('A cart above 200 dollars must miss the maximum.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('order', $e->errors());
        }
    }

    public function test_admin_new_order_emails_are_saved_as_a_comma_separated_list(): void
    {
        $admin = Admin::query()->firstOrFail();
        $order = OrderSettings::payload();
        $payload = array_merge($order['defaults'], $order['checkout'], $order['payment'], $order['notifications']);
        $payload['email_admin_new_order'] = true;
        $payload['admin_new_order_emails'] = 'admin@wentocart.test, Buyer@Shop.test, admin@wentocart.test';

        $this->actingAs($admin, 'admin')->put('/admin/order-settings', $payload)->assertRedirect();

        $this->assertSame(
            ['admin@wentocart.test', 'buyer@shop.test'],
            OrderSettings::adminNewOrderEmails(),
        );

        $payload['admin_new_order_emails'] = 'not-an-email, owner@shop.test';
        $this->actingAs($admin, 'admin')->from('/admin/order-settings')
            ->put('/admin/order-settings', $payload)
            ->assertRedirect('/admin/order-settings')
            ->assertSessionHasErrors('admin_new_order_emails');
        $this->assertSame(
            ['admin@wentocart.test', 'buyer@shop.test'],
            OrderSettings::adminNewOrderEmails(),
        );
    }
}
