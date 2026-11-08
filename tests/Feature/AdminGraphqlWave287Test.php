<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminGraphqlWave287Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_categories_tree_images_reorder_families_alias(): void
    {
        $this->seed();
        Storage::fake('public');
        $admin = Admin::query()->firstOrFail();
        $product = Product::query()->where('is_enabled', true)->where('type', 'simple')->firstOrFail();

        $tree = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/categories/tree');
        $tree->assertOk();
        $this->assertIsArray($tree->json('data'));

        $this->actingAs($admin, 'admin')->getJson('/api/v1/console/families')->assertOk();

        $img1 = ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'catalog/products/'.$product->id.'/a.jpg',
            'alt' => 'A',
            'position' => 0,
        ]);
        $img2 = ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'catalog/products/'.$product->id.'/b.jpg',
            'alt' => 'B',
            'position' => 1,
        ]);
        Storage::disk('public')->put($img1->path, 'x');
        Storage::disk('public')->put($img2->path, 'y');

        $reorder = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/products/'.$product->id.'/images/reorder',
            ['orders' => [
                ['id' => $img1->id, 'position' => 5],
                ['id' => $img2->id, 'position' => 2],
            ]]
        );
        $reorder->assertOk();
        $this->assertSame(5, (int) $img1->fresh()->position);
        $this->assertSame(2, (int) $img2->fresh()->position);
    }
}
