<?php

namespace App\Domain\Subscription\Services;

use App\Domain\Order\Models\Order;
use App\Domain\Order\Services\OrderNumberService;
use App\Domain\Payment\SavedChargeResult;
use App\Domain\Payment\Services\SavedMethodCharger;
use App\Domain\Settings\MailEvents;
use App\Domain\Settings\ShopMailer;
use App\Domain\Subscription\Models\CustomerSubscription;
use App\Domain\Subscription\Models\ProductSubscriptionPlan;
use App\Domain\Subscription\Models\SubscriptionPlan;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubscriptionBilling
{
    public function __construct(
        protected ?SavedMethodCharger $charger = null,
        protected ?SubscriptionService $subscriptions = null,
    ) {
        $this->charger ??= app(SavedMethodCharger::class);
        $this->subscriptions ??= app(SubscriptionService::class);
    }

    /**
     * Sync subscriptions from a placed order. Unpaid signup orders create pending
     * rows so the customer can see them; a later paid transition activates the
     * same rows (idempotent, never duplicates per order item).
     */
    public function startFromOrder(Order $order): void
    {
        if (! $order->customer_id) {
            return;
        }
        // Renewal invoices carry subscription_plan_id on lines but must not spawn new rows.
        if (($order->customer_note ?? '') === 'Subscription renewal') {
            return;
        }
        $details = is_array($order->payment_details) ? $order->payment_details : [];
        if (! empty($details['subscription_renewal'])) {
            return;
        }

        $closed = in_array($order->payment_status, ['failed', 'refunded'], true)
            || $order->status === 'cancelled';
        if ($closed) {
            $this->handleSignupOrderClosed($order);

            return;
        }

        $paid = $order->payment_status === 'paid';
        $order->loadMissing(['items.subscriptionPlan', 'items.product']);
        foreach ($order->items as $item) {
            if (! $item->subscription_plan_id) {
                continue;
            }
            $row = CustomerSubscription::query()->where('order_item_id', $item->id)->first();
            $plan = $item->subscriptionPlan;
            if (! $row) {
                $trialEnds = $paid ? $this->trialEndsAt($plan) : null;
                CustomerSubscription::query()->create([
                    'customer_id' => $order->customer_id,
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'subscription_plan_id' => $item->subscription_plan_id,
                    'status' => $paid
                        ? ($trialEnds ? CustomerSubscription::STATUS_TRIALING : CustomerSubscription::STATUS_ACTIVE)
                        : CustomerSubscription::STATUS_PENDING,
                    'started_at' => $paid ? now() : null,
                    'trial_ends_at' => $trialEnds,
                    'next_renewal_at' => $paid ? ($trialEnds ?: $this->nextAt($plan)) : null,
                    'cycles_completed' => 0,
                    'cancel_at_period_end' => false,
                ]);

                continue;
            }
            if ($paid && $row->status === CustomerSubscription::STATUS_PENDING) {
                $this->activatePendingSignup($row, $plan);
            }
        }
    }

    /**
     * Create pending signup rows for subscription order items that were placed
     * before unpaid checkout started writing customer_subscriptions.
     */
    public function syncSignupOrdersForCustomer(int $customerId): void
    {
        if ($customerId < 1) {
            return;
        }

        $linkedItemIds = CustomerSubscription::query()
            ->where('customer_id', $customerId)
            ->whereNotNull('order_item_id')
            ->pluck('order_item_id');

        $orderIds = \App\Domain\Order\Models\OrderItem::query()
            ->whereNotNull('subscription_plan_id')
            ->where('subscription_plan_id', '>', 0)
            ->whereHas('order', fn ($q) => $q->where('customer_id', $customerId))
            ->when($linkedItemIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $linkedItemIds))
            ->pluck('order_id')
            ->unique()
            ->filter()
            ->all();

        if ($orderIds === []) {
            return;
        }

        Order::query()->with(['items.subscriptionPlan', 'items.product'])->whereIn('id', $orderIds)->get()
            ->each(fn (Order $order) => $this->startFromOrder($order));
    }

    protected function activatePendingSignup(CustomerSubscription $row, ?SubscriptionPlan $plan): void
    {
        $trialEnds = $this->trialEndsAt($plan);
        $row->update([
            'status' => $trialEnds ? CustomerSubscription::STATUS_TRIALING : CustomerSubscription::STATUS_ACTIVE,
            'started_at' => now(),
            'trial_ends_at' => $trialEnds,
            'next_renewal_at' => $trialEnds ?: $this->nextAt($plan),
            'cancelled_at' => null,
            'ended_at' => null,
        ]);
    }

    /**
     * When a signup order is refunded or cancelled, end any subscriptions it created.
     */
    public function handleSignupOrderClosed(Order $order): void
    {
        $rows = CustomerSubscription::query()
            ->where('order_id', $order->id)
            ->where('status', '!=', CustomerSubscription::STATUS_CANCELLED)
            ->get();

        foreach ($rows as $row) {
            $this->cancel($row, atPeriodEnd: false);
        }
    }

    /**
     * When a plan offers a trial, the date it ends. Null when there is no trial.
     *
     * A trial with no duration is treated as no trial — otherwise a misconfigured plan
     * would silently grant free service forever.
     */
    protected function trialEndsAt(?SubscriptionPlan $plan): ?CarbonInterface
    {
        if (! $plan || ! $plan->trial_enabled) {
            return null;
        }

        $duration = (int) ($plan->trial_duration ?: 0);
        if ($duration <= 0) {
            return null;
        }

        $cycle = max(1, (int) ($plan->trial_cycle ?: 1));
        $freq = $plan->trial_frequency ?: 'day';

        return match ($freq) {
            'hour' => now()->addHours($duration * $cycle),
            'week' => now()->addWeeks($duration * $cycle),
            'month' => now()->addMonths($duration * $cycle),
            'year' => now()->addYears($duration * $cycle),
            default => now()->addDays($duration * $cycle),
        };
    }

    /**
     * @param  bool  $atPeriodEnd  When true, keep access until the paid period ends.
     */
    public function cancel(CustomerSubscription $row, bool $atPeriodEnd = false): CustomerSubscription
    {
        if ($row->status === CustomerSubscription::STATUS_CANCELLED) {
            return $row;
        }

        if ($atPeriodEnd) {
            if (! in_array($row->status, [
                CustomerSubscription::STATUS_ACTIVE,
                CustomerSubscription::STATUS_TRIALING,
                CustomerSubscription::STATUS_PAUSED,
                CustomerSubscription::STATUS_PAST_DUE,
            ], true)) {
                return $row;
            }
            $row->update(['cancel_at_period_end' => true]);

            return $row->fresh();
        }

        $row->update([
            'status' => CustomerSubscription::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'ended_at' => now(),
            'cancel_at_period_end' => false,
            'next_renewal_at' => null,
        ]);

        return $row->fresh();
    }

    public function resumeCancelAtPeriodEnd(CustomerSubscription $row): CustomerSubscription
    {
        if ($row->status === CustomerSubscription::STATUS_CANCELLED) {
            return $row;
        }
        if ($row->cancel_at_period_end) {
            $row->update(['cancel_at_period_end' => false]);
        }

        return $row->fresh();
    }

    public function pause(CustomerSubscription $row): CustomerSubscription
    {
        if (! in_array($row->status, [
            CustomerSubscription::STATUS_ACTIVE,
            CustomerSubscription::STATUS_TRIALING,
        ], true)) {
            return $row;
        }
        $row->update(['status' => CustomerSubscription::STATUS_PAUSED]);

        return $row->fresh();
    }

    public function resume(CustomerSubscription $row): CustomerSubscription
    {
        if ($row->status !== CustomerSubscription::STATUS_PAUSED) {
            return $row;
        }
        $row->loadMissing('plan');
        $status = ($row->trial_ends_at && $row->trial_ends_at->isFuture())
            ? CustomerSubscription::STATUS_TRIALING
            : CustomerSubscription::STATUS_ACTIVE;
        $row->update(['status' => $status]);

        return $row->fresh();
    }

    public function skip(CustomerSubscription $row): CustomerSubscription
    {
        if (! in_array($row->status, [
            CustomerSubscription::STATUS_ACTIVE,
            CustomerSubscription::STATUS_PAUSED,
            CustomerSubscription::STATUS_TRIALING,
        ], true)) {
            return $row;
        }
        $row->loadMissing('plan');
        $from = $row->next_renewal_at && $row->next_renewal_at->isFuture()
            ? $row->next_renewal_at
            : now();
        $row->update([
            'next_renewal_at' => $this->nextAt($row->plan, $from),
        ]);

        return $row->fresh();
    }

    /**
     * Re-open a cancelled subscription for another billing cycle.
     */
    public function reactivate(CustomerSubscription $row): CustomerSubscription
    {
        if ($row->status !== CustomerSubscription::STATUS_CANCELLED) {
            return $row;
        }
        if (! $row->customer_id) {
            throw ValidationException::withMessages([
                'subscription' => 'This subscription has no customer and cannot be reactivated.',
            ]);
        }
        $row->loadMissing('plan');
        if (! $row->plan || ! $row->plan->is_enabled) {
            throw ValidationException::withMessages([
                'subscription' => 'The subscription plan is no longer available.',
            ]);
        }

        $row->update([
            'status' => CustomerSubscription::STATUS_ACTIVE,
            'cancelled_at' => null,
            'ended_at' => null,
            'cancel_at_period_end' => false,
            'dunning_attempts' => 0,
            'renewal_attempts' => 0,
            'last_renewal_error' => null,
            'last_pending_action_url' => null,
            'last_dunning_at' => null,
            'trial_ends_at' => null,
            'next_renewal_at' => $this->nextAt($row->plan),
        ]);

        return $row->fresh();
    }

    /**
     * Change plan effective at the next renewal (no mid-cycle proration charge).
     * Future renewal orders use the new plan's product assignment price.
     */
    public function changePlan(CustomerSubscription $row, int $newPlanId): CustomerSubscription
    {
        if (! in_array($row->status, [
            CustomerSubscription::STATUS_ACTIVE,
            CustomerSubscription::STATUS_TRIALING,
            CustomerSubscription::STATUS_PAUSED,
            CustomerSubscription::STATUS_PAST_DUE,
        ], true)) {
            throw ValidationException::withMessages([
                'subscription_plan_id' => 'Only an active subscription can change plan.',
            ]);
        }

        if ((int) $row->subscription_plan_id === $newPlanId) {
            return $row;
        }

        $link = ProductSubscriptionPlan::query()
            ->where('product_id', $row->product_id)
            ->where('subscription_plan_id', $newPlanId)
            ->where('is_enabled', true)
            ->first();

        if (! $link) {
            throw ValidationException::withMessages([
                'subscription_plan_id' => 'That plan is not available for this product.',
            ]);
        }

        $plan = SubscriptionPlan::query()->whereKey($newPlanId)->where('is_enabled', true)->first();
        if (! $plan) {
            throw ValidationException::withMessages([
                'subscription_plan_id' => 'That subscription plan is not available.',
            ]);
        }

        $row->loadMissing(['orderItem', 'product']);
        $price = $this->subscriptions->assignmentPrice($row->product, $plan);
        $qty = max(1, (float) ($row->orderItem?->qty ?: 1));

        return DB::transaction(function () use ($row, $plan, $price, $qty) {
            $locked = CustomerSubscription::query()->lockForUpdate()->findOrFail($row->id);
            $locked->update(['subscription_plan_id' => $plan->id]);

            if ($locked->orderItem) {
                $locked->orderItem->update([
                    'subscription_plan_id' => $plan->id,
                    'unit_price' => $price,
                    'total' => round($price * $qty, 2),
                ]);
            }

            return $locked->fresh(['plan', 'orderItem']);
        });
    }

    /**
     * Admin: move or clear the trial end (and next renewal when still trialing).
     */
    public function setTrialEndsAt(CustomerSubscription $row, ?CarbonInterface $until): CustomerSubscription
    {
        if ($row->status === CustomerSubscription::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'trial_ends_at' => 'Cannot change the trial on a cancelled subscription.',
            ]);
        }

        if ($until === null) {
            $row->update([
                'trial_ends_at' => null,
                'status' => $row->status === CustomerSubscription::STATUS_TRIALING
                    ? CustomerSubscription::STATUS_ACTIVE
                    : $row->status,
            ]);

            return $row->fresh();
        }

        $row->update([
            'trial_ends_at' => $until,
            'status' => $until->isFuture()
                ? CustomerSubscription::STATUS_TRIALING
                : CustomerSubscription::STATUS_ACTIVE,
            'next_renewal_at' => $until->isFuture() ? $until : ($row->next_renewal_at ?: $this->nextAt($row->plan)),
        ]);

        return $row->fresh();
    }

    public function tick(int $limit = 50): int
    {
        $due = CustomerSubscription::query()
            // Trials renew too: the first tick after a trial ends is the first real charge.
            ->whereIn('status', CustomerSubscription::RENEWABLE_STATUSES)
            ->whereNotNull('next_renewal_at')
            ->where('next_renewal_at', '<=', now())
            ->with(['customer', 'order.items', 'orderItem', 'plan', 'product'])
            ->limit($limit)
            ->pluck('id');

        $n = 0;
        foreach ($due as $id) {
            if ($this->renewOne((int) $id)) {
                $n++;
            }
        }

        $n += $this->retryPastDue($limit);

        return $n;
    }

    /**
     * Renew a single subscription, isolated and locked.
     *
     * The row lock is what stops two overlapping cron runs (or a cron run and a manual
     * admin retry) from charging the same customer twice for the same period.
     */
    public function renewOne(int $id): bool
    {
        return (bool) DB::transaction(function () use ($id) {
            $row = CustomerSubscription::query()->lockForUpdate()->find($id);
            if (! $row) {
                return false;
            }

            // Re-check under the lock: another worker may have just renewed it.
            if (! in_array($row->status, CustomerSubscription::RENEWABLE_STATUSES, true)) {
                return false;
            }
            if (! $row->next_renewal_at || $row->next_renewal_at->isFuture()) {
                return false;
            }

            // Anchor the period to the scheduled due time (not wall-clock processing time)
            // so a late cron cannot permanently shift the billing cadence.
            $dueAt = $row->next_renewal_at;
            $periodKey = $dueAt->format('Y-m-d-H-i');
            $attempt = (int) $row->renewal_attempts + 1;

            $renewal = $this->createRenewalOrder($row);
            $charge = $this->chargeRenewal($row, $renewal, $periodKey, $attempt);

            $cyclesAfter = (int) $row->cycles_completed + 1;
            $duration = (int) ($row->plan?->duration ?? 0);
            $endedByDuration = $duration > 0 && $cyclesAfter >= $duration;
            $shouldEnd = $row->cancel_at_period_end || $endedByDuration;

            // Cancel-at-period-end or finite plan duration: final paid period.
            if ($charge->isPaid() && $shouldEnd) {
                $row->update([
                    'status' => CustomerSubscription::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                    'ended_at' => now(),
                    'last_renewal_order_id' => $renewal?->id,
                    'cycles_completed' => $cyclesAfter,
                    'next_renewal_at' => null,
                    'renewal_attempts' => 0,
                    'dunning_attempts' => 0,
                    'last_renewal_error' => null,
                    'last_pending_action_url' => null,
                    'trial_ends_at' => null,
                    'cancel_at_period_end' => false,
                ]);

                return true;
            }

            if ($charge->isPaid()) {
                $row->update([
                    'status' => CustomerSubscription::STATUS_ACTIVE,
                    'last_renewal_order_id' => $renewal?->id,
                    'cycles_completed' => $cyclesAfter,
                    'next_renewal_at' => $this->nextAt($row->plan, $dueAt),
                    'renewal_attempts' => 0,
                    'dunning_attempts' => 0,
                    'last_dunning_at' => null,
                    'last_renewal_error' => null,
                    'last_pending_action_url' => null,
                    'trial_ends_at' => null,
                ]);

                return true;
            }

            // Charge did not complete. The customer still owes this period, so the
            // renewal date stays put until dunning succeeds or gives up.
            $failed = $attempt >= CustomerSubscription::MAX_DUNNING_ATTEMPTS;

            $row->update([
                'status' => $failed ? CustomerSubscription::STATUS_CANCELLED : CustomerSubscription::STATUS_PAST_DUE,
                'last_renewal_order_id' => $renewal?->id,
                'renewal_attempts' => $attempt,
                'dunning_attempts' => (int) $row->dunning_attempts + 1,
                'last_dunning_at' => now(),
                'last_renewal_error' => $charge->message,
                'last_pending_action_url' => $charge->redirectUrl,
                'cancelled_at' => $failed ? now() : $row->cancelled_at,
                'ended_at' => $failed ? now() : $row->ended_at,
                'trial_ends_at' => null,
            ]);

            $this->sendRenewalFailedEmail($row, $renewal, $charge);

            return true;
        });
    }

    /**
     * Charge a renewal through the customer's saved payment method.
     */
    protected function chargeRenewal(CustomerSubscription $row, ?Order $renewal, string $periodKey, int $attempt): SavedChargeResult
    {
        if (! $renewal) {
            return SavedChargeResult::failed('No renewal order could be created.');
        }

        // Offline/manual gateways are not charged automatically — the merchant takes the
        // payment. Treat as needing payment rather than a hard failure.
        if (! SavedMethodCharger::enabled()) {
            return SavedChargeResult::failed('Automatic charging is disabled for this store.');
        }

        $key = $this->charger->idempotencyKey('subscription', $row->id, $periodKey, $attempt);

        $result = $this->charger->charge($renewal, $key);
        if ($result instanceof SavedChargeResult) {
            if ($result->isPaid()) {
                $this->markRenewalPaid($renewal, $result);
            }

            return $result;
        }

        return SavedChargeResult::failed('No saved payment method is available for this subscription.');
    }

    protected function markRenewalPaid(Order $renewal, SavedChargeResult $result): void
    {
        $renewal->updateTrusted([
            'status' => 'processing',
            'payment_status' => 'paid',
            'amount_paid' => $renewal->grand_total,
            'amount_due' => 0,
            'payment_details' => array_merge(
                is_array($renewal->payment_details) ? $renewal->payment_details : [],
                ['subscription_renewal' => true, 'meta' => $result->meta],
            ),
        ]);
        $renewal->statusHistories()->create(['status' => 'processing', 'comment' => 'Subscription renewal paid']);
    }

    protected function sendRenewalFailedEmail(CustomerSubscription $row, ?Order $renewal, SavedChargeResult $charge): void
    {
        $payUrl = rtrim((string) config('app.url'), '/').'/account/orders/'.($renewal?->number ?: '');

        // An SCA challenge is actionable — send the customer straight to it.
        if ($charge->redirectUrl) {
            $payUrl = $charge->redirectUrl;
        } elseif ($renewal) {
            $payUrl = $this->renewalPayUrl($renewal) ?: $payUrl;
        }

        $email = $row->customer?->email ?: $row->order?->email;
        if (! $email) {
            return;
        }

        app(ShopMailer::class)->sendRecipient(
            MailEvents::SUBSCRIPTION_RENEWAL,
            'customer',
            [
                'customer_name' => $row->customer?->name ?: 'Customer',
                'plan_name' => $row->plan?->translation()?->name ?: 'Subscription',
                'product_name' => $row->product?->translation()?->name ?: $row->product?->sku,
                'renewal_url' => $payUrl,
                'pay_url' => $payUrl,
                'renewal_order' => $renewal?->number,
                'failure_reason' => $charge->message,
            ],
            $email,
        );
    }

    /**
     * Retry subscriptions whose renewal charge failed.
     *
     * Bounded on purpose: after MAX_DUNNING_ATTEMPTS the subscription is cancelled rather
     * than left `past_due` forever, which is what previously produced an infinite
     * "please pay" email loop.
     */
    protected function retryPastDue(int $limit): int
    {
        $retries = CustomerSubscription::query()
            ->where('status', CustomerSubscription::STATUS_PAST_DUE)
            ->where('dunning_attempts', '>', 0)
            ->where('dunning_attempts', '<', CustomerSubscription::MAX_DUNNING_ATTEMPTS)
            ->where(function ($q) {
                $q->whereNull('last_dunning_at')
                    ->orWhere('last_dunning_at', '<=', now()->subDays(3));
            })
            ->limit($limit)
            ->pluck('id');

        $n = 0;
        foreach ($retries as $id) {
            // Retry is a real charge attempt, so it goes through the same locked path.
            $row = CustomerSubscription::query()->find($id);
            if (! $row) {
                continue;
            }
            $row->forceFill([
                'status' => CustomerSubscription::STATUS_ACTIVE,
                'next_renewal_at' => now()->subMinute(),
            ])->save();

            if ($this->renewOne((int) $id)) {
                $n++;
            }
        }

        return $n;
    }

    protected function createRenewalOrder(CustomerSubscription $row): ?Order
    {
        $source = $row->order;
        $item = $row->orderItem;
        if (! $source || ! $item) {
            $row->loadMissing(['order.items', 'orderItem']);
            $source = $row->order;
            $item = $row->orderItem ?: $source?->items->first();
        }
        if (! $source || ! $item) {
            return null;
        }

        $order = Order::createTrusted([
            'number' => app(OrderNumberService::class)->next(),
            'customer_id' => $row->customer_id ?: $source->customer_id,
            'email' => $source->email,
            'status' => 'pending',
            'currency_code' => $source->currency_code,
            'currency_rate' => $source->currency_rate,
            'subtotal' => $item->total,
            'tax_total' => $item->tax_amount,
            'tax_details' => $source->tax_details,
            'shipping_total' => 0,
            'discount_total' => 0,
            'fee_total' => 0,
            'grand_total' => round((float) $item->total + (float) $item->tax_amount, 2),
            'payment_method_id' => $source->payment_method_id,
            'shipping_method_id' => null,
            'payment_status' => 'pending',
            'billing_address' => $source->billing_address,
            'shipping_address' => $source->shipping_address,
            'customer_note' => 'Subscription renewal',
            'store_id' => $source->store_id,
        ]);
        $order->items()->create([
            'product_id' => $item->product_id,
            'tax_class_id' => $item->tax_class_id,
            'subscription_plan_id' => $row->subscription_plan_id,
            'sku' => $item->sku,
            'name' => $item->name,
            'qty' => $item->qty,
            'unit_price' => $item->unit_price,
            'tax_rate' => $item->tax_rate,
            'tax_amount' => $item->tax_amount,
            'total' => $item->total,
        ]);
        $order->statusHistories()->create(['status' => 'pending', 'comment' => 'Subscription renewal invoice']);

        return $order;
    }

    protected function renewalPayUrl(Order $order): ?string
    {
        $order->loadMissing('paymentMethod');
        $driver = strtolower((string) ($order->paymentMethod?->driver ?: 'offline'));
        if ($driver === '' || $driver === 'offline') {
            return rtrim((string) config('app.url'), '/').'/account/orders/'.$order->number;
        }
        try {
            $result = app(\App\Domain\Payment\PaymentGatewayRegistry::class)->get($driver)->capture($order);
            if ($result->redirectUrl) {
                return $result->redirectUrl;
            }
        } catch (\Throwable) {
        }

        return rtrim((string) config('app.url'), '/').'/pay/'.$driver.'/'.$order->number;
    }

    /**
     * Next due time = one billing interval after $from.
     *
     * Pass the previous scheduled due time (not now()) after a renewal so late
     * scheduler runs do not accumulate drift.
     */
    protected function nextAt(?SubscriptionPlan $plan, ?CarbonInterface $from = null): CarbonInterface
    {
        $cycle = max(1, (int) ($plan?->cycle ?: 1));
        $freq = $plan?->frequency ?: 'month';
        $base = $from ? $from->copy() : now();

        return match ($freq) {
            'hour' => $base->addHours($cycle),
            'day' => $base->addDays($cycle),
            'week' => $base->addWeeks($cycle),
            'year' => $base->addYears($cycle),
            default => $base->addMonths($cycle),
        };
    }
}
