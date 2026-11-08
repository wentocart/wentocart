<?php

namespace Tests\Feature;

use App\Domain\Settings\Models\EmailTemplate;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave176Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_marketing_email_templates_rest_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/templates', [
            'name' => 'Wave176 Promo',
            'status' => 'active',
            'content' => '<p>Hello {{name}}</p>',
            'subject' => 'Wave176 subject',
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');
        $this->assertGreaterThan(0, $id);
        $this->assertSame('active', (string) $create->json('status'));
        $this->assertSame('<p>Hello {{name}}</p>', (string) $create->json('content'));

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketing/templates?name=Wave176');
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $this->assertArrayHasKey('currentPage', $list->json('meta'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketing/templates/'.$id);
        $show->assertOk();
        $this->assertSame('Wave176 Promo', (string) $show->json('name'));

        $update = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/marketing/templates/'.$id, [
            'status' => 'inactive',
            'content' => '<p>Updated</p>',
        ]);
        $update->assertOk();
        $this->assertSame('inactive', (string) $update->json('status'));
        $this->assertSame('<p>Updated</p>', (string) $update->json('content'));
        $this->assertFalse((bool) EmailTemplate::query()->whereKey($id)->value('is_enabled'));

        $bad = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/templates', [
            'name' => 'Missing content',
            'status' => 'active',
        ]);
        $bad->assertStatus(422);

        $extra = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/templates', [
            'name' => 'Wave176 Extra',
            'status' => 'draft',
            'content' => '<p>x</p>',
        ]);
        $extra->assertCreated();
        $extraId = (int) $extra->json('id');

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/templates/bulk-delete', [
            'ids' => [$extraId, 999999],
        ]);
        $mass->assertOk();
        $this->assertContains($extraId, $mass->json('deleted'));

        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/marketing/templates/'.$id);
        $del->assertOk();
        $this->assertFalse(EmailTemplate::query()->whereKey($id)->exists());

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminMarketingTemplates(limit: 5) { id name status } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
    }
}
