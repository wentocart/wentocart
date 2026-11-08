<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\ShopReturn;
use App\Domain\Order\OrderStageService;
use App\Domain\Order\Services\ShopReturnService;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Settings\ShopSetting;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BundleProductCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function seedFixedBundle(): array
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        ShopSetting::put('inventory.enabled', true);
        ShopSetting::put('inventory.allow_negative', false);
        ShopSetting::put('orders.restock_on_cancellation', true);
        ShopSetting::put('returns.restock', 'automatic');

        $mouse = $this->createSimple('BDL-CHK-MOUSE', 10, 8, 0.4);
        $pad = $this->createSimple('BDL-CHK-PAD', 5, 6, 0.1);

        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'bundle',
            'name' => 'QA Desk Bundle',
            'slug' => 'qa-desk-bundle',
            'sku' => 'QA-DESK-BDL',
            'price' => 12,
            'bundle_type' => 'fixed',
            'pricing_mode' => 'fixed',
            'inventory_mode' => 'derived',
            'shipping_mode' => 'derived',
            'is_enabled' => true,
            'available_for_order' => true,
            'components' => [
                ['id' => $mouse->id, 'quantity' => 1, 'is_required' => true, 'default_selected' => true],
                ['id' => $pad->id, 'quantity' => 1, 'is_required' => true, 'default_selected' => true],
            ],
        ]))->assertProductSaveRedirect();

        $bundle = Product::query()->where('sku', 'QA-DESK-BDL')->firstOrFail();

        return compact('bundle', 'mouse', 'pad');
    }

    protected function createSimple(string $sku, float $price, int $stock = 10, float $weight = 0): Product
    {
        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'simple',
            'name' => $sku,
            'slug' => strtolower($sku),
            'sku' => $sku,
            'price' => $price,
            'weight' => $weight,
            'weight_class' => 'kg',
            'manage_stock' => true,
            'stock_qty' => $stock,
            'is_enabled' => true,
            'available_for_order' => true,
        ]))->assertProductSaveRedirect();

        return Product::query()->where('sku', $sku)->firstOrFail();
    }

    public function test_fixed_bundle_checkout_deducts_children_and_stores_selection(): void
    {
        ['bundle' => $bundle, 'mouse' => $mouse, 'pad' => $pad] = $this->seedFixedBundle();
        $payment = PaymentMethod::query()->firstOrFail();
        $shipping = ShippingMethod::query()->firstOrFail();

        $cart = $this->postJson('/api/v1/cart', [
            'product_id' => $bundle->id,
            'qty' => 1,
        ])->assertOk()->json('cart');

        $this->assertCount(1, $cart['items']);
        $this->assertEqualsWithDelta(12.0, (float) $cart['items'][0]['unit_price'], 0.01);
        $this->assertSame($bundle->id, (int) $cart['items'][0]['product_id']);
        $this->assertCount(2, $cart['items'][0]['bundle_selection'] ?? []);

        $this->postJson('/api/v1/checkout', [
            'email' => 'bundle-fixed@example.test',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated();

        $line = OrderItem::query()->where('product_id', $bundle->id)->latest('id')->firstOrFail();
        $this->assertEqualsWithDelta(12.0, (float) $line->unit_price, 0.01);
        $this->assertCount(2, $line->bundle_selection ?? []);
        $this->assertSame(7.0, (float) $mouse->fresh()->stock_qty);
        $this->assertSame(5.0, (float) $pad->fresh()->stock_qty);
    }

    public function test_configurable_bundle_prices_and_deducts_only_selected(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        ShopSetting::put('inventory.enabled', true);
        ShopSetting::put('inventory.allow_negative', false);

        $base = $this->createSimple('CFG-BASE', 20, 10);
        $opt = $this->createSimple('CFG-OPT', 10, 10);
        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'bundle',
            'name' => 'QA Config Bundle',
            'slug' => 'qa-config-bundle',
            'sku' => 'QA-CFG-BDL',
            'price' => 0,
            'bundle_type' => 'configurable',
            'pricing_mode' => 'sum_discount',
            'pricing_discount_type' => 'fixed',
            'pricing_discount_value' => 5,
            'inventory_mode' => 'derived',
            'shipping_mode' => 'derived',
            'is_enabled' => true,
            'available_for_order' => true,
            'components' => [
                ['id' => $base->id, 'quantity' => 1, 'is_required' => true, 'default_selected' => true],
                ['id' => $opt->id, 'quantity' => 1, 'is_required' => false, 'default_selected' => false, 'option_group' => 'Extra'],
            ],
        ]))->assertProductSaveRedirect();

        $bundle = Product::query()->where('sku', 'QA-CFG-BDL')->firstOrFail();
        $this->assertEqualsWithDelta(15.0, $bundle->sellingPrice(), 0.01);

        $withoutOpt = $this->postJson('/api/v1/cart', [
            'product_id' => $bundle->id,
            'qty' => 1,
            'components' => [['product_id' => $base->id, 'qty' => 1]],
        ])->assertOk()->json('cart.items.0');
        $this->assertEqualsWithDelta(15.0, (float) $withoutOpt['unit_price'], 0.01);
        $this->assertCount(1, $withoutOpt['bundle_selection'] ?? []);

        $this->deleteJson('/api/v1/cart/items/'.$withoutOpt['id'])->assertOk();

        $withOpt = $this->postJson('/api/v1/cart', [
            'product_id' => $bundle->id,
            'qty' => 1,
            'components' => [
                ['product_id' => $base->id, 'qty' => 1],
                ['product_id' => $opt->id, 'qty' => 1],
            ],
        ])->assertOk()->json('cart.items.0');
        $this->assertEqualsWithDelta(25.0, (float) $withOpt['unit_price'], 0.01);
        $this->assertCount(2, $withOpt['bundle_selection'] ?? []);

        $payment = PaymentMethod::query()->firstOrFail();
        $shipping = ShippingMethod::query()->firstOrFail();
        $this->postJson('/api/v1/checkout', [
            'email' => 'bundle-cfg@example.test',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated();

        $this->assertSame(9.0, (float) $base->fresh()->stock_qty);
        $this->assertSame(9.0, (float) $opt->fresh()->stock_qty);
    }

    public function test_required_oos_component_blocks_add_to_cart(): void
    {
        ['bundle' => $bundle, 'mouse' => $mouse] = $this->seedFixedBundle();
        $mouse->update(['stock_qty' => 0, 'stock_status' => 'out_of_stock']);

        $this->postJson('/api/v1/cart', [
            'product_id' => $bundle->id,
            'qty' => 1,
        ])->assertStatus(422);
    }

    public function test_derived_shipping_weight_uses_child_weights(): void
    {
        ['bundle' => $bundle] = $this->seedFixedBundle();
        $this->postJson('/api/v1/cart', ['product_id' => $bundle->id, 'qty' => 2])->assertOk();

        $cart = app(CartService::class)->current(null);
        $weight = app(CartService::class)->cartWeight($cart);
        $this->assertEqualsWithDelta(1.0, $weight, 0.001);
    }

    public function test_cancel_restocks_bundle_children(): void
    {
        ['bundle' => $bundle, 'mouse' => $mouse, 'pad' => $pad] = $this->seedFixedBundle();
        $payment = PaymentMethod::query()->firstOrFail();
        $shipping = ShippingMethod::query()->firstOrFail();

        $this->postJson('/api/v1/cart', ['product_id' => $bundle->id, 'qty' => 1])->assertOk();
        $orderId = $this->postJson('/api/v1/checkout', [
            'email' => 'bundle-cancel@example.test',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated()->json('order.id');

        $this->assertSame(7.0, (float) $mouse->fresh()->stock_qty);
        app(OrderStageService::class)->apply(Order::query()->findOrFail($orderId), 'cancelled', 'Bundle cancel test');
        $this->assertSame(8.0, (float) $mouse->fresh()->stock_qty);
        $this->assertSame(6.0, (float) $pad->fresh()->stock_qty);
    }

    public function test_return_refund_restocks_bundle_children(): void
    {
        ['bundle' => $bundle, 'mouse' => $mouse, 'pad' => $pad] = $this->seedFixedBundle();
        $payment = PaymentMethod::query()->firstOrFail();
        $shipping = ShippingMethod::query()->firstOrFail();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Bundle Buyer',
            'email' => 'bundle-return@example.test',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
        ])->assertOk();
        $customer = Customer::query()->where('email', 'bundle-return@example.test')->firstOrFail();

        $this->actingAs($customer, 'customer')->postJson('/api/v1/cart', [
            'product_id' => $bundle->id,
            'qty' => 1,
        ])->assertOk();
        $order = $this->actingAs($customer, 'customer')->postJson('/api/v1/checkout', [
            'email' => $customer->email,
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated()->json('order');

        $this->assertSame(7.0, (float) $mouse->fresh()->stock_qty);

        $itemId = (int) $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/orders/'.$order['number'])
            ->json('order.items.0.id');

        $this->actingAs($customer, 'customer')->postJson('/api/v1/account/orders/'.$order['number'].'/returns', [
            'items' => [['order_item_id' => $itemId, 'qty' => 1]],
            'reason' => 'Changed mind',
        ])->assertCreated();

        $return = ShopReturn::query()->where('order_id', $order['id'])->firstOrFail();
        $returns = app(ShopReturnService::class);
        if ($return->status === ShopReturn::STATUS_REQUESTED) {
            $return = $returns->approve($return);
        }
        $return->update(['restock' => true]);
        $returns->refund($return->fresh(), Admin::query()->first()?->id);

        $this->assertSame(8.0, (float) $mouse->fresh()->stock_qty);
        $this->assertSame(6.0, (float) $pad->fresh()->stock_qty);
    }

    public function test_storefront_payload_exposes_bundle_type_and_stock_flags(): void
    {
        ['bundle' => $bundle] = $this->seedFixedBundle();
        $slug = $bundle->translation('en')?->slug;

        $this->getJson('/api/v1/catalog/product/'.$slug)
            ->assertOk()
            ->assertJsonPath('product.bundle_type', 'fixed')
            ->assertJsonPath('product.pricing_mode', 'fixed')
            ->assertJsonPath('product.price', 12)
            ->assertJsonPath('product.components.0.in_stock', true);
    }
}
