<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminGraphqlWave208Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_storefront_keys_rest_lifecycle(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/storefront-keys', [
            'name' => 'Wave208 Key',
            'rateLimit' => 250,
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');
        $plain = (string) $create->json('plain_key');
        $this->assertGreaterThan(0, $id);
        $this->assertStringStartsWith('pk_storefront_', $plain);
        $this->assertSame(250, (int) $create->json('rate_limit'));

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/storefront-keys');
        $list->assertOk();
        $this->assertIsArray($list->json('data'));
        $this->assertArrayHasKey('required', $list->json('requirement') ?? []);

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/settings/storefront-keys/'.$id);
        $show->assertOk();
        $this->assertSame('Wave208 Key', (string) $show->json('name'));
        $this->assertNull($show->json('plain_key'));

        $rate = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/storefront-keys/'.$id.'/rate-limit', [
            'rateLimit' => 400,
        ]);
        $rate->assertOk();
        $this->assertSame(400, (int) $rate->json('rateLimit'));

        $reqOn = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/settings/storefront-keys/requirement', [
            'required' => true,
        ]);
        $reqOn->assertOk();
        $this->assertTrue((bool) $reqOn->json('required'));

        $reqGet = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/storefront-keys/requirement');
        $reqGet->assertOk();
        $this->assertTrue((bool) $reqGet->json('required'));

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ storefrontApiKeys { id name rate_limit } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
        $names = collect($gql->json('data.storefrontApiKeys') ?? [])->pluck('name')->all();
        $this->assertContains('Wave208 Key', $names);

        $regen = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/storefront-keys/'.$id.'/regenerate');
        $regen->assertOk();
        $newId = (int) $regen->json('id');
        $newPlain = (string) $regen->json('plain_key');
        $this->assertNotSame($id, $newId);
        $this->assertStringStartsWith('pk_storefront_', $newPlain);
        $this->assertNotSame($plain, $newPlain);

        $revoke = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/settings/storefront-keys/'.$newId);
        $revoke->assertOk();
        $this->assertTrue((bool) $revoke->json('ok'));

        $gone = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/storefront-keys/'.$newId);
        $gone->assertNotFound();

        $this->actingAs($admin, 'admin')->putJson('/api/v1/console/storefront-keys/requirement', [
            'required' => false,
        ])->assertOk();

        Auth::guard('admin')->logout();
        $this->app['auth']->forgetGuards();
        $guest = $this->getJson('/api/v1/console/settings/storefront-keys');
        $guest->assertUnauthorized();
    }
}
