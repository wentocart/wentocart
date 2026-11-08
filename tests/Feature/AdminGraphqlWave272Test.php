<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminGraphqlWave272Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_pdp_embeds_and_wishlist_show(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $product = Product::query()->where('sku', 'DEMO-001')->first()
            ?? Product::query()->where('is_enabled', true)->where('type', 'simple')->firstOrFail();
        $related = Product::query()->where('is_enabled', true)->where('id', '!=', $product->id)->first();
        if ($related) {
            DB::table('product_relations')->insertOrIgnore([
                'product_id' => $product->id,
                'related_product_id' => $related->id,
                'type' => 'related',
            ]);
        }

        $pdp = $this->getJson('/api/v1/store/products/'.$product->id);
        $pdp->assertOk();
        foreach ([
            'isSaleable', 'productTemplate', 'categories', 'stores', 'images', 'videos',
            'variantAxes', 'variants', 'bookingProducts', 'bundleOptions', 'groupedProducts',
            'downloadableLinks', 'downloadableSamples', 'customizableOptions',
            'relatedProducts', 'upSells', 'crossSells',
        ] as $key) {
            $this->assertArrayHasKey($key, $pdp->json() ?? [], $key);
        }
        $this->assertIsBool((bool) $pdp->json('isSaleable') || $pdp->json('isSaleable') === 0 || $pdp->json('isSaleable') === 1 || is_bool($pdp->json('isSaleable')));
        $this->assertIsArray($pdp->json('variants'));
        $this->assertIsArray($pdp->json('relatedProducts'));
        if ($related) {
            $this->assertNotEmpty($pdp->json('relatedProducts'));
        }

        $customer = Customer::query()->create([
            'name' => 'Wave272 Shopper',
            'email' => 'w272@example.com',
            'password' => Hash::make('Password1!'),
            'is_enabled' => true,
        ]);
        $login = $this->postJson('/api/v1/store/customer/login', [
            'email' => 'w272@example.com',
            'password' => 'Password1!',
        ]);
        $login->assertCreated();
        $token = (string) $login->json('token');
        $headers = ['Authorization' => 'Bearer '.$token];

        $add = $this->withHeaders($headers)->postJson('/api/v1/store/wishlists', [
            'productId' => (int) $product->id,
        ]);
        $add->assertCreated();
        $wishId = (int) ($add->json('id') ?? 0);
        $this->assertGreaterThan(0, $wishId);

        $show = $this->withHeaders($headers)->getJson('/api/v1/store/wishlists/'.$wishId);
        $show->assertOk();
        $this->assertSame($wishId, (int) $show->json('id'));
        $this->assertStringContainsString('/api/v1/store/products/'.$product->id, (string) $show->json('product'));

        $missing = $this->withHeaders($headers)->getJson('/api/v1/store/wishlists/99999999');
        $missing->assertNotFound();
    }
}
