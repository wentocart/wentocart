<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductQtyPrice;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave286Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_catalog_customer_group_prices_rest(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $product = Product::query()->where('is_enabled', true)->where('type', 'simple')->firstOrFail();

        $list = $this->actingAs($admin, 'admin')
            ->getJson('/api/v1/console/products/'.$product->id.'/customer-group-prices');
        $list->assertOk();
        $this->assertIsArray($list->json('data'));

        $create = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/products/'.$product->id.'/customer-group-prices',
            ['qty' => 5, 'value' => 12.5, 'value_type' => 'fixed', 'position' => 1]
        );
        $create->assertCreated();
        $priceId = (int) $create->json('data.id');
        $this->assertGreaterThan(0, $priceId);
        $this->assertSame(5.0, (float) $create->json('data.qty'));

        $update = $this->actingAs($admin, 'admin')->putJson(
            '/api/v1/console/products/'.$product->id.'/customer-group-prices/'.$priceId,
            ['qty' => 10, 'price' => 11]
        );
        $update->assertOk();
        $this->assertSame(10.0, (float) $update->json('data.qty'));
        $this->assertSame(11.0, (float) $update->json('data.price'));

        $destroy = $this->actingAs($admin, 'admin')->deleteJson(
            '/api/v1/console/products/'.$product->id.'/customer-group-prices/'.$priceId
        );
        $destroy->assertOk();
        $this->assertNull(ProductQtyPrice::query()->find($priceId));
    }
}
