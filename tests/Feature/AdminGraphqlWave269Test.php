<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductQtyPrice;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave269Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_customer_group_prices_from_qty_breaks(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $product = Product::query()->where('sku', 'DEMO-001')->first()
            ?? Product::query()->where('is_enabled', true)->where('type', 'simple')->firstOrFail();
        Product::query()->whereKey($product->id)->update(['price' => 100]);

        ProductQtyPrice::query()->where('product_id', $product->id)->delete();
        $tier = ProductQtyPrice::query()->create([
            'product_id' => $product->id,
            'min_qty' => 5,
            'max_qty' => null,
            'price' => 80,
            'position' => 1,
        ]);

        $res = $this->getJson('/api/v1/store/products/'.$product->id.'/customer-group-prices');
        $res->assertOk();
        $this->assertCount(1, $res->json() ?? []);
        $this->assertSame((int) $tier->id, (int) $res->json('0.id'));
        $this->assertSame(5, (int) $res->json('0.qty'));
        $this->assertSame((int) $product->id, (int) $res->json('0.productId'));
        $this->assertSame('discount', (string) $res->json('0.valueType'));
        $this->assertEqualsWithDelta(20.0, (float) $res->json('0.value'), 0.01);

        $alias = $this->getJson('/api/v1/store/products/'.$product->id.'/customer-group-prices');
        $alias->assertOk();
        $this->assertSame((int) $tier->id, (int) $alias->json('0.id'));
    }
}
