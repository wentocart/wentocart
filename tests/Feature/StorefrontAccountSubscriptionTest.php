<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductTranslation;
use App\Domain\Customer\Models\Customer;
use App\Domain\Localization\Models\Language;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Subscription\Models\CustomerSubscription;
use App\Domain\Subscription\Models\ProductSubscriptionPlan;
use App\Domain\Subscription\Models\SubscriptionPlan;
use App\Domain\Subscription\Models\SubscriptionPlanTranslation;
use App\Domain\Subscription\Services\SubscriptionBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontAccountSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_guest_cannot_list_or_view_subscriptions(): void
    {
        $this->getJson('/api/v1/account/subscriptions')->assertUnauthorized();
        $this->getJson('/api/v1/account/subscriptions/1')->assertUnauthorized();
    }

    public function test_customer_lists_own_subscriptions_in_table_payload(): void
    {
        $customer = $this->customer();
        $sub = $this->subscribePaid($customer);

        $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/subscriptions')
            ->assertOk()
            ->assertJsonCount(1, 'subscriptions')
            ->assertJsonPath('subscriptions.0.id', $sub->id)
            ->assertJsonPath('subscriptions.0.product', 'Precision Pro Beard Trimmer')
            ->assertJsonPath('subscriptions.0.plan', 'Biannual Business')
            ->assertJsonPath('subscriptions.0.status', CustomerSubscription::STATUS_ACTIVE)
            ->assertJsonPath('subscriptions.0.order_number', $sub->order->number);

        $row = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/subscriptions')
            ->json('subscriptions.0');

        $this->assertEquals(36, $row['price']);
        $this->assertSame('Every 1 Month', $row['billing_summary']);
        $this->assertNotEmpty($row['next_renewal_at']);
        $this->assertNotEmpty($row['product_cover_url']);
        $this->assertTrue($row['can_pause']);
        $this->assertTrue($row['can_skip']);
        $this->assertTrue($row['can_cancel']);
        $this->assertFalse($row['can_reactivate']);
        $this->assertSame('paid', $row['payment_status']);
    }

    public function test_customer_can_view_own_subscription_details(): void
    {
        $customer = $this->customer();
        $sub = $this->subscribePaid($customer);
        $sub->update([
            'cycles_completed' => 2,
            'last_renewal_order_id' => $sub->order_id,
        ]);

        $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/subscriptions/'.$sub->id)
            ->assertOk()
            ->assertJsonPath('subscription.id', $sub->id)
            ->assertJsonPath('subscription.product', 'Precision Pro Beard Trimmer')
            ->assertJsonPath('subscription.plan', 'Biannual Business')
            ->assertJsonPath('subscription.status', CustomerSubscription::STATUS_ACTIVE)
            ->assertJsonPath('subscription.cycles_completed', 2)
            ->assertJsonPath('subscription.order_number', $sub->order->number)
            ->assertJsonPath('subscription.last_renewal_order_number', $sub->order->number)
            ->assertJsonPath('subscription.trial_summary', 'No')
            ->assertJsonPath('subscription.duration_summary', 'Until cancelled');

        $payload = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/subscriptions/'.$sub->id)
            ->json('subscription');

        $this->assertEquals(36, $payload['price']);
        $this->assertSame('Every 1 Month', $payload['billing_summary']);
        $this->assertNotEmpty($payload['started_at']);
        $this->assertNotEmpty($payload['next_renewal_at']);
        $this->assertArrayHasKey('cancelled_at', $payload);
        $this->assertArrayHasKey('can_change_plan', $payload);
    }

    public function test_customer_cannot_view_another_customers_subscription(): void
    {
        $owner = $this->customer();
        $stranger = $this->customer('stranger-sub@example.test');
        $sub = $this->subscribePaid($owner);

        $this->actingAs($stranger, 'customer')
            ->getJson('/api/v1/account/subscriptions/'.$sub->id)
            ->assertNotFound();

        $this->actingAs($stranger, 'customer')
            ->postJson('/api/v1/account/subscriptions/'.$sub->id.'/pause')
            ->assertNotFound();
    }

    public function test_storefront_uses_table_list_and_nested_details_route(): void
    {
        $list = file_get_contents(base_path('storefront/src/pages/Subscriptions.jsx'));
        $detail = file_get_contents(base_path('storefront/src/pages/SubscriptionDetail.jsx'));
        $routes = file_get_contents(base_path('storefront/src/main.jsx'));

        $this->assertStringContainsString('shop-module-table', $list);
        $this->assertStringContainsString('shop-subscriptions-table', $list);
        $this->assertStringContainsString('/account/subscriptions/${row.id}', $list);
        $this->assertStringContainsString("'View'", $list);
        $this->assertStringNotContainsString('space-y-3', $list);

        $this->assertStringContainsString('/account/subscriptions/${id}', $detail);
        $this->assertStringContainsString('SubscriptionActions', $detail);
        $this->assertStringContainsString('shop-subscriptions-dl', $detail);
        $this->assertStringContainsString('shop-od-card', $detail);
        $this->assertStringContainsString('subscription_information', $detail);
        $this->assertStringContainsString('billing_information', $detail);
        $this->assertStringContainsString('order_information', $detail);
        $this->assertStringContainsString('shop-od-action is-compact', $detail);
        $this->assertStringContainsString("'View order'", $detail);
        $this->assertStringContainsString('name="eye"', $detail);
        $this->assertStringContainsString('/account/orders/${number}', $detail);

        $actions = file_get_contents(base_path('storefront/src/components/SubscriptionActions.jsx'));
        $this->assertStringContainsString('tone="primary"', $actions);
        $this->assertStringContainsString('tone="warning"', $actions);
        $this->assertStringContainsString('tone="success"', $actions);
        $this->assertStringContainsString('tone="danger"', $actions);
        $this->assertStringContainsString('icon="pause"', $actions);
        $this->assertStringContainsString('icon="play"', $actions);
        $this->assertStringContainsString('icon="skipForward"', $actions);
        $this->assertStringContainsString('icon="close"', $actions);
        $this->assertStringContainsString('icon="refresh"', $actions);

        $this->assertStringContainsString('path="subscriptions/:id"', $routes);
        $this->assertStringContainsString('SubscriptionDetail', $routes);
    }

    public function test_pending_order_with_two_subscription_products_appears_for_owner_only(): void
    {
        $customer = $this->customer();
        $stranger = $this->customer('stranger-pending-sub@example.test');
        [$order, $first, $second] = $this->pendingTwoProductOrder($customer);

        $list = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/subscriptions')
            ->assertOk()
            ->assertJsonCount(2, 'subscriptions')
            ->json('subscriptions');

        $ids = collect($list)->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$first->id, $second->id])->sort()->values()->all(), $ids);
        foreach ($list as $row) {
            $this->assertSame(CustomerSubscription::STATUS_PENDING, $row['status']);
            $this->assertSame('pending', $row['payment_status']);
            $this->assertFalse($row['can_pause']);
            $this->assertNotEmpty($row['product']);
            $this->assertNotEmpty($row['plan']);
            $this->assertSame($order->number, $row['order_number']);
        }

        $this->actingAs($stranger, 'customer')
            ->getJson('/api/v1/account/subscriptions')
            ->assertOk()
            ->assertJsonCount(0, 'subscriptions');

        $this->actingAs($stranger, 'customer')
            ->getJson('/api/v1/account/subscriptions/'.$first->id)
            ->assertNotFound();
    }

    public function test_account_list_backfills_pending_rows_from_existing_unpaid_order(): void
    {
        $customer = $this->customer();
        [$order, $first, $second] = $this->pendingTwoProductOrder($customer);
        $firstName = $first->product?->translation()?->name;
        $secondName = $second->product?->translation()?->name;
        CustomerSubscription::query()->whereIn('id', [$first->id, $second->id])->delete();
        $this->assertSame(0, CustomerSubscription::query()->where('order_id', $order->id)->count());

        $list = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/subscriptions')
            ->assertOk()
            ->assertJsonCount(2, 'subscriptions')
            ->json('subscriptions');

        $this->assertSame(2, CustomerSubscription::query()->where('order_id', $order->id)->count());
        foreach ($list as $row) {
            $this->assertSame(CustomerSubscription::STATUS_PENDING, $row['status']);
            $this->assertSame('pending', $row['payment_status']);
            $this->assertSame($order->number, $row['order_number']);
        }
        $this->assertEqualsCanonicalizing([$firstName, $secondName], collect($list)->pluck('product')->all());
    }

    public function test_pending_subscriptions_activate_when_payment_succeeds_without_duplicates(): void
    {
        $customer = $this->customer();
        [$order, $first, $second] = $this->pendingTwoProductOrder($customer);

        $order->updateTrusted([
            'payment_status' => 'paid',
            'amount_paid' => 72,
            'amount_due' => 0,
            'status' => 'processing',
        ]);
        app(SubscriptionBilling::class)->startFromOrder($order->fresh());

        $this->assertSame(2, CustomerSubscription::query()->where('order_id', $order->id)->count());
        $this->assertSame(CustomerSubscription::STATUS_ACTIVE, $first->fresh()->status);
        $this->assertSame(CustomerSubscription::STATUS_ACTIVE, $second->fresh()->status);
        $this->assertSame($first->id, $first->fresh()->id);

        $list = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/subscriptions')
            ->assertOk()
            ->assertJsonCount(2, 'subscriptions')
            ->json('subscriptions');

        foreach ($list as $row) {
            $this->assertSame(CustomerSubscription::STATUS_ACTIVE, $row['status']);
            $this->assertSame('paid', $row['payment_status']);
        }
    }

    public function test_failed_pending_order_cancels_both_subscriptions(): void
    {
        $customer = $this->customer();
        [$order, $first, $second] = $this->pendingTwoProductOrder($customer);

        $order->updateTrusted(['payment_status' => 'failed']);

        $this->assertSame(CustomerSubscription::STATUS_CANCELLED, $first->fresh()->status);
        $this->assertSame(CustomerSubscription::STATUS_CANCELLED, $second->fresh()->status);

        $list = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/subscriptions')
            ->assertOk()
            ->json('subscriptions');

        foreach ($list as $row) {
            $this->assertSame(CustomerSubscription::STATUS_CANCELLED, $row['status']);
            $this->assertSame('failed', $row['payment_status']);
        }
    }

    /**
     * @return array{0: Order, 1: CustomerSubscription, 2: CustomerSubscription}
     */
    private function pendingTwoProductOrder(Customer $customer): array
    {
        $planA = $this->plan('Flexible Unlimited');
        $planB = $this->plan('Biannual Business');
        $firstProduct = $this->subscriptionProduct('CrystalBuds True Wireless Earbuds', 36, $planA);
        $secondProduct = $this->subscriptionProduct('Precision Pro Beard Trimmer', 36, $planB);

        $order = Order::createTrusted([
            'number' => 'ORD-PEND-'.uniqid(),
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'pending',
            'currency_code' => 'USD',
            'subtotal' => 72,
            'grand_total' => 72,
            'payment_status' => 'pending',
            'amount_paid' => 0,
            'amount_due' => 72,
            'billing_address' => ['first_name' => 'A', 'line1' => 'x', 'city' => 'y', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'A', 'line1' => 'x', 'city' => 'y', 'country_code' => 'US'],
        ]);
        $itemA = OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $firstProduct->id,
            'subscription_plan_id' => $planA->id,
            'sku' => $firstProduct->sku,
            'name' => 'CrystalBuds True Wireless Earbuds',
            'qty' => 1,
            'unit_price' => 36,
            'total' => 36,
        ]);
        $itemB = OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $secondProduct->id,
            'subscription_plan_id' => $planB->id,
            'sku' => $secondProduct->sku,
            'name' => 'Precision Pro Beard Trimmer',
            'qty' => 1,
            'unit_price' => 36,
            'total' => 36,
        ]);

        $first = CustomerSubscription::query()->where('order_item_id', $itemA->id)->firstOrFail();
        $second = CustomerSubscription::query()->where('order_item_id', $itemB->id)->firstOrFail();

        return [$order, $first, $second];
    }

    private function subscriptionProduct(string $name, float $price, SubscriptionPlan $plan): Product
    {
        $product = Product::query()->create([
            'type' => 'simple',
            'sku' => 'SUB-'.uniqid(),
            'price' => $price,
            'manage_stock' => false,
            'is_enabled' => true,
            'subscription_enabled' => true,
            'purchase_option' => 'both',
        ]);
        ProductTranslation::query()->create([
            'product_id' => $product->id,
            'language_code' => Language::defaultCode(),
            'name' => $name,
            'slug' => str($name)->slug().'-'.$product->id,
        ]);
        ProductSubscriptionPlan::query()->create([
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'price' => $price,
            'is_enabled' => true,
        ]);

        return $product;
    }

    private function customer(string $email = 'account-sub@example.test'): Customer
    {
        return Customer::factory()->create(['email' => $email]);
    }

    private function plan(string $name = 'Biannual Business'): SubscriptionPlan
    {
        $plan = SubscriptionPlan::query()->create([
            'is_enabled' => true,
            'duration' => 0,
            'cycle' => 1,
            'frequency' => 'month',
        ]);
        SubscriptionPlanTranslation::query()->create([
            'subscription_plan_id' => $plan->id,
            'language_code' => Language::defaultCode(),
            'name' => $name,
        ]);

        return $plan;
    }

    private function subscribePaid(Customer $customer): CustomerSubscription
    {
        $plan = $this->plan();
        $product = Product::query()->create([
            'type' => 'simple',
            'sku' => 'SUB-'.uniqid(),
            'price' => 36,
            'manage_stock' => false,
            'is_enabled' => true,
            'subscription_enabled' => true,
            'purchase_option' => 'both',
        ]);
        ProductTranslation::query()->create([
            'product_id' => $product->id,
            'language_code' => Language::defaultCode(),
            'name' => 'Precision Pro Beard Trimmer',
            'slug' => 'precision-pro-beard-trimmer-'.$product->id,
        ]);
        ProductSubscriptionPlan::query()->create([
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'price' => 36,
            'is_enabled' => true,
        ]);

        $order = Order::createTrusted([
            'number' => 'ORD-SUB-'.uniqid(),
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'processing',
            'currency_code' => 'USD',
            'subtotal' => 36,
            'grand_total' => 36,
            'payment_status' => 'paid',
            'amount_paid' => 36,
            'amount_due' => 0,
            'billing_address' => ['first_name' => 'A', 'line1' => 'x', 'city' => 'y', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'A', 'line1' => 'x', 'city' => 'y', 'country_code' => 'US'],
        ]);
        $item = OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'sku' => $product->sku,
            'name' => 'Precision Pro Beard Trimmer',
            'qty' => 1,
            'unit_price' => 36,
            'total' => 36,
        ]);
        app(SubscriptionBilling::class)->startFromOrder($order->fresh());

        return CustomerSubscription::query()->where('order_item_id', $item->id)->firstOrFail();
    }
}
