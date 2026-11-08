<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Checkout\Models\Cart;
use App\Domain\Customer\Models\Customer;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminGraphqlWave251Test extends TestCase
{
    use RefreshDatabase;

    public function test_merge_carts_rest_and_home_modules_alias(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);
        $storeId = app(StoreContext::class)->requireId();

        $product = Product::query()->where('is_enabled', true)->where('type', 'simple')->first()
            ?? Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);
        Product::query()->whereKey($product->id)->update([
            'manage_stock' => true,
            'stock_qty' => max(20, (int) $product->stock_qty),
            'is_enabled' => true,
        ]);

        $guest = $this->postJson('/api/v1/store/cart-tokens', []);
        $guest->assertCreated();
        $guestCartId = (int) $guest->json('id');
        $guestToken = (string) ($guest->json('cartToken') ?? '');
        $this->assertGreaterThan(0, $guestCartId);

        $add = $this->withHeader('X-Cart-Token', $guestToken)
            ->postJson('/api/v1/store/add-product-in-cart', [
                'productId' => (int) $product->id,
                'quantity' => 2,
            ]);
        $add->assertOk();
        $this->assertGreaterThanOrEqual(1, (int) $add->json('itemsCount'));

        $customer = Customer::factory()->create([
            'store_id' => $storeId,
            'password' => Hash::make('password'),
            'is_enabled' => true,
        ]);

        $merged = $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/store/merge-carts', ['cartId' => $guestCartId]);
        $merged->assertCreated();
        $this->assertTrue((bool) $merged->json('success'));
        $this->assertGreaterThanOrEqual(1, (int) $merged->json('itemsCount'));
        $this->assertSame((int) $customer->id, (int) $merged->json('customerId'));
        $this->assertFalse(Cart::query()->whereKey($guestCartId)->exists());

        $missing = $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/store/merge-carts', ['cartId' => 99999999]);
        $missing->assertNotFound();

        $themes = $this->getJson('/api/v1/store/home-modules');
        $themes->assertOk();
        $this->assertIsArray($themes->json());
        $this->assertNotEmpty($themes->headers->get('X-Total-Count'));
    }
}
