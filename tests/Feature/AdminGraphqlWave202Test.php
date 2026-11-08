<?php

namespace Tests\Feature;

use App\Domain\Order\Models\ReturnCustomField;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave202Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_rma_custom_fields_rest_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/rma/custom-fields', [
            'label' => 'Wave202 Field '.$suffix,
            'type' => 'select',
            'isRequired' => 1,
            'status' => 1,
            'optionsJson' => json_encode([
                ['id' => '1', 'name' => 'Yes', 'value' => 'yes'],
                ['id' => '2', 'name' => 'No', 'value' => 'no'],
            ]),
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');
        $this->assertGreaterThan(0, $id);
        $this->assertSame('select', (string) $create->json('type'));
        $this->assertSame(1, (int) $create->json('isRequired'));
        $this->assertCount(2, $create->json('options') ?? []);

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/rma/custom-fields?q=Wave202%20Field%20'.$suffix);
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $this->assertArrayHasKey('currentPage', $list->json('meta'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/rma/custom-fields/'.$id);
        $show->assertOk();
        $this->assertSame('Wave202 Field '.$suffix, (string) $show->json('label'));

        $update = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/rma/custom-fields/'.$id, [
            'label' => 'Wave202 Renamed '.$suffix,
            'status' => 0,
        ]);
        $update->assertOk();
        $this->assertSame('Wave202 Renamed '.$suffix, (string) $update->json('label'));
        $this->assertSame(0, (int) $update->json('status'));

        $massStatus = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/rma/custom-fields/bulk-update-status', [
            'ids' => [$id],
            'status' => 1,
        ]);
        $massStatus->assertOk();
        $this->assertTrue((bool) $massStatus->json('ok'));

        $extra = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/rma/custom-fields', [
            'label' => 'Wave202 Extra '.$suffix,
            'type' => 'text',
        ]);
        $extra->assertCreated();
        $extraId = (int) $extra->json('id');

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/rma/custom-fields/bulk-delete', [
            'ids' => [$extraId, 999999],
        ]);
        $mass->assertOk();
        $this->assertContains($extraId, $mass->json('deleted'));

        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/rma/custom-fields/'.$id);
        $del->assertOk();
        $this->assertSame('Return custom field deleted.', (string) $del->json('message'));
        $this->assertFalse(ReturnCustomField::query()->whereKey($id)->exists());

        $alias = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/settings/return-custom-fields');
        $alias->assertOk();

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminRmaCustomFields(limit: 5) { id label type } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
    }
}
