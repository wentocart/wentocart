<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AdminGraphqlWave158Test extends TestCase
{
    use RefreshDatabase;

    public function test_regenerate_integration_token_issues_new_secret_and_retires_old(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation { createIntegrationToken(name: "Wave158 Regen", neverExpires: true, rateLimitPerMinute: 30) { id plain_token rateLimitPerMinute } }',
        ]);
        $create->assertOk();
        $oldId = (int) $create->json('data.createIntegrationToken.id');
        $oldPlain = (string) $create->json('data.createIntegrationToken.plain_token');

        $regen = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { regenerateIntegrationToken(id: $id) { id plain_token rateLimitPerMinute name } }',
            'variables' => ['id' => (string) $oldId],
        ]);
        $regen->assertOk();
        $this->assertArrayNotHasKey('errors', $regen->json() ?? [], json_encode($regen->json()));
        $newId = (int) $regen->json('data.regenerateIntegrationToken.id');
        $newPlain = (string) $regen->json('data.regenerateIntegrationToken.plain_token');
        $this->assertNotSame($oldId, $newId);
        $this->assertNotSame($oldPlain, $newPlain);
        $this->assertSame(30, (int) $regen->json('data.regenerateIntegrationToken.rateLimitPerMinute'));
        $this->assertNull(PersonalAccessToken::query()->find($oldId));

        $oldDenied = $this->withToken($oldPlain)->postJson('/api/v1/console/graphql', [
            'query' => '{ __typename }',
        ]);
        $oldDenied->assertStatus(401);
        $this->assertSame('unauthenticated', $oldDenied->json('error'));

        $newOk = $this->withToken($newPlain)->postJson('/api/v1/console/graphql', [
            'query' => '{ __typename }',
        ]);
        $newOk->assertOk();
    }
}
