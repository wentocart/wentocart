<?php

namespace Tests\Feature;

use App\Domain\Customer\GdprSchema;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\GdprRequest;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\StoreContext;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave172Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_gdpr_request_queue_list_update_process(): void
    {
        $this->seed();
        ShopSetting::put('gdpr.enabled', '1');
        GdprSchema::ensure();
        $admin = Admin::query()->firstOrFail();

        $customer = Customer::factory()->create([
            'store_id' => app(StoreContext::class)->requireId(),
            'email' => 'w172-'.strtolower(substr(uniqid(), -6)).'@example.test',
            'first_name' => 'Wave',
            'last_name' => '172',
            'name' => 'Wave 172',
        ]);

        $request = GdprRequest::query()->create([
            'customer_id' => $customer->id,
            'reference' => 'GDPR-W172TEST',
            'type' => 'update',
            'status' => 'pending',
            'message' => 'Please update my phone',
        ]);

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/customers/gdpr-requests');
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/customers/gdpr-requests/'.$request->id);
        $show->assertOk();
        $this->assertSame('pending', (string) $show->json('status'));

        $triage = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/customers/gdpr-requests/'.$request->id, [
            'status' => 'processing',
            'message' => 'Looking into it',
        ]);
        $triage->assertOk();
        $this->assertSame('processing', (string) $triage->json('status'));

        $process = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/customers/gdpr-requests/'.$request->id.'/process');
        $process->assertOk();
        $this->assertSame('approved', (string) $process->json('status'));
        $this->assertFalse((bool) $process->json('customerDeleted'));
        $this->assertTrue(Customer::query()->whereKey($customer->id)->exists());

        // Idempotent refuse
        $this->actingAs($admin, 'admin')
            ->postJson('/api/v1/console/customers/gdpr-requests/'.$request->id.'/process')
            ->assertStatus(422);

        // GraphQL list
        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminCustomerGdprRequests(limit: 10) { id status type requestId } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));

        // Delete-type process cascades customer removal
        $victim = Customer::factory()->create([
            'store_id' => app(StoreContext::class)->requireId(),
            'email' => 'w172-del-'.strtolower(substr(uniqid(), -6)).'@example.test',
            'name' => 'Wave172 Delete',
        ]);
        $delReq = GdprRequest::query()->create([
            'customer_id' => $victim->id,
            'reference' => 'GDPR-W172DEL',
            'type' => 'delete',
            'status' => 'pending',
            'message' => 'Erase me',
        ]);
        $delProcess = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                createAdminCustomerGdprProcess(requestId: $id) {
                    requestId status customerDeleted success
                }
            }',
            'variables' => ['id' => (string) $delReq->id],
        ]);
        $delProcess->assertOk();
        $this->assertArrayNotHasKey('errors', $delProcess->json() ?? [], json_encode($delProcess->json()));
        $this->assertTrue((bool) $delProcess->json('data.createAdminCustomerGdprProcess.customerDeleted'));
        $victimFresh = Customer::query()->find($victim->id);
        $this->assertTrue(
            $victimFresh === null
            || str_ends_with((string) $victimFresh->email, '@anonymized.invalid')
            || ! (bool) $victimFresh->is_enabled
        );

    }
}
