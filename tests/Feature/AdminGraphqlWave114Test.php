<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Attachment;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\AttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminGraphqlWave114Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_product_downloadable_links_and_samples(): void
    {
        $this->seed();
        Storage::fake('local');

        $product = Product::query()->create([
            'type' => 'virtual',
            'sku' => 'W114-DL-'.uniqid(),
            'price' => 29,
            'is_enabled' => true,
            'manage_stock' => false,
        ]);
        $product->translations()->create([
            'language_code' => 'en',
            'name' => 'Wave114 Downloadable',
            'slug' => 'wave114-dl-'.uniqid(),
        ]);

        Storage::disk('local')->put('downloads/w114-paid.pdf', '%PDF-1.4 paid');
        Storage::disk('local')->put('downloads/w114-sample.pdf', '%PDF-1.4 sample');

        $paid = Attachment::query()->create([
            'name' => 'Wave114 Paid Chapter',
            'original_filename' => 'chapter.pdf',
            'stored_filename' => 'chapter.pdf',
            'storage_path' => 'downloads/w114-paid.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'file_size' => 12,
        ]);
        $sample = Attachment::query()->create([
            'name' => 'Wave114 Sample',
            'original_filename' => 'sample.pdf',
            'stored_filename' => 'sample.pdf',
            'storage_path' => 'downloads/w114-sample.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'file_size' => 14,
        ]);
        app(AttachmentService::class)->attachToProduct($product, $paid, AttachmentService::ROLE_PAID);
        app(AttachmentService::class)->attachToProduct($product, $sample, AttachmentService::ROLE_DOCUMENT);

        $response = $this->postJson('/api/v1/graphql', [
            'query' => 'query ($id: ID!) {
                product(id: $id) {
                    id type
                    downloadableLinks(first: 10) {
                        totalCount
                        edges {
                            node {
                                id price
                                translation { title }
                                file fileUrl
                            }
                        }
                    }
                    downloadableSamples(first: 10) {
                        totalCount
                        edges {
                            node {
                                id
                                translation { title }
                                file url
                            }
                        }
                    }
                }
            }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? [], json_encode($response->json()));
        $this->assertSame(1, (int) $response->json('data.product.downloadableLinks.totalCount'));
        $this->assertSame(1, (int) $response->json('data.product.downloadableSamples.totalCount'));
        $this->assertSame(
            'Wave114 Paid Chapter',
            (string) $response->json('data.product.downloadableLinks.edges.0.node.translation.title')
        );
        $this->assertSame(
            'Wave114 Sample',
            (string) $response->json('data.product.downloadableSamples.edges.0.node.translation.title')
        );
    }
}
