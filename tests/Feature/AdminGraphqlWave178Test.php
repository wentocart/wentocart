<?php

namespace Tests\Feature;

use App\Domain\Marketing\Models\MarketingEvent;
use App\Domain\Marketing\Models\NewsletterCampaign;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave178Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_marketing_events_rest_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/events', [
            'name' => 'Wave178 Sale',
            'description' => 'Black Friday kickoff',
            'date' => '2026-11-28',
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');
        $this->assertGreaterThan(0, $id);
        $this->assertSame('Wave178 Sale', (string) $create->json('name'));
        $this->assertSame('2026-11-28', (string) $create->json('date'));

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketing/events?name=Wave178&date_from=2026-11-01&date_to=2026-12-01');
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $this->assertArrayHasKey('currentPage', $list->json('meta'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketing/events/'.$id);
        $show->assertOk();
        $this->assertSame('Black Friday kickoff', (string) $show->json('description'));

        $update = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/marketing/events/'.$id, [
            'date' => '2026-11-27',
        ]);
        $update->assertOk();
        $this->assertSame('2026-11-27', (string) $update->json('date'));

        $bad = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/events', [
            'name' => 'Missing date',
        ]);
        $bad->assertStatus(422);

        $campaign = NewsletterCampaign::query()->create([
            'subject' => 'Linked to Wave178',
            'html_body' => '<p>x</p>',
            'status' => 'draft',
            'sent_count' => 0,
            'marketing_event_id' => $id,
        ]);

        $extra = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/events', [
            'name' => 'Wave178 Extra',
            'description' => 'extra',
            'date' => '2026-12-01',
        ]);
        $extra->assertCreated();
        $extraId = (int) $extra->json('id');

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/events/bulk-delete', [
            'ids' => [$extraId],
        ]);
        $mass->assertOk();
        $this->assertContains($extraId, $mass->json('deleted'));

        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/marketing/events/'.$id);
        $del->assertOk();
        $this->assertFalse(MarketingEvent::query()->whereKey($id)->exists());
        $this->assertNull(NewsletterCampaign::query()->whereKey($campaign->id)->value('marketing_event_id'));

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminMarketingEvents(limit: 5) { id name date } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
    }
}
