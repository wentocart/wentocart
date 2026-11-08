<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Order\Models\Order;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminGraphqlWave277Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_admin_customers_tree_groups_orders_gdpr_alias(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $groups = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/customers/groups');
        $groups->assertOk();
        $this->assertIsArray($groups->json());

        $created = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/customers/groups', [
            'name' => 'Wave277 VIP',
            'code' => 'w277-vip-'.uniqid(),
            'discountPercent' => 5,
        ]);
        $created->assertCreated();
        $gid = (int) $created->json('id');
        $this->assertGreaterThan(0, $gid);

        $showG = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/customers/groups/'.$gid);
        $showG->assertOk();
        $this->assertSame('Wave277 VIP', (string) $showG->json('name'));

        $customer = Customer::query()->create([
            'name' => 'Wave277 Buyer',
            'email' => 'w277@example.com',
            'password' => Hash::make('Password1!'),
            'is_enabled' => true,
        ]);
        Order::createTrusted([
            'number' => 'W277-'.uniqid(),
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'processing',
            'payment_status' => 'paid',
            'currency_code' => 'USD',
            'subtotal' => 40,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'fee_total' => 0,
            'grand_total' => 40,
            'refunded_total' => 0,
            'amount_paid' => 40,
            'amount_due' => 0,
            'billing_address' => [],
            'shipping_address' => [],
        ]);

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/customers?q=w277');
        $list->assertOk();
        $this->assertTrue(collect($list->json() ?? [])->contains(fn ($r) => (int) ($r['id'] ?? 0) === (int) $customer->id));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/customers/'.$customer->id);
        $show->assertOk();
        $this->assertSame('w277@example.com', (string) $show->json('email'));

        $orders = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/customers/'.$customer->id.'/orders');
        $orders->assertOk();
        $this->assertNotEmpty($orders->json());

        $invoices = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/customers/'.$customer->id.'/invoices');
        $invoices->assertOk();
        $this->assertNotEmpty($invoices->json());

        $gdpr = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/customers/gdpr');
        $gdpr->assertOk();

        $this->actingAs($admin, 'admin')
            ->postJson('/api/v1/console/customers/reviews/bulk-delete', ['ids' => []])
            ->assertSuccessful();

        $this->assertInstanceOf(CustomerGroup::class, CustomerGroup::query()->find($gid));
    }
}
