<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\ProductTemplate;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminGraphqlWave293Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_product_categories_and_family_validation(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));
        $product = Product::query()->where('is_enabled', true)->where('type', 'simple')->orderBy('id')->firstOrFail();
        $cats = Category::query()->where('is_enabled', true)->orderBy('id')->take(2)->get();
        $this->assertGreaterThanOrEqual(1, $cats->count());
        $catA = $cats[0];
        $catB = $cats->count() > 1 ? $cats[1] : null;
        if (! $catB) {
            $createCat = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/categories', [
                'name' => 'Wave293 Cat '.$suffix,
                'status' => 1,
            ]);
            $createCat->assertCreated();
            $catB = Category::query()->findOrFail((int) $createCat->json('data.id'));
        }

        $put = $this->actingAs($admin, 'admin')->putJson(
            '/api/v1/console/products/'.$product->id.'/categories',
            ['categories' => [$catA->id, $catB->id]]
        );
        $put->assertOk();
        $this->assertGreaterThanOrEqual(2, count($put->json('data') ?? []));
        $this->assertTrue(DB::table('category_product')->where('product_id', $product->id)->where('category_id', $catA->id)->exists());

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/products/'.$product->id.'/categories');
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));

        $badFamily = $this->actingAs($admin, 'admin')->putJson(
            '/api/v1/console/products/'.$product->id,
            ['product_template_id' => 999999, 'name' => 'Wave293 '.$suffix]
        );
        $badFamily->assertStatus(422);

        $family = ProductTemplate::query()->orderBy('id')->first();
        if ($family) {
            $ok = $this->actingAs($admin, 'admin')->putJson(
                '/api/v1/console/products/'.$product->id,
                ['product_template_id' => $family->id, 'name' => 'Wave293 Family '.$suffix]
            );
            $ok->assertOk();
            $this->assertSame('simple', (string) ($ok->json('data.type') ?? ''));
        }

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/products/'.$product->id);
        $show->assertOk();
        $this->assertArrayHasKey('typeNative', $show->json('data') ?? []);
    }
}
