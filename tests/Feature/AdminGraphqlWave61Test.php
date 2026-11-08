<?php

namespace Tests\Feature;

use App\Domain\Cms\Models\CmsPage;
use App\Domain\Tax\Models\TaxClass;
use App\Domain\Tax\Models\TaxRate;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave61Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cms_and_tax_settings_rest_aliases(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $slug = 'wave61-'.substr(uniqid(), -6);
        $page = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($slug: String!) {
                createAdminCmsPage(title: "Wave61 About", slug: $slug, body: "Hello", status: "published") {
                    id title pageTitle slug slug status
                }
            }',
            'variables' => ['slug' => $slug],
        ]);
        $page->assertOk();
        $this->assertArrayNotHasKey('errors', $page->json() ?? [], json_encode($page->json()));
        $pageId = (string) $page->json('data.createAdminCmsPage.id');
        $this->assertSame('Wave61 About', (string) $page->json('data.createAdminCmsPage.pageTitle'));
        $this->assertSame($slug, (string) $page->json('data.createAdminCmsPage.slug'));

        $updated = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateAdminCmsPage(id: $id, title: "Wave61 About Updated") { id pageTitle }
            }',
            'variables' => ['id' => $pageId],
        ]);
        $updated->assertOk();
        $this->assertSame('Wave61 About Updated', (string) $updated->json('data.updateAdminCmsPage.pageTitle'));

        $list = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($q: String!) {
                adminCmsPages(q: $q, limit: 10) { id slug }
                adminCmsPage(id: "'.$pageId.'") { id title slug }
            }',
            'variables' => ['q' => 'Wave61'],
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? [], json_encode($list->json()));
        $this->assertNotEmpty($list->json('data.adminCmsPages'));
        $this->assertSame($pageId, (string) $list->json('data.adminCmsPage.id'));

        $rate = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createAdminSettingsTaxRate(identifier: "W61-VAT", taxRate: 8.5, enabled: true) {
                    identifier taxRate rate status
                }
            }',
        ]);
        $rate->assertOk();
        $this->assertArrayNotHasKey('errors', $rate->json() ?? [], json_encode($rate->json()));
        $rateId = (string) $rate->json('data.createAdminSettingsTaxRate.id');
        $this->assertSame(8.5, (float) $rate->json('data.createAdminSettingsTaxRate.taxRate'));

        $rateUpdate = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateAdminSettingsTaxRate(id: $id, taxRate: 9) { id taxRate }
            }',
            'variables' => ['id' => $rateId],
        ]);
        $rateUpdate->assertOk();
        $this->assertSame(9.0, (float) $rateUpdate->json('data.updateAdminSettingsTaxRate.taxRate'));

        $category = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($ids: [ID!]!) {
                createAdminSettingsTaxCategory(name: "Wave61 Goods", taxrates: $ids) {
                    id name code rate_ids
                }
            }',
            'variables' => ['ids' => [$rateId]],
        ]);
        $category->assertOk();
        $this->assertArrayNotHasKey('errors', $category->json() ?? [], json_encode($category->json()));
        $catId = (string) $category->json('data.createAdminSettingsTaxCategory.id');
        $this->assertContains($rateId, $category->json('data.createAdminSettingsTaxCategory.rate_ids') ?? []);

        $taxLists = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($rq: String!, $cq: String!) {
                adminSettingsTaxRates(q: $rq, limit: 10) { identifier }
                adminSettingsTaxCategories(q: $cq, limit: 10) { id name rate_ids }
                adminSettingsTaxRate(id: "'.$rateId.'") { id taxRate }
                adminSettingsTaxCategory(id: "'.$catId.'") { id code }
            }',
            'variables' => ['rq' => 'W61-VAT', 'cq' => 'Wave61'],
        ]);
        $taxLists->assertOk();
        $this->assertArrayNotHasKey('errors', $taxLists->json() ?? [], json_encode($taxLists->json()));
        $this->assertNotEmpty($taxLists->json('data.adminSettingsTaxRates'), json_encode($taxLists->json()));
        $this->assertNotEmpty($taxLists->json('data.adminSettingsTaxCategories'), json_encode($taxLists->json()));
        $this->assertSame($rateId, (string) $taxLists->json('data.adminSettingsTaxRate.id'));
        $this->assertSame($catId, (string) $taxLists->json('data.adminSettingsTaxCategory.id'));

        $delCat = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { deleteAdminSettingsTaxCategory(id: $id) { ok id } }',
            'variables' => ['id' => $catId],
        ]);
        $delCat->assertOk();
        $this->assertTrue((bool) $delCat->json('data.deleteAdminSettingsTaxCategory.ok'));
        $this->assertNull(TaxClass::query()->find((int) $catId));

        $delRate = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { deleteAdminSettingsTaxRate(id: $id) { ok id } }',
            'variables' => ['id' => $rateId],
        ]);
        $delRate->assertOk();
        $this->assertTrue((bool) $delRate->json('data.deleteAdminSettingsTaxRate.ok'));
        $this->assertNull(TaxRate::query()->find((int) $rateId));

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($ids: [ID!]!) {
                bulkDeleteAdminCmsPage(ids: $ids) { ok updated_count }
            }',
            'variables' => ['ids' => [$pageId]],
        ]);
        $mass->assertOk();
        $this->assertArrayNotHasKey('errors', $mass->json() ?? [], json_encode($mass->json()));
        $this->assertSame(1, (int) $mass->json('data.bulkDeleteAdminCmsPage.updated_count'));
        $this->assertNull(CmsPage::query()->find((int) $pageId));
    }
}
