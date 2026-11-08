<?php

namespace Tests\Feature;

use App\Domain\Cms\Models\CmsPage;
use App\Domain\Search\Models\SearchRedirect;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave186Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cms_pages_rest_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));
        $slug = 'wave186-about-'.$suffix;

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/cms/pages', [
            'locale' => 'en',
            'stores' => [1],
            'page_title' => 'Wave186 About '.$suffix,
            'url_key' => $slug,
            'html_content' => '<p>Hello wave186</p>',
            'meta_title' => 'Meta '.$suffix,
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');
        $this->assertGreaterThan(0, $id);
        $this->assertSame($slug, (string) $create->json('url_key'));
        $this->assertSame('Wave186 About '.$suffix, (string) $create->json('page_title'));

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/cms/pages?url_key='.$slug);
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $this->assertArrayHasKey('currentPage', $list->json('meta'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/cms/pages/'.$id);
        $show->assertOk();
        $this->assertSame('<p>Hello wave186</p>', (string) $show->json('html_content'));

        $newSlug = 'wave186-about-v2-'.$suffix;
        $update = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/cms/pages/'.$id, [
            'locale' => 'en',
            'stores' => [1],
            'en' => [
                'url_key' => $newSlug,
                'page_title' => 'Wave186 About V2 '.$suffix,
                'html_content' => '<p>Updated</p>',
            ],
        ]);
        $update->assertOk();
        $this->assertSame($newSlug, (string) $update->json('url_key'));
        $this->assertTrue(SearchRedirect::query()->where('query', '/page/'.$slug)->where('target_path', '/page/'.$newSlug)->exists());

        $dup = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/cms/pages', [
            'page_title' => 'Dup',
            'url_key' => $newSlug,
            'html_content' => '<p>x</p>',
        ]);
        $dup->assertStatus(422);

        $extra = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/cms/pages', [
            'page_title' => 'Wave186 Extra '.$suffix,
            'url_key' => 'wave186-extra-'.$suffix,
            'html_content' => '<p>extra</p>',
            'stores' => [1],
        ]);
        $extra->assertCreated();
        $extraId = (int) $extra->json('id');

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/cms/pages/bulk-delete', [
            'ids' => [$extraId, 999999],
        ]);
        $mass->assertOk();
        $this->assertContains($extraId, $mass->json('deleted'));

        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/cms/pages/'.$id);
        $del->assertOk();
        $this->assertFalse(CmsPage::query()->whereKey($id)->exists());

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminCmsPages(limit: 5) { id pageTitle slug } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
    }
}
