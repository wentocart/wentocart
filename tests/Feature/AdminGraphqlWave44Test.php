<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Search\Models\SearchSynonym;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave44Test extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_product_status_and_search_synonym_crud(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $skuA = 'W44A-'.strtoupper(substr(uniqid(), -5));
        $skuB = 'W44B-'.strtoupper(substr(uniqid(), -5));
        $createA = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createSimpleProduct(sku: $sku, name: "Wave44 A", price: 11, stockQty: 4, enabled: true) { id }
            }',
            'variables' => ['sku' => $skuA],
        ]);
        $createA->assertOk();
        $this->assertArrayNotHasKey('errors', $createA->json() ?? []);
        $idA = (string) $createA->json('data.createSimpleProduct.id');

        $createB = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createSimpleProduct(sku: $sku, name: "Wave44 B", price: 12, stockQty: 4, enabled: true) { id }
            }',
            'variables' => ['sku' => $skuB],
        ]);
        $createB->assertOk();
        $idB = (string) $createB->json('data.createSimpleProduct.id');

        $disable = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($a: ID!, $b: ID!) {
                bulkUpdateProductStatus(ids: [$a, $b], enabled: false) { ok updated_count }
            }',
            'variables' => ['a' => $idA, 'b' => $idB],
        ]);
        $disable->assertOk();
        $this->assertArrayNotHasKey('errors', $disable->json() ?? [], json_encode($disable->json()));
        $this->assertTrue((bool) $disable->json('data.bulkUpdateProductStatus.ok'));
        $this->assertSame(2, (int) $disable->json('data.bulkUpdateProductStatus.updated_count'));
        $this->assertFalse((bool) Product::query()->find((int) $idA)?->is_enabled);
        $this->assertFalse((bool) Product::query()->find((int) $idB)?->is_enabled);

        $enable = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($a: ID!) {
                bulkUpdateProductStatus(ids: [$a], enabled: true) { ok updated_count }
            }',
            'variables' => ['a' => $idA],
        ]);
        $enable->assertOk();
        $this->assertTrue((bool) Product::query()->find((int) $idA)?->is_enabled);

        $term = 'w44-tee-'.substr(uniqid(), -5);
        $syn = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($term: String!) {
                createSearchSynonym(term: $term, aliases: "tshirt, t-shirt", enabled: true) {
                    id term aliases is_enabled
                }
            }',
            'variables' => ['term' => $term],
        ]);
        $syn->assertOk();
        $this->assertArrayNotHasKey('errors', $syn->json() ?? [], json_encode($syn->json()));
        $synId = (string) $syn->json('data.createSearchSynonym.id');
        $this->assertSame($term, (string) $syn->json('data.createSearchSynonym.term'));
        $this->assertNotNull(SearchSynonym::query()->find((int) $synId));

        $list = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ searchSynonyms(limit: 50) { id term aliases } }',
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? []);
        $terms = collect($list->json('data.searchSynonyms') ?? [])->pluck('term')->all();
        $this->assertContains($term, $terms);

        $del = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { deleteSearchSynonym(id: $id) { ok id } }',
            'variables' => ['id' => $synId],
        ]);
        $del->assertOk();
        $this->assertTrue((bool) $del->json('data.deleteSearchSynonym.ok'));
        $this->assertNull(SearchSynonym::query()->find((int) $synId));
    }
}
