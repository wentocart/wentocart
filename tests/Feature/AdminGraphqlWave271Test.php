<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Attachment;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductComponent;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave271Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_bundle_translation_and_sample_root_collections(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $parent = Product::query()->create([
            'type' => 'bundle',
            'sku' => 'W271-B-'.uniqid(),
            'price' => 50,
            'stock_qty' => 5,
            'is_enabled' => true,
        ]);
        $child = Product::query()->where('is_enabled', true)->where('type', 'simple')->firstOrFail();
        $comp = ProductComponent::query()->create([
            'parent_product_id' => $parent->id,
            'child_product_id' => $child->id,
            'quantity' => 1,
            'option_group' => 'Finish',
            'position' => 1,
        ]);

        $trs = $this->getJson('/api/v1/store/product_bundle_option_translations?per_page=50');
        $trs->assertOk();
        $this->assertTrue(collect($trs->json() ?? [])->contains(fn ($r) => (int) ($r['id'] ?? 0) === (int) $comp->id));

        $one = $this->getJson('/api/v1/store/product_bundle_option_translations/'.$comp->id);
        $one->assertOk();
        $this->assertSame('Finish', (string) $one->json('label'));

        Attachment::query()->create([
            'name' => 'W271 Sample',
            'original_filename' => 'sample.pdf',
            'stored_filename' => 'sample.pdf',
            'storage_path' => 'attachments/w271-sample.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'file_size' => 512,
        ]);

        $samples = $this->getJson('/api/v1/store/product-downloadable-samples?per_page=10');
        $samples->assertOk();
        $this->assertNotEmpty($samples->json());
        $sid = (int) ($samples->json('0.id') ?? 0);
        $this->assertGreaterThan(0, $sid);

        $show = $this->getJson('/api/v1/store/product-downloadable-samples/'.$sid);
        $show->assertOk();
        $this->assertSame($sid, (int) $show->json('id'));
    }
}
