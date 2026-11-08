<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Subscription\Models\CustomerSubscription;
use App\Domain\Subscription\Models\SubscriptionPlan;
use App\Domain\Subscription\Services\SubscriptionBilling;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ChargeableRenewal;
use Tests\TestCase;

/**
 * The merchant-facing side: seeing subscribers and acting on their behalf.
 */
class AdminCustomerSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
    }

    private function subscription(string $status = CustomerSubscription::STATUS_ACTIVE): CustomerSubscription
    {
        $customer = Customer::factory()->create(['email' => 'sub-'.uniqid().'@example.test']);
        $plan = SubscriptionPlan::query()->create([
            'code' => 'ADM-'.uniqid(),
            'name' => 'Admin Plan',
            'billing_cycle' => 'monthly',
            'interval_count' => 1,
            'cycle' => 1,
            'frequency' => 'month',
            'price' => 30,
            'is_enabled' => true,
        ]);
        $product = Product::query()->create([
            'type' => 'subscription',
            'sku' => 'ADM-'.uniqid(),
            'price' => 30,
            'manage_stock' => false,
            'is_enabled' => true,
        ]);

        // Price lives on the product↔plan link.
        \App\Domain\Subscription\Models\ProductSubscriptionPlan::query()->create([
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'price' => 30,
            'is_enabled' => true,
        ]);
        $order = Order::createTrusted([
            'number' => 'ORD-ADM-'.uniqid(),
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'processing',
            'currency_code' => 'USD',
            'subtotal' => 30,
            'grand_total' => 30,
            'payment_status' => 'paid',
            'amount_paid' => 30,
            'amount_due' => 0,
            'billing_address' => ['first_name' => 'A', 'line1' => 'x', 'city' => 'y', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'A', 'line1' => 'x', 'city' => 'y', 'country_code' => 'US'],
        ]);
        $item = OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'sku' => $product->sku,
            'name' => 'Subscription',
            'qty' => 1,
            'unit_price' => 30,
            'total' => 30,
        ]);

        app(SubscriptionBilling::class)->startFromOrder($order->refresh());

        $sub = CustomerSubscription::query()->where('order_item_id', $item->id)->firstOrFail();
        if ($status !== CustomerSubscription::STATUS_ACTIVE) {
            $sub->forceFill(['status' => $status])->save();
        }

        return $sub->fresh();
    }

    public function test_index_lists_subscribers_with_metrics(): void
    {
        $this->subscription();

        $this->get('/admin/customer-subscriptions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/Subscriptions/Customers')
                ->has('subscriptions.data')
                ->has('metrics')
                ->has('counts')
                ->where('metrics.active', fn ($v) => $v >= 1)
                ->where('metrics.mrr', fn ($v) => $v >= 30)
            );
    }

    public function test_index_can_be_filtered_by_status(): void
    {
        $this->subscription(CustomerSubscription::STATUS_ACTIVE);
        $this->subscription(CustomerSubscription::STATUS_CANCELLED);

        $this->get('/admin/customer-subscriptions?status=cancelled')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('subscriptions.data', fn ($rows) => collect($rows)->every(
                    fn ($row) => $row['status'] === 'cancelled'
                ))
            );
    }

    public function test_index_can_be_searched_by_customer_email(): void
    {
        $sub = $this->subscription();
        $email = $sub->customer->email;

        $this->get('/admin/customer-subscriptions?q='.urlencode($email))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('subscriptions.data', fn ($rows) => collect($rows)->contains(
                    fn ($row) => $row['customer_email'] === $email
                ))
            );
    }

    public function test_show_page_exposes_billing_health_and_renewals(): void
    {
        $sub = $this->subscription();

        $this->get('/admin/customer-subscriptions/'.$sub->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/Subscriptions/CustomerShow')
                ->where('subscription.id', $sub->id)
                ->has('subscription.status')
                ->has('subscription.payment_status')
                ->has('subscription.billing_summary')
                ->has('subscription.product_cover')
                ->has('subscription.order.number')
                ->has('subscription.dunning_attempts')
                ->has('subscription.renewal_attempts')
                ->has('subscription.can_skip')
                ->where('subscription.can_skip', true)
                ->has('renewals')
            );
    }

    public function test_admin_can_skip_one_billing_cycle(): void
    {
        $sub = $this->subscription();
        $sub->forceFill(['next_renewal_at' => now()->addDays(3)])->save();
        $before = $sub->fresh()->next_renewal_at->copy();

        $this->from('/admin/customer-subscriptions/'.$sub->id)
            ->post('/admin/customer-subscriptions/'.$sub->id.'/skip')
            ->assertRedirect('/admin/customer-subscriptions/'.$sub->id);

        $after = $sub->fresh()->next_renewal_at;
        $this->assertTrue($after->greaterThan($before));
        $this->assertSame(CustomerSubscription::STATUS_ACTIVE, $sub->fresh()->status);
    }

    public function test_skip_is_a_no_op_when_the_subscription_cannot_skip(): void
    {
        $sub = $this->subscription(CustomerSubscription::STATUS_CANCELLED);
        $sub->forceFill(['next_renewal_at' => now()->addDays(3)])->save();
        $before = $sub->fresh()->next_renewal_at->toIso8601String();

        $this->post('/admin/customer-subscriptions/'.$sub->id.'/skip')->assertRedirect();

        $this->assertSame($before, $sub->fresh()->next_renewal_at->toIso8601String());
        $this->get('/admin/customer-subscriptions/'.$sub->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('subscription.can_skip', false));
    }

    public function test_index_actions_are_view_only_and_show_page_is_sectioned(): void
    {
        $list = (string) file_get_contents(resource_path('js/admin/Pages/Catalog/Subscriptions/Customers.jsx'));
        $show = (string) file_get_contents(resource_path('js/admin/Pages/Catalog/Subscriptions/CustomerShow.jsx'));

        $this->assertStringContainsString('RowActions', $list);
        $this->assertStringContainsString('viewHref={row.url}', $list);
        $this->assertStringContainsString('canEdit={false}', $list);
        $this->assertStringContainsString('canDelete={false}', $list);
        $this->assertStringNotContainsString("act(row, 'pause')", $list);
        $this->assertStringNotContainsString("act(row, 'cancel')", $list);
        $this->assertStringNotContainsString('Monthly value', $list);
        $this->assertStringContainsString('col="center"', $list);

        $this->assertStringContainsString('formLayoutEqual', $show);
        $this->assertStringContainsString('sectionCardCompact', $show);
        $this->assertStringContainsString('infoRowPlain', $show);
        $this->assertStringContainsString('sectionSplitTight', $show);
        $this->assertStringContainsString('subscription_information', $show);
        $this->assertStringContainsString('billing_information', $show);
        $this->assertStringContainsString('order_information', $show);
        $this->assertStringContainsString('skip_cycle', $show);
        $this->assertStringContainsString("icon=\"skip-forward\"", $show);
        $this->assertStringContainsString('variant="warning"', $show);
        $this->assertStringContainsString('variant="dangerSoft"', $show);
        $this->assertStringContainsString('emptyPanel', $show);
        $this->assertStringContainsString("icon=\"eye\"", $show);
    }

    public function test_admin_can_cancel_on_behalf_of_a_customer(): void
    {
        $sub = $this->subscription();

        $this->post('/admin/customer-subscriptions/'.$sub->id.'/cancel')->assertRedirect();

        $this->assertSame(CustomerSubscription::STATUS_CANCELLED, $sub->fresh()->status);
    }

    public function test_admin_can_pause_and_resume(): void
    {
        $sub = $this->subscription();

        $this->post('/admin/customer-subscriptions/'.$sub->id.'/pause')->assertRedirect();
        $this->assertSame(CustomerSubscription::STATUS_PAUSED, $sub->fresh()->status);

        $this->post('/admin/customer-subscriptions/'.$sub->id.'/resume')->assertRedirect();
        $this->assertSame(CustomerSubscription::STATUS_ACTIVE, $sub->fresh()->status);
    }

    public function test_admin_retry_attempts_a_charge_and_reports_the_outcome(): void
    {
        $sub = $this->subscription(CustomerSubscription::STATUS_PAST_DUE);

        $this->post('/admin/customer-subscriptions/'.$sub->id.'/retry')->assertRedirect();

        $fresh = $sub->fresh();
        // The charge cannot succeed without a saved method, so it must stay past_due
        // with a recorded reason rather than silently claiming success.
        $this->assertSame(CustomerSubscription::STATUS_PAST_DUE, $fresh->status);
        $this->assertNotNull($fresh->last_renewal_error);
    }

    public function test_retry_marks_active_when_the_charge_succeeds(): void
    {
        $sub = $this->subscription(CustomerSubscription::STATUS_PAST_DUE);
        ChargeableRenewal::attach($sub);

        $this->post('/admin/customer-subscriptions/'.$sub->id.'/retry')->assertRedirect();

        $this->assertSame(CustomerSubscription::STATUS_ACTIVE, $sub->fresh()->status);
    }

    public function test_mrr_falls_back_to_the_order_price_when_the_link_is_gone(): void
    {
        $sub = $this->subscription();

        // Simulate the product↔plan link being removed after the customer subscribed.
        \App\Domain\Subscription\Models\ProductSubscriptionPlan::query()
            ->where('product_id', $sub->product_id)
            ->where('subscription_plan_id', $sub->subscription_plan_id)
            ->delete();

        $this->get('/admin/customer-subscriptions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // A live subscriber must not silently drop out of MRR.
                ->where('metrics.mrr', fn ($v) => $v >= 30)
            );
    }

    public function test_admin_can_cancel_at_period_end_and_reactivate(): void
    {
        $sub = $this->subscription();

        $this->post('/admin/customer-subscriptions/'.$sub->id.'/cancel-at-period-end')->assertRedirect();
        $this->assertTrue($sub->fresh()->cancel_at_period_end);
        $this->assertSame(CustomerSubscription::STATUS_ACTIVE, $sub->fresh()->status);

        $this->post('/admin/customer-subscriptions/'.$sub->id.'/cancel')->assertRedirect();
        $this->assertSame(CustomerSubscription::STATUS_CANCELLED, $sub->fresh()->status);

        $this->post('/admin/customer-subscriptions/'.$sub->id.'/reactivate')->assertRedirect();
        $this->assertSame(CustomerSubscription::STATUS_ACTIVE, $sub->fresh()->status);
    }

    public function test_guests_cannot_reach_the_subscriber_screen(): void
    {
        $this->app['auth']->forgetGuards();

        $this->get('/admin/customer-subscriptions')->assertRedirect();
    }
}