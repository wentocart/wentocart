<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave268Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_products_items_per_page_and_product_videos(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $list = $this->getJson('/api/v1/store/products?itemsPerPage=5&page=1');
        $list->assertOk();
        $this->assertLessThanOrEqual(5, count($list->json() ?? []));
        $this->assertSame('5', (string) $list->headers->get('X-Per-Page'));

        $product = Product::query()->where('sku', 'DEMO-001')->first()
            ?? Product::query()->where('is_enabled', true)->firstOrFail();
        $name = (string) ($product->translation('en')?->name ?? 'Demo');
        $token = explode(' ', $name)[0] ?? $name;
        $byName = $this->getJson('/api/v1/store/products?name='.urlencode($token));
        $byName->assertOk();
        $this->assertNotEmpty($byName->json());

        Product::query()->whereKey($product->id)->update([
            'video_url' => 'https://cdn.example.com/videos/w268.mp4',
        ]);

        $videos = $this->getJson('/api/v1/store/product-videos?per_page=10');
        $videos->assertOk();
        $this->assertNotEmpty($videos->json());
        $this->assertSame('videos', (string) $videos->json('0.type'));

        $one = $this->getJson('/api/v1/store/product-videos/'.$product->id);
        $one->assertOk();
        $this->assertStringContainsString('w268.mp4', (string) $one->json('path'));
    }
}
