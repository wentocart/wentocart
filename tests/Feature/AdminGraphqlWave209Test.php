<?php

namespace Tests\Feature;

use App\Domain\Api\Models\AdminApiAudit;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminGraphqlWave209Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_api_audit_history_rest(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/integrations', [
            'name' => 'Wave209 Audit Token',
            'neverExpires' => true,
            'permissionMode' => 'same_as_web',
        ]);
        $create->assertCreated();
        $tokenId = (int) $create->json('id');

        $this->assertTrue(
            AdminApiAudit::query()->where('path', 'like', '%integrations%')->exists(),
            'write to integrations should create an audit row'
        );

        $history = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/settings/integrations/history');
        $history->assertOk();
        $this->assertIsArray($history->json('data'));
        $this->assertGreaterThanOrEqual(1, (int) $history->json('meta.total'));
        $firstId = (int) ($history->json('data.0.id') ?? 0);
        $this->assertGreaterThan(0, $firstId);

        $detail = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/integrations/history/'.$firstId);
        $detail->assertOk();
        $this->assertArrayHasKey('request_payload', $detail->json() ?? []);
        $payload = $detail->json('request_payload') ?? [];
        $this->assertIsArray($payload);
        $serialized = strtolower(json_encode($payload) ?: '');
        $this->assertStringNotContainsString('plain_token', $serialized);

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminApiAudits(limit: 5) { id method path operation status } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
        $this->assertNotEmpty($gql->json('data.adminApiAudits') ?? []);

        $alias = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/api-audits?path=integrations');
        $alias->assertOk();
        $this->assertGreaterThanOrEqual(1, (int) $alias->json('meta.total'));

        $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/integrations/'.$tokenId)->assertOk();

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/integrations/history/bulk-delete', [
            'ids' => [$firstId],
        ]);
        $mass->assertOk();
        $this->assertTrue((bool) $mass->json('ok'));

        $prune = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/api-audits/prune', [
            'days' => 1,
        ]);
        $prune->assertOk();
        $this->assertTrue((bool) $prune->json('ok'));

        Auth::guard('admin')->logout();
        $this->app['auth']->forgetGuards();
        $guest = $this->getJson('/api/v1/console/settings/integrations/history');
        $guest->assertUnauthorized();
    }
}
