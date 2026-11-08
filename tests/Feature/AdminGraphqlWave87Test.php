<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Marketing\Models\NewsletterCampaign;
use App\Domain\Marketing\Models\NewsletterSubscriber;
use App\Domain\Store\StoreContext;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminGraphqlWave87Test extends TestCase
{
    use RefreshDatabase;

    public function test_customer_group_and_marketing_mass_delete_plus_csv_exports(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $groupsCsv = $this->actingAs($admin, 'admin')->get('/api/v1/console/customer-groups/export');
        $groupsCsv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $groupsCsv->headers->get('content-type'));
        $this->assertStringContainsString('code', $groupsCsv->streamedContent());

        NewsletterSubscriber::query()->create([
            'email' => 'wave87-sub@example.com',
            'is_subscribed' => true,
        ]);
        $subsCsv = $this->actingAs($admin, 'admin')->get('/api/v1/console/newsletter-subscribers/export');
        $subsCsv->assertOk();
        $this->assertStringContainsString('wave87-sub@example.com', $subsCsv->streamedContent());

        $ok = CustomerGroup::query()->create([
            'name' => 'Wave87 Empty',
            'code' => 'wave87-empty',
            'discount_percent' => 0,
            'is_enabled' => true,
            'is_system' => false,
            'position' => 900,
        ]);
        $system = CustomerGroup::query()->where('is_system', true)->first()
            ?? CustomerGroup::query()->create([
                'name' => 'Wave87 System',
                'code' => 'wave87-system',
                'discount_percent' => 0,
                'is_enabled' => true,
                'is_system' => true,
                'position' => 901,
            ]);
        $assigned = CustomerGroup::query()->create([
            'name' => 'Wave87 Assigned',
            'code' => 'wave87-assigned',
            'discount_percent' => 0,
            'is_enabled' => true,
            'is_system' => false,
            'position' => 902,
        ]);
        $storeId = app(StoreContext::class)->requireId();
        Customer::factory()->create([
            'store_id' => $storeId,
            'email' => 'wave87-group@example.com',
            'customer_group' => $assigned->code,
            'is_enabled' => true,
        ]);

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($a: ID!, $b: ID!, $c: ID!) {
                bulkDeleteAdminCustomerGroup(ids: [$a, $b, $c]) {
                    ok updated_count deleted skipped
                }
            }',
            'variables' => [
                'a' => (string) $ok->id,
                'b' => (string) $system->id,
                'c' => (string) $assigned->id,
            ],
        ]);
        $mass->assertOk();
        $this->assertArrayNotHasKey('errors', $mass->json() ?? [], json_encode($mass->json()));
        $this->assertSame(1, (int) $mass->json('data.bulkDeleteAdminCustomerGroup.updated_count'));
        $this->assertContains((string) $ok->id, $mass->json('data.bulkDeleteAdminCustomerGroup.deleted') ?? []);
        $this->assertContains((string) $system->id, $mass->json('data.bulkDeleteAdminCustomerGroup.skipped') ?? []);
        $this->assertContains((string) $assigned->id, $mass->json('data.bulkDeleteAdminCustomerGroup.skipped') ?? []);
        $this->assertNull(CustomerGroup::query()->find($ok->id));
        $this->assertNotNull(CustomerGroup::query()->find($system->id));
        $this->assertNotNull(CustomerGroup::query()->find($assigned->id));

        $subA = NewsletterSubscriber::query()->create([
            'email' => 'wave87-a@example.com',
            'is_subscribed' => true,
        ]);
        $subB = NewsletterSubscriber::query()->create([
            'email' => 'wave87-b@example.com',
            'is_subscribed' => false,
        ]);
        $subsMass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($a: ID!, $b: ID!) {
                bulkDeleteAdminMarketingSubscriber(ids: [$a, $b]) {
                    ok updated_count deleted skipped
                }
            }',
            'variables' => ['a' => (string) $subA->id, 'b' => (string) $subB->id],
        ]);
        $subsMass->assertOk();
        $this->assertArrayNotHasKey('errors', $subsMass->json() ?? [], json_encode($subsMass->json()));
        $this->assertSame(2, (int) $subsMass->json('data.bulkDeleteAdminMarketingSubscriber.updated_count'));
        $this->assertNull(NewsletterSubscriber::query()->find($subA->id));
        $this->assertNull(NewsletterSubscriber::query()->find($subB->id));

        $camp = NewsletterCampaign::query()->create([
            'subject' => 'Wave87 Campaign',
            'html_body' => '<p>hi</p>',
            'status' => 'draft',
            'sent_count' => 0,
        ]);
        $campMass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                bulkDeleteAdminMarketingCampaign(ids: [$id]) {
                    ok updated_count deleted
                }
            }',
            'variables' => ['id' => (string) $camp->id],
        ]);
        $campMass->assertOk();
        $this->assertArrayNotHasKey('errors', $campMass->json() ?? [], json_encode($campMass->json()));
        $this->assertSame(1, (int) $campMass->json('data.bulkDeleteAdminMarketingCampaign.updated_count'));
        $this->assertNull(NewsletterCampaign::query()->find($camp->id));

        Auth::guard('admin')->logout();
        $this->app['auth']->forgetGuards();
        $this->get('/api/v1/console/customer-groups/export')->assertUnauthorized();
        $this->get('/api/v1/console/newsletter-subscribers/export')->assertUnauthorized();
    }
}
