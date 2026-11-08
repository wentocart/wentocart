<?php

namespace Tests\Feature;

use App\Domain\Api\Graphql\GraphqlAdminImpersonate;
use App\Domain\Auth\Models\AdminImpersonation;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AdminGraphqlWave169Test extends TestCase
{
    use RefreshDatabase;

    public function test_impersonate_issues_customer_token_and_draft_cart_rest_payload(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $email = 'w169-'.strtolower(substr(uniqid(), -6)).'@example.test';
        $customerId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createCustomer(email: $e, firstName: "Wave", lastName: "169", password: "Password1!") { id }
            }',
            'variables' => ['e' => $email],
        ])->json('data.createCustomer.id');
        $this->assertNotSame('', $customerId);

        // API nested createAdminOrderComposerDraft payload
        $draft = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($input: createAdminOrderComposerDraftInput!) {
                createAdminOrderComposerDraft(input: $input) {
                    success message
                    adminOrderComposerDraft { cartId customerId success message }
                    cartId customerId
                }
            }',
            'variables' => ['input' => ['customerId' => $customerId]],
        ]);
        $draft->assertOk();
        $this->assertArrayNotHasKey('errors', $draft->json() ?? [], json_encode($draft->json()));
        $this->assertTrue((bool) $draft->json('data.createAdminOrderComposerDraft.success'));
        $this->assertNotSame('', (string) $draft->json('data.createAdminOrderComposerDraft.adminOrderComposerDraft.cartId'));
        $this->assertSame(
            (string) $draft->json('data.createAdminOrderComposerDraft.cartId'),
            (string) $draft->json('data.createAdminOrderComposerDraft.adminOrderComposerDraft.cartId'),
        );

        // REST impersonate
        $rest = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/customers/'.$customerId.'/impersonate');
        $rest->assertOk();
        $token = (string) $rest->json('token');
        $this->assertNotSame('', $token);
        $this->assertSame((int) $customerId, (int) $rest->json('customerId'));
        $this->assertSame((int) $admin->id, (int) $rest->json('impersonatedByAdminId'));
        $this->assertNotSame('', (string) $rest->json('expiresAt'));
        $this->assertNotNull(PersonalAccessToken::findToken($token));
        $this->assertSame(1, AdminImpersonation::query()->where('customer_id', $customerId)->count());

        // Token authenticates as the customer on shop GraphQL (API headless impersonation).
        \Illuminate\Support\Facades\Auth::guard('admin')->logout();
        \Illuminate\Support\Facades\Auth::guard('customer')->logout();
        $this->app['auth']->forgetGuards();
        $me = $this->call(
            'POST',
            '/api/v1/graphql',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            json_encode(['query' => '{ me { id email } }'], JSON_THROW_ON_ERROR),
        );
        $this->assertSame(200, $me->getStatusCode(), $me->getContent());
        $meJson = json_decode($me->getContent(), true);
        $this->assertSame($customerId, (string) ($meJson['data']['me']['id'] ?? ''));

        // GraphQL impersonate alias re-issues (replaces prior token)
        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!) {
                createAdminCustomerImpersonate(customerId: $cid) {
                    token customerId impersonatedByAdminId expiresAt success
                }
            }',
            'variables' => ['cid' => $customerId],
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
        $newToken = (string) $gql->json('data.createAdminCustomerImpersonate.token');
        $this->assertNotSame('', $newToken);
        $this->assertNull(PersonalAccessToken::findToken($token));
        $this->assertNotNull(PersonalAccessToken::findToken($newToken));
        $this->assertSame(GraphqlAdminImpersonate::TOKEN_NAME, PersonalAccessToken::findToken($newToken)?->name);

    }
}
