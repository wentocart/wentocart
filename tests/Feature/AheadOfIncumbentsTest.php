<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductComponent;
use App\Domain\Catalog\Models\UrlRewrite;
use App\Domain\Customer\Models\B2bQuote;
use App\Domain\Customer\Models\Company;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerProductPrice;
use App\Domain\Customer\Services\CompanyService;
use App\Domain\Customer\Services\LoyaltyService;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Search\Models\SearchRedirect;
use App\Domain\Settings\ShopSetting;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AheadOfIncumbentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_net_terms_payment_is_offered_and_enforced(): void
    {
        $this->seed();
        $this->postJson('/api/v1/auth/register', [
            'name' => 'B2B Buyer',
            'email' => 'b2b.buyer@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $customer = Customer::query()->where('email', 'b2b.buyer@example.test')->firstOrFail();
        $customer->update(['customer_group' => 'wholesale']);
        $company = app(CompanyService::class)->create([
            'name' => 'Acme Wholesale',
            'credit_limit' => 500,
            'net_days' => 30,
        ]);
        app(CompanyService::class)->attach($company, $customer->id, 'manager');
        $customer->refresh();

        $invoice = PaymentMethod::query()->where('code', 'invoice')->firstOrFail();
        $this->actingAs($customer, 'customer');
        $this->postJson('/api/v1/cart', [
            'product_id' => Product::query()->where('sku', 'DEMO-001')->value('id'),
            'qty' => 1,
        ])->assertOk();
        $options = $this->getJson('/api/v1/checkout/options')->assertOk()->json('payment_methods');
        $this->assertTrue(collect($options)->contains(fn ($row) => ($row['code'] ?? '') === 'invoice'));

        $shipping = ShippingMethod::query()->firstOrFail();
        $this->postJson('/api/v1/checkout', [
            'email' => $customer->email,
            'payment_method_id' => $invoice->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated();
        $order = Order::query()->where('email', $customer->email)->latest('id')->firstOrFail();
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame($invoice->id, $order->payment_method_id);
    }

    public function test_accepted_quote_loads_into_cart_at_quoted_prices(): void
    {
        $this->seed();
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Quote Buyer',
            'email' => 'quote.buyer@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $customer = Customer::query()->where('email', 'quote.buyer@example.test')->firstOrFail();
        $this->actingAs($customer, 'customer');
        $this->postJson('/api/v1/cart', [
            'product_id' => Product::query()->where('sku', 'DEMO-001')->value('id'),
            'qty' => 2,
        ])->assertOk();
        $quoteId = $this->postJson('/api/v1/account/quotes', [])->assertCreated()->json('quote.id');
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/quotes/'.$quoteId.'/accept')
            ->assertRedirect();
        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/account/quotes/'.$quoteId.'/checkout')
            ->assertOk();
        $this->assertSame(B2bQuote::STATUS_ACCEPTED, B2bQuote::query()->find($quoteId)?->status);
        $cart = $this->getJson('/api/v1/cart')->assertOk()->json('cart.items');
        $this->assertNotEmpty($cart);
        $this->assertSame(2, (int) $cart[0]['qty']);
    }

    public function test_bundle_sale_deducts_component_stock(): void
    {
        $this->seed();
        $childA = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $childB = Product::query()->create([
            'type' => 'simple',
            'sku' => 'KIT-PART-B',
            'price' => 8,
            'stock_qty' => 10,
            'is_enabled' => true,
            'available_for_order' => true,
            'manage_stock' => true,
            'visibility' => 'both',
        ]);
        $childB->translations()->create([
            'language_code' => 'en',
            'name' => 'Kit part B',
            'slug' => 'kit-part-b',
        ]);
        $startA = (int) $childA->stock_qty;
        $startB = (int) $childB->stock_qty;
        $bundle = Product::query()->create([
            'type' => 'bundle',
            'sku' => 'KIT-AHEAD',
            'price' => 20,
            'is_enabled' => true,
            'available_for_order' => true,
            'manage_stock' => false,
            'inventory_mode' => 'derived',
            'pricing_mode' => 'fixed',
            'visibility' => 'both',
        ]);
        $bundle->translations()->create([
            'language_code' => 'en',
            'name' => 'Ahead Kit',
            'slug' => 'ahead-kit',
        ]);
        ProductComponent::query()->create([
            'parent_product_id' => $bundle->id,
            'child_product_id' => $childA->id,
            'quantity' => 1,
            'is_required' => true,
            'default_selected' => true,
            'position' => 0,
        ]);
        ProductComponent::query()->create([
            'parent_product_id' => $bundle->id,
            'child_product_id' => $childB->id,
            'quantity' => 2,
            'is_required' => true,
            'default_selected' => true,
            'position' => 1,
        ]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Kit Buyer',
            'email' => 'kit.buyer@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $this->postJson('/api/v1/cart', ['product_id' => $bundle->id, 'qty' => 1])->assertOk();
        $payment = PaymentMethod::query()->where('code', '!=', 'invoice')->firstOrFail();
        $shipping = ShippingMethod::query()->firstOrFail();
        $this->postJson('/api/v1/checkout', [
            'email' => 'kit.buyer@example.test',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Kit', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Kit', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated();

        $this->assertSame($startA - 1, (int) $childA->fresh()->stock_qty);
        $this->assertSame($startB - 2, (int) $childB->fresh()->stock_qty);
    }

    public function test_search_redirect_and_facets_and_negotiated_price(): void
    {
        $this->seed();
        SearchRedirect::query()->create([
            'query' => 'shoes',
            'target_path' => '/catalog/footwear',
            'is_enabled' => true,
        ]);
        $this->getJson('/api/v1/catalog/search?q=shoes')
            ->assertOk()
            ->assertJsonPath('redirect', '/catalog/footwear');

        $this->getJson('/api/v1/catalog/search?q=demo')
            ->assertOk()
            ->assertJsonStructure(['data', 'filters']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Deal Buyer',
            'email' => 'deal.buyer@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $customer = Customer::query()->where('email', 'deal.buyer@example.test')->firstOrFail();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        CustomerProductPrice::query()->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'price' => 1.11,
        ]);
        $this->actingAs($customer, 'customer');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $unit = (float) $this->getJson('/api/v1/cart')->json('cart.items.0.unit_price');
        $this->assertEqualsWithDelta(1.11, $unit, 0.01);
    }

    public function test_partial_refund_restock_loyalty_age_gate_and_json_ld(): void
    {
        $this->seed();
        ShopSetting::put('loyalty.enabled', true);
        ShopSetting::put('loyalty.earn_percent', 10);
        ShopSetting::put('privacy.age_gate', true);
        $this->getJson('/api/v1/store')
            ->assertOk()
            ->assertJsonPath('commerce.privacy.age_gate', true)
            ->assertJsonPath('commerce.loyalty.enabled', true);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Desk Buyer',
            'email' => 'desk.buyer@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $customer = Customer::query()->where('email', 'desk.buyer@example.test')->firstOrFail();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $start = (int) $product->stock_qty;
        $this->actingAs($customer, 'customer');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $payment = PaymentMethod::query()->where('code', '!=', 'invoice')->firstOrFail();
        $shipping = ShippingMethod::query()->firstOrFail();
        $this->postJson('/api/v1/checkout', [
            'email' => $customer->email,
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Desk', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Desk', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated();
        $order = Order::query()->where('email', $customer->email)->latest('id')->firstOrFail();
        $order->forceFill(['payment_status' => 'paid', 'grand_total' => 40])->save();
        app(LoyaltyService::class)->earn($customer, $order);
        $this->assertGreaterThan(0, app(LoyaltyService::class)->balance($customer));

        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/orders/'.$order->id.'/refund', ['amount' => 10, 'restock' => 1])
            ->assertRedirect();
        $this->assertSame('partially_refunded', $order->fresh()->payment_status);
        $this->assertSame($start, (int) $product->fresh()->stock_qty);

        $this->post('/admin/orders/'.$order->id.'/comments', [
            'body' => 'Packed separately',
            'visibility' => 'customer',
        ])->assertRedirect();
        $this->assertDatabaseHas('order_comments', ['order_id' => $order->id, 'visibility' => 'customer']);

        $this->post('/admin/orders/'.$order->id.'/shipments', [
            'items' => [['order_item_id' => $order->items()->first()->id, 'qty' => 1]],
        ])->assertRedirect();

        $path = UrlRewrite::query()->where('entity_type', 'product')->value('request_path');
        if ($path) {
            $html = $this->get('/'.$path)->assertOk()->getContent();
            $this->assertStringContainsString('BreadcrumbList', $html);
        }
    }

    public function test_pickup_eta_and_store_credit_toggle_and_companies_admin(): void
    {
        $this->seed();
        $pickup = ShippingMethod::query()->updateOrCreate(
            ['code' => 'store-pickup'],
            [
                'name' => 'Collect in store',
                'type' => 'local_pickup',
                'driver' => 'offline',
                'price' => 0,
                'is_enabled' => true,
                'position' => 9,
                'conditions' => ShippingMethod::emptyConditions(),
                'rate_config' => ['tiers' => [], 'min_days' => 0, 'max_days' => 1],
            ]
        );
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Pickup Buyer',
            'email' => 'pickup.buyer@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $this->postJson('/api/v1/cart', [
            'product_id' => Product::query()->where('sku', 'DEMO-001')->value('id'),
            'qty' => 1,
        ])->assertOk();
        $row = collect($this->getJson('/api/v1/checkout/options')->json('shipping_methods'))
            ->firstWhere('id', $pickup->id);
        $this->assertTrue((bool) ($row['is_pickup'] ?? false));
        $this->assertNotEmpty($row['eta'] ?? null);

        $this->postJson('/api/v1/cart/store-credit', ['enabled' => false])->assertOk();
        $this->assertFalse((bool) $this->getJson('/api/v1/cart')->json('cart.apply_store_credit'));

        $this->actingAs(Admin::query()->first(), 'admin')
            ->get('/admin/companies')
            ->assertOk();
        $this->post('/admin/companies', [
            'name' => 'Northwind',
            'credit_limit' => 1000,
            'net_days' => 45,
        ])->assertRedirect();
        $this->assertTrue(Company::query()->where('name', 'Northwind')->exists());
    }
}
