<?php

namespace Tests\Feature;

use App\Domain\Search\Models\SearchRedirect;
use App\Domain\Search\Models\SearchSynonym;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave64Test extends TestCase
{
    use RefreshDatabase;

    public function test_marketing_search_seo_rest_aliases(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $syn = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createAdminMarketingSearchSynonym(
                    name: "wave64-shoes"
                    terms: "sneakers, trainers, kicks"
                    enabled: true
                ) { id name terms term aliases status }
            }',
        ]);
        $syn->assertOk();
        $this->assertArrayNotHasKey('errors', $syn->json() ?? [], json_encode($syn->json()));
        $synId = (string) $syn->json('data.createAdminMarketingSearchSynonym.id');
        $this->assertSame('wave64-shoes', (string) $syn->json('data.createAdminMarketingSearchSynonym.name'));
        $this->assertStringContainsString('sneakers', (string) $syn->json('data.createAdminMarketingSearchSynonym.terms'));

        $synUpdate = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateAdminMarketingSearchSynonym(id: $id, terms: "sneakers, runners") {
                    id terms
                }
            }',
            'variables' => ['id' => $synId],
        ]);
        $synUpdate->assertOk();
        $this->assertStringContainsString('runners', (string) $synUpdate->json('data.updateAdminMarketingSearchSynonym.terms'));

        $synList = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                adminMarketingSearchSynonyms(name: "wave64", limit: 10) { id name }
                adminMarketingSearchSynonym(id: "'.$synId.'") { id terms }
            }',
        ]);
        $synList->assertOk();
        $this->assertArrayNotHasKey('errors', $synList->json() ?? [], json_encode($synList->json()));
        $this->assertNotEmpty($synList->json('data.adminMarketingSearchSynonyms'));
        $this->assertSame($synId, (string) $synList->json('data.adminMarketingSearchSynonym.id'));

        $rewrite = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createAdminMarketingUrlRewrite(
                    requestPath: "wave64-old-path"
                    targetPath: "/collections/wave64"
                    enabled: true
                ) { id requestPath targetPath redirectType term redirect_url }
            }',
        ]);
        $rewrite->assertOk();
        $this->assertArrayNotHasKey('errors', $rewrite->json() ?? [], json_encode($rewrite->json()));
        $rewriteId = (string) $rewrite->json('data.createAdminMarketingUrlRewrite.id');
        $this->assertSame('wave64-old-path', (string) $rewrite->json('data.createAdminMarketingUrlRewrite.requestPath'));
        $this->assertSame('/collections/wave64', (string) $rewrite->json('data.createAdminMarketingUrlRewrite.targetPath'));

        $termUpdate = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateAdminMarketingSearchTerm(id: $id, term: "wave64-search", redirect_url: "/search?q=wave64") {
                    id term redirect_url
                }
            }',
            'variables' => ['id' => $rewriteId],
        ]);
        $termUpdate->assertOk();
        $this->assertArrayNotHasKey('errors', $termUpdate->json() ?? [], json_encode($termUpdate->json()));
        $this->assertSame('wave64-search', (string) $termUpdate->json('data.updateAdminMarketingSearchTerm.term'));

        $lists = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                adminMarketingUrlRewrites(q: "wave64", limit: 10) { id requestPath }
                adminMarketingSearchTerms(q: "wave64", limit: 10) { id term }
                adminMarketingUrlRewrite(id: "'.$rewriteId.'") { id targetPath }
                adminMarketingSearchTerm(id: "'.$rewriteId.'") { id redirect_url }
            }',
        ]);
        $lists->assertOk();
        $this->assertArrayNotHasKey('errors', $lists->json() ?? [], json_encode($lists->json()));
        $this->assertNotEmpty($lists->json('data.adminMarketingUrlRewrites'));
        $this->assertNotEmpty($lists->json('data.adminMarketingSearchTerms'));

        $massSyn = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($ids: [ID!]!) {
                bulkDeleteAdminMarketingSearchSynonym(ids: $ids) {
                    ok updated_count deleted
                }
            }',
            'variables' => ['ids' => [$synId]],
        ]);
        $massSyn->assertOk();
        $this->assertTrue((bool) $massSyn->json('data.bulkDeleteAdminMarketingSearchSynonym.ok'));
        $this->assertNull(SearchSynonym::query()->find((int) $synId));

        $massRw = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($ids: [ID!]!) {
                bulkDeleteAdminMarketingUrlRewrite(ids: $ids) {
                    ok updated_count
                }
            }',
            'variables' => ['ids' => [$rewriteId]],
        ]);
        $massRw->assertOk();
        $this->assertTrue((bool) $massRw->json('data.bulkDeleteAdminMarketingUrlRewrite.ok'));
        $this->assertNull(SearchRedirect::query()->find((int) $rewriteId));
    }
}
