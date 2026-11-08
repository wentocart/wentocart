<?php

namespace Tests\Feature;

use App\Domain\Marketing\Models\NewsletterCampaign;
use App\Domain\Marketing\Models\NewsletterSubscriber;
use App\Domain\Settings\Models\EmailTemplate;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminGraphqlWave177Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_marketing_campaigns_rest_rest_compat(): void
    {
        $this->seed();
        Mail::fake();
        $admin = Admin::query()->firstOrFail();

        $template = EmailTemplate::query()->create([
            'code' => 'w177-'.substr(uniqid(), -6),
            'name' => 'Wave177 Template',
            'subject' => 'Wave177 subject',
            'body' => '<p>Campaign body</p>',
            'is_enabled' => true,
            'is_custom' => true,
        ]);

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/campaigns', [
            'name' => 'Wave177 Campaign',
            'subject' => 'Wave177 Campaign',
            'marketing_template_id' => $template->id,
            'status' => 1,
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');
        $this->assertGreaterThan(0, $id);
        $this->assertSame('active', (string) $create->json('status'));
        $this->assertSame('<p>Campaign body</p>', (string) $create->json('content'));

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketing/campaigns?name=Wave177');
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $this->assertArrayHasKey('currentPage', $list->json('meta'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketing/campaigns/'.$id);
        $show->assertOk();
        $this->assertSame('Wave177 Campaign', (string) $show->json('subject'));

        $update = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/marketing/campaigns/'.$id, [
            'status' => 0,
        ]);
        $update->assertOk();
        $this->assertSame('inactive', (string) $update->json('status'));

        $refuse = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/campaigns/'.$id.'/send', []);
        $refuse->assertStatus(422);

        $this->actingAs($admin, 'admin')->putJson('/api/v1/console/marketing/campaigns/'.$id, [
            'status' => 'active',
        ])->assertOk();

        NewsletterSubscriber::query()->create([
            'email' => 'w177-'.strtolower(substr(uniqid(), -6)).'@example.test',
            'is_subscribed' => true,
        ]);

        $send = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/campaigns/'.$id.'/send', []);
        $send->assertOk();
        $this->assertSame($id, (int) $send->json('campaignId'));
        $this->assertArrayHasKey('queued', $send->json());
        $this->assertSame('sent', (string) NewsletterCampaign::query()->whereKey($id)->value('status'));

        $extra = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/campaigns', [
            'name' => 'Wave177 Extra',
            'content' => '<p>x</p>',
            'status' => 'draft',
        ]);
        $extra->assertCreated();
        $extraId = (int) $extra->json('id');

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/campaigns/bulk-delete', [
            'ids' => [$extraId],
        ]);
        $mass->assertOk();
        $this->assertContains($extraId, $mass->json('deleted'));

        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/marketing/campaigns/'.$id);
        $del->assertOk();
        $this->assertFalse(NewsletterCampaign::query()->whereKey($id)->exists());

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminMarketingCampaigns(limit: 5) { id subject status } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
    }
}
