<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCustomizationField;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminGraphqlWave290Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_customizable_options_and_product_relations_rest(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));
        $product = Product::query()->where('is_enabled', true)->where('type', 'simple')->orderBy('id')->firstOrFail();
        $related = Product::query()->where('is_enabled', true)->where('id', '!=', $product->id)->orderBy('id')->first();
        if (! $related) {
            $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/products', [
                'sku' => 'w290-rel-'.$suffix,
                'name' => 'Wave290 Related '.$suffix,
                'price' => 12,
                'type' => 'simple',
                'stock_qty' => 5,
                'status' => 1,
            ]);
            $create->assertCreated();
            $related = Product::query()->findOrFail((int) $create->json('data.id'));
        }

        $opt = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/products/'.$product->id.'/custom-options',
            [
                'type' => 'field',
                'label' => 'Engraving '.$suffix,
                'isRequired' => 1,
                'priceType' => 'fixed',
                'price' => 5,
                'maxCharacters' => 40,
            ]
        );
        $opt->assertCreated();
        $optionId = (int) $opt->json('data.id');
        $this->assertGreaterThan(0, $optionId);
        $this->assertSame('field', (string) $opt->json('data.type'));
        $this->assertSame(1, (int) $opt->json('data.isRequired'));

        $list = $this->actingAs($admin, 'admin')->getJson(
            '/api/v1/console/products/'.$product->id.'/custom-options'
        );
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));

        $upd = $this->actingAs($admin, 'admin')->putJson(
            '/api/v1/console/products/'.$product->id.'/custom-options/'.$optionId,
            ['label' => 'Engraving renamed '.$suffix, 'is_required' => false]
        );
        $upd->assertOk();
        $this->assertSame('Engraving renamed '.$suffix, (string) $upd->json('data.label'));
        $this->assertSame(0, (int) $upd->json('data.isRequired'));

        $rel = $this->actingAs($admin, 'admin')->putJson(
            '/api/v1/console/products/'.$product->id.'/related-products',
            ['ids' => [$related->id]]
        );
        $rel->assertOk();
        $this->assertCount(1, $rel->json('data') ?? []);
        $this->assertSame((int) $related->id, (int) ($rel->json('data.0.id') ?? 0));

        $this->actingAs($admin, 'admin')->putJson(
            '/api/v1/console/products/'.$product->id.'/up-sells',
            ['ids' => [$related->id]]
        )->assertOk();
        $this->actingAs($admin, 'admin')->putJson(
            '/api/v1/console/products/'.$product->id.'/cross-sells',
            ['product_ids' => [$related->id]]
        )->assertOk();

        $ups = $this->actingAs($admin, 'admin')->getJson(
            '/api/v1/console/products/'.$product->id.'/up-sells'
        );
        $ups->assertOk();
        $this->assertNotEmpty($ups->json('data'));

        $this->assertSame(1, (int) DB::table('product_relations')
            ->where('product_id', $product->id)->where('type', 'related')->count());

        $this->actingAs($admin, 'admin')
            ->deleteJson('/api/v1/console/products/'.$product->id.'/custom-options/'.$optionId)
            ->assertOk();
        $this->assertNull(ProductCustomizationField::query()->find($optionId));
    }
}
