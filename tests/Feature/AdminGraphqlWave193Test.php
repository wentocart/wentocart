<?php

namespace Tests\Feature;

use App\Domain\Tax\Models\TaxClass;
use App\Domain\Tax\Models\TaxRate;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave193Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_settings_tax_categories_rest_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $rateA = TaxRate::query()->create([
            'name' => 'wave193-a-'.$suffix,
            'rate' => 5,
            'is_enabled' => true,
        ]);
        $rateB = TaxRate::query()->create([
            'name' => 'wave193-b-'.$suffix,
            'rate' => 10,
            'is_enabled' => true,
        ]);

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/tax-categories', [
            'name' => 'Wave193 Category '.$suffix,
            'taxrates' => [(int) $rateA->id, (int) $rateB->id],
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');
        $this->assertGreaterThan(0, $id);
        $this->assertSame('Wave193 Category '.$suffix, (string) $create->json('name'));
        $this->assertEqualsCanonicalizing([(int) $rateA->id, (int) $rateB->id], $create->json('taxrates'));

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/settings/tax-categories?name=Wave193%20Category%20'.$suffix);
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $this->assertArrayHasKey('currentPage', $list->json('meta'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/settings/tax-categories/'.$id);
        $show->assertOk();
        $this->assertCount(2, $show->json('rate_ids') ?? []);

        $update = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/settings/tax-categories/'.$id, [
            'name' => 'Wave193 Renamed '.$suffix,
            'taxrates' => [(int) $rateA->id],
        ]);
        $update->assertOk();
        $this->assertSame('Wave193 Renamed '.$suffix, (string) $update->json('name'));
        $this->assertEqualsCanonicalizing([(int) $rateA->id], $update->json('taxrates'));

        $bad = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/tax-categories', [
            'name' => 'missing rates',
            'taxrates' => [],
        ]);
        $bad->assertStatus(422);

        $extra = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/tax-categories', [
            'name' => 'Wave193 Extra '.$suffix,
            'taxrates' => [(int) $rateB->id],
        ]);
        $extra->assertCreated();
        $extraId = (int) $extra->json('id');

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/tax-categories/bulk-delete', [
            'ids' => [$extraId, 999999],
        ]);
        $mass->assertOk();
        $this->assertContains($extraId, $mass->json('deleted'));

        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/settings/tax-categories/'.$id);
        $del->assertOk();
        $this->assertFalse(TaxClass::query()->whereKey($id)->exists());

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminSettingsTaxCategories(limit: 5) { id name rate_ids } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
    }
}
