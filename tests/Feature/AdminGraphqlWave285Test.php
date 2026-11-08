<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminGraphqlWave285Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_catalog_product_images_rest(): void
    {
        $this->seed();
        Storage::fake('public');
        $admin = Admin::query()->firstOrFail();
        $product = Product::query()->where('is_enabled', true)->where('type', 'simple')->firstOrFail();

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/products/'.$product->id.'/images');
        $list->assertOk();
        $this->assertIsArray($list->json('data'));

        $upload = $this->actingAs($admin, 'admin')->post(
            '/api/v1/console/products/'.$product->id.'/images',
            [
                'image' => UploadedFile::fake()->image('wave285.jpg', 120, 120),
                'alt_text' => 'Wave285 alt',
                'position' => 3,
            ],
            ['Accept' => 'application/json']
        );
        $upload->assertCreated();
        $imageId = (int) $upload->json('id');
        $this->assertGreaterThan(0, $imageId);
        $this->assertSame('Wave285 alt', (string) $upload->json('altText'));

        $update = $this->actingAs($admin, 'admin')->putJson(
            '/api/v1/console/products/'.$product->id.'/images/'.$imageId,
            ['alt_text' => 'Wave285 updated', 'position' => 1]
        );
        $update->assertOk();
        $this->assertSame('Wave285 updated', (string) $update->json('data.altText'));

        $destroy = $this->actingAs($admin, 'admin')->deleteJson(
            '/api/v1/console/products/'.$product->id.'/images/'.$imageId
        );
        $destroy->assertOk();
        $this->assertNull(ProductImage::query()->find($imageId));
    }
}
