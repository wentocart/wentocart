<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave171Test extends TestCase
{
    use RefreshDatabase;

    public function test_customer_notes_and_gdpr_download_data(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $email = 'w171-'.strtolower(substr(uniqid(), -6)).'@example.test';
        $customerId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createCustomer(email: $e, firstName: "Wave", lastName: "171", password: "Password1!") { id }
            }',
            'variables' => ['e' => $email],
        ])->json('data.createCustomer.id');
        $this->assertNotSame('', $customerId);

        // REST create note
        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/customers/'.$customerId.'/notes', [
            'note' => 'Wave171 support follow-up',
            'customer_notified' => false,
        ]);
        $create->assertCreated();
        $this->assertSame('Wave171 support follow-up', (string) $create->json('note'));
        $this->assertFalse((bool) $create->json('customerNotified'));

        // REST list
        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/customers/'.$customerId.'/notes');
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $this->assertArrayHasKey('meta', $list->json());

        // GraphQL list + create
        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!) {
                createAdminCustomerNote(customerId: $cid, note: "GQL note", customerNotified: false) {
                    id note customerId customerNotified
                }
            }
            query Notes($cid: ID!) {
                adminCustomerNotes(customerId: $cid) { id note }
            }',
            'variables' => ['cid' => $customerId],
        ]);
        // Two operations in one document may be rejected — fall back to separate calls.
        if ($gql->status() !== 200 || isset($gql->json()['errors'])) {
            $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
                'query' => 'mutation ($cid: ID!) {
                    createAdminCustomerNote(customerId: $cid, note: "GQL note", customerNotified: false) {
                        id note customerId
                    }
                }',
                'variables' => ['cid' => $customerId],
            ])->assertOk();
            $notes = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
                'query' => 'query ($cid: ID!) { adminCustomerNotes(customerId: $cid) { id note } }',
                'variables' => ['cid' => $customerId],
            ]);
            $notes->assertOk();
            $this->assertArrayNotHasKey('errors', $notes->json() ?? [], json_encode($notes->json()));
            $this->assertGreaterThanOrEqual(2, count($notes->json('data.adminCustomerNotes') ?? []));
        }

        // REST GDPR download
        $dl = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/customers/'.$customerId.'/gdpr-download-data');
        $dl->assertOk();
        $this->assertSame((int) $customerId, (int) $dl->json('customerId'));
        $this->assertArrayHasKey('customer', $dl->json('data') ?? []);
        $this->assertArrayNotHasKey('password', $dl->json('data.customer') ?? ['password' => 'x']);
        $this->assertIsArray($dl->json('data.notes'));
        $this->assertNotEmpty($dl->json('data.notes'));

        // GraphQL GDPR download
        $gqlDl = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!) {
                createAdminCustomerGdprDownloadData(customerId: $cid) {
                    success customerId customerEmail generatedAt data
                }
            }',
            'variables' => ['cid' => $customerId],
        ]);
        $gqlDl->assertOk();
        $this->assertArrayNotHasKey('errors', $gqlDl->json() ?? [], json_encode($gqlDl->json()));
        $this->assertTrue((bool) $gqlDl->json('data.createAdminCustomerGdprDownloadData.success'));
        $payload = json_decode((string) $gqlDl->json('data.createAdminCustomerGdprDownloadData.data'), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('addresses', $payload);

    }
}
