<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave295Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_admin_product_detail_embeds(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $product = Product::query()->where('is_enabled', true)->where('type', 'simple')->orderBy('id')->firstOrFail();

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/products/'.$product->id);
        $show->assertOk();
        $data = $show->json('data');
        $this->assertIsArray($data);
        foreach (['images', 'videos', 'categories', 'downloadableLinks', 'downloadableSamples', 'customizableOptions', 'warnings'] as $key) {
            $this->assertArrayHasKey($key, $data, $key);
            $this->assertIsArray($data[$key]);
        }
    }
}
