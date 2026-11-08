<?php

namespace Tests\Feature;

use App\Domain\Store\Models\Store;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave198Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_appearance_themes_rest_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $store = Store::query()->orderBy('id')->firstOrFail();

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/appearance/themes');
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $code = (string) $list->json('data.0.code');
        $this->assertNotSame('', $code);
        $this->assertArrayHasKey('displayName', $list->json('data.0'));
        $this->assertArrayHasKey('stores', $list->json('data.0'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/appearance/themes/'.$code);
        $show->assertOk();
        $this->assertSame($code, (string) $show->json('code'));

        $missing = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/appearance/themes/not-a-real-theme');
        $missing->assertNotFound();

        $impact = $this->actingAs($admin, 'admin')->getJson(
            '/api/v1/console/appearance/themes/'.$code.'/impact?storeIds[]='.$store->id
        );
        $impact->assertOk();
        $this->assertSame($code, (string) $impact->json('code'));
        $this->assertIsArray($impact->json('would_switch'));
        $this->assertIsArray($impact->json('already_active'));

        $activate = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/appearance/themes/'.$code.'/activate', [
            'storeIds' => [$store->id],
        ]);
        $activate->assertOk();
        $this->assertTrue((bool) $activate->json('ok'));
        $this->assertSame($code, (string) $activate->json('code'));
        $store->refresh();
        $this->assertSame($code, (string) $store->theme);

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminAppearanceThemes { code displayName status active } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
        $this->assertNotEmpty($gql->json('data.adminAppearanceThemes'));
    }
}
