<?php

namespace Tests\Feature;

use App\Domain\Tax\Models\TaxRate;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave192Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_settings_tax_rates_rest_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/tax-rates', [
            'identifier' => 'wave192-std-'.$suffix,
            'tax_rate' => 8.5,
            'country' => 'US',
            'state' => 'CA',
            'status' => 1,
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');
        $this->assertGreaterThan(0, $id);
        $this->assertSame('wave192-std-'.$suffix, (string) $create->json('identifier'));
        $this->assertEqualsWithDelta(8.5, (float) $create->json('tax_rate'), 0.0001);

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/settings/tax-rates?identifier=wave192-std-'.$suffix);
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $this->assertArrayHasKey('currentPage', $list->json('meta'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/settings/tax-rates/'.$id);
        $show->assertOk();
        $this->assertEqualsWithDelta(8.5, (float) $show->json('rate'), 0.0001);

        $update = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/settings/tax-rates/'.$id, [
            'tax_rate' => 9.25,
            'status' => 0,
        ]);
        $update->assertOk();
        $this->assertEqualsWithDelta(9.25, (float) $update->json('tax_rate'), 0.0001);
        $this->assertSame(0, (int) $update->json('status'));

        $bad = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/tax-rates', [
            'identifier' => 'bad',
            'tax_rate' => 150,
        ]);
        $bad->assertStatus(422);

        $extra = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/tax-rates', [
            'identifier' => 'wave192-extra-'.$suffix,
            'tax_rate' => 5,
        ]);
        $extra->assertCreated();
        $extraId = (int) $extra->json('id');

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/tax-rates/bulk-delete', [
            'ids' => [$extraId, 999999],
        ]);
        $mass->assertOk();
        $this->assertContains($extraId, $mass->json('deleted'));

        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/settings/tax-rates/'.$id);
        $del->assertOk();
        $this->assertFalse(TaxRate::query()->whereKey($id)->exists());

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminSettingsTaxRates(limit: 5) { id name rate } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
    }
}
