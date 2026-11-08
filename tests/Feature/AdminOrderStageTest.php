<?php

namespace Tests\Feature;

use App\Domain\Auth\Models\Permission;
use App\Domain\Auth\Models\Profile;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderStage;
use App\Domain\Order\OrderStageCatalog;
use App\Mail\ShopHtmlMail;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminOrderStageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        OrderStageCatalog::seed();
    }

    public function test_order_stages_index_lists_seeded_stages(): void
    {
        $this->get('/admin/order-stages')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('OrderStages/Index', false)
                ->has('stages.data')
            );

        $this->assertTrue(OrderStage::query()->where('code', 'pending')->exists());
    }

    public function test_can_create_custom_stage(): void
    {
        $this->post('/admin/order-stages', [
            'name' => 'Packed',
            'buyer_label' => 'Your order is packed',
            'buyer_note' => 'Warehouse finished packing',
            'tone' => 'info',
            'money_meaning' => 'paid',
            'fulfillment_meaning' => 'processing',
            'notify_buyer' => true,
            'mail_template' => 'order_status',
            'unlock_invoice' => true,
            'unlock_credit_slip' => false,
            'unlock_delivery_slip' => false,
            'is_enabled' => true,
        ])->assertRedirect('/admin/order-stages');

        $stage = OrderStage::query()->where('name', 'Packed')->first();
        $this->assertNotNull($stage);
        $this->assertTrue($stage->settle_payment);
        $this->assertTrue($stage->unlock_invoice);
        $this->assertTrue($stage->notify_buyer);
    }

    public function test_non_super_cannot_create_or_edit_order_status(): void
    {
        $profile = Profile::query()->where('slug', 'order_desk')->firstOrFail();
        $staff = Admin::factory()->create(['email' => 'desk-status@store.test']);
        $staff->applyProfile($profile);

        $this->actingAs($staff->fresh(['profile.permissions', 'roles']), 'admin');

        $this->get('/admin/order-stages/create')->assertForbidden();
        $this->post('/admin/order-stages', [
            'name' => 'Warehouse only',
            'buyer_label' => 'Packed',
            'tone' => 'info',
            'money_meaning' => 'none',
            'fulfillment_meaning' => 'processing',
            'notify_buyer' => false,
            'unlock_invoice' => false,
            'unlock_credit_slip' => false,
            'unlock_delivery_slip' => false,
            'is_enabled' => true,
        ])->assertForbidden();

        $this->get('/admin/order-stages')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('OrderStages/Index', false)
                ->where('can_manage', false)
            );
    }

    public function test_status_dropdown_and_update_honor_profile_access(): void
    {
        $warehouse = Profile::query()->create([
            'name' => 'Warehouse',
            'slug' => 'warehouse-packed',
            'description' => 'Fulfilment only',
            'is_enabled' => true,
        ]);
        $warehouse->permissions()->sync(
            Permission::query()->whereIn('slug', ['orders.manage'])->pluck('id')
        );

        $shipped = OrderStage::query()->where('code', 'shipped')->firstOrFail();
        $shipped->profiles()->sync([$warehouse->id]);

        $desk = Profile::query()->where('slug', 'order_desk')->firstOrFail();
        $staff = Admin::factory()->create(['email' => 'desk-packed@store.test']);
        $staff->applyProfile($desk);

        $order = $this->makeOrder('processing');

        $this->actingAs($staff->fresh(['profile.permissions', 'roles']), 'admin')
            ->get('/admin/orders/'.$order->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Show', false)
                ->where('stages', fn ($stages) => collect($stages)->pluck('code')->doesntContain('shipped'))
            );

        $this->post('/admin/orders/'.$order->id.'/status', [
            'order_stage_id' => $shipped->id,
        ])->assertSessionHasErrors('order_stage_id');

        $this->assertSame('processing', $order->fresh()->status);

        $picker = Admin::factory()->create(['email' => 'warehouse-packed@store.test']);
        $picker->applyProfile($warehouse);

        $this->actingAs($picker->fresh(['profile.permissions', 'roles']), 'admin')
            ->post('/admin/orders/'.$order->id.'/status', [
                'order_stage_id' => $shipped->id,
            ])
            ->assertRedirect();

        $fresh = $order->fresh();
        $this->assertSame('shipped', $fresh->status);
        $this->assertSame($shipped->id, (int) $fresh->order_stage_id);
    }

    public function test_create_status_can_limit_access_to_profiles(): void
    {
        $desk = Profile::query()->where('slug', 'order_desk')->firstOrFail();

        $this->post('/admin/order-stages', [
            'name' => 'Ready to pick',
            'buyer_label' => 'We are picking your order',
            'tone' => 'info',
            'money_meaning' => 'paid',
            'fulfillment_meaning' => 'processing',
            'notify_buyer' => false,
            'unlock_invoice' => true,
            'unlock_credit_slip' => false,
            'unlock_delivery_slip' => false,
            'is_enabled' => true,
            'profile_ids' => [$desk->id],
        ])->assertRedirect('/admin/order-stages');

        $stage = OrderStage::query()->where('code', 'ready-to-pick')->first();
        $this->assertNotNull($stage);
        $this->assertEqualsCanonicalizing([$desk->id], $stage->profiles()->pluck('profiles.id')->all());
    }

    public function test_system_stage_cannot_be_deleted(): void
    {
        $stage = OrderStage::query()->where('code', 'pending')->firstOrFail();

        $this->from('/admin/order-stages')
            ->delete('/admin/order-stages/'.$stage->id)
            ->assertRedirect();

        $this->assertDatabaseHas('order_stages', ['id' => $stage->id]);
    }

    public function test_status_change_sends_mail_when_enabled(): void
    {
        Mail::fake();
        $order = $this->makeOrder('pending');
        $processing = OrderStage::query()->where('code', 'processing')->firstOrFail();

        $this->from('/admin/orders/'.$order->id)
            ->post('/admin/orders/'.$order->id.'/status', [
                'order_stage_id' => $processing->id,
                'comment' => 'Paid and packing',
            ])
            ->assertRedirect();

        $order->refresh();
        $this->assertSame('processing', $order->status);
        $this->assertSame($processing->id, (int) $order->order_stage_id);
        $this->assertSame('paid', $order->payment_status);
        Mail::assertSent(ShopHtmlMail::class);
    }

    public function test_status_change_skips_mail_when_disabled(): void
    {
        OrderStage::query()->where('code', 'shipped')->update([
            'notify_buyer' => false,
            'unlock_delivery_slip' => true,
            'settle_payment' => true,
        ]);

        Mail::fake();

        $order = Order::createTrusted([
            'number' => 'ST-NOMAIL',
            'email' => 'buyer.stage@example.com',
            'status' => 'processing',
            'currency_code' => 'USD',
            'currency_rate' => 1,
            'subtotal' => 10,
            'tax_total' => 0,
            'tax_details' => [],
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 10,
            'payment_status' => 'paid',
            'billing_address' => ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ]);

        Mail::fake();
        $shipped = OrderStage::query()->where('code', 'shipped')->firstOrFail();

        $this->post('/admin/orders/'.$order->id.'/status', [
            'order_stage_id' => $shipped->id,
        ])->assertRedirect();

        Mail::assertNothingSent();
        $fresh = $order->fresh();
        $this->assertSame('shipped', $fresh->status);
        $this->assertSame($shipped->id, (int) $fresh->order_stage_id);
    }

    public function test_order_edit_exposes_stage_ids_for_selects(): void
    {
        $order = $this->makeOrder('pending');

        $this->get('/admin/orders/'.$order->id.'/edit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Edit', false)
                ->has('stages.0.id')
                ->has('paymentStatuses.0.id')
                ->has('salesChannels.0.id')
                ->where('stages.0.code', fn ($code) => is_string($code) && $code !== '')
            );
    }

    public function test_bulk_status_and_bulk_destroy(): void
    {
        $stage1 = OrderStage::create([
            'code' => 'custom-stage-1',
            'name' => 'Custom Stage 1',
            'buyer_label' => 'Custom 1',
            'is_system' => false,
            'is_enabled' => true,
            'position' => 100,
        ]);
        $stage2 = OrderStage::create([
            'code' => 'custom-stage-2',
            'name' => 'Custom Stage 2',
            'buyer_label' => 'Custom 2',
            'is_system' => false,
            'is_enabled' => true,
            'position' => 101,
        ]);

        // Bulk deactivate
        $this->post('/admin/order-stages/bulk-status', [
            'ids' => [$stage1->id, $stage2->id],
            'is_enabled' => false,
        ])->assertRedirect();

        $this->assertFalse($stage1->fresh()->is_enabled);
        $this->assertFalse($stage2->fresh()->is_enabled);

        // Bulk activate
        $this->post('/admin/order-stages/bulk-status', [
            'ids' => [$stage1->id],
            'is_enabled' => true,
        ])->assertRedirect();

        $this->assertTrue($stage1->fresh()->is_enabled);
        $this->assertFalse($stage2->fresh()->is_enabled);

        // Bulk destroy
        $systemStage = OrderStage::query()->where('is_system', true)->firstOrFail();
        $this->post('/admin/order-stages/bulk-destroy', [
            'ids' => [$stage1->id, $stage2->id, $systemStage->id],
        ])->assertRedirect();

        $this->assertNull($stage1->fresh());
        $this->assertNull($stage2->fresh());
        $this->assertNotNull($systemStage->fresh()); // system stage was skipped
    }

    protected function makeOrder(string $status): Order
    {
        return Order::createTrusted([
            'number' => 'ST-'.uniqid(),
            'email' => 'buyer.stage@example.com',
            'status' => $status,
            'currency_code' => 'USD',
            'currency_rate' => 1,
            'subtotal' => 10,
            'tax_total' => 0,
            'tax_details' => [],
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 10,
            'payment_status' => 'pending',
            'billing_address' => ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ]);
    }
}
