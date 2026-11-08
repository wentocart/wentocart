<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave204Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_appearance_sections_rest_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/appearance/sections');
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $hero = collect($list->json('data'))->firstWhere('moduleCode', 'hero')
            ?? collect($list->json('data'))->firstWhere('type', 'hero');
        $this->assertIsArray($hero);
        $heroId = (int) $hero['id'];

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/appearance/sections/'.$heroId);
        $show->assertOk();
        $this->assertSame($heroId, (int) $show->json('id'));

        $fields = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/appearance/sections/'.$heroId.'/fields');
        $fields->assertOk();
        $this->assertNotEmpty($fields->json('fields'));

        $status = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/appearance/sections/'.$heroId.'/status', [
            'status' => 0,
        ]);
        $status->assertOk();
        $this->assertTrue((bool) $status->json('hasDraft'));

        $preview = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/appearance/sections/preview');
        $preview->assertOk();
        $this->assertNotEmpty($preview->json('data'));

        $ids = collect($list->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $reorder = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/appearance/sections/reorder', [
            'ids' => $ids,
        ]);
        $reorder->assertOk();
        $this->assertTrue((bool) $reorder->json('ok'));

        $publish = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/appearance/sections/publish');
        $publish->assertOk();
        $this->assertTrue((bool) $publish->json('ok'));

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/appearance/sections', [
            'name' => 'Wave204 Section '.$suffix,
            'type' => 'content',
        ]);
        $create->assertCreated();
        $extraId = (int) $create->json('id');
        $this->assertGreaterThan(0, $extraId);

        $dup = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/appearance/sections/'.$extraId.'/duplicate');
        $dup->assertCreated();
        $dupId = (int) $dup->json('id');

        $delDup = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/appearance/sections/'.$dupId);
        $delDup->assertOk();

        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/appearance/sections/'.$extraId);
        $del->assertOk();

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminAppearanceSections { id type moduleCode } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
    }
}
