<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\GiftCard;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductComponent;
use App\Domain\Customer\Models\Customer;
use App\Domain\Localization\Models\Country;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\ShopReturn;
use App\Domain\Order\Services\ShopReturnService;
use App\Domain\Plugin\Models\InstalledPlugin;
use App\Domain\Plugin\PluginKernel;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Payment\PaymentSettings;
use App\Domain\Promo\Models\DiscountRule;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Shipping\Models\ShippingZone;
use App\Domain\Subscription\Models\CustomerSubscription;
use App\Domain\Subscription\Services\SubscriptionBilling;
use App\Domain\Tax\Models\TaxClass;
use App\Domain\Tax\Models\TaxRate;
use App\Domain\Tax\Models\TaxRule;
use App\Domain\Tax\Models\TaxZone;
use App\Domain\Tax\TaxEngine;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\StagesArchivedExtension;
use Tests\TestCase;

class RemainingMustGapsTest extends TestCase
{
    use RefreshDatabase;
    use StagesArchivedExtension;

    public function test_compound_tax_layers_stack_on_prior_tax(): void
    {
        $applied = app(TaxEngine::class)->applyLayers(100, [
            ['rate' => 10, 'name' => 'GST', 'is_compound' => false],
            ['rate' => 5, 'name' => 'Cess', 'is_compound' => true],
        ], false);

        $this->assertEquals(15.5, $applied['tax']);
    }

    public function test_shipping_zone_hides_method_outside_the_zone(): void
    {
        $this->seed();
        $us = Country::query()->where('code', 'US')->firstOrFail();
        $zone = ShippingZone::query()->create(['name' => 'USA only', 'is_enabled' => true, 'position' => 1]);
        $us->update(['zone_id' => $zone->id]);
        $method = ShippingMethod::query()->create([
            'code' => 'zone-ground',
            'name' => 'Zone ground',
            'type' => 'flat',
            'driver' => 'offline',
            'price' => 9,
            'is_enabled' => true,
            'position' => 50,
            'conditions' => array_merge(ShippingMethod::emptyConditions(), ['zone_ids' => [$zone->id]]),
            'rate_config' => ['tiers' => []],
        ]);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();

        $usOptions = $this->getJson('/api/v1/checkout/options?country_code=US')->assertOk();
        $this->assertNotNull(collect($usOptions->json('shipping_methods'))->firstWhere('id', $method->id));

        $inOptions = $this->getJson('/api/v1/checkout/options?country_code=IN')->assertOk();
        $this->assertNull(collect($inOptions->json('shipping_methods'))->firstWhere('id', $method->id));
    }

