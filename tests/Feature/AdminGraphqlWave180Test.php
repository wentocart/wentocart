<?php

namespace Tests\Feature;

use App\Domain\Search\Models\SearchRedirect;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave180Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_marketing_search_terms_rest_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $row = SearchRedirect::query()->create([
            'query' => 'wave180-term-'.$suffix,
            'target_path' => '/search?q=wave180',
            'is_enabled' => true,
        ]);
        $id = (int) $row->id;

        $noCreate = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/search-terms', [
            'term' => 'should-not-create',
        ]);
        // API has no create — Laravel reports 404 when the POST route is absent.
        $this->assertContains($noCreate->status(), [404, 405]);

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketing/search-terms?term=wave180-term-'.$suffix);
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $this->assertArrayHasKey('currentPage', $list->json('meta'));
        $this->assertSame('wave180-term-'.$suffix, (string) $list->json('data.0.term'));
        $this->assertArrayHasKey('uses', $list->json('data.0'));
        $this->assertArrayHasKey('results', $list->json('data.0'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketing/search-terms/'.$id);
        $show->assertOk();
        $this->assertArrayHasKey('channel', $show->json());
        $this->assertNull($show->json('channel'));

        $update = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/marketing/search-terms/'.$id, [
            'term' => 'wave180-updated-'.$suffix,
            'redirect_url' => '/catalog/wave180-'.$suffix,
        ]);
        $update->assertOk();
        $this->assertSame('wave180-updated-'.$suffix, (string) $update->json('term'));
        $this->assertSame('/catalog/wave180-'.$suffix, (string) $update->json('redirect_url'));

        $bad = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/marketing/search-terms/'.$id, [
            'redirect_url' => 'https://evil.example',
        ]);
        $bad->assertStatus(422);

        $extra = SearchRedirect::query()->create([
            'query' => 'wave180-extra-'.$suffix,
            'target_path' => '/x',
            'is_enabled' => true,
        ]);
        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/search-terms/bulk-delete', [
            'ids' => [(int) $extra->id, 999999],
        ]);
        $mass->assertOk();
        $this->assertContains((int) $extra->id, $mass->json('deleted'));

        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/marketing/search-terms/'.$id);
        $del->assertOk();
        $this->assertFalse(SearchRedirect::query()->whereKey($id)->exists());

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminMarketingSearchTerms(limit: 5) { id term redirect_url } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
    }
}
