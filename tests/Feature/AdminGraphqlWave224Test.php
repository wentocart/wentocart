<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminGraphqlWave224Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_shop_products_rest(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $list = $this->getJson('/api/v1/store/products?per_page=10');
        $list->assertOk();
        $this->assertIsArray($list->json());
        $this->assertNotEmpty($list->json());
        $this->assertNotEmpty((string) $list->headers->get('X-Total-Count'));
        $first = $list->json('0');
        $this->assertArrayHasKey('sku', $first);
        $this->assertArrayHasKey('slug', $first);
        $this->assertArrayHasKey('formattedPrice', $first);
        $this->assertSame(0, (int) $first['isInWishlist']);

        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $show = $this->getJson('/api/v1/store/products/'.$product->id);
        $show->assertOk()->assertJsonPath('id', $product->id)
            ->assertJsonPath('sku', 'DEMO-001');

        $customer = Customer::factory()->create();
        DB::table('wishlists')->insert([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $authed = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/store/products?query=DEMO-001');
        $authed->assertOk();
        $hit = collect($authed->json())->firstWhere('sku', 'DEMO-001');
        $this->assertNotNull($hit);
        $this->assertSame(1, (int) $hit['isInWishlist']);

        $this->getJson('/api/v1/store/products/999999')->assertNotFound();
    }
}
