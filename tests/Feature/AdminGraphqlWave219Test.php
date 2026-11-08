<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminGraphqlWave219Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_shop_wishlists_compare_gdpr_rest(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);
        ShopSetting::put('catalog.wishlist_enabled', true);
        ShopSetting::put('products.compare_enabled', true);
        ShopSetting::put('gdpr.enabled', true);

        $customer = Customer::factory()->create();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $other = Product::query()->where('sku', '!=', 'DEMO-001')->where('is_enabled', true)->first()
            ?? $product;

        $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/store/wishlists')
            ->assertOk()
            ->assertJson([]);

        $created = $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/store/wishlists', ['productId' => $product->id]);
        $created->assertCreated();
        $wid = (int) $created->json('id');
        $this->assertGreaterThan(0, $wid);
        $this->assertStringContainsString('/shop/products/'.$product->id, (string) $created->json('product'));

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/store/wishlists', ['productId' => $product->id])
            ->assertStatus(400);

        $list = $this->actingAs($customer, 'customer')->getJson('/api/v1/store/wishlists');
        $list->assertOk();
        $this->assertCount(1, $list->json());
        $this->assertSame('1', (string) $list->headers->get('X-Total-Count'));

        $cmp = $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/store/compare-items', ['productId' => $product->id]);
        $cmp->assertCreated()->assertJsonPath('id', $product->id);

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/store/compare-items', ['productId' => $product->id])
            ->assertStatus(400);

        $cmpList = $this->actingAs($customer, 'customer')->getJson('/api/v1/store/compare-items');
        $cmpList->assertOk();
        $this->assertNotEmpty($cmpList->json());

        if ($other->id !== $product->id) {
            $this->actingAs($customer, 'customer')
                ->postJson('/api/v1/store/compare-items', ['productId' => $other->id])
                ->assertCreated();
        }

        $gdpr = $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/store/gdpr-requests', [
                'type' => 'delete',
                'message' => 'Please remove my account data.',
            ]);
        $gdpr->assertCreated();
        $gid = (int) $gdpr->json('id');
        $this->assertSame('delete', $gdpr->json('type'));

        $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/store/gdpr-requests')
            ->assertOk()
            ->assertJsonPath('0.id', $gid);

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/store/gdpr-requests/'.$gid.'/revoke')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($customer, 'customer')
            ->deleteJson('/api/v1/store/wishlists/'.$wid)
            ->assertOk();
        $this->assertSame(0, DB::table('wishlists')->where('customer_id', $customer->id)->count());

        $this->actingAs($customer, 'customer')
            ->deleteJson('/api/v1/store/compare-items')
            ->assertOk();
    }
}
