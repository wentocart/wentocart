<?php

namespace Tests\Feature;

use App\Domain\Search\Models\SearchSynonym;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave181Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_marketing_search_synonyms_rest_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/search-synonyms', [
            'name' => 'wave181-shirts-'.$suffix,
            'terms' => 'shirt,tshirt,tee',
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');
        $this->assertGreaterThan(0, $id);
        $this->assertSame('wave181-shirts-'.$suffix, (string) $create->json('name'));
        $this->assertSame('shirt,tshirt,tee', (string) $create->json('terms'));

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketing/search-synonyms?name=wave181-shirts-'.$suffix);
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $this->assertArrayHasKey('currentPage', $list->json('meta'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketing/search-synonyms/'.$id);
        $show->assertOk();
        $this->assertSame('shirt,tshirt,tee', (string) $show->json('terms'));

        $update = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/marketing/search-synonyms/'.$id, [
            'terms' => 'shirt,t-shirt,tee,blouse',
        ]);
        $update->assertOk();
        $this->assertSame('shirt,t-shirt,tee,blouse', (string) $update->json('terms'));

        $bad = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/search-synonyms', [
            'name' => 'incomplete',
        ]);
        $bad->assertStatus(422);

        $extra = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/search-synonyms', [
            'name' => 'wave181-extra-'.$suffix,
            'terms' => 'a,b',
        ]);
        $extra->assertCreated();
        $extraId = (int) $extra->json('id');

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/search-synonyms/bulk-delete', [
            'ids' => [$extraId, 999999],
        ]);
        $mass->assertOk();
        $this->assertContains($extraId, $mass->json('deleted'));

        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/marketing/search-synonyms/'.$id);
        $del->assertOk();
        $this->assertFalse(SearchSynonym::query()->whereKey($id)->exists());

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminMarketingSearchSynonyms(limit: 5) { id name terms } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
    }
}
