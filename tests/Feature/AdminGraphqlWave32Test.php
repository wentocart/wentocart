<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave32Test extends TestCase
{
    use RefreshDatabase;

    public function test_warehouses_create_customer_update_category_and_invoice_detail(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $warehouses = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ warehouses(limit: 10) { id code name is_enabled } }',
        ]);
        $warehouses->assertOk();
        $this->assertArrayNotHasKey('errors', $warehouses->json() ?? []);
        $this->assertIsArray($warehouses->json('data.warehouses'));

        $email = 'gql-cust-'.uniqid().'@example.com';
        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createCustomer(email: $e, firstName: "Graph", lastName: "Customer", phone: "+15551212") {
                    id email name phone
                }
            }',
            'variables' => ['e' => $email],
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? []);
        $create->assertJsonPath('data.createCustomer.email', $email);
        $this->assertStringContainsString('Graph', (string) $create->json('data.createCustomer.name'));
        $customerId = (string) $create->json('data.createCustomer.id');
        $delCustomer = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { deleteCustomer(id: $id) { ok id } }',
            'variables' => ['id' => $customerId],
        ]);
        $delCustomer->assertOk();
        $this->assertArrayNotHasKey('errors', $delCustomer->json() ?? []);
        $this->assertTrue((bool) $delCustomer->json('data.deleteCustomer.ok'));
        $this->assertNull(\App\Domain\Customer\Models\Customer::query()->find((int) $customerId));

        $catName = 'Wave32 Cat '.uniqid();
        $cat = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($n: String!) { createCategory(name: $n, enabled: true) { id name } }',
            'variables' => ['n' => $catName],
        ]);
        $cat->assertOk();
        $this->assertArrayNotHasKey('errors', $cat->json() ?? []);
        $catId = (string) $cat->json('data.createCategory.id');

        $updatedName = $catName.' Updated';
        $update = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!, $n: String!) {
                updateCategory(id: $id, name: $n, enabled: true) { id name is_enabled }
            }',
            'variables' => ['id' => $catId, 'n' => $updatedName],
        ]);
        $update->assertOk();
        $this->assertArrayNotHasKey('errors', $update->json() ?? []);
        $update->assertJsonPath('data.updateCategory.name', $updatedName);

        $order = \App\Domain\Order\Models\Order::query()->first();
        if (! $order) {
            return;
        }

        $invoice = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($n: String!) {
                invoice(number: $n) { available enabled order_number invoice_number grand_total items { id sku qty } }
            }',
            'variables' => ['n' => $order->number],
        ]);
        $invoice->assertOk();
        $this->assertArrayNotHasKey('errors', $invoice->json() ?? []);
        $this->assertNotNull($invoice->json('data.invoice'));

        if ((bool) $invoice->json('data.invoice.enabled')) {
            $ensure = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
                'query' => 'mutation ($n: String!) {
                    ensureInvoice(number: $n) {
                        available invoice_number grand_total html items { id name qty total }
                    }
                }',
                'variables' => ['n' => $order->number],
            ]);
            $ensure->assertOk();
            $this->assertArrayNotHasKey('errors', $ensure->json() ?? []);
            $this->assertNotEmpty($ensure->json('data.ensureInvoice.invoice_number'));
            if ((bool) $ensure->json('data.ensureInvoice.available')) {
                $this->assertNotEmpty($ensure->json('data.ensureInvoice.html'));
                $this->assertIsArray($ensure->json('data.ensureInvoice.items'));
            }
        }
    }
}
