<?php

namespace Tests\Feature;

use App\Domain\Localization\Models\Language;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave189Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_settings_locales_rest_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -4));
        $code = 'xx'.$suffix;
        // locale codes must match /^[a-z]{2}(-[a-z0-9]+)?$/ — use fr-xx style
        $code = 'fr-w'.$suffix;

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/locales', [
            'code' => $code,
            'name' => 'Wave189 French '.$suffix,
            'direction' => 'ltr',
            'status' => 1,
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');
        $this->assertGreaterThan(0, $id);
        $this->assertSame($code, (string) $create->json('code'));
        $this->assertSame('ltr', (string) $create->json('direction'));

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/settings/locales?code='.$code);
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $this->assertArrayHasKey('currentPage', $list->json('meta'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/settings/locales/'.$id);
        $show->assertOk();
        $this->assertSame('Wave189 French '.$suffix, (string) $show->json('name'));

        $update = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/settings/locales/'.$id, [
            'name' => 'Wave189 Renamed '.$suffix,
            'status' => 0,
        ]);
        $update->assertOk();
        $this->assertSame('Wave189 Renamed '.$suffix, (string) $update->json('name'));
        $this->assertSame(0, (int) $update->json('status'));

        $dup = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/locales', [
            'code' => $code,
            'name' => 'Dup',
        ]);
        $dup->assertStatus(422);

        $extra = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/locales', [
            'code' => 'de-w'.$suffix,
            'name' => 'Wave189 Extra '.$suffix,
            'status' => 1,
        ]);
        $extra->assertCreated();
        $extraId = (int) $extra->json('id');

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/locales/bulk-delete', [
            'ids' => [$extraId, 999999],
        ]);
        $mass->assertOk();
        $this->assertContains($extraId, $mass->json('deleted'));

        $default = Language::query()->where('is_default', true)->first();
        if ($default) {
            $deny = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/settings/locales/'.$default->id);
            $deny->assertStatus(422);
        }

        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/settings/locales/'.$id);
        $del->assertOk();
        $this->assertFalse(Language::query()->whereKey($id)->exists());

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminSettingsLocales(limit: 5) { id code name } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
    }
}
