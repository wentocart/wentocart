<?php

namespace Tests\Unit;

use App\Domain\Catalog\Models\Product;
use App\Domain\Checkout\Models\Cart;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Localization\Models\Country;
use App\Domain\Promo\DiscountContext;
use App\Domain\Promo\DiscountEngine;
use App\Domain\Promo\Models\DiscountRule;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscountEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_percent_applies_on_top_of_sale_price(): void
    {
        $product = $this->demoProduct();
        $product->update(['sale_price' => 20, 'sale_price_type' => 'fixed']);

        $this->rule([
            'name' => 'Catalog 10%',
            'apply_as' => 'catalog',
            'action' => 'percent',
            'value' => 10,
        ]);

        $this->assertSame(18.0, $product->fresh()->sellingPrice());
    }

    public function test_best_of_wins_unless_stop_further_cuts_later_rules(): void
    {
        $product = $this->demoProduct();

        $this->rule([
            'name' => 'Ten percent',
            'priority' => 10,
            'action' => 'percent',
            'value' => 10,
            'stop_further' => true,
        ]);
        $this->rule([
            'name' => 'Half off',
            'priority' => 20,
            'action' => 'percent',
            'value' => 50,
        ]);

        $this->assertSame(26.99, $product->fresh()->sellingPrice());

        DiscountRule::query()->where('name', 'Ten percent')->update(['stop_further' => false]);
        app(DiscountEngine::class)->flush();

        $this->assertSame(14.99, $product->fresh()->sellingPrice());
    }

    public function test_country_condition_does_not_apply_without_a_country(): void
    {
        $product = $this->demoProduct();
        $us = Country::query()->where('code', 'US')->firstOrFail();

        $rule = $this->rule([
            'name' => 'US only',
            'action' => 'percent',
            'value' => 10,
        ]);
        $rule->conditions()->create(['type' => 'country', 'value_ids' => [$us->id]]);

        $this->assertSame(29.99, $product->fresh()->sellingPrice(DiscountContext::guest('USD')));
        $this->assertSame(26.99, $product->fresh()->sellingPrice(new DiscountContext(
            currencyCode: 'USD',
            countryCode: 'US',
        )));
    }

    public function test_cart_coupon_reduces_subtotal_and_automatic_cart_competes(): void
    {
        $product = $this->demoProduct();
        $cart = Cart::query()->create(['session_id' => 'discount-test']);
        $cart->items()->create([
            'product_id' => $product->id,
            'qty' => 1,
            'unit_price' => $product->sellingPrice(),
        ]);

        $this->rule([
            'name' => 'Auto five off',
            'apply_as' => 'cart',
            'action' => 'amount',
            'value' => 5,
            'priority' => 50,
        ]);
        $this->rule([
            'name' => 'SAVE10',
            'apply_as' => 'coupon',
            'code' => 'SAVE10',
            'action' => 'percent',
            'value' => 10,
            'priority' => 100,
        ]);

        $carts = app(CartService::class);
        $carts->applyCoupon($cart->fresh('items.product'), 'save10');

        $totals = $carts->summary($cart->fresh());
        $this->assertSame(5.0, $totals['discount']);
        $this->assertNotNull($totals['discount_rule_id']);
    }

    public function test_customer_group_percent_is_applied_at_catalog_price(): void
    {
        $product = $this->demoProduct();
        ShopSetting::put('customers.group_pricing', true);
        CustomerGroup::query()->where('code', 'vip')->update(['discount_percent' => 20]);
        $customer = Customer::factory()->create(['customer_group' => 'vip']);

        $this->assertSame(23.99, $product->sellingPrice(new DiscountContext(
            customer: $customer,
            currencyCode: 'USD',
        )));
    }

    public function test_group_percent_stays_off_until_group_pricing_is_enabled(): void
    {
        $product = $this->demoProduct();
        ShopSetting::put('customers.group_pricing', false);
        CustomerGroup::query()->where('code', 'vip')->update(['discount_percent' => 20]);
        $customer = Customer::factory()->create(['customer_group' => 'vip']);

        $this->assertSame(29.99, $product->sellingPrice(new DiscountContext(
            customer: $customer,
            currencyCode: 'USD',
        )));
    }

    public function test_customer_group_discount_rule_follows_the_group_discounts_toggle(): void
    {
        $product = $this->demoProduct();
        $group = CustomerGroup::query()->where('code', 'vip')->firstOrFail();
        $customer = Customer::factory()->create([
            'customer_group' => 'vip',
            'customer_group_id' => $group->id,
        ]);
        $rule = $this->rule([
            'name' => 'VIP catalog',
            'action' => 'percent',
            'value' => 10,
        ]);
        $rule->conditions()->create(['type' => 'customer_group', 'value_ids' => [$group->id]]);

        ShopSetting::put('customers.group_discounts', true);
        $this->assertSame(26.99, $product->fresh()->sellingPrice(new DiscountContext(
            customer: $customer,
            currencyCode: 'USD',
        )));

        ShopSetting::put('customers.group_discounts', false);
        app(DiscountEngine::class)->flush();
        $this->assertSame(29.99, $product->fresh()->sellingPrice(new DiscountContext(
            customer: $customer,
            currencyCode: 'USD',
        )));
    }

    public function test_fixed_amount_discount_converts_from_rule_currency_to_default(): void
    {
        $product = $this->demoProduct();

        // 830 INR ~= 10 USD when INR rate is 83.
        $this->rule([
            'name' => 'INR ten dollars worth',
            'apply_as' => 'catalog',
            'action' => 'amount',
            'value' => 830,
            'currency_code' => 'INR',
        ]);

        $this->assertSame(19.99, $product->fresh()->sellingPrice());
    }

    public function test_quantity_break_replaces_unit_price_before_catalog_rules(): void
    {
        $product = $this->demoProduct();
        $product->qtyPrices()->create([
            'min_qty' => 3,
            'price' => 20,
            'position' => 1,
        ]);

        $this->assertSame(29.99, $product->fresh()->sellingPrice(DiscountContext::guest('USD')));
        $this->assertSame(20.0, $product->fresh()->sellingPrice((new DiscountContext(currencyCode: 'USD'))->forProduct($product, 3)));
    }

    protected function demoProduct(): Product
    {
        $this->seed();

        return Product::query()->where('sku', 'DEMO-001')->firstOrFail();
    }

    /** @param  array<string, mixed>  $attrs */
    protected function rule(array $attrs): DiscountRule
    {
        return DiscountRule::query()->create(array_merge([
            'name' => 'Test',
            'apply_as' => 'catalog',
            'action' => 'percent',
            'value' => 10,
            'priority' => 100,
            'stop_further' => false,
            'is_enabled' => true,
        ], $attrs));
    }
}
