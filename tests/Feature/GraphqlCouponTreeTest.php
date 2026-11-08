<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GraphqlCouponTreeTest extends TestCase
{
    use RefreshDatabase;

    public function test_tree_categories_query(): void
    {
        $this->seed();

        $response = $this->postJson('/api/v1/graphql', [
            'query' => '{ treeCategories(depth: 2) { id name slug children(first: 20) { totalCount edges { node { id name } } } } }',
        ]);
        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? []);
        $this->assertIsArray($response->json('data.treeCategories'));
    }

    public function test_apply_and_remove_coupon_via_graphql(): void
    {
        $this->seed();

        $product = \App\Domain\Catalog\Models\Product::query()->where('sku', 'DEMO-001')->first()
            ?? \App\Domain\Catalog\Models\Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);

        $code = 'GQLTREE'.strtoupper(substr(uniqid(), -4));
        \App\Domain\Promo\Models\DiscountRule::query()->create([
            'name' => 'GQL Tree Coupon',
            'apply_as' => 'coupon',
            'code' => $code,
            'action' => 'percent',
            'value' => 10,
            'priority' => 10,
            'stop_further' => false,
            'used_count' => 0,
            'is_enabled' => true,
        ]);

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();

        $apply = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation ($c: String!) { applyCoupon(code: $c) { coupon_code discount grand_total item_count } }',
            'variables' => ['c' => $code],
        ]);
        $apply->assertOk();
        $this->assertArrayNotHasKey('errors', $apply->json() ?? []);
        $apply->assertJsonPath('data.applyCoupon.coupon_code', $code);
        $this->assertGreaterThanOrEqual(1, (int) $apply->json('data.applyCoupon.item_count'));

        $remove = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation { removeCoupon { coupon_code } }',
        ]);
        $remove->assertOk();
        $this->assertArrayNotHasKey('errors', $remove->json() ?? []);
        $this->assertNull($remove->json('data.removeCoupon.coupon_code'));
    }
}
