<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave140Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_appearance_sections_status_reorder_publish(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $list = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{
                adminAppearanceSections {
                    id type name sortOrder status moduleCode hasDraft
                }
            }',
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? [], json_encode($list->json()));
        $sections = $list->json('data.adminAppearanceSections') ?? [];
        $this->assertNotEmpty($sections);
        $types = collect($sections)->pluck('type')->unique()->values()->all();
        $this->assertContains('hero', $types);
        $this->assertContains('footer', $types);

        $hero = collect($sections)->firstWhere('moduleCode', 'hero')
            ?? collect($sections)->firstWhere('type', 'hero');
        $this->assertIsArray($hero);
        $heroId = (string) $hero['id'];

        $status = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                createAdminAppearanceSectionStatus(id: $id, status: 0) {
                    id status hasDraft message moduleCode
                }
            }',
            'variables' => ['id' => $heroId],
        ]);
        $status->assertOk();
        $this->assertArrayNotHasKey('errors', $status->json() ?? [], json_encode($status->json()));
        $this->assertTrue((bool) $status->json('data.createAdminAppearanceSectionStatus.hasDraft'));

        $preview = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{
                adminAppearanceSectionPreview {
                    id moduleCode status hasDraft
                }
            }',
        ]);
        $preview->assertOk();
        $this->assertArrayNotHasKey('errors', $preview->json() ?? [], json_encode($preview->json()));
        $previewHero = collect($preview->json('data.adminAppearanceSectionPreview') ?? [])
            ->firstWhere('id', $heroId);
        $this->assertSame(0, (int) ($previewHero['status'] ?? -1));

        $ids = collect($sections)->pluck('id')->map(fn ($id) => (string) $id)->all();
        $reorder = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($ids: [ID!]!) {
                createAdminAppearanceSectionReorder(ids: $ids) {
                    ok order message
                }
            }',
            'variables' => ['ids' => $ids],
        ]);
        $reorder->assertOk();
        $this->assertArrayNotHasKey('errors', $reorder->json() ?? [], json_encode($reorder->json()));
        $this->assertTrue((bool) $reorder->json('data.createAdminAppearanceSectionReorder.ok'));

        $publish = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createAdminAppearanceSectionPublish { ok message }
            }',
        ]);
        $publish->assertOk();
        $this->assertArrayNotHasKey('errors', $publish->json() ?? [], json_encode($publish->json()));
        $this->assertTrue((bool) $publish->json('data.createAdminAppearanceSectionPublish.ok'));

        $after = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($id: ID!) {
                adminAppearanceSection(id: $id) { id status hasDraft moduleCode }
                adminAppearanceSectionFields(id: $id) { type fields { code type label } }
            }',
            'variables' => ['id' => $heroId],
        ]);
        $after->assertOk();
        $this->assertArrayNotHasKey('errors', $after->json() ?? [], json_encode($after->json()));
        $this->assertSame(0, (int) $after->json('data.adminAppearanceSection.status'));
        $this->assertNotEmpty($after->json('data.adminAppearanceSectionFields.fields'));

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createAdminAppearanceSection(input: {
                    name: "Wave140 Promo"
                    type: "content"
                }) {
                    id name type status moduleCode message
                }
            }',
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? [], json_encode($create->json()));
        $this->assertSame('content', (string) $create->json('data.createAdminAppearanceSection.type'));
        $this->assertSame(0, (int) $create->json('data.createAdminAppearanceSection.status'));
        $extraId = (string) $create->json('data.createAdminAppearanceSection.id');

        $del = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                deleteAdminAppearanceSection(id: $id) { ok id }
            }',
            'variables' => ['id' => $extraId],
        ]);
        $del->assertOk();
        $this->assertArrayNotHasKey('errors', $del->json() ?? [], json_encode($del->json()));
        $this->assertTrue((bool) $del->json('data.deleteAdminAppearanceSection.ok'));
    }
}
