<?php

namespace Tests\Feature;

use App\Domain\Search\Models\SearchRedirect;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave179Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_marketing_url_rewrites_rest_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/url-rewrites', [
            'entity_type' => 'cms_page',
            'request_path' => '/old-wave179-'.$suffix,
            'target_path' => '/new-wave179-'.$suffix,
            'redirect_type' => '301',
            'locale' => 'en',
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');
        $this->assertGreaterThan(0, $id);
        $this->assertSame('/old-wave179-'.$suffix, (string) $create->json('request_path'));
        $this->assertSame('/new-wave179-'.$suffix, (string) $create->json('target_path'));
        $this->assertSame('301', (string) $create->json('redirect_type'));

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketing/url-rewrites?request_path=wave179-'.$suffix);
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $this->assertArrayHasKey('currentPage', $list->json('meta'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketing/url-rewrites/'.$id);
        $show->assertOk();
        $this->assertSame('/new-wave179-'.$suffix, (string) $show->json('target_path'));

        $update = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/marketing/url-rewrites/'.$id, [
            'target_path' => '/updated-wave179-'.$suffix,
            'redirect_type' => '302',
        ]);
        $update->assertOk();
        $this->assertSame('/updated-wave179-'.$suffix, (string) $update->json('target_path'));
        $this->assertSame('302', (string) $update->json('redirect_type'));

        $bad = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/url-rewrites', [
            'request_path' => '/bad',
            'target_path' => 'https://evil.example',
            'redirect_type' => '301',
        ]);
        $bad->assertStatus(422);

        $extra = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/url-rewrites', [
            'request_path' => '/extra-wave179-'.$suffix,
            'target_path' => '/extra-target-'.$suffix,
            'redirect_type' => '301',
            'entity_type' => 'product',
            'locale' => 'en',
        ]);
        $extra->assertCreated();
        $extraId = (int) $extra->json('id');

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/url-rewrites/bulk-delete', [
            'ids' => [$extraId, 999999],
        ]);
        $mass->assertOk();
        $this->assertContains($extraId, $mass->json('deleted'));

        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/marketing/url-rewrites/'.$id);
        $del->assertOk();
        $this->assertFalse(SearchRedirect::query()->whereKey($id)->exists());

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminMarketingUrlRewrites(limit: 5) { id requestPath targetPath } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
    }
}
