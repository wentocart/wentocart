<?php

namespace Tests\Feature;

use App\Domain\Marketing\Models\NewsletterCampaign;
use App\Domain\Marketing\Models\NewsletterSubscriber;
use App\Domain\Settings\Models\EmailTemplate;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminGraphqlWave65Test extends TestCase
{
    use RefreshDatabase;

    public function test_marketing_templates_and_campaigns_rest_aliases(): void
    {
        $this->seed();
        Mail::fake();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $template = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createAdminMarketingTemplate(
                    name: "Wave65 Welcome"
                    subject: "Welcome to Wave65"
                    content: "<p>Hello Wave65</p>"
                    status: "active"
                ) { id name subject content status code }
            }',
        ]);
        $template->assertOk();
        $this->assertArrayNotHasKey('errors', $template->json() ?? [], json_encode($template->json()));
        $templateId = (string) $template->json('data.createAdminMarketingTemplate.id');
        $this->assertSame('Wave65 Welcome', (string) $template->json('data.createAdminMarketingTemplate.name'));
        $this->assertSame('active', (string) $template->json('data.createAdminMarketingTemplate.status'));

        $tplUpdate = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateAdminMarketingTemplate(id: $id, subject: "Wave65 Updated") { id subject }
            }',
            'variables' => ['id' => $templateId],
        ]);
        $tplUpdate->assertOk();
        $this->assertSame('Wave65 Updated', (string) $tplUpdate->json('data.updateAdminMarketingTemplate.subject'));

        $tplList = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                adminMarketingTemplates(name: "Wave65", status: "active", limit: 10) { id name }
                adminMarketingTemplate(id: "'.$templateId.'") { id content }
            }',
        ]);
        $tplList->assertOk();
        $this->assertArrayNotHasKey('errors', $tplList->json() ?? [], json_encode($tplList->json()));
        $this->assertNotEmpty($tplList->json('data.adminMarketingTemplates'));

        $campaign = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($tid: ID!) {
                createAdminMarketingCampaign(
                    name: "Wave65 Launch"
                    marketingTemplateId: $tid
                    status: "draft"
                ) { id name subject content status }
            }',
            'variables' => ['tid' => $templateId],
        ]);
        $campaign->assertOk();
        $this->assertArrayNotHasKey('errors', $campaign->json() ?? [], json_encode($campaign->json()));
        $campaignId = (string) $campaign->json('data.createAdminMarketingCampaign.id');
        $this->assertNotSame('', (string) $campaign->json('data.createAdminMarketingCampaign.content'));

        NewsletterSubscriber::query()->create([
            'email' => 'wave65-'.substr(uniqid(), -6).'@example.test',
            'is_subscribed' => true,
        ]);

        $send = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                createAdminMarketingCampaignSend(id: $id) {
                    ok sent_count message
                    campaign { id status sent_count }
                }
            }',
            'variables' => ['id' => $campaignId],
        ]);
        $send->assertOk();
        $this->assertArrayNotHasKey('errors', $send->json() ?? [], json_encode($send->json()));
        $this->assertTrue((bool) $send->json('data.createAdminMarketingCampaignSend.ok'));
        $this->assertSame('sent', (string) $send->json('data.createAdminMarketingCampaignSend.campaign.status'));

        $campList = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                adminMarketingCampaigns(name: "Wave65", limit: 10) { id status }
                adminMarketingCampaign(id: "'.$campaignId.'") { id subject }
            }',
        ]);
        $campList->assertOk();
        $this->assertArrayNotHasKey('errors', $campList->json() ?? [], json_encode($campList->json()));
        $this->assertNotEmpty($campList->json('data.adminMarketingCampaigns'));

        $delCamp = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { deleteAdminMarketingCampaign(id: $id) { ok id } }',
            'variables' => ['id' => $campaignId],
        ]);
        $delCamp->assertOk();
        $this->assertTrue((bool) $delCamp->json('data.deleteAdminMarketingCampaign.ok'));
        $this->assertNull(NewsletterCampaign::query()->find((int) $campaignId));

        $delTpl = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { deleteAdminMarketingTemplate(id: $id) { ok id } }',
            'variables' => ['id' => $templateId],
        ]);
        $delTpl->assertOk();
        $this->assertTrue((bool) $delTpl->json('data.deleteAdminMarketingTemplate.ok'));
        $this->assertNull(EmailTemplate::query()->find((int) $templateId));
    }
}