    public function test_paypal_without_keys_completes_checkout_in_sandbox(): void
    {
        $this->seed();
        $this->assertTrue(PaymentSettings::sandboxPay());
        $paypal = PaymentMethod::query()->create([
            'code' => 'paypal-test',
            'name' => 'PayPal',
            'driver' => 'paypal',
            'is_enabled' => true,
            'position' => 30,
            'config' => [],
            'rules' => PaymentMethod::emptyRules(),
        ]);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $shipping = ShippingMethod::query()->where('is_enabled', true)->firstOrFail();
        $customer = Customer::factory()->create();

        $this->actingAs($customer, 'customer')->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $placed = $this->actingAs($customer, 'customer')->postJson('/api/v1/checkout', [
            'email' => $customer->email,
            'payment_method_id' => $paypal->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated();

        $url = (string) $placed->json('payment.redirect_url');
        $this->assertStringContainsString('/pay/paypal/', $url);
        $this->get($url)->assertOk()->assertSee('Sandbox');
    }

    public function test_gift_card_wallet_lists_customer_cards(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $customer = Customer::factory()->create();
        GiftCard::query()->create([
            'product_id' => $product->id,
            'code' => 'WALLET1',
            'initial_balance' => 25,
            'current_balance' => 12,
            'selected_amount' => 25,
            'status' => 'active',
            'customer_id' => $customer->id,
            'issued_at' => now(),
        ]);

        $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/gift-cards')
            ->assertOk()
            ->assertJsonPath('gift_cards.0.code', 'WALLET1');
    }

    public function test_admin_can_impersonate_and_stop(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $customer = Customer::factory()->create();

        $this->actingAs($admin, 'admin')
            ->post('/admin/customers/'.$customer->id.'/impersonate')
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($customer, 'customer');
        $this->getJson('/api/v1/auth/me')->assertJsonPath('customer.impersonating', true);

        $this->actingAs($customer, 'customer')->postJson('/api/v1/account/stop-impersonation')->assertOk();
        $this->assertGuest('customer');
    }

    public function test_shop_return_can_be_marked_received(): void
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
        $itemId = (int) $this->actingAs($customer, 'customer')->getJson('/api/v1/account/orders/'.$number)->json('order.items.0.id');
        $this->actingAs($customer, 'customer')->postJson('/api/v1/account/orders/'.$number.'/returns', [
            'items' => [['order_item_id' => $itemId, 'qty' => 1]],
        ])->assertCreated();

        $row = ShopReturn::query()->latest('id')->firstOrFail();
        $returns = app(ShopReturnService::class);
        if ($row->status === ShopReturn::STATUS_REQUESTED) {
            $row = $returns->approve($row);
        }
        $row = $returns->receive($row);
        $this->assertSame(ShopReturn::STATUS_RECEIVED, $row->status);
    }

    public function test_subscription_tick_creates_a_pending_renewal_order(): void
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
        $order = Order::query()->where('number', $placed->json('order.number'))->with('items')->firstOrFail();
        $item = $order->items->firstOrFail();
        $sub = CustomerSubscription::query()->create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'product_id' => $product->id,
            'status' => CustomerSubscription::STATUS_ACTIVE,
            'started_at' => now()->subMonth(),
            'next_renewal_at' => now()->subDay(),
            'cycles_completed' => 0,
        ]);

        $before = Order::query()->count();
        $this->assertSame(1, app(SubscriptionBilling::class)->tick());
        $this->assertSame($before + 1, Order::query()->count());
        $this->assertNotNull($sub->fresh()->last_renewal_order_id);
        $this->assertSame(CustomerSubscription::STATUS_PAST_DUE, $sub->fresh()->status);
        $renewal = Order::query()->find($sub->fresh()->last_renewal_order_id);
        $this->assertSame('pending', $renewal->payment_status);
    }

    public function test_bogo_makes_the_cheapest_matching_unit_free(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        DiscountRule::query()->create([
            'name' => 'BOGO',
            'apply_as' => 'cart',
            'action' => 'buy_x_get_y',
            'value' => 2,
            'priority' => 1,
            'stop_further' => false,
            'is_enabled' => true,
        ]);
        $customer = Customer::factory()->create();
        $this->actingAs($customer, 'customer')->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 3])->assertOk();
        $totals = $this->actingAs($customer, 'customer')->getJson('/api/v1/cart')->json('totals');
        $unit = (float) $product->sellingPrice();
        $this->assertEqualsWithDelta($unit, (float) $totals['discount'], 0.05);
    }

    public function test_group_pdp_adds_selected_children(): void
    {
        $this->seed();
        $parent = Product::query()->create([
            'type' => 'group',
            'sku' => 'GROUP-PARENT',
            'price' => 0,
            'stock_qty' => 10,
            'is_enabled' => true,
            'available_for_order' => true,
            'selection_mode' => 'customer',
            'pricing_mode' => 'sum',
        ]);
        $child = Product::query()->create([
            'type' => 'simple',
            'sku' => 'GROUP-CHILD',
            'price' => 12,
            'stock_qty' => 10,
            'is_enabled' => true,
            'available_for_order' => true,
            'manage_stock' => false,
        ]);
        ProductComponent::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $child->id,
            'quantity' => 1,
            'qty_editable' => true,
            'is_required' => false,
            'default_selected' => true,
            'position' => 0,
        ]);

        $cart = $this->postJson('/api/v1/cart', [
            'product_id' => $parent->id,
            'qty' => 1,
            'components' => [['product_id' => $child->id, 'qty' => 2]],
        ])->assertOk()->json('cart.items');

        $this->assertSame($child->id, (int) ($cart[0]['product_id'] ?? $cart[0]['product']['id'] ?? 0));
        $this->assertSame(2, (int) $cart[0]['qty']);
        $this->assertSame($parent->id, (int) ($cart[0]['parent_product_id'] ?? 0));
    }

    public function test_merchant_feed_includes_image_and_identifiers(): void
    {
        $this->seed();
        $this->get('/feeds/google-merchant.xml')
            ->assertOk()
            ->assertSee('<g:image_link>', false);
    }

    public function test_local_catalog_installs_stripe_from_extensions_home(): void
    {
        if (! is_dir(base_path('extensions/weblytic/wtsstripe'))) {
            $this->markTestSkipped('Stripe is installed from the marketplace, not this source tree.');
        }
        $this->seed();
        PluginKernel::reset();
        $admin = Admin::query()->firstOrFail();
        $this->assertTrue(collect(app(\App\Domain\Plugin\LocalExtensionCatalog::class)->available())->contains(
            fn ($row) => ($row['vendor'] ?? '') === 'weblytic' && ($row['name'] ?? '') === 'wtsstripe'
        ));
        $this->actingAs($admin, 'admin')
            ->get('/admin/extensions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('catalog'));

        try {
            $this->actingAs($admin, 'admin')
                ->from('/admin/extensions')
                ->post('/admin/extensions/catalog/weblytic/wtsstripe')
                ->assertRedirect('/admin/extensions');
            $this->assertTrue(
                InstalledPlugin::query()->where('vendor', 'weblytic')->where('name', 'wtsstripe')->exists()
            );
        } finally {
            if (InstalledPlugin::query()->where('vendor', 'weblytic')->where('name', 'wtsstripe')->exists()) {
                app(\App\Domain\Plugin\PluginInstaller::class)->uninstall('weblytic', 'wtsstripe');
            }
            PluginKernel::reset();
        }
    }

    public function test_paused_subscription_is_not_invoiced_on_tick(): void
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
        $order = Order::query()->where('number', $placed->json('order.number'))->with('items')->firstOrFail();
        $item = $order->items->firstOrFail();
        $sub = CustomerSubscription::query()->create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'product_id' => $product->id,
            'status' => CustomerSubscription::STATUS_ACTIVE,
            'started_at' => now()->subMonth(),
            'next_renewal_at' => now()->subDay(),
            'cycles_completed' => 0,
        ]);

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/account/subscriptions/'.$sub->id.'/pause')
            ->assertOk();
        $this->assertSame(CustomerSubscription::STATUS_PAUSED, $sub->fresh()->status);
        $this->assertSame(0, app(SubscriptionBilling::class)->tick());
        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/account/subscriptions/'.$sub->id.'/skip')
            ->assertOk();
        $this->assertTrue($sub->fresh()->next_renewal_at->isFuture());
    }

    public function test_cart_applies_stacked_tax_rules_for_a_class(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $class = TaxClass::query()->create(['name' => 'Compound demo']);
        $zone = TaxZone::query()->create(['name' => 'Worldwide', 'is_enabled' => true, 'position' => 99]);
        $gst = TaxRate::query()->create(['name' => 'GST 10', 'rate' => 10, 'is_enabled' => true]);
        $cess = TaxRate::query()->create(['name' => 'Cess 5', 'rate' => 5, 'is_enabled' => true]);
        TaxRule::query()->create([
            'tax_class_id' => $class->id,
            'tax_zone_id' => $zone->id,
            'tax_rate_id' => $gst->id,
            'name' => 'GST',
            'rate' => 10,
            'priority' => 1,
            'is_compound' => false,
            'is_enabled' => true,
        ]);
        TaxRule::query()->create([
            'tax_class_id' => $class->id,
            'tax_zone_id' => $zone->id,
            'tax_rate_id' => $cess->id,
            'name' => 'Cess',
            'rate' => 5,
            'priority' => 2,
            'is_compound' => true,
            'is_enabled' => true,
        ]);
        $product->update(['tax_class_id' => $class->id]);

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $tax = (float) $this->getJson('/api/v1/cart?country_code=US')->json('totals.tax');
        $base = (float) $product->sellingPrice();
        $this->assertEqualsWithDelta(round($base * 0.10 + ($base * 1.10) * 0.05, 2), $tax, 0.05);
    }

    public function test_weblytic_wtsstripe_is_installable_from_local_catalog(): void
    {
        if (! is_dir(base_path('extensions/weblytic/wtsstripe'))) {
            $this->markTestSkipped('Stripe is installed from the marketplace, not this source tree.');
        }
        $this->seed();
        $catalog = collect(app(\App\Domain\Plugin\LocalExtensionCatalog::class)->available());
        $row = $catalog->firstWhere('id', 'weblytic/wtsstripe');
        $this->assertNotNull($row, 'weblytic/wtsstripe missing from local catalog');
        $this->assertTrue((bool) $row['installable'], 'weblytic/wtsstripe should be installable');
        $this->assertFalse(
            $catalog->contains(fn ($r) => str_starts_with((string) ($r['id'] ?? ''), 'community/')),
            'Archived community packages must not appear in the local catalog'
        );
    }

    public function test_community_fedex_quotes_weight_and_international_surcharge(): void
    {
        if (! is_dir(base_path('unused-extensions/community/fedex'))) {
            $this->markTestSkipped('FedEx sample lives on branch archive/unused-extensions.');
        }
        $this->seed();
        PluginKernel::reset();
        $this->stageArchivedExtension('community', 'fedex');
        $admin = Admin::query()->firstOrFail();
        try {
            $this->actingAs($admin, 'admin')
                ->from('/admin/extensions')
                ->post('/admin/extensions/catalog/community/fedex')
                ->assertRedirect('/admin/extensions')
                ->assertSessionHasNoErrors();
            $this->actingAs($admin, 'admin')
                ->from('/admin/extensions')
                ->post('/admin/extensions/community/fedex/enable', ['enabled' => 1])
                ->assertRedirect()
                ->assertSessionHasNoErrors();
            PluginKernel::reset();
            app(PluginKernel::class)->boot();
            $row = InstalledPlugin::query()->where('vendor', 'community')->where('name', 'fedex')->first();
            $this->assertNotNull($row);
            $this->assertTrue((bool) $row->is_enabled);
            $method = ShippingMethod::query()->where('code', 'fedex')->first();
            $this->assertNotNull($method, 'FedEx shipping method missing; health='.($row->health_status ?? '').' err='.($row->last_error ?? ''));
            $this->assertEquals(20.0, $method->quoteFor(40, 2, ['country_code' => 'US']));
            $this->assertEquals(28.0, $method->quoteFor(40, 2, ['country_code' => 'GB']));
        } finally {
            if (InstalledPlugin::query()->where('vendor', 'community')->where('name', 'fedex')->exists()) {
                app(\App\Domain\Plugin\PluginInstaller::class)->uninstall('community', 'fedex');
            }
            $this->cleanupStagedExtensions();
            PluginKernel::reset();
        }
    }

    public function test_upi_without_vpa_completes_checkout_in_sandbox(): void
    {
        $this->seed();
        $this->assertTrue(PaymentSettings::sandboxPay());
        $upi = PaymentMethod::query()->create([
            'code' => 'upi-test',
            'name' => 'UPI',
            'driver' => 'upi',
            'is_enabled' => true,
            'position' => 31,
            'config' => [],
            'rules' => PaymentMethod::emptyRules(),
        ]);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $shipping = ShippingMethod::query()->where('is_enabled', true)->firstOrFail();
        $customer = Customer::factory()->create();

        $this->actingAs($customer, 'customer')->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $placed = $this->actingAs($customer, 'customer')->postJson('/api/v1/checkout', [
            'email' => $customer->email,
            'payment_method_id' => $upi->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated();

        $url = (string) $placed->json('payment.redirect_url');
        $this->assertStringContainsString('/pay/upi/', $url);
        $this->assertNotSame('paid', $placed->json('order.payment_status'));
    }

    public function test_quantity_break_applies_when_adding_to_cart(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->qtyPrices()->create(['min_qty' => 2, 'price' => 10, 'position' => 1]);

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 2])->assertOk();
        $cart = $this->getJson('/api/v1/cart')->assertOk();
        $item = collect($cart->json('cart.items'))->firstWhere('product_id', $product->id);
        $this->assertNotNull($item);
        $this->assertEquals(10.0, (float) $item['unit_price']);
    }

    public function test_warehouse_admin_creates_a_location(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $this->actingAs($admin, 'admin')
            ->post('/admin/warehouses', [
                'name' => 'East',
                'code' => 'EAST',
                'is_enabled' => true,
                'is_default' => false,
                'email' => 'warehouse@example.com',
                'address' => '123 Main Street',
                'city' => 'Springfield',
                'postcode' => '62701',
                'country' => 'US',
                'state' => 'IL',
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('warehouses', ['code' => 'EAST', 'name' => 'East']);
    }

    public function test_shop_countries_include_cities(): void
    {
        $this->seed();
        $us = Country::query()->where('code', 'US')->firstOrFail();
        \App\Domain\Localization\Models\City::query()->create([
            'country_id' => $us->id,
            'name' => 'Austin',
            'is_enabled' => true,
            'position' => 1,
        ]);
        $this->getJson('/api/v1/store')->assertOk()
            ->assertJsonFragment(['name' => 'Austin']);
    }

    public function test_search_synonym_expands_query(): void
    {
        $this->seed();
        \App\Domain\Search\Models\SearchSynonym::query()->create([
            'term' => 'sofa',
            'aliases' => 'couch',
            'is_enabled' => true,
        ]);
        $terms = app(\App\Domain\Search\CatalogSearch::class)->expand('couch');
        $this->assertContains('sofa', $terms);
        $this->assertContains('couch', $terms);
    }

    public function test_seller_payout_export_is_csv(): void
    {
        $this->seed();
        \App\Domain\Marketplace\MarketplaceSettings::setEnabled(true);
        $admin = Admin::query()->firstOrFail();
        $this->actingAs($admin, 'admin')
            ->get('/admin/multivendor/payouts/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_customer_totp_blocks_login_until_code(): void
    {
        $this->seed();
        $customer = Customer::factory()->create();
        $customer->forceFill([
            'totp_secret' => \App\Domain\Security\Totp::secret(),
            'totp_confirmed_at' => now(),
        ])->save();
        $this->postJson('/api/v1/auth/login', [
            'email' => $customer->email,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('mfa_required', true);

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('customer', null);
    }

    public function test_service_product_requires_booking_date(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->update(['type' => 'service', 'manage_stock' => false]);
        app(\App\Domain\Catalog\Services\ServiceProductService::class)->syncProduct($product, [
            'service_type' => 'appointment',
            'duration' => 60,
            'duration_unit' => 'minutes',
            'pricing_model' => 'fixed',
            'delivery_method' => 'online',
            'availability_config' => [
                'available_days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
                'start_time' => '00:00',
                'end_time' => '23:59',
                'min_advance_hours' => 0,
                'max_advance_days' => 365,
            ],
        ]);
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])
            ->assertStatus(422);
        $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'qty' => 1,
            'booking_date' => now()->addDays(2)->toDateString(),
        ])->assertOk();
    }

    public function test_sandbox_carrier_quotes_without_live_keys(): void
    {
        $this->seed();
        $method = ShippingMethod::query()->where('code', 'sandbox-ground')->firstOrFail();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $options = $this->getJson('/api/v1/checkout/options?country_code=IN')->assertOk();
        $row = collect($options->json('shipping_methods'))->firstWhere('id', $method->id);
        $this->assertNotNull($row);
        $this->assertGreaterThan(8, (float) $row['price']);
    }

    public function test_admin_buys_a_sandbox_shipping_label(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $payment = PaymentMethod::query()->firstOrFail();
        $shipping = ShippingMethod::query()->where('code', 'sandbox-ground')->firstOrFail();
        $customer = Customer::factory()->create();
        $this->actingAs($customer, 'customer')->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $placed = $this->actingAs($customer, 'customer')->postJson('/api/v1/checkout', [
            'email' => $customer->email,
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated();
        $order = Order::query()->where('number', $placed->json('order.number'))->firstOrFail();
        $admin = Admin::query()->firstOrFail();
        $this->actingAs($admin, 'admin')
            ->from('/admin/orders/'.$order->id)
            ->post('/admin/orders/'.$order->id.'/label')
            ->assertRedirect();
        $this->assertNotNull($order->fresh()->shipments()->first()?->tracking_number);
    }

    public function test_warehouse_scope_deducts_from_the_stocked_location(): void
    {
        $this->seed();
        \App\Domain\Settings\ShopSetting::put('inventory.scope', 'warehouse');
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $default = \App\Domain\Catalog\Models\Warehouse::query()->where('is_default', true)->firstOrFail();
        $east = \App\Domain\Catalog\Models\Warehouse::query()->create([
            'name' => 'East DC',
            'code' => 'east-dc',
            'is_enabled' => true,
            'is_default' => false,
        ]);
        \App\Domain\Catalog\Models\WarehouseStock::query()->updateOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $default->id],
            ['qty' => 0, 'reserved_qty' => 0]
        );
        \App\Domain\Catalog\Models\WarehouseStock::query()->updateOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $east->id],
            ['qty' => 40, 'reserved_qty' => 0]
        );
        $product->update(['stock_qty' => 40, 'manage_stock' => true]);
        $picked = app(\App\Domain\Catalog\Services\InventoryService::class)->pickWarehouse($product->fresh(), 1);
        $this->assertSame($east->id, $picked->id);

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
        $order = Order::query()->where('number', $placed->json('order.number'))->with('items')->firstOrFail();
        $this->assertSame($east->id, (int) $order->items->first()->warehouse_id);
    }
}
