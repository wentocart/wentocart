<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminPreorderOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(Admin::query()->firstOrFail(), 'admin');
    }

    public function test_preorders_route_requires_authentication(): void
    {
        auth('admin')->logout();

        $this->get('/admin/orders/preorders')->assertRedirect();
    }

    public function test_preorders_route_requires_orders_manage_permission(): void
    {
        $staff = Admin::factory()->create(['email' => 'no-orders-preorders@store.test']);
        $this->actingAs($staff->fresh(['profile.permissions', 'roles']), 'admin');

        $this->get('/admin/orders/preorders')->assertForbidden();
    }

    public function test_preorders_index_renders_and_lists_only_preorder_lines(): void
    {
        $customer = Customer::factory()->create(['name' => 'Preorder Buyer', 'email' => 'prebuyer@example.com']);
        $mixed = $this->makeOrder($customer, 'ORD-MIX-1', 'partial');
        $this->addItem($mixed, [
            'sku' => 'PRE-1',
            'name' => 'Preorder Camera',
            'qty' => 2,
            'unit_price' => 40,
            'total' => 80,
            'preorder_data' => [
                'enabled' => true,
                'status' => 'awaiting_release',
                'full_unit_price' => 50,
                'charge_unit_price' => 40,
                'deposit' => 80,
                'remaining_balance' => 20,
                'payment_type' => 'deposit',
            ],
        ]);
        $this->addItem($mixed, [
            'sku' => 'REG-1',
            'name' => 'Regular Cable',
            'qty' => 1,
            'unit_price' => 15,
            'total' => 15,
            'preorder_data' => null,
        ]);

        $plain = $this->makeOrder($customer, 'ORD-PLAIN-1', 'paid');
        $this->addItem($plain, [
            'sku' => 'REG-2',
            'name' => 'Plain Mug',
            'qty' => 1,
            'unit_price' => 12,
            'total' => 12,
            'preorder_data' => null,
        ]);

        $onlyPre = $this->makeOrder($customer, 'ORD-PRE-1', 'paid');
        $this->addItem($onlyPre, [
            'sku' => 'PRE-2',
            'name' => 'Preorder Watch',
            'qty' => 1,
            'unit_price' => 90,
            'total' => 90,
            'preorder_data' => [
                'enabled' => true,
                'status' => 'ready',
                'full_unit_price' => 90,
                'charge_unit_price' => 90,
                'deposit' => 90,
                'remaining_balance' => 0,
                'payment_type' => 'full',
            ],
        ]);

        $response = $this->get('/admin/orders/preorders')->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Orders/Preorders')
            ->has('preorders.data', 2)
            ->where('preorders.total', 2)
            ->where('filters.status', 'preorders')
            ->has('preorders.data.0.order_id')
            ->has('preorders.data.0.order_number')
            ->has('preorders.data.0.customer')
            ->has('preorders.data.0.product')
            ->has('preorders.data.0.quantity')
            ->has('preorders.data.0.line_total')
            ->has('preorders.data.0.payment_status')
            ->has('preorders.data.0.preorder_status')
            ->has('preorders.data.0.created_at')
        );

        $rows = collect($response->original->getData()['page']['props']['preorders']['data'] ?? []);
        $numbers = $rows->pluck('order_number')->all();
        $this->assertContains('ORD-MIX-1', $numbers);
        $this->assertContains('ORD-PRE-1', $numbers);
        $this->assertNotContains('ORD-PLAIN-1', $numbers);

        $mixedRow = $rows->firstWhere('order_number', 'ORD-MIX-1');
        $this->assertTrue((bool) ($mixedRow['is_mixed_order'] ?? false));
        $this->assertSame('Preorder Camera', $mixedRow['product']['name'] ?? null);
        $this->assertEquals(2.0, (float) ($mixedRow['quantity'] ?? 0));
    }

    public function test_preorders_excludes_balance_payment_lines(): void
    {
        $customer = Customer::factory()->create();
        $order = $this->makeOrder($customer, 'ORD-BAL-1', 'paid');
        $this->addItem($order, [
            'sku' => 'PRE-BAL',
            'name' => 'Balance Payment Line',
            'qty' => 1,
            'unit_price' => 30,
            'total' => 30,
            'preorder_data' => [
                'enabled' => true,
                'kind' => 'balance_payment',
                'status' => 'awaiting_release',
                'full_unit_price' => 30,
                'payment_type' => 'full',
            ],
        ]);

        $this->get('/admin/orders/preorders')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Preorders')
                ->where('preorders.total', 0)
                ->has('preorders.data', 0)
            );
    }

    public function test_preorders_filters_by_customer_and_payment_status(): void
    {
        $alice = Customer::factory()->create(['name' => 'Alice Pre', 'email' => 'alice.pre@example.com']);
        $bob = Customer::factory()->create(['name' => 'Bob Pre', 'email' => 'bob.pre@example.com']);

        $aliceOrder = $this->makeOrder($alice, 'ORD-ALICE', 'pending');
        $this->addItem($aliceOrder, [
            'sku' => 'PRE-A',
            'name' => 'Alice Item',
            'qty' => 1,
            'unit_price' => 40,
            'total' => 40,
            'preorder_data' => [
                'enabled' => true,
                'status' => 'awaiting_release',
                'full_unit_price' => 40,
                'payment_type' => 'full',
            ],
        ]);

        $bobOrder = $this->makeOrder($bob, 'ORD-BOB', 'paid');
        $this->addItem($bobOrder, [
            'sku' => 'PRE-B',
            'name' => 'Bob Item',
            'qty' => 1,
            'unit_price' => 55,
            'total' => 55,
            'preorder_data' => [
                'enabled' => true,
                'status' => 'ready',
                'full_unit_price' => 55,
                'payment_type' => 'full',
            ],
        ]);

        $this->get('/admin/orders/preorders?customer=Alice&payment_status=pending')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Preorders')
                ->where('preorders.total', 1)
                ->where('preorders.data.0.order_number', 'ORD-ALICE')
                ->where('preorders.data.0.customer.name', 'Alice Pre')
            );
    }

    public function test_preorders_search_and_preorder_status_filter(): void
    {
        $customer = Customer::factory()->create(['name' => 'Search Buyer']);
        $ready = $this->makeOrder($customer, 'ORD-READY', 'paid');
        $this->addItem($ready, [
            'sku' => 'PRE-READY',
            'name' => 'Ready Headset',
            'qty' => 1,
            'unit_price' => 70,
            'total' => 70,
            'preorder_data' => [
                'enabled' => true,
                'status' => 'ready',
                'full_unit_price' => 70,
                'payment_type' => 'full',
            ],
        ]);
        $waiting = $this->makeOrder($customer, 'ORD-WAIT', 'partial');
        $this->addItem($waiting, [
            'sku' => 'PRE-WAIT',
            'name' => 'Waiting Keyboard',
            'qty' => 1,
            'unit_price' => 60,
            'total' => 60,
            'preorder_data' => [
                'enabled' => true,
                'status' => 'awaiting_release',
                'full_unit_price' => 60,
                'payment_type' => 'deposit',
            ],
        ]);

        $this->get('/admin/orders/preorders?q=Headset&preorder_status=ready')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('preorders.total', 1)
                ->where('preorders.data.0.order_number', 'ORD-READY')
                ->where('preorders.data.0.product.name', 'Ready Headset')
            );
    }

    public function test_preorders_pagination(): void
    {
        $customer = Customer::factory()->create();
        for ($i = 1; $i <= 12; $i++) {
            $order = $this->makeOrder($customer, 'ORD-PAGE-'.$i, 'paid');
            $this->addItem($order, [
                'sku' => 'PRE-P-'.$i,
                'name' => 'Paged Item '.$i,
                'qty' => 1,
                'unit_price' => 20,
                'total' => 20,
                'preorder_data' => [
                    'enabled' => true,
                    'status' => 'awaiting_release',
                    'full_unit_price' => 20,
                    'payment_type' => 'full',
                ],
            ]);
        }

        $this->get('/admin/orders/preorders?per_page=5')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('preorders.total', 12)
                ->where('preorders.per_page', 5)
                ->has('preorders.data', 5)
                ->where('preorders.last_page', 3)
            );

        $this->get('/admin/orders/preorders?per_page=5&page=3')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('preorders.data', 2)
                ->where('preorders.current_page', 3)
            );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeOrder(Customer $customer, string $number, string $paymentStatus): Order
    {
        $address = [
            'first_name' => 'Pre',
            'last_name' => 'Buyer',
            'line1' => '1 Main',
            'city' => 'Austin',
            'state' => 'TX',
            'postal_code' => '78701',
            'country_code' => 'US',
        ];

        return Order::createTrusted([
            'number' => $number,
            'checkout_reference' => $number,
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'pending',
            'currency_code' => 'USD',
            'currency_rate' => 1,
            'subtotal' => 0,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 0,
            'payment_status' => $paymentStatus,
            'billing_address' => $address,
            'shipping_address' => $address,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function addItem(Order $order, array $attributes): OrderItem
    {
        return $order->items()->create([
            'sku' => $attributes['sku'],
            'name' => $attributes['name'],
            'qty' => $attributes['qty'],
            'unit_price' => $attributes['unit_price'],
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total' => $attributes['total'],
            'preorder_data' => $attributes['preorder_data'] ?? null,
        ]);
    }
}
