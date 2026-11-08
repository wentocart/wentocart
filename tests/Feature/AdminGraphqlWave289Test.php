<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Attachment;
use App\Domain\Catalog\Models\Product;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminGraphqlWave289Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_admin_downloadables_videos_and_mass_destroy_alias(): void
    {
        $this->seed();
        Storage::fake('public');
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/products', [
            'sku' => 'w289-'.$suffix,
            'name' => 'Wave289 DL '.$suffix,
            'price' => 19.5,
            'type' => 'downloadable',
            'stock_qty' => 0,
            'status' => 1,
        ]);
        $create->assertCreated();
        $productId = (int) $create->json('data.id');
        $this->assertGreaterThan(0, $productId);

        $link = $this->actingAs($admin, 'admin')->post(
            '/api/v1/console/products/'.$productId.'/downloadable-links',
            [
                'file' => UploadedFile::fake()->create('wave289-paid.pdf', 32, 'application/pdf'),
                'title' => 'Paid chapter '.$suffix,
            ],
            ['Accept' => 'application/json']
        );
        $link->assertCreated();
        $linkId = (int) $link->json('data.id');
        $this->assertGreaterThan(0, $linkId);
        $this->assertSame('Paid chapter '.$suffix, (string) $link->json('data.title'));

        $sample = $this->actingAs($admin, 'admin')->post(
            '/api/v1/console/products/'.$productId.'/downloadable-samples',
            [
                'file' => UploadedFile::fake()->create('wave289-sample.pdf', 16, 'application/pdf'),
                'title' => 'Sample '.$suffix,
            ],
            ['Accept' => 'application/json']
        );
        $sample->assertCreated();
        $sampleId = (int) $sample->json('data.id');

        $links = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/products/'.$productId.'/downloadable-links');
        $links->assertOk();
        $this->assertNotEmpty($links->json('data'));

        $samples = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/products/'.$productId.'/downloadable-samples');
        $samples->assertOk();
        $this->assertNotEmpty($samples->json('data'));

        $upd = $this->actingAs($admin, 'admin')->putJson(
            '/api/v1/console/products/'.$productId.'/downloadable-links/'.$linkId,
            ['title' => 'Paid renamed '.$suffix]
        );
        $upd->assertOk();
        $this->assertSame('Paid renamed '.$suffix, (string) $upd->json('data.title'));

        $video = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/products/'.$productId.'/videos',
            ['video_url' => 'https://cdn.example.test/w289-'.$suffix.'.mp4']
        );
        $video->assertCreated();
        $listVideos = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/products/'.$productId.'/videos');
        $listVideos->assertOk();
        $this->assertCount(1, $listVideos->json('data') ?? []);

        $this->actingAs($admin, 'admin')->putJson(
            '/api/v1/console/products/'.$productId.'/videos',
            ['url' => 'https://cdn.example.test/w289-updated-'.$suffix.'.mp4']
        )->assertOk();

        $this->actingAs($admin, 'admin')
            ->deleteJson('/api/v1/console/products/'.$productId.'/videos')
            ->assertOk();
        $this->assertSame('', trim((string) (Product::query()->find($productId)?->video_url ?? '')));

        $this->actingAs($admin, 'admin')
            ->deleteJson('/api/v1/console/products/'.$productId.'/downloadable-links/'.$linkId)
            ->assertOk();
        $this->assertNull(Attachment::query()->find($linkId));

        $this->actingAs($admin, 'admin')
            ->deleteJson('/api/v1/console/products/'.$productId.'/downloadable-samples/'.$sampleId)
            ->assertOk();
        $this->assertNull(Attachment::query()->find($sampleId));

        // API currencies bulk-delete alias (empty ids → 422 validation, proves route wired)
        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/currencies/bulk-delete', [
            'ids' => [],
        ]);
        $this->assertContains($mass->status(), [200, 422]);
    }
}
