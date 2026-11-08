<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartQuantityRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_second_add_when_step_blocks_increase_returns_no_increase_notice(): void
    {
        $this->seed();

        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->update([
            'min_qty' => 1,
            'qty_step' => 4,
            'max_qty' => 4,
            'manage_stock' => true,
            'stock_qty' => 50,
            'is_enabled' => true,
            'available_for_order' => true,
        ]);

        $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertOk()
            ->assertJsonPath('cart.items.0.qty', 1);

        $second = $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertOk();

        $second->assertJsonPath('cart.items.0.qty', 1);
        $notices = $second->json('quantity_notices');
        $this->assertIsArray($notices);
        $this->assertNotEmpty($notices);
        $this->assertSame('no_increase', $notices[0]['code'] ?? null);
        $this->assertSame(1, $notices[0]['before'] ?? null);
        $this->assertSame(1, $notices[0]['after'] ?? null);
    }
}
