<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave197Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_settings_themes_rest_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/settings/themes');
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $this->assertArrayHasKey('currentPage', $list->json('meta'));
        $first = $list->json('data.0');
        $this->assertArrayHasKey('themeCode', $first);
        $this->assertArrayHasKey('type', $first);
        $this->assertArrayNotHasKey('options', $first);

        $filtered = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/settings/themes?type=hero');
        $filtered->assertOk();
        foreach ($filtered->json('data') ?? [] as $row) {
            $this->assertSame('hero', (string) ($row['type'] ?? ''));
        }

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/themes', [
            'name' => 'Wave197 Block '.$suffix,
            'type' => 'content',
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');
        $this->assertGreaterThan(0, $id);
        $this->assertSame('content', (string) $create->json('type'));
        $this->assertArrayHasKey('translation', $create->json() ?? []);

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/settings/themes/'.$id);
        $show->assertOk();
        $this->assertSame('Wave197 Block '.$suffix, (string) $show->json('name'));
        $this->assertNotNull($show->json('options'));

        $update = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/settings/themes/'.$id, [
            'name' => 'Wave197 Renamed '.$suffix,
            'status' => 1,
        ]);
        $update->assertOk();
        $this->assertSame('Wave197 Renamed '.$suffix, (string) $update->json('name'));

        $footer = collect($list->json('data'))->firstWhere('type', 'footer');
        if ($footer) {
            $deny = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/settings/themes/'.(int) $footer['id']);
            $deny->assertStatus(422);
        }

        $extra = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/themes', [
            'name' => 'Wave197 Extra '.$suffix,
            'type' => 'content',
        ]);
        $extra->assertCreated();
        $extraId = (int) $extra->json('id');

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/themes/bulk-delete', [
            'ids' => [$extraId, 999999],
        ]);
        $mass->assertOk();
        $this->assertContains($extraId, $mass->json('deleted'));

        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/settings/themes/'.$id);
        $del->assertOk();
        $this->assertSame('Home module deleted successfully.', (string) $del->json('message'));

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminSettingsThemes(limit: 5) { id name type themeCode status } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
        $this->assertNotEmpty($gql->json('data.adminSettingsThemes'));
    }
}
