<?php

namespace Tests\Feature;

use App\Domain\Order\Models\Order;
use App\Domain\Promo\Models\DiscountRule;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave37Test extends TestCase
{
    use RefreshDatabase;

    public function test_cancel_order_create_promotion_and_delete_promotion(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $promo = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createPromotion(
                    name: "Wave37 Cart 10"
                    applyAs: "cart"
                    action: "percent"
                    value: 10
                    minCart: 25
                    enabled: true
                ) { id name apply_as action value is_enabled }
            }',
        ]);
        $promo->assertOk();
        $this->assertArrayNotHasKey('errors', $promo->json() ?? []);
        $promo->assertJsonPath('data.createPromotion.name', 'Wave37 Cart 10');
        $promo->assertJsonPath('data.createPromotion.apply_as', 'cart');
        $this->assertEqualsWithDelta(10.0, (float) $promo->json('data.createPromotion.value'), 0.001);
        $promoId = (string) $promo->json('data.createPromotion.id');
        $this->assertNotNull(DiscountRule::query()->find((int) $promoId));

        $bogo = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createPromotion(name: "Wave37 BOGO", applyAs: "cart", action: "buy_x_get_y", value: 2) {
                    id action value
                }
            }',
        ]);
        $bogo->assertOk();
        $this->assertArrayNotHasKey('errors', $bogo->json() ?? []);
        $bogo->assertJsonPath('data.createPromotion.action', 'buy_x_get_y');

        $del = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { deletePromotion(id: $id) { ok id } }',
            'variables' => ['id' => $promoId],
        ]);
        $del->assertOk();
        $this->assertArrayNotHasKey('errors', $del->json() ?? []);
        $this->assertTrue((bool) $del->json('data.deletePromotion.ok'));
        $this->assertNull(DiscountRule::query()->find((int) $promoId));

        $order = Order::query()
            ->whereNotIn('status', ['cancelled', 'refunded', 'completed', 'delivered'])
            ->first();
        if (! $order) {
            return;
        }

        $cancel = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($n: String!) {
                cancelOrder(number: $n, comment: "Wave37 cancel") { id number status }
            }',
            'variables' => ['n' => $order->number],
        ]);
        $cancel->assertOk();
        $this->assertArrayNotHasKey('errors', $cancel->json() ?? []);
        $cancel->assertJsonPath('data.cancelOrder.status', 'cancelled');
        $this->assertSame('cancelled', Order::query()->find($order->id)?->status);
    }
}
