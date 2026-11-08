<?php

namespace Tests\Feature;

use App\Domain\Localization\Models\Currency;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave190Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_settings_currencies_rest_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        // Pick a rarely-used ISO code unlikely to be seeded
        $code = 'XTS';

        Currency::query()->where('code', $code)->delete();

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/currencies', [
            'code' => $code,
            'name' => 'Wave190 Test Currency',
            'symbol' => '₮',
            'decimal' => 2,
            'currency_position' => 'left',
            'status' => 1,
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');
        $this->assertGreaterThan(0, $id);
        $this->assertSame($code, (string) $create->json('code'));
        $this->assertSame('left', (string) $create->json('currency_position'));

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/settings/currencies?code='.$code);
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $this->assertArrayHasKey('currentPage', $list->json('meta'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/settings/currencies/'.$id);
        $show->assertOk();
        $this->assertSame('Wave190 Test Currency', (string) $show->json('name'));

        $update = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/settings/currencies/'.$id, [
            'name' => 'Wave190 Renamed',
            'currency_position' => 'right',
            'status' => 0,
        ]);
        $update->assertOk();
        $this->assertSame('Wave190 Renamed', (string) $update->json('name'));
        $this->assertSame('right', (string) $update->json('currency_position'));
        $this->assertSame(0, (int) $update->json('status'));

        $dup = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/currencies', [
            'code' => $code,
            'name' => 'Dup',
        ]);
        $dup->assertStatus(422);

        $extraCode = 'XAU';
        Currency::query()->where('code', $extraCode)->delete();
        $extra = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/currencies', [
            'code' => $extraCode,
            'name' => 'Wave190 Extra',
            'status' => 1,
        ]);
        $extra->assertCreated();
        $extraId = (int) $extra->json('id');

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/currencies/bulk-delete', [
            'ids' => [$extraId, 999999],
        ]);
        $mass->assertOk();
        $this->assertContains($extraId, $mass->json('deleted'));

        $default = Currency::query()->where('is_default', true)->first();
        if ($default) {
            $deny = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/settings/currencies/'.$default->id);
            $deny->assertStatus(422);
        }

        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/settings/currencies/'.$id);
        $del->assertOk();
        $this->assertFalse(Currency::query()->whereKey($id)->exists());

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminSettingsCurrencies(limit: 5) { id code name } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
    }
}
