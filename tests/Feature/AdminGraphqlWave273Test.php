<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminGraphqlWave273Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_related_upsell_cart_estimate_and_max_price(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $product = Product::query()->where('sku', 'DEMO-001')->first()
            ?? Product::query()->where('is_enabled', true)->where('type', 'simple')->first()
            ?? Product::query()->create([
                'type' => 'simple',
                'sku' => 'W273-MAIN-'.uniqid(),
                'price' => 29.99,
                'stock_qty' => 10,
                'is_enabled' => true,
            ]);
        $related = Product::query()->create([
            'type' => 'simple',
            'sku' => 'W273-REL-'.uniqid(),
            'price' => 19.99,
            'stock_qty' => 10,
            'is_enabled' => true,
        ]);
        $upsell = Product::query()->create([
            'type' => 'simple',
            'sku' => 'W273-UP-'.uniqid(),
            'price' => 39.99,
            'stock_qty' => 10,
            'is_enabled' => true,
        ]);
        foreach ([$product, $related, $upsell] as $p) {
            if (! $p->translations()->exists()) {
                $p->translations()->create([
                    'language_code' => 'en',
                    'name' => 'Wave273 '.$p->sku,
                    'slug' => strtolower($p->sku),
                ]);
            }
        }

        DB::table('product_relations')->insertOrIgnore([
            ['product_id' => $product->id, 'related_product_id' => $related->id, 'type' => 'related'],
            ['product_id' => $product->id, 'related_product_id' => $upsell->id, 'type' => 'upsell'],
            ['product_id' => $product->id, 'related_product_id' => $related->id, 'type' => 'crosssell'],
        ]);

        $rel = $this->getJson('/api/v1/store/products/'.$product->id.'/related');
        $rel->assertOk();
        $this->assertTrue(collect($rel->json() ?? [])->contains(fn ($r) => (int) ($r['id'] ?? 0) === (int) $related->id));

        $up = $this->getJson('/api/v1/store/products/'.$product->id.'/up-sell');
        $up->assertOk();
        $this->assertTrue(collect($up->json() ?? [])->contains(fn ($r) => (int) ($r['id'] ?? 0) === (int) $upsell->id));

        $add = $this->getJson('/api/v1/store/products/'.$product->id.'/extra-info');
        $add->assertOk();
        $this->assertIsArray($add->json('data'));

        $cfg = $this->getJson('/api/v1/store/products/'.$product->id.'/variant-config');
        $cfg->assertOk();
        $this->assertArrayHasKey('attributes', $cfg->json('data') ?? []);

        $max = $this->getJson('/api/v1/store/categories/max-price');
        $max->assertOk();
        $this->assertArrayHasKey('max_price', $max->json('data') ?? []);
        $this->assertGreaterThanOrEqual(0, (float) $max->json('data.max_price'));

        $category = Category::query()->where('is_enabled', true)->first();
        if ($category) {
            $catMax = $this->getJson('/api/v1/store/categories/max-price/'.$category->id);
            $catMax->assertOk();
            $this->assertArrayHasKey('max_price', $catMax->json('data') ?? []);

            $desc = $this->getJson('/api/v1/store/descendant-categories?parent_id='.$category->id);
            $desc->assertOk();
            $this->assertIsArray($desc->json());
        }

        $families = $this->getJson('/api/v1/store/product-templates');
        $families->assertOk();
        $this->assertNotEmpty($families->json());

        $estimate = $this->postJson('/api/v1/store/estimate-shipping-methods', ['country' => 'US']);
        $estimate->assertOk();
        $this->assertTrue($estimate->json('success'));
        $this->assertIsArray($estimate->json('shippingMethods'));

        $badCountry = $this->postJson('/api/v1/store/checkout/cart/estimate-shipping-methods', ['country' => 'USA']);
        $badCountry->assertStatus(422);

        $create = $this->postJson('/api/v1/store/create-cart');
        $create->assertCreated();
        $token = (string) $create->json('cartToken');
        $this->assertNotSame('', $token);

        $addCart = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/v1/store/add-product-in-cart', [
                'productId' => (int) $product->id,
                'quantity' => 1,
            ]);
        $addCart->assertOk();
        $cartItemId = (int) ($addCart->json('items.0.id') ?? 0);
        $this->assertGreaterThan(0, $cartItemId);

        $cross = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/v1/store/checkout/cart/cross-sell');
        $cross->assertOk();
        $this->assertTrue(collect($cross->json() ?? [])->contains(fn ($r) => (int) ($r['id'] ?? 0) === (int) $related->id));

        $customer = Customer::query()->create([
            'name' => 'Wave273 Shopper',
            'email' => 'w273@example.com',
            'password' => Hash::make('Password1!'),
            'is_enabled' => true,
        ]);
        $login = $this->postJson('/api/v1/store/customer/login', [
            'email' => 'w273@example.com',
            'password' => 'Password1!',
        ]);
        $login->assertCreated();
        $custToken = (string) $login->json('token');

        // Customer cart + move-to-wishlist
        $this->withHeaders(['Authorization' => 'Bearer '.$custToken])
            ->postJson('/api/v1/store/add-product-in-cart', [
                'productId' => (int) $product->id,
                'quantity' => 1,
            ])->assertOk();

        $move = $this->withHeaders(['Authorization' => 'Bearer '.$custToken])
            ->postJson('/api/v1/store/move-to-wishlist', [
                'productId' => (int) $product->id,
            ]);
        $move->assertOk();
        $this->assertTrue((bool) $move->json('success'));
        $this->assertTrue(
            $customer->fresh()->wishlist()->where('product_id', $product->id)->exists()
        );
    }
}
