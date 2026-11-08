<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\FeatureValue;
use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\Review;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminGraphqlWave274Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_review_translate_category_attrs_compare_hyphen_summary(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $product = Product::query()->where('is_enabled', true)->where('type', 'simple')->first()
            ?? Product::query()->create([
                'type' => 'simple',
                'sku' => 'W274-'.uniqid(),
                'price' => 12.5,
                'stock_qty' => 5,
                'is_enabled' => true,
            ]);

        $feature = Feature::query()->create([
            'code' => 'w274_color_'.uniqid(),
            'type' => 'select',
            'is_filterable' => true,
            'is_enabled' => true,
            'position' => 1,
        ]);
        $feature->translations()->create([
            'language_code' => 'en',
            'name' => 'Wave274 Color',
        ]);
        $opt = FeatureValue::query()->create([
            'feature_id' => $feature->id,
            'code' => 'red',
            'is_enabled' => true,
            'position' => 1,
        ]);
        $opt->translations()->create([
            'language_code' => 'en',
            'value' => 'Red',
        ]);

        $attrs = $this->getJson('/api/v1/store/categories/attributes');
        $attrs->assertOk();
        $this->assertTrue(collect($attrs->json() ?? [])->contains(fn ($r) => (int) ($r['id'] ?? 0) === (int) $feature->id));

        $opts = $this->getJson('/api/v1/store/categories/attributes/'.$feature->id.'/options');
        $opts->assertOk();
        $this->assertTrue(collect($opts->json() ?? [])->contains(fn ($r) => (int) ($r['id'] ?? 0) === (int) $opt->id));

        $customer = Customer::query()->create([
            'name' => 'Wave274 Shopper',
            'email' => 'w274@example.com',
            'password' => Hash::make('Password1!'),
            'is_enabled' => true,
        ]);
        $login = $this->postJson('/api/v1/store/customer/login', [
            'email' => 'w274@example.com',
            'password' => 'Password1!',
        ]);
        $login->assertCreated();
        $headers = ['Authorization' => 'Bearer '.(string) $login->json('token')];

        $review = Review::query()->create([
            'product_id' => $product->id,
            'customer_id' => $customer->id,
            'rating' => 5,
            'title' => 'Great',
            'body' => 'Excellent product for Wave 274.',
            'is_approved' => true,
            'status' => Review::STATUS_APPROVED,
        ]);

        $translate = $this->getJson('/api/v1/store/products/'.$product->id.'/reviews/'.$review->id.'/translate');
        $translate->assertOk();
        $this->assertSame('Excellent product for Wave 274.', (string) $translate->json('content'));
        $this->assertTrue((bool) $translate->json('success'));

        $review->forceFill([
            'is_approved' => false,
            'status' => Review::STATUS_PENDING,
        ])->save();
        $this->getJson('/api/v1/store/product/'.$product->id.'/reviews/'.$review->id.'/translate')
            ->assertStatus(400);
        $review->forceFill([
            'is_approved' => true,
            'status' => Review::STATUS_APPROVED,
        ])->save();

        $summary = $this->getJson('/api/v1/store/checkout/onepage/summary');
        $summary->assertOk();
        $this->assertArrayHasKey('grandTotal', $summary->json() ?? []);
        $this->assertTrue((bool) $summary->json('success'));

        $coreStates = $this->getJson('/api/v1/store/core/states?country=US');
        $coreStates->assertOk();
        $this->assertIsArray($coreStates->json());

        $this->withHeaders($headers)->postJson('/api/v1/store/compare-items', [
            'productId' => (int) $product->id,
        ])->assertCreated();

        $list = $this->withHeaders($headers)->getJson('/api/v1/store/compare-items');
        $list->assertOk();
        $this->assertTrue(collect($list->json() ?? [])->contains(fn ($r) => (int) ($r['productId'] ?? $r['id'] ?? 0) === (int) $product->id
            || str_contains((string) ($r['product'] ?? ''), '/products/'.$product->id)));

        $this->withHeaders($headers)->deleteJson('/api/v1/store/compare-items', [
            'product_id' => (int) $product->id,
        ])->assertOk();

        $this->withHeaders($headers)->postJson('/api/v1/store/wishlist', [
            'productId' => (int) $product->id,
        ])->assertCreated();
        $wl = $this->withHeaders($headers)->getJson('/api/v1/store/wishlist');
        $wl->assertOk();
        $this->assertNotEmpty($wl->json());

        // destroySelected alias
        $create = $this->postJson('/api/v1/store/create-cart');
        $create->assertCreated();
        $token = (string) $create->json('cartToken');
        $add = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/v1/store/add-product-in-cart', [
                'productId' => (int) $product->id,
                'quantity' => 1,
            ]);
        $add->assertOk();
        $itemId = (int) ($add->json('items.0.id') ?? 0);
        $this->assertGreaterThan(0, $itemId);
        $rm = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->deleteJson('/api/v1/store/checkout/cart/selected', ['ids' => [$itemId]]);
        $rm->assertCreated();
        $this->assertTrue((bool) $rm->json('success'));
    }
}
