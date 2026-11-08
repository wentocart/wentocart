<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminGraphqlWave207Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_integrations_rest_lifecycle(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/integrations', [
            'name' => 'Wave207 Token',
            'neverExpires' => true,
            'permissionMode' => 'same_as_web',
            'rateLimitPerMinute' => 40,
            'rateLimitPerDay' => 400,
            'allowedIps' => '127.0.0.1',
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');
        $plain = (string) $create->json('plain_token');
        $this->assertGreaterThan(0, $id);
        $this->assertNotSame('', $plain);
        $this->assertSame('same_as_web', (string) $create->json('permissionMode'));
        $this->assertSame(40, (int) $create->json('rateLimitPerMinute'));

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/integrations');
        $list->assertOk();
        $this->assertIsArray($list->json('data'));
        $this->assertGreaterThanOrEqual(1, (int) $list->json('meta.total'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/settings/integrations/'.$id);
        $show->assertOk();
        $this->assertSame('Wave207 Token', (string) $show->json('name'));
        $this->assertNull($show->json('plain_token'));

        $allow = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/integrations/'.$id.'/allowlist', [
            'allowedIps' => '127.0.0.1,10.0.0.0/8',
        ]);
        $allow->assertOk();
        $ips = $allow->json('allowed_ips');
        $this->assertIsArray($ips);
        $this->assertNotEmpty($ips);

        $rates = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/settings/integrations/'.$id.'/rate-limits', [
            'rateLimitPerMinute' => 55,
            'rateLimitPerDay' => 500,
        ]);
        $rates->assertOk();
        $this->assertSame(55, (int) $rates->json('rateLimitPerMinute'));
        $this->assertSame(500, (int) $rates->json('rateLimitPerDay'));

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ integrationTokens { id name rateLimitPerMinute } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
        $names = collect($gql->json('data.integrationTokens') ?? [])->pluck('name')->all();
        $this->assertContains('Wave207 Token', $names);

        $regen = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/integrations/'.$id.'/regenerate');
        $regen->assertOk();
        $newId = (int) $regen->json('id');
        $newPlain = (string) $regen->json('plain_token');
        $this->assertNotSame($id, $newId);
        $this->assertNotSame('', $newPlain);
        $this->assertNotSame($plain, $newPlain);

        $bearer = $this->withToken($newPlain)->postJson('/api/v1/console/graphql', [
            'query' => '{ integrationTokens { id } }',
        ]);
        $bearer->assertOk();
        $this->assertArrayNotHasKey('errors', $bearer->json() ?? [], json_encode($bearer->json()));
        $this->flushHeaders();

        $revoke = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/settings/integrations/'.$newId);
        $revoke->assertOk();
        $this->assertTrue((bool) $revoke->json('ok'));

        $gone = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/integrations/'.$newId);
        $gone->assertNotFound();

        Auth::guard('admin')->logout();
        $this->app['auth']->forgetGuards();
        $guest = $this->getJson('/api/v1/console/settings/integrations');
        $guest->assertUnauthorized();
    }
}
