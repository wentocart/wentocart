<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Attachment;
use App\Domain\Catalog\Models\Product;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave267Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_downloadable_translation_iris(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $product = Product::query()->where('sku', 'DEMO-001')->first()
            ?? Product::query()->where('is_enabled', true)->firstOrFail();

        $attachment = Attachment::query()->create([
            'name' => 'Wave267 Guide',
            'original_filename' => 'guide.pdf',
            'stored_filename' => 'guide.pdf',
            'storage_path' => 'attachments/w267-guide.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'description' => 'Sample guide',
        ]);
        $product->attachments()->syncWithoutDetaching([$attachment->id]);

        $links = $this->getJson('/api/v1/store/products/'.$product->id.'/downloadable-links');
        $links->assertOk();
        $this->assertNotEmpty($links->json());
        $iri = (string) ($links->json('0.translation') ?? '');
        $this->assertStringContainsString('/api/v1/store/product_downloadable_link_translations/', $iri);

        $tr = $this->getJson('/api/v1/store/product_downloadable_link_translations/'.$attachment->id);
        $tr->assertOk();
        $this->assertSame((int) $attachment->id, (int) $tr->json('id'));
        $this->assertNotEmpty((string) $tr->json('title'));

        $sampleTr = $this->getJson('/api/v1/store/product_downloadable_sample_translations/'.$attachment->id);
        $sampleTr->assertOk();
        $this->assertSame((int) $attachment->id, (int) $sampleTr->json('id'));
    }
}
