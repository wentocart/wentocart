<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave165Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_admin_draft_cart_create_and_get(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $email = 'w165-'.uniqid().'@example.test';
        $customerId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createCustomer(email: $e, firstName: "Wave", lastName: "165", password: "Password1!") { id }
            }',
            'variables' => ['e' => $email],
        ])->json('data.createCustomer.id');
        $this->assertNotSame('', $customerId);

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/customers/'.$customerId.'/order-composer/drafts');
        $create->assertCreated();
        $cartId = (string) $create->json('cartId');
        $this->assertNotSame('', $cartId);
        $this->assertTrue((bool) $create->json('success'));
        $this->assertSame($customerId, (string) $create->json('customerId'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/carts/'.$cartId);
        $show->assertOk();
        $this->assertSame($cartId, (string) $show->json('cartId'));
        $this->assertFalse((bool) $show->json('isActive'));
        $this->assertSame(0, (int) $show->json('itemsCount'));
    }
}
