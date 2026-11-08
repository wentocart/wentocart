<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductComponent;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\OrderStageService;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Settings\ShopSetting;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupedProductCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function seedGroup(array $overrides = []): array
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        ShopSetting::put('inventory.enabled', true);
        ShopSetting::put('inventory.allow_negative', false);
        ShopSetting::put('orders.restock_on_cancellation', true);

        $shirt = $this->createSimple('GRP-CHK-A', 10, 8);
        $jeans = $this->createSimple('GRP-CHK-B', 20, 5);

        $payload = array_merge([
            'type' => 'group',
            'name' => 'QA Fashion Group',
            'slug' => 'qa-fashion-group',
            'sku' => 'QA-FASH-GRP',
            'price' => 0,
            'selection_mode' => 'all',
            'pricing_mode' => 'sum_discount',
            'pricing_discount_type' => 'fixed',
            'pricing_discount_value' => 5,
            'inventory_mode' => 'derived',
            'shipping_mode' => 'derived',
            'is_enabled' => true,
            'available_for_order' => true,
            'components' => [
                ['id' => $shirt->id, 'quantity' => 1, 'qty_editable' => true, 'is_required' => true, 'default_selected' => true],
                ['id' => $jeans->id, 'quantity' => 1, 'qty_editable' => false, 'is_required' => true, 'default_selected' => true],
            ],
        ], $overrides);

        $this->post('/admin/products', $this->withProductCategories($payload))->assertProductSaveRedirect();

        $group = Product::query()->where('sku', 'QA-FASH-GRP')->firstOrFail();

        return compact('group', 'shirt', 'jeans');
    }

    protected function createSimple(string $sku, float $price, int $stock = 10): Product
    {
        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'simple',
            'name' => $sku,
            'slug' => strtolower($sku),
            'sku' => $sku,
            'price' => $price,
            'manage_stock' => true,
            'stock_qty' => $stock,
            'is_enabled' => true,
            'available_for_order' => true,
        ]))->assertProductSaveRedirect();

        return Product::query()->where('sku', $sku)->firstOrFail();
    }

    public function test_group_discount_applies_on_cart_and_checkout(): void
    {
        ['group' => $group, 'shirt' => $shirt, 'jeans' => $jeans] = $this->seedGroup();
        $payment = PaymentMethod::query()->firstOrFail();
        $shipping = ShippingMethod::query()->firstOrFail();

        $cart = $this->postJson('/api/v1/cart', [
            'product_id' => $group->id,
            'qty' => 1,
            'components' => [
                ['product_id' => $shirt->id, 'qty' => 1],
                ['product_id' => $jeans->id, 'qty' => 1],
            ],
        ])->assertOk()->json('cart.items');

        $this->assertCount(2, $cart);
        $sum = collect($cart)->sum(fn ($row) => (float) $row['unit_price'] * (float) $row['qty']);
        $this->assertEqualsWithDelta(25.0, $sum, 0.02);
        $this->assertSame($group->id, (int) ($cart[0]['parent_product_id'] ?? 0));
        $this->assertSame($group->id, (int) ($cart[1]['parent_product_id'] ?? 0));

        $this->postJson('/api/v1/checkout', [
            'email' => 'group-price@example.test',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated();

        $lines = OrderItem::query()->where('parent_product_id', $group->id)->get();
        $this->assertCount(2, $lines);
        $this->assertEqualsWithDelta(25.0, $lines->sum(fn ($row) => (float) $row->unit_price * (float) $row->qty), 0.01);
        $this->assertTrue($lines->contains(fn ($row) => str_contains((string) $row->name, 'QA Fashion Group')));
        $this->assertSame(7.0, (float) $shirt->fresh()->stock_qty);
        $this->assertSame(4.0, (float) $jeans->fresh()->stock_qty);
    }

    public function test_selection_mode_all_forces_every_child(): void
    {
        ['group' => $group, 'shirt' => $shirt] = $this->seedGroup();

        $cart = $this->postJson('/api/v1/cart', [
            'product_id' => $group->id,
            'components' => [
                ['product_id' => $shirt->id, 'qty' => 1],
            ],
        ])->assertOk()->json('cart.items');

        $this->assertCount(2, $cart);
    }

    public function test_qty_editable_false_ignores_posted_qty(): void
    {
        ['group' => $group, 'shirt' => $shirt, 'jeans' => $jeans] = $this->seedGroup();

        $cart = $this->postJson('/api/v1/cart', [
            'product_id' => $group->id,
            'components' => [
                ['product_id' => $shirt->id, 'qty' => 1],
                ['product_id' => $jeans->id, 'qty' => 9],
            ],
        ])->assertOk()->json('cart.items');

        $jeansLine = collect($cart)->first(fn ($row) => (int) $row['product_id'] === (int) $jeans->id);
        $this->assertSame(1, (int) $jeansLine['qty']);
    }

    public function test_customer_mode_allows_subset_and_blocks_oos(): void
    {
        ['group' => $group, 'shirt' => $shirt, 'jeans' => $jeans] = $this->seedGroup();

        $this->put('/admin/products/' . $group->id, $this->withProductCategories([
            'type' => 'group',
            'name' => 'QA Fashion Group',
            'slug' => 'qa-fashion-group',
            'sku' => 'QA-FASH-GRP',
            'price' => 0,
            'selection_mode' => 'customer',
            'pricing_mode' => 'sum',
            'inventory_mode' => 'derived',
            'shipping_mode' => 'derived',
            'is_enabled' => true,
            'available_for_order' => true,
            'components' => [
                ['id' => $shirt->id, 'quantity' => 1, 'qty_editable' => true, 'is_required' => false, 'default_selected' => true],
                ['id' => $jeans->id, 'quantity' => 1, 'qty_editable' => true, 'is_required' => false, 'default_selected' => false],
            ],
        ]))->assertRedirect('/admin/products/' . $group->id . '/edit');

        $jeans->update(['stock_qty' => 0, 'stock_status' => 'out_of_stock']);

        $cart = $this->postJson('/api/v1/cart', [
            'product_id' => $group->id,
            'components' => [
                ['product_id' => $shirt->id, 'qty' => 1],
            ],
        ])->assertOk()->json('cart.items');
        $this->assertCount(1, $cart);

        $this->postJson('/api/v1/cart', [
            'product_id' => $group->id,
            'components' => [
                ['product_id' => $jeans->id, 'qty' => 1],
            ],
        ])->assertStatus(422);
    }

    public function test_cancel_restocks_group_children(): void
    {
        ['group' => $group, 'shirt' => $shirt, 'jeans' => $jeans] = $this->seedGroup();
        $payment = PaymentMethod::query()->firstOrFail();
        $shipping = ShippingMethod::query()->firstOrFail();

        $this->postJson('/api/v1/cart', [
            'product_id' => $group->id,
            'components' => [
                ['product_id' => $shirt->id, 'qty' => 1],
                ['product_id' => $jeans->id, 'qty' => 1],
            ],
        ])->assertOk();

        $orderId = $this->postJson('/api/v1/checkout', [
            'email' => 'group-cancel@example.test',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated()->json('order.id');

        $this->assertSame(7.0, (float) $shirt->fresh()->stock_qty);
        $this->assertSame(4.0, (float) $jeans->fresh()->stock_qty);

        $order = Order::query()->findOrFail($orderId);
        app(OrderStageService::class)->apply($order, 'cancelled', 'Cancel grouped order for restock test');

        $this->assertSame(8.0, (float) $shirt->fresh()->stock_qty);
        $this->assertSame(5.0, (float) $jeans->fresh()->stock_qty);
    }

    public function test_storefront_payload_exposes_group_modes_and_stock_flags(): void
    {
        ['group' => $group] = $this->seedGroup();
        $slug = $group->translation('en')?->slug;

        $this->getJson('/api/v1/catalog/product/'.$slug)
            ->assertOk()
            ->assertJsonPath('product.selection_mode', 'all')
            ->assertJsonPath('product.pricing_mode', 'sum_discount')
            ->assertJsonPath('product.price', 25)
            ->assertJsonPath('product.components.0.in_stock', true);
    }
}
