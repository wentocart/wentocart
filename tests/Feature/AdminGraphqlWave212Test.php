<?php

namespace Tests\Feature;

use App\Domain\Settings\ShopSetting;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave212Test extends TestCase
{
    use RefreshDatabase;

    public function test_integration_module_settings_and_guards(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        ShopSetting::put('api.integration.enabled', true);
        ShopSetting::put('api.integration.one_token_per_admin', false);

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/configuration/api/integration');
        $show->assertOk();
        $this->assertTrue((bool) $show->json('enabled'));
        $this->assertFalse((bool) $show->json('oneTokenPerAdmin'));

        $one = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/settings/integrations/module', [
            'oneTokenPerAdmin' => true,
        ]);
        $one->assertOk();
        $this->assertTrue((bool) $one->json('oneTokenPerAdmin'));

        $first = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/integrations', [
            'name' => 'Wave212 Only',
            'neverExpires' => true,
        ]);
        $first->assertCreated();

        $second = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/integrations', [
            'name' => 'Wave212 Second',
            'neverExpires' => true,
        ]);
        $second->assertStatus(422);
        $this->assertStringContainsString('already has', (string) $second->json('message'));

        $disable = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/configuration/api/integration', [
            'enabled' => false,
        ]);
        $disable->assertOk();
        $this->assertFalse((bool) $disable->json('enabled'));

        $blocked = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/integrations');
        $blocked->assertStatus(422);
        $this->assertStringContainsString('disabled', strtolower((string) $blocked->json('message')));

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation { updateAdminIntegrationModule(enabled: true, oneTokenPerAdmin: false) { enabled oneTokenPerAdmin auditEnabled } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
        $this->assertTrue((bool) $gql->json('data.updateAdminIntegrationModule.enabled'));
        $this->assertFalse((bool) $gql->json('data.updateAdminIntegrationModule.oneTokenPerAdmin'));

        $read = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminIntegrationModule { enabled oneTokenPerAdmin retentionDays } }',
        ]);
        $read->assertOk();
        $this->assertArrayNotHasKey('errors', $read->json() ?? [], json_encode($read->json()));
        $this->assertTrue((bool) $read->json('data.adminIntegrationModule.enabled'));
    }
}
