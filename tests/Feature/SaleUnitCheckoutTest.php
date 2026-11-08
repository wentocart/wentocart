<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\SaleUnit;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\ShippingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleUnitCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_weight_sold_product_accepts_decimal_qty_and_deducts_stock(): void
    {
        $this->seed();

        $rice = $this->makeLooseRice();
        $payment = PaymentMethod::query()->firstOrFail();
        $shipping = ShippingMethod::query()->firstOrFail();

        $add = $this->postJson('/api/v1/cart', ['product_id' => $rice->id, 'qty' => 1.5]);
        $add->assertOk();
        $this->assertEquals(1.5, (float) $add->json('cart.items.0.qty'));

        $this->postJson('/api/v1/checkout', [
            'email' => 'rice@example.test',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated();

        $line = \App\Domain\Order\Models\OrderItem::query()->where('sku', 'RICE-KG')->first();
        $this->assertNotNull($line);
        $this->assertEquals(1.5, (float) $line->qty);
        $this->assertEquals(120.0, (float) $line->total);
        $this->assertEquals(8.5, (float) $rice->fresh()->stock_qty);
    }

    public function test_qty_snaps_to_step_and_shipping_weight_uses_ordered_kilos(): void
    {
        $this->seed();

        $rice = $this->makeLooseRice();
        $carts = app(CartService::class);
        $cart = $carts->current(null);
        $carts->add($cart, $rice, 0.6);

        $item = $cart->fresh()->items->first();
        $this->assertEquals(1.0, (float) $item->qty);
        $this->assertEquals(1.0, SaleUnit::snapQty($rice, 0.6));
        $this->assertEquals(1.0, SaleUnit::lineShippingWeight($rice, 1.0));
        $this->assertSame('kg', $rice->sale_unit_label);
    }

    public function test_catalog_payload_exposes_sale_unit(): void
    {
        $this->seed();
        $rice = $this->makeLooseRice();

        $this->getJson('/api/v1/catalog/product/loose-basmati')
            ->assertOk()
            ->assertJsonPath('product.sale_unit', 'weight')
            ->assertJsonPath('product.sale_unit_code', 'kg')
            ->assertJsonPath('product.sale_unit_label', 'kg');
    }

    protected function makeLooseRice(): Product
    {
        $base = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $rice = Product::query()->create([
            'type' => 'simple',
            'sku' => 'RICE-KG',
            'price' => 80,
            'stock_qty' => 10,
            'manage_stock' => true,
            'is_enabled' => true,
            'available_for_order' => true,
            'visibility' => 'both',
            'sale_unit' => 'weight',
            'sale_unit_code' => 'kg',
            'min_qty' => 0.5,
            'qty_step' => 0.5,
            'weight' => 0,
            'weight_class' => 'kg',
            'tax_class_id' => $base->tax_class_id,
            'default_category_id' => $base->default_category_id,
        ]);
        $rice->translations()->create([
            'language_code' => 'en',
            'name' => 'Loose basmati',
            'slug' => 'loose-basmati',
        ]);
        if ($base->default_category_id) {
            $rice->categories()->syncWithoutDetaching([$base->default_category_id => ['is_default' => true]]);
        }
        $rice->attachToCurrentShop();

        return $rice->fresh();
    }
}
