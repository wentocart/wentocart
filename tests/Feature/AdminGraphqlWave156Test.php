<?php

namespace Tests\Feature;

use App\Domain\Api\Graphql\IntegrationTokenAllowlist;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AdminGraphqlWave156Test extends TestCase
{
    use RefreshDatabase;

    public function test_create_integration_token_defaults_to_one_year_expiry(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $res = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation { createIntegrationToken(name: "Wave156 Expiry") { id plain_token expiresAt } }',
        ]);
        $res->assertOk();
        $this->assertArrayNotHasKey('errors', $res->json() ?? [], json_encode($res->json()));
        $expires = (string) $res->json('data.createIntegrationToken.expiresAt');
        $this->assertNotSame('', $expires);
        $this->assertTrue(now()->addMonths(11)->lt(\Illuminate\Support\Carbon::parse($expires)));
        $this->assertTrue(now()->addMonths(13)->gt(\Illuminate\Support\Carbon::parse($expires)));
    }

    public function test_expired_integration_token_is_rejected(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation { createIntegrationToken(name: "Wave156 Expired", neverExpires: true) { id plain_token expiresAt } }',
        ]);
        $plain = (string) $create->json('data.createIntegrationToken.plain_token');
        $id = (int) $create->json('data.createIntegrationToken.id');
        $this->assertNull($create->json('data.createIntegrationToken.expiresAt'));

        PersonalAccessToken::query()->whereKey($id)->update(['expires_at' => now()->subMinute()]);

        $blocked = $this->withToken($plain)->postJson('/api/v1/console/graphql', [
            'query' => '{ __typename }',
        ]);
        $blocked->assertStatus(401);
    }

    public function test_cidr_allowlist_and_loopback_bypass(): void
    {
        $this->assertTrue(IntegrationTokenAllowlist::ipMatches('203.0.113.10', '203.0.113.0/24'));
        $this->assertFalse(IntegrationTokenAllowlist::ipMatches('203.0.114.10', '203.0.113.0/24'));

        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => <<<'GQL'
                mutation {
                  createIntegrationToken(name: "Wave156 CIDR", allowedIps: "203.0.113.0/24", neverExpires: true) {
                    id plain_token allowed_ips
                  }
                }
            GQL,
        ]);
        $create->assertOk();
        $this->assertContains('203.0.113.0/24', $create->json('data.createIntegrationToken.allowed_ips') ?? []);
        $plain = (string) $create->json('data.createIntegrationToken.plain_token');

        // Loopback always allowed (API parity) even when allowlist is set.
        $ok = $this->withToken($plain)->postJson('/api/v1/console/graphql', [
            'query' => '{ __typename }',
        ]);
        $ok->assertOk();
    }
}
