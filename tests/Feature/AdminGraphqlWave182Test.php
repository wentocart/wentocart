<?php

namespace Tests\Feature;

use App\Domain\Marketing\Models\MarketingSitemap;
use App\Domain\Store\Models\Store;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave182Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_marketing_sitemaps_rest_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $store = Store::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));
        $fileName = 'wave182-'.$suffix.'.xml';

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/sitemaps', [
            'file_name' => $fileName,
            'path' => '/sitemap/',
            'stores' => [(int) $store->id],
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');
        $this->assertGreaterThan(0, $id);
        $this->assertSame($fileName, (string) $create->json('file_name'));
        $this->assertNull($create->json('generated_at'));

        $badPath = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/sitemaps', [
            'file_name' => 'bad.xml',
            'path' => 'no-slash',
            'stores' => [(int) $store->id],
        ]);
        $badPath->assertStatus(422);

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketing/sitemaps?file_name=wave182-'.$suffix);
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $this->assertArrayHasKey('currentPage', $list->json('meta'));
        $this->assertArrayNotHasKey('generatedFiles', $list->json('data.0') ?? []);

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketing/sitemaps/'.$id);
        $show->assertOk();
        $this->assertArrayHasKey('generatedFiles', $show->json());

        $gen = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/sitemaps/'.$id.'/generate');
        $gen->assertOk();
        $this->assertTrue((bool) $gen->json('ok'));
        $this->assertNotEmpty($gen->json('generatedAt'));
        $this->assertNotEmpty($gen->json('indexFile'));
        $this->assertNotNull(MarketingSitemap::query()->find($id)?->generated_at);

        $update = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/marketing/sitemaps/'.$id, [
            'path' => '/sitemap/v2/',
        ]);
        $update->assertOk();
        $this->assertSame('/sitemap/v2/', (string) $update->json('path'));

        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/marketing/sitemaps/'.$id);
        $del->assertOk();
        $this->assertFalse(MarketingSitemap::query()->whereKey($id)->exists());

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminMarketingSitemaps(limit: 5) { id fileName } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
    }
}
