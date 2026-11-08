<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave160Test extends TestCase
{
    use RefreshDatabase;

    public function test_custom_permission_mode_freezes_token_acl(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => <<<'GQL'
                mutation {
                  createIntegrationToken(
                    name: "Wave160 Custom"
                    neverExpires: true
                    permissionMode: "custom"
                    permissions: ["settings.manage"]
                  ) {
                    id
                    plain_token
                    permissionMode
                    permissions
                  }
                }
            GQL,
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? [], json_encode($create->json()));
        $this->assertSame('custom', (string) $create->json('data.createIntegrationToken.permissionMode'));
        $this->assertContains('settings.manage', $create->json('data.createIntegrationToken.permissions') ?? []);
        $plain = (string) $create->json('data.createIntegrationToken.plain_token');

        $ok = $this->withToken($plain)->postJson('/api/v1/console/graphql', [
            'query' => '{ storefrontApiKeys { id name } }',
        ]);
        $ok->assertOk();
        $this->assertArrayNotHasKey('errors', $ok->json() ?? [], json_encode($ok->json()));

        $denied = $this->withToken($plain)->postJson('/api/v1/console/graphql', [
            'query' => '{ adminCustomers(limit: 1) { id email } }',
        ]);
        $denied->assertOk();
        $message = strtolower((string) ($denied->json('errors.0.message') ?? ''));
        $this->assertStringContainsString('forbidden', $message);
    }
}
