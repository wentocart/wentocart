<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPreorderSetting;
use App\Domain\Catalog\Services\CatalogPresenter;
use App\Domain\Catalog\Support\ProductCsvSchema;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Checkout\Services\CheckoutService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\OrderStageService;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Settings\ShopSetting;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PreorderCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_presenter_exposes_active_preorder_payload_and_price(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $product = $this->makePreorder([
            'sku' => 'PRE-PRES',
            'price' => 100,
            'stock_qty' => 0,
            'preorder' => [
                'start_at' => '2026-10-01T00:00',
                'end_at' => '2026-10-30T23:59',
                'release_at' => '2026-11-01T00:00',
                'payment_type' => 'deposit',
                'deposit_type' => 'percent',
                'deposit_value' => 25,
                'preorder_price' => 80,
                'min_quantity' => 1,
                'total_limit' => 10,
                'show_badge' => true,
                'message' => 'Ship after launch',
            ],
        ]);

        $data = app(CatalogPresenter::class)->product(
            $product->fresh()->load('preorderSetting'),
            'en',
            true,
        );

        $this->assertNotNull($data['preorder']);
        $this->assertTrue($data['preorder']['active']);
        $this->assertFalse($data['flags']['preorder'] ?? false);
        $this->assertFalse($data['preorder']['show_image_label']);
        $this->assertEquals(20.0, (float) $data['price']); // 25% of 80
        $this->assertEquals(80.0, (float) $data['preorder_full_price']);
        $this->assertEquals(60.0, (float) $data['preorder']['remaining_per_unit']);
        $this->assertFalse($data['preorder']['show_countdown_pdp']);
        $this->assertFalse($data['preorder']['show_countdown_listing']);
        $this->assertSame('end', $data['preorder']['countdown_kind']);
        $this->assertNotEmpty($data['preorder']['countdown_at']);
        $this->assertNotEmpty($data['preorder']['server_now']);
        $this->assertSame(
            \Illuminate\Support\Carbon::parse('2026-10-30T23:59')->toIso8601String(),
            $data['preorder']['countdown_at'],
        );
    }

    public function test_storefront_listing_omits_countdown_when_disabled_and_exposes_it_when_enabled(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));

        $hidden = $this->makePreorder([
            'sku' => 'PRE-HIDE-TIMER',
            'preorder' => [
                'end_at' => '2026-10-20T18:00',
                'show_countdown_pdp' => false,
                'show_countdown_listing' => false,
            ],
        ]);
        $shown = $this->makePreorder([
            'sku' => 'PRE-SHOW-TIMER',
            'preorder' => [
                'end_at' => '2026-12-01T09:00',
                'show_countdown_pdp' => true,
                'show_countdown_listing' => true,
                'show_image_label' => true,
            ],
        ]);
        $expired = $this->makePreorder([
            'sku' => 'PRE-ENDED-TIMER',
            'preorder' => [
                'start_at' => '2026-09-01T00:00',
                'end_at' => '2026-10-01T12:00',
                'show_countdown_pdp' => true,
                'show_countdown_listing' => true,
            ],
        ]);

        $listHidden = app(CatalogPresenter::class)->product($hidden->fresh()->load('preorderSetting'), 'en', false);
        $this->assertFalse($listHidden['preorder']['show_countdown_listing']);
        $this->assertFalse($listHidden['preorder']['show_image_label']);
        $this->assertFalse($listHidden['flags']['preorder'] ?? false);
        $this->assertNotEmpty($listHidden['preorder']['countdown_at']);

        $listShown = app(CatalogPresenter::class)->product($shown->fresh()->load('preorderSetting'), 'en', false);
        $this->assertTrue($listShown['preorder']['show_countdown_listing']);
        $this->assertTrue($listShown['preorder']['show_countdown_pdp']);
        $this->assertTrue($listShown['preorder']['show_image_label']);
        $this->assertTrue($listShown['flags']['preorder'] ?? false);
        $this->assertSame('end', $listShown['preorder']['countdown_kind']);
        $this->assertSame(
            Carbon::parse('2026-12-01T09:00')->toIso8601String(),
            $listShown['preorder']['countdown_at'],
        );

        $listExpired = app(CatalogPresenter::class)->product($expired->fresh()->load('preorderSetting'), 'en', true);
        $this->assertNull($listExpired['preorder'] ?? null);
        $this->assertFalse($listExpired['flags']['preorder'] ?? false);

        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00'));
        $scheduled = $this->makePreorder([
            'sku' => 'PRE-SOON-TIMER',
            'preorder' => [
                'start_at' => '2026-10-01T09:00',
                'end_at' => '2026-10-20T18:00',
                'show_countdown_pdp' => true,
                'show_countdown_listing' => true,
            ],
        ]);
        $soon = app(CatalogPresenter::class)->product($scheduled->fresh()->load('preorderSetting'), 'en', true);
        $this->assertNull($soon['preorder'] ?? null);
        $this->assertFalse($soon['flags']['preorder'] ?? false);
    }

    public function test_preorder_window_storefront_and_cart_follow_start_and_end(): void
    {
        $this->seed();
        ShopSetting::put('inventory.enabled', true);
        ShopSetting::put('inventory.allow_negative', false);
        ShopSetting::put('cart.stock_validation', true);

        $start = '2026-10-01T09:00';
        $end = '2026-10-20T18:00';
        Carbon::setTestNow(Carbon::parse('2026-09-30 12:00:00', config('app.timezone')));
        $product = $this->makePreorder([
            'sku' => 'PRE-WINDOW',
            'price' => 50,
            'stock_qty' => 8,
            'preorder' => [
                'start_at' => $start,
                'end_at' => $end,
                'release_at' => '2026-11-01T00:00',
                'payment_type' => 'full',
                'preorder_price' => 40,
                'min_quantity' => 1,
                'max_quantity' => null,
                'show_badge' => true,
                'show_countdown_pdp' => true,
                'show_image_label' => true,
            ],
        ]);
        $preorders = app(\App\Domain\Catalog\Services\PreorderService::class);

        // Future start: normal product (no FO pre-order payload), normal cart price/stock.
        $this->assertSame('scheduled', $preorders->status($product));
        $this->assertFalse($preorders->isActive($product));
        $before = app(CatalogPresenter::class)->product($product->fresh()->load('preorderSetting'), 'en', true);
        $this->assertNull($before['preorder'] ?? null);
        $this->assertEquals(50.0, (float) $before['price']);
        $this->assertFalse($before['flags']['preorder'] ?? false);

        $beforeCart = $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->assertNull($beforeCart->json('cart.items.0.preorder_data'));
        $this->assertEquals(50.0, (float) $beforeCart->json('cart.items.0.unit_price'));

        // Exact start instant: pre-order becomes active.
        Carbon::setTestNow(Carbon::parse($start, config('app.timezone')));
        $product = $product->fresh()->load('preorderSetting');
        $this->assertSame('open', $preorders->status($product));
        $this->assertTrue($preorders->isActive($product));
        $atStart = app(CatalogPresenter::class)->product($product, 'en', true);
        $this->assertNotNull($atStart['preorder']);
        $this->assertTrue($atStart['preorder']['active']);
        $this->assertTrue($atStart['preorder']['enabled']);
        $this->assertSame('open', $atStart['preorder']['status']);
        $this->assertEquals(40.0, (float) $atStart['price']);
        $this->assertTrue($atStart['flags']['preorder'] ?? false);

        app(CartService::class)->current()->items()->delete();
        $activeCart = $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->assertTrue((bool) $activeCart->json('cart.items.0.preorder_data.enabled'));
        $this->assertEquals(40.0, (float) $activeCart->json('cart.items.0.unit_price'));

        // Active mid-window still exposes pre-order UI/payload.
        Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00', config('app.timezone')));
        $mid = app(CatalogPresenter::class)->product($product->fresh()->load('preorderSetting'), 'en', true);
        $this->assertTrue($mid['preorder']['active']);
        $this->assertSame('open', $mid['preorder']['status']);

        // After end: back to normal product presentation; OOS pre-order stock rejects normal cart.
        Carbon::setTestNow(Carbon::parse('2026-10-21 09:00:00', config('app.timezone')));
        $product = $product->fresh()->load('preorderSetting');
        $this->assertSame('ended', $preorders->status($product));
        $this->assertFalse($preorders->isActive($product));
        $closed = app(CatalogPresenter::class)->product($product, 'en', true);
        $this->assertNull($closed['preorder'] ?? null);
        $this->assertEquals(50.0, (float) $closed['price']);

        $product->update(['stock_qty' => 0]);
        app(CartService::class)->current()->items()->delete();
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertStatus(422);
    }

    public function test_oos_preorder_can_add_to_cart_with_preorder_price_and_limits(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        ShopSetting::put('inventory.enabled', true);
        ShopSetting::put('products.disable_purchase_when_out_of_stock', true);

        $product = $this->makePreorder([
            'sku' => 'PRE-CART',
            'price' => 50,
            'stock_qty' => 0,
            'manage_stock' => true,
            'preorder' => [
                'start_at' => '2026-10-01T00:00',
                'end_at' => '2026-10-30T23:59',
                'release_at' => '2026-11-15T00:00',
                'payment_type' => 'full',
                'preorder_price' => 40,
                'min_quantity' => 2,
                'max_quantity' => 3,
                'total_limit' => 5,
            ],
        ]);

        $customer = Customer::factory()->create();
        $this->actingAs($customer, 'customer');

        $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertStatus(422);

        $ok = $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'qty' => 2,
        ])->assertOk();

        $item = $ok->json('cart.items.0');
        $this->assertTrue((bool) ($item['preorder_data']['enabled'] ?? false));
        $this->assertEquals(40.0, (float) $item['unit_price']);
        $this->assertEquals(40.0, (float) ($item['preorder_data']['full_unit_price'] ?? 0));
        $this->assertSame('awaiting_release', $item['preorder_data']['status'] ?? null);

        // Cart already has 2; adding 2 more would be 4 > max per customer 3.
        $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'qty' => 2,
        ])->assertStatus(422);
    }

    public function test_max_per_customer_requires_login_and_spans_orders(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));

        $product = $this->makePreorder([
            'sku' => 'PRE-CUST-MAX',
            'price' => 20,
            'stock_qty' => 0,
            'preorder' => [
                'start_at' => '2026-10-01T00:00',
                'end_at' => '2026-10-30T23:59',
                'payment_type' => 'full',
                'min_quantity' => 1,
                'max_quantity' => 10,
                'total_limit' => 100,
            ],
        ]);

        // Guest cannot add when a per-customer maximum is configured.
        $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertStatus(422);

        $customer = Customer::factory()->create();
        $carts = app(CartService::class);
        $pay = PaymentMethod::query()->where('is_enabled', true)->firstOrFail();
        $ship = ShippingMethod::query()->where('is_enabled', true)->value('id');
        $addr = [
            'first_name' => 'P', 'last_name' => 'O', 'line1' => '1 Main',
            'city' => 'Austin', 'state' => 'TX', 'postcode' => '78701', 'country_code' => 'US',
        ];

        $cart1 = $carts->current($customer);
        $carts->add($cart1, $product->fresh()->load('preorderSetting'), 6);
        $first = app(CheckoutService::class)->place($cart1->fresh(['items.product.preorderSetting']), [
            'email' => $customer->email,
            'payment_method_id' => $pay->id,
            'shipping_method_id' => $ship,
            'billing_address' => $addr,
            'shipping_address' => $addr,
        ], $customer);
        $this->assertNotNull($first['order']);

        $preorders = app(\App\Domain\Catalog\Services\PreorderService::class);
        $this->assertEquals(6.0, $preorders->customerActivePreorderQty($product->fresh(), (int) $customer->id));
        $this->assertEquals(4.0, $preorders->customerRemainingAllowance($product->fresh(), $customer));

        $cart2 = $carts->current($customer);
        $carts->add($cart2, $product->fresh()->load('preorderSetting'), 4);
        $second = app(CheckoutService::class)->place($cart2->fresh(['items.product.preorderSetting']), [
            'email' => $customer->email,
            'payment_method_id' => $pay->id,
            'shipping_method_id' => $ship,
            'billing_address' => $addr,
            'shipping_address' => $addr,
        ], $customer);
        $this->assertNotNull($second['order']);
        $this->assertEquals(10.0, $preorders->customerActivePreorderQty($product->fresh(), (int) $customer->id));
        $this->assertEquals(0.0, $preorders->customerRemainingAllowance($product->fresh(), $customer));

        $cart3 = $carts->current($customer);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $carts->add($cart3, $product->fresh()->load('preorderSetting'), 1);
    }

    public function test_max_per_customer_releases_on_cancel_and_blocks_concurrent_overshoot(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));

        $product = $this->makePreorder([
            'sku' => 'PRE-CUST-REL',
            'price' => 15,
            'stock_qty' => 0,
            'preorder' => [
                'start_at' => '2026-10-01T00:00',
                'end_at' => '2026-10-30T23:59',
                'payment_type' => 'full',
                'min_quantity' => 1,
                'max_quantity' => 5,
                'total_limit' => 50,
            ],
        ]);

        $customer = Customer::factory()->create();
        $carts = app(CartService::class);
        $pay = PaymentMethod::query()->where('is_enabled', true)->firstOrFail();
        $ship = ShippingMethod::query()->where('is_enabled', true)->value('id');
        $addr = [
            'first_name' => 'P', 'last_name' => 'O', 'line1' => '1 Main',
            'city' => 'Austin', 'state' => 'TX', 'postcode' => '78701', 'country_code' => 'US',
        ];

        $cart = $carts->current($customer);
        $carts->add($cart, $product->fresh()->load('preorderSetting'), 5);
        $placed = app(CheckoutService::class)->place($cart->fresh(['items.product.preorderSetting']), [
            'email' => $customer->email,
            'payment_method_id' => $pay->id,
            'shipping_method_id' => $ship,
            'billing_address' => $addr,
            'shipping_address' => $addr,
        ], $customer);

        $preorders = app(\App\Domain\Catalog\Services\PreorderService::class);
        $this->assertEquals(5.0, $preorders->customerActivePreorderQty($product->fresh(), (int) $customer->id));

        app(OrderStageService::class)->apply($placed['order'], 'cancelled', 'buyer cancel');
        $this->assertEquals(0.0, $preorders->customerActivePreorderQty($product->fresh(), (int) $customer->id));
        $this->assertEquals(5.0, $preorders->customerRemainingAllowance($product->fresh(), $customer));

        // Simulate another sale consuming global capacity after cart build — customer limit still locked at checkout.
        $cartA = $carts->current($customer);
        $carts->add($cartA, $product->fresh()->load('preorderSetting'), 3);

        $other = Customer::factory()->create();
        $cartB = $carts->current($other);
        $carts->add($cartB, $product->fresh()->load('preorderSetting'), 3);
        app(CheckoutService::class)->place($cartB->fresh(['items.product.preorderSetting']), [
            'email' => $other->email,
            'payment_method_id' => $pay->id,
            'shipping_method_id' => $ship,
            'billing_address' => $addr,
            'shipping_address' => $addr,
        ], $other);

        // Customer A still within per-customer max; checkout with lock succeeds for remaining personal allowance.
        $ok = app(CheckoutService::class)->place($cartA->fresh(['items.product.preorderSetting']), [
            'email' => $customer->email,
            'payment_method_id' => $pay->id,
            'shipping_method_id' => $ship,
            'billing_address' => $addr,
            'shipping_address' => $addr,
        ], $customer);
        $this->assertNotNull($ok['order']);
        $this->assertEquals(3.0, $preorders->customerActivePreorderQty($product->fresh(), (int) $customer->id));

        // Overshoot personal max at checkout after cart was built with stale allowance.
        $cartOver = $carts->current($customer);
        $carts->add($cartOver, $product->fresh()->load('preorderSetting'), 2);
        // Manually inflate used qty as if a concurrent checkout reserved the rest for this customer.
        $line = $ok['order']->fresh('items')->items->first();
        $data = $line->preorder_data;
        $data['qty_reserved'] = 5;
        $line->qty = 5;
        $line->preorder_data = $data;
        $line->save();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(CheckoutService::class)->place($cartOver->fresh(['items.product.preorderSetting']), [
            'email' => $customer->email,
            'payment_method_id' => $pay->id,
            'shipping_method_id' => $ship,
            'billing_address' => $addr,
            'shipping_address' => $addr,
        ], $customer);
    }

    public function test_max_per_customer_refund_releases_allowance(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));

        $product = $this->makePreorder([
            'sku' => 'PRE-CUST-REF',
            'price' => 25,
            'stock_qty' => 0,
            'preorder' => [
                'start_at' => '2026-10-01T00:00',
                'end_at' => '2026-10-30T23:59',
                'payment_type' => 'full',
                'min_quantity' => 1,
                'max_quantity' => 4,
            ],
        ]);

        $customer = Customer::factory()->create();
        $carts = app(CartService::class);
        $pay = PaymentMethod::query()->where('is_enabled', true)->firstOrFail();
        $ship = ShippingMethod::query()->where('is_enabled', true)->value('id');
        $addr = [
            'first_name' => 'P', 'last_name' => 'O', 'line1' => '1 Main',
            'city' => 'Austin', 'state' => 'TX', 'postcode' => '78701', 'country_code' => 'US',
        ];

        $cart = $carts->current($customer);
        $carts->add($cart, $product->fresh()->load('preorderSetting'), 4);
        $placed = app(CheckoutService::class)->place($cart->fresh(['items.product.preorderSetting']), [
            'email' => $customer->email,
            'payment_method_id' => $pay->id,
            'shipping_method_id' => $ship,
            'billing_address' => $addr,
            'shipping_address' => $addr,
        ], $customer);

        $preorders = app(\App\Domain\Catalog\Services\PreorderService::class);
        app(OrderStageService::class)->apply($placed['order'], 'refunded', 'refund');
        $this->assertEquals(0.0, $preorders->customerActivePreorderQty($product->fresh(), (int) $customer->id));

        $cart2 = $carts->current($customer);
        $carts->add($cart2, $product->fresh()->load('preorderSetting'), 4);
        $again = app(CheckoutService::class)->place($cart2->fresh(['items.product.preorderSetting']), [
            'email' => $customer->email,
            'payment_method_id' => $pay->id,
            'shipping_method_id' => $ship,
            'billing_address' => $addr,
            'shipping_address' => $addr,
        ], $customer);
        $this->assertNotNull($again['order']);
    }

    public function test_closed_window_and_sold_out_reject_cart(): void
    {
        $this->seed();
        ShopSetting::put('inventory.enabled', true);
        ShopSetting::put('inventory.allow_negative', false);
        ShopSetting::put('cart.stock_validation', true);

        // Before start with no stock: normal OOS rejection (not a pre-order purchase path).
        Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00'));
        $scheduled = $this->makePreorder([
            'sku' => 'PRE-SCHED',
            'price' => 10,
            'stock_qty' => 0,
            'preorder' => [
                'start_at' => '2026-10-01T00:00',
                'end_at' => '2026-10-30T23:59',
                'payment_type' => 'full',
                'min_quantity' => 1,
            ],
        ]);
        $this->postJson('/api/v1/cart', ['product_id' => $scheduled->id, 'qty' => 1])->assertStatus(422);

        // Before start with stock: normal purchase allowed, no preorder_data.
        $scheduledInStock = $this->makePreorder([
            'sku' => 'PRE-SCHED-STOCK',
            'price' => 12,
            'stock_qty' => 3,
            'preorder' => [
                'start_at' => '2026-10-01T00:00',
                'end_at' => '2026-10-30T23:59',
                'payment_type' => 'full',
                'preorder_price' => 9,
                'min_quantity' => 1,
                'max_quantity' => null,
            ],
        ]);
        $scheduledCart = $this->postJson('/api/v1/cart', ['product_id' => $scheduledInStock->id, 'qty' => 1])->assertOk();
        $this->assertNull($scheduledCart->json('cart.items.0.preorder_data'));
        $this->assertEquals(12.0, (float) $scheduledCart->json('cart.items.0.unit_price'));

        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $soldOut = $this->makePreorder([
            'sku' => 'PRE-SOLD',
            'price' => 10,
            'stock_qty' => 0,
            'preorder' => [
                'start_at' => '2026-10-01T00:00',
                'end_at' => '2026-10-30T23:59',
                'payment_type' => 'full',
                'min_quantity' => 1,
                'total_limit' => 2,
            ],
        ]);
        ProductPreorderSetting::query()->where('product_id', $soldOut->id)->update(['orders_placed' => 2]);
        $this->postJson('/api/v1/cart', ['product_id' => $soldOut->id, 'qty' => 1])->assertStatus(422);
    }

    public function test_checkout_reserves_orders_placed_skips_stock_and_cancel_releases(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        ShopSetting::put('inventory.enabled', true);

        $product = $this->makePreorder([
            'sku' => 'PRE-CHK',
            'price' => 100,
            'stock_qty' => 0,
            'manage_stock' => true,
            'preorder' => [
                'start_at' => '2026-10-01T00:00',
                'end_at' => '2026-10-30T23:59',
                'release_at' => '2026-12-01T00:00',
                'payment_type' => 'deposit',
                'deposit_type' => 'fixed',
                'deposit_value' => 30,
                'preorder_price' => 90,
                'min_quantity' => 1,
                'total_limit' => 10,
                'fulfillment_rule' => 'auto',
            ],
        ]);

        $customer = Customer::factory()->create();
        $carts = app(CartService::class);
        $cart = $carts->current($customer);
        $carts->add($cart, $product->fresh()->load('preorderSetting'), 2);

        $item = $cart->fresh('items')->items->first();
        $this->assertEquals(30.0, (float) $item->unit_price);
        $this->assertEquals(120.0, (float) ($item->preorder_data['remaining_balance'] ?? 0)); // (90-30)*2

        $pay = PaymentMethod::query()->where('is_enabled', true)->firstOrFail();
        $ship = ShippingMethod::query()->where('is_enabled', true)->value('id');
        $addr = [
            'first_name' => 'P', 'last_name' => 'O', 'line1' => '1 Main',
            'city' => 'Austin', 'state' => 'TX', 'postcode' => '78701', 'country_code' => 'US',
        ];
        $result = app(CheckoutService::class)->place($cart->fresh(['items.product.preorderSetting']), [
            'email' => $customer->email,
            'payment_method_id' => $pay->id,
            'shipping_method_id' => $ship,
            'billing_address' => $addr,
            'shipping_address' => $addr,
        ], $customer);

        $order = $result['order']->fresh('items');
        $line = $order->items->first();
        $this->assertTrue((bool) ($line->preorder_data['enabled'] ?? false));
        $this->assertEquals(30.0, (float) $line->unit_price);
        $this->assertEquals(120.0, (float) ($line->preorder_data['remaining_balance'] ?? 0));
        $this->assertSame('awaiting_release', $line->preorder_data['status'] ?? null);
        $this->assertEquals(0, (int) $product->fresh()->stock_qty);
        $this->assertEquals(2, (int) ProductPreorderSetting::query()->where('product_id', $product->id)->value('orders_placed'));

        app(OrderStageService::class)->apply($order, 'processing', 'ready preorder');
        $line = $order->fresh('items')->items->first();
        $this->assertSame('ready', $line->preorder_data['status'] ?? null);

        app(OrderStageService::class)->apply($order->fresh(), 'shipped', 'fulfill preorder');
        $line = $order->fresh('items')->items->first();
        $this->assertSame('fulfilled', $line->preorder_data['status'] ?? null);

        $order2Product = $this->makePreorder([
            'sku' => 'PRE-CXL',
            'price' => 50,
            'stock_qty' => 0,
            'preorder' => [
                'start_at' => '2026-10-01T00:00',
                'end_at' => '2026-10-30T23:59',
                'release_at' => '2026-12-01T00:00',
                'payment_type' => 'full',
                'preorder_price' => 45,
                'min_quantity' => 1,
                'total_limit' => 5,
            ],
        ]);
        $c2 = Customer::factory()->create();
        $cart2 = $carts->current($c2);
        $carts->add($cart2, $order2Product->fresh()->load('preorderSetting'), 3);
        $placed = app(CheckoutService::class)->place($cart2->fresh(['items.product.preorderSetting']), [
            'email' => $c2->email,
            'payment_method_id' => $pay->id,
            'shipping_method_id' => $ship,
            'billing_address' => $addr,
            'shipping_address' => $addr,
        ], $c2);
        $this->assertEquals(3, (int) ProductPreorderSetting::query()->where('product_id', $order2Product->id)->value('orders_placed'));

        app(OrderStageService::class)->apply($placed['order'], 'cancelled', 'buyer cancel');
        $this->assertEquals(0, (int) ProductPreorderSetting::query()->where('product_id', $order2Product->id)->value('orders_placed'));
        $cancelledLine = $placed['order']->fresh('items')->items->first();
        $this->assertSame('cancelled', $cancelledLine->preorder_data['status'] ?? null);
    }

    public function test_total_limit_enforced_at_checkout_reserve(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));
        $product = $this->makePreorder([
            'sku' => 'PRE-LIM',
            'price' => 20,
            'stock_qty' => 0,
            'preorder' => [
                'start_at' => '2026-10-01T00:00',
                'end_at' => '2026-10-30T23:59',
                'payment_type' => 'full',
                'min_quantity' => 1,
                'total_limit' => 2,
            ],
        ]);

        $customer = Customer::factory()->create();
        $carts = app(CartService::class);
        $cart = $carts->current($customer);
        $carts->add($cart, $product->fresh()->load('preorderSetting'), 2);

        // Another sale takes the remaining capacity after the cart was built.
        ProductPreorderSetting::query()->where('product_id', $product->id)->update(['orders_placed' => 1]);

        $pay = PaymentMethod::query()->where('is_enabled', true)->firstOrFail();
        $ship = ShippingMethod::query()->where('is_enabled', true)->value('id');
        $addr = [
            'first_name' => 'P', 'last_name' => 'O', 'line1' => '1 Main',
            'city' => 'Austin', 'state' => 'TX', 'postcode' => '78701', 'country_code' => 'US',
        ];

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(CheckoutService::class)->place($cart->fresh(['items.product.preorderSetting']), [
            'email' => $customer->email,
            'payment_method_id' => $pay->id,
            'shipping_method_id' => $ship,
            'billing_address' => $addr,
            'shipping_address' => $addr,
        ], $customer);
    }

    public function test_csv_round_trip_includes_preorder_fields(): void
    {
        $this->seed();
        $product = $this->makePreorder([
            'sku' => 'PRE-CSV',
            'price' => 129,
            'stock_qty' => 25,
            'preorder' => [
                'start_at' => '2026-10-01T09:00',
                'end_at' => '2026-11-01T18:00',
                'release_at' => '2026-11-15T00:00',
                'payment_type' => 'deposit',
                'deposit_type' => 'percent',
                'deposit_value' => 20,
                'min_quantity' => 1,
                'total_limit' => 500,
                'message' => 'Reserve yours before launch.',
                'show_badge' => true,
                'fulfillment_rule' => 'auto',
                'preorder_price' => 119,
            ],
        ]);

        $cols = ProductCsvSchema::columns();
        $vals = ProductCsvSchema::exportValues(
            $product->fresh()->load(['preorderSetting', 'translations', 'categories', 'brand', 'suppliers', 'tags']),
            'en'
        );
        $map = array_combine($cols, $vals);
        $this->assertSame('1', (string) $map['preorder_enabled']);
        $this->assertSame('deposit', (string) $map['preorder_payment_type']);
        $this->assertSame('percent', (string) $map['preorder_deposit_type']);
        $this->assertNotSame('', (string) $map['preorder_start_at']);
        $this->assertSame('Reserve yours before launch.', (string) $map['preorder_message']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makePreorder(array $overrides): Product
    {
        $this->actingAs(Admin::query()->first(), 'admin');
        $preorder = $overrides['preorder'] ?? [];
        unset($overrides['preorder']);

        $this->post('/admin/products', $this->withProductCategories(array_merge([
            'type' => 'simple',
            'name' => $overrides['sku'] ?? 'Preorder Product',
            'slug' => strtolower((string) ($overrides['sku'] ?? 'preorder-product')),
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
                'show_delivery_date' => true,
                'fulfillment_rule' => 'auto',
            ], $preorder),
        ], $overrides)))->assertProductSaveRedirect();

        return Product::query()->where('sku', $overrides['sku'])->firstOrFail();
    }
}
