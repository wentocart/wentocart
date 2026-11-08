<?php

namespace Tests\Feature;

use App\Domain\Marketing\Models\MarketingEvent;
use App\Domain\Order\Models\ReturnReason;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave67Test extends TestCase
{
    use RefreshDatabase;

    public function test_marketing_events_and_return_reasons_rest_aliases(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $event = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createAdminMarketingEvent(
                    name: "Wave67 Flash Sale"
                    description: "Kickoff for Wave67 promos"
                    date: "2026-11-01"
                ) { id name description date }
            }',
        ]);
        $event->assertOk();
        $this->assertArrayNotHasKey('errors', $event->json() ?? [], json_encode($event->json()));
        $eventId = (string) $event->json('data.createAdminMarketingEvent.id');
        $this->assertSame('Wave67 Flash Sale', (string) $event->json('data.createAdminMarketingEvent.name'));
        $this->assertSame('2026-11-01', (string) $event->json('data.createAdminMarketingEvent.date'));

        $eventUpdate = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateAdminMarketingEvent(id: $id, date: "2026-11-15") { id date }
            }',
            'variables' => ['id' => $eventId],
        ]);
        $eventUpdate->assertOk();
        $this->assertSame('2026-11-15', (string) $eventUpdate->json('data.updateAdminMarketingEvent.date'));

        $eventList = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                adminMarketingEvents(name: "Wave67", date_from: "2026-11-01", date_to: "2026-12-01", limit: 10) {
                    id name
                }
                adminMarketingEvent(id: "'.$eventId.'") { id description }
            }',
        ]);
        $eventList->assertOk();
        $this->assertArrayNotHasKey('errors', $eventList->json() ?? [], json_encode($eventList->json()));
        $this->assertNotEmpty($eventList->json('data.adminMarketingEvents'));

        $campaign = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($eid: ID!) {
                createAdminMarketingCampaign(
                    name: "Wave67 Event Blast"
                    content: "<p>Wave67</p>"
                    marketingEventId: $eid
                    status: "draft"
                ) { id marketingEventId subject }
            }',
            'variables' => ['eid' => $eventId],
        ]);
        $campaign->assertOk();
        $this->assertArrayNotHasKey('errors', $campaign->json() ?? [], json_encode($campaign->json()));
        $this->assertSame($eventId, (string) $campaign->json('data.createAdminMarketingCampaign.marketingEventId'));

        $reason = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createAdminSettingsReturnReason(name: "Wave67 Too large", status: 1, position: 99) {
                    id code name status position
                }
            }',
        ]);
        $reason->assertOk();
        $this->assertArrayNotHasKey('errors', $reason->json() ?? [], json_encode($reason->json()));
        $reasonId = (string) $reason->json('data.createAdminSettingsReturnReason.id');
        $this->assertSame(1, (int) $reason->json('data.createAdminSettingsReturnReason.status'));

        $reasonUpdate = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateAdminSettingsReturnReason(id: $id, name: "Wave67 Size issue", status: 0) {
                    id name status
                }
            }',
            'variables' => ['id' => $reasonId],
        ]);
        $reasonUpdate->assertOk();
        $this->assertSame(0, (int) $reasonUpdate->json('data.updateAdminSettingsReturnReason.status'));

        $massStatus = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($ids: [ID!]!) {
                createAdminSettingsReturnReasonBulkUpdateStatus(ids: $ids, status: 1) {
                    ok updated_count
                }
            }',
            'variables' => ['ids' => [$reasonId]],
        ]);
        $massStatus->assertOk();
        $this->assertTrue((bool) $massStatus->json('data.createAdminSettingsReturnReasonBulkUpdateStatus.ok'));
        $this->assertTrue((bool) ReturnReason::query()->find((int) $reasonId)?->is_enabled);

        $reasonList = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                adminSettingsReturnReasons(q: "Wave67", status: 1, limit: 10) { id name }
                adminSettingsReturnReason(id: "'.$reasonId.'") { id code }
            }',
        ]);
        $reasonList->assertOk();
        $this->assertArrayNotHasKey('errors', $reasonList->json() ?? [], json_encode($reasonList->json()));
        $this->assertNotEmpty($reasonList->json('data.adminSettingsReturnReasons'));

        $delReason = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { deleteAdminSettingsReturnReason(id: $id) { ok id } }',
            'variables' => ['id' => $reasonId],
        ]);
        $delReason->assertOk();
        $this->assertTrue((bool) $delReason->json('data.deleteAdminSettingsReturnReason.ok'));
        $this->assertNull(ReturnReason::query()->find((int) $reasonId));

        $delEvent = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { deleteAdminMarketingEvent(id: $id) { ok id } }',
            'variables' => ['id' => $eventId],
        ]);
        $delEvent->assertOk();
        $this->assertTrue((bool) $delEvent->json('data.deleteAdminMarketingEvent.ok'));
        $this->assertNull(MarketingEvent::query()->find((int) $eventId));
    }
}
