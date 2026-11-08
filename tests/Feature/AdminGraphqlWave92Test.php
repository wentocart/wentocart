<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\GdprRequest;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminGraphqlWave92Test extends TestCase
{
    use RefreshDatabase;

    public function test_shop_gdpr_graphql_rest_surface(): void
    {
        $this->seed();
        ShopSetting::put('gdpr.enabled', true);
        Mail::fake();
        $customer = Customer::factory()->create();

        $status = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => '{ gdprStatus { enabled } }',
        ]);
        $status->assertOk();
        $this->assertArrayNotHasKey('errors', $status->json() ?? [], json_encode($status->json()));
        $this->assertTrue((bool) $status->json('data.gdprStatus.enabled'));

        $create = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                createGdprRequest(type: "delete", message: "Please erase my account via GraphQL") {
                    id type status message can_revoke
                }
            }',
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? [], json_encode($create->json()));
        $this->assertSame('delete', (string) $create->json('data.createGdprRequest.type'));
        $this->assertSame('pending', (string) $create->json('data.createGdprRequest.status'));
        $id = (string) $create->json('data.createGdprRequest.id');

        $list = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => '{ gdprRequests { id type status } }',
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? [], json_encode($list->json()));
        $ids = collect($list->json('data.gdprRequests') ?? [])->pluck('id')->map(fn ($v) => (string) $v)->all();
        $this->assertContains($id, $ids);

        $one = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'query ($id: ID!) { gdprRequest(id: $id) { id status } }',
            'variables' => ['id' => $id],
        ]);
        $one->assertOk();
        $this->assertSame($id, (string) $one->json('data.gdprRequest.id'));

        $revoke = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) {
                revokeGdprRequest(id: $id) { id status can_revoke }
            }',
            'variables' => ['id' => $id],
        ]);
        $revoke->assertOk();
        $this->assertArrayNotHasKey('errors', $revoke->json() ?? [], json_encode($revoke->json()));
        $this->assertSame('revoked', (string) $revoke->json('data.revokeGdprRequest.status'));
        $this->assertNull(GdprRequest::query()->find((int) $id));

        $update = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                createGdprRequest(type: "update", message: "Please update my phone on file") {
                    id type status
                }
            }',
        ]);
        $update->assertOk();
        $this->assertArrayNotHasKey('errors', $update->json() ?? [], json_encode($update->json()));
        $this->assertSame('update', (string) $update->json('data.createGdprRequest.type'));
        $updateId = (string) $update->json('data.createGdprRequest.id');

        $delete = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) {
                deleteGdprRequest(id: $id) { ok message }
            }',
            'variables' => ['id' => $updateId],
        ]);
        $delete->assertOk();
        $this->assertArrayNotHasKey('errors', $delete->json() ?? [], json_encode($delete->json()));
        $this->assertTrue((bool) $delete->json('data.deleteGdprRequest.ok'));
        $this->assertNull(GdprRequest::query()->find((int) $updateId));

        // REST remains delete-only.
        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/account/gdpr', [
                'type' => 'update',
                'message' => 'Please correct my phone number.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }
}
