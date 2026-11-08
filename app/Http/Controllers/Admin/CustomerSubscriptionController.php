<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Subscription\Models\CustomerSubscription;
use App\Domain\Subscription\Services\SubscriptionBilling;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Merchant-facing view of actual subscribers.
 *
 * Plans were already manageable; this is the missing half — who is subscribed, what it
 * is worth, and the ability to act on a customer's behalf when support asks.
 */
class CustomerSubscriptionController extends Controller
{
    public function __construct(
        protected SubscriptionBilling $billing,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeSubscriptions('view');

        $status = $request->string('status')->toString() ?: 'all';
        $q = trim($request->string('q')->toString());
        $planId = $request->input('plan_id');
        $sort = $request->string('sort')->toString() ?: 'id';
        $dir = $request->string('dir')->toString() === 'asc' ? 'asc' : 'desc';
        if (! in_array($sort, ['id', 'status', 'next_renewal_at', 'cycles_completed', 'started_at'], true)) {
            $sort = 'id';
        }

        $base = CustomerSubscription::query();
        $counts = [
            'all' => (clone $base)->count(),
            'pending' => (clone $base)->where('status', CustomerSubscription::STATUS_PENDING)->count(),
            'active' => (clone $base)->where('status', CustomerSubscription::STATUS_ACTIVE)->count(),
            'trialing' => (clone $base)->where('status', CustomerSubscription::STATUS_TRIALING)->count(),
            'paused' => (clone $base)->where('status', CustomerSubscription::STATUS_PAUSED)->count(),
            'past_due' => (clone $base)->where('status', CustomerSubscription::STATUS_PAST_DUE)->count(),
            'cancelled' => (clone $base)->where('status', CustomerSubscription::STATUS_CANCELLED)->count(),
        ];

        $rows = CustomerSubscription::query()
            ->with(['customer:id,email,first_name,last_name', 'plan.translations', 'product.translations'])
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($planId !== null && $planId !== '', fn ($query) => $query->where('subscription_plan_id', (int) $planId))
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->whereHas('customer', fn ($c) => $c->where('email', 'like', "%{$q}%")
                        ->orWhere('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%"))
                        ->orWhereHas('plan.translations', fn ($t) => $t->where('name', 'like', "%{$q}%"));
                });
            })
            ->orderBy($sort, $dir)
            ->adminPaginate();

        $rows->through(fn (CustomerSubscription $row) => $this->present($row));

        return Inertia::render('Catalog/Subscriptions/Customers', [
            'subscriptions' => $rows,
            'counts' => $counts,
            'metrics' => $this->metrics(),
            'plans' => \App\Domain\Subscription\Models\SubscriptionPlan::query()
                ->with('translations')
                ->orderBy('id')
                ->get()
                ->map(fn ($plan) => [
                    'id' => $plan->id,
                    'name' => $plan->translation()?->name ?: $plan->code,
                ]),
            'filters' => [
                'status' => $status,
                'q' => $q,
                'plan_id' => $planId !== null && $planId !== '' ? (string) $planId : '',
                'sort' => $sort,
                'dir' => $dir,
            ],
            'can' => [
                'manage' => $this->admin()?->hasPermission('catalog.manage')
                    || $this->admin()?->hasPermission('catalog.subscriptions.update'),
            ],
            // Generic placeholder path — merchants replace with their real app root.
            'renewalScheduler' => [
                'cron' => '* * * * * cd /path/to/wentocart && php artisan schedule:run >> /dev/null 2>&1',
                'verify' => [
                    'php artisan schedule:list',
                    'php artisan wento:subscriptions-tick',
                ],
            ],
        ]);
    }

    public function show(CustomerSubscription $customer_subscription): Response
    {
        $this->authorizeSubscriptions('view');

        $customer_subscription->load([
            'customer:id,email,first_name,last_name',
            'plan.translations',
            'product.translations',
            'order',
            'orderItem',
            'lastRenewalOrder',
        ]);

        $renewalRows = $customer_subscription->renewalOrdersQuery()
            ->limit(50)
            ->get(['id', 'number', 'status', 'payment_status', 'grand_total', 'currency_code', 'created_at']);

        $invoices = [];
        if ($customer_subscription->order) {
            $signup = $customer_subscription->order;
            $invoices[] = [
                'id' => (int) $signup->id,
                'number' => $signup->number,
                'status' => $signup->status,
                'payment_status' => $signup->payment_status,
                'grand_total' => $signup->grand_total,
                'currency_code' => $signup->currency_code,
                'created_at' => optional($signup->created_at)?->toIso8601String(),
                'kind' => 'original',
            ];
        }
        foreach ($renewalRows as $order) {
            $invoices[] = [
                'id' => (int) $order->id,
                'number' => $order->number,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'grand_total' => $order->grand_total,
                'currency_code' => $order->currency_code,
                'created_at' => optional($order->created_at)?->toIso8601String(),
                'kind' => 'renewal',
            ];
        }

        return Inertia::render('Catalog/Subscriptions/CustomerShow', [
            'subscription' => $this->present($customer_subscription, detailed: true),
            'renewals' => $invoices,
            'available_plans' => \App\Domain\Subscription\Models\ProductSubscriptionPlan::query()
                ->where('product_id', $customer_subscription->product_id)
                ->where('is_enabled', true)
                ->with('plan.translations')
                ->get()
                ->filter(fn ($link) => $link->plan && $link->plan->is_enabled)
                ->map(fn ($link) => [
                    'id' => (int) $link->subscription_plan_id,
                    'name' => $link->plan?->translation()?->name ?: $link->plan?->code,
                    'price' => (float) $link->price,
                ])
                ->values()
                ->all(),
            'can' => [
                'manage' => $this->admin()?->hasPermission('catalog.manage')
                    || $this->admin()?->hasPermission('catalog.subscriptions.update'),
            ],
        ]);
    }

    public function cancel(CustomerSubscription $customer_subscription): RedirectResponse
    {
        $this->authorizeSubscriptions('update');
        $this->billing->cancel($customer_subscription, atPeriodEnd: false);

        return back()->with('success', 'Subscription cancelled immediately.');
    }

    public function cancelAtPeriodEnd(CustomerSubscription $customer_subscription): RedirectResponse
    {
        $this->authorizeSubscriptions('update');
        $this->billing->cancel($customer_subscription, atPeriodEnd: true);

        return back()->with('success', 'Subscription will end after the current period.');
    }

    public function resumePeriodEnd(CustomerSubscription $customer_subscription): RedirectResponse
    {
        $this->authorizeSubscriptions('update');
        $this->billing->resumeCancelAtPeriodEnd($customer_subscription);

        return back()->with('success', 'Cancel at period end cleared.');
    }

    public function pause(CustomerSubscription $customer_subscription): RedirectResponse
    {
        $this->authorizeSubscriptions('update');
        $this->billing->pause($customer_subscription);

        return back()->with('success', 'Subscription paused.');
    }

    public function resume(CustomerSubscription $customer_subscription): RedirectResponse
    {
        $this->authorizeSubscriptions('update');
        $this->billing->resume($customer_subscription);

        return back()->with('success', 'Subscription resumed.');
    }

    public function skip(CustomerSubscription $customer_subscription): RedirectResponse
    {
        $this->authorizeSubscriptions('update');
        $this->billing->skip($customer_subscription);

        return back()->with('success', 'Skipped one billing cycle.');
    }

    /**
     * Force a renewal attempt now. Support uses this after a customer fixes their card.
     */
    public function retry(CustomerSubscription $customer_subscription): RedirectResponse
    {
        $this->authorizeSubscriptions('update');

        $customer_subscription->forceFill([
            'status' => CustomerSubscription::STATUS_ACTIVE,
            'next_renewal_at' => now()->subMinute(),
            'dunning_attempts' => 0,
            'renewal_attempts' => 0,
        ])->save();

        $this->billing->renewOne((int) $customer_subscription->id);

        $fresh = $customer_subscription->fresh();

        return back()->with(
            $fresh?->status === CustomerSubscription::STATUS_ACTIVE ? 'success' : 'error',
            $fresh?->status === CustomerSubscription::STATUS_ACTIVE
                ? 'Renewal charged successfully.'
                : 'Renewal could not be charged: '.($fresh?->last_renewal_error ?: 'unknown reason'),
        );
    }

    public function reactivate(CustomerSubscription $customer_subscription): RedirectResponse
    {
        $this->authorizeSubscriptions('update');
        $this->billing->reactivate($customer_subscription);

        return back()->with('success', 'Subscription reactivated.');
    }

    public function changePlan(Request $request, CustomerSubscription $customer_subscription): RedirectResponse
    {
        $this->authorizeSubscriptions('update');
        $data = $request->validate([
            'subscription_plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
        ]);
        $this->billing->changePlan($customer_subscription, (int) $data['subscription_plan_id']);

        return back()->with('success', 'Plan updated. New price applies on the next renewal.');
    }

    public function setTrial(Request $request, CustomerSubscription $customer_subscription): RedirectResponse
    {
        $this->authorizeSubscriptions('update');
        $data = $request->validate([
            'trial_ends_at' => ['nullable', 'date'],
        ]);
        $until = ! empty($data['trial_ends_at']) ? \Carbon\Carbon::parse($data['trial_ends_at']) : null;
        $this->billing->setTrialEndsAt($customer_subscription, $until);

        return back()->with('success', $until ? 'Trial updated.' : 'Trial cleared.');
    }

    /**
     * Headline numbers a merchant actually asks for.
     *
     * @return array<string, mixed>
     */
    protected function metrics(): array
    {
        $activeStatuses = [
            CustomerSubscription::STATUS_ACTIVE,
            CustomerSubscription::STATUS_TRIALING,
        ];

        $active = CustomerSubscription::query()
            ->whereIn('status', $activeStatuses)
            ->with(['plan', 'orderItem'])
            ->get();

        // Normalise every plan to a monthly figure so MRR is comparable across cycles.
        $mrr = 0.0;
        foreach ($active as $row) {
            $mrr += $this->monthlyValue($row);
        }

        $trialing = $active->where('status', CustomerSubscription::STATUS_TRIALING)->count();

        return [
            'active' => $active->count(),
            'trialing' => $trialing,
            'past_due' => CustomerSubscription::query()->where('status', CustomerSubscription::STATUS_PAST_DUE)->count(),
            'cancelled' => CustomerSubscription::query()->where('status', CustomerSubscription::STATUS_CANCELLED)->count(),
            'mrr' => round($mrr, 2),
            'currency' => \App\Domain\Localization\Money::defaultCode(),
        ];
    }

    protected function monthlyValue(CustomerSubscription $row): float
    {
        $plan = $row->plan;
        if (! $plan) {
            return 0.0;
        }

        // Price lives on the product↔plan link, not the plan itself — the same plan can
        // cost different amounts on different products.
        $price = (float) (\App\Domain\Subscription\Models\ProductSubscriptionPlan::query()
            ->where('product_id', $row->product_id)
            ->where('subscription_plan_id', $row->subscription_plan_id)
            ->value('price') ?? 0);

        // Fall back to what the customer is actually paying on their order when the
        // product↔plan link has been removed or repriced since they subscribed. Without
        // this, live subscribers would silently drop out of MRR.
        if ($price <= 0) {
            $price = (float) ($row->orderItem?->unit_price ?? 0);
        }

        if ($price <= 0) {
            return 0.0;
        }

        $cycle = max(1, (int) ($plan->cycle ?: 1));

        return match ($plan->frequency ?: 'month') {
            'day' => $price * $cycle * 30,
            'week' => $price * $cycle * 4.345,
            'year' => ($price * $cycle) / 12,
            default => $price * $cycle,
        };
    }

    /** @return array<string, mixed> */
    protected function present(CustomerSubscription $row, bool $detailed = false): array
    {
        $customer = $row->customer;
        $name = $customer
            ? trim(($customer->first_name ?? '').' '.($customer->last_name ?? '')) ?: $customer->email
            : null;

        $data = [
            'id' => $row->id,
            'status' => $row->status,
            'customer_id' => $row->customer_id,
            'customer_name' => $name,
            'customer_email' => $customer?->email,
            'plan_id' => $row->subscription_plan_id,
            'plan_name' => $row->plan?->translation()?->name ?: $row->plan?->code,
            'product_id' => $row->product_id,
            'product_name' => $row->product?->translation()?->name ?: $row->product?->sku,
            'started_at' => $row->started_at?->toIso8601String(),
            'trial_ends_at' => $row->trial_ends_at?->toIso8601String(),
            'on_trial' => $row->onTrial(),
            'next_renewal_at' => $row->next_renewal_at?->toIso8601String(),
            'cancelled_at' => $row->cancelled_at?->toIso8601String(),
            'cancel_at_period_end' => (bool) $row->cancel_at_period_end,
            'cycles_completed' => (int) $row->cycles_completed,
            'plan_duration' => (int) ($row->plan?->duration ?? 0),
            'dunning_attempts' => (int) $row->dunning_attempts,
            'renewal_attempts' => (int) $row->renewal_attempts,
            'last_renewal_error' => $row->last_renewal_error,
            'dunning_exhausted' => $row->dunningExhausted(),
            'last_pending_action_url' => $row->last_pending_action_url,
            'monthly_value' => round($this->monthlyValue($row), 2),
            'is_manageable' => $row->isManageable(),
            'can_reactivate' => $row->canReactivate(),
            'url' => '/admin/customer-subscriptions/'.$row->id,
        ];

        if ($detailed) {
            $plan = $row->plan;
            $item = $row->orderItem;
            $order = $row->order;
            $svc = app(\App\Domain\Subscription\Services\SubscriptionService::class);
            $cycle = max(1, (int) ($plan?->cycle ?? 1));
            $frequency = (string) ($plan?->frequency ?: 'month');
            $price = (float) (\App\Domain\Subscription\Models\ProductSubscriptionPlan::query()
                ->where('product_id', $row->product_id)
                ->where('subscription_plan_id', $row->subscription_plan_id)
                ->value('price') ?? 0);
            if ($price <= 0 && $item && $item->unit_price !== null) {
                $price = (float) $item->unit_price;
            }

            $data['payment_status'] = $this->presentPaymentStatus($row, $order);
            $data['price'] = $price > 0 ? $price : null;
            $data['currency_code'] = $order?->currency_code;
            $data['qty'] = $item?->qty !== null ? (int) $item->qty : null;
            $data['billing_summary'] = $plan ? $svc->billingLabel($cycle, $frequency) : null;
            $data['trial_summary'] = $plan ? $svc->trialSummary($plan) : null;
            $data['duration_summary'] = $plan ? $svc->durationSummary($plan) : null;
            $data['ended_at'] = $row->ended_at?->toIso8601String();
            $data['product_cover'] = \App\Domain\Catalog\Support\CatalogMediaUrl::url($row->product?->cover_path);
            $data['product_url'] = $row->product_id ? '/admin/products/'.$row->product_id.'/edit' : null;
            $data['customer_url'] = $row->customer_id ? '/admin/customers/'.$row->customer_id : null;
            $data['order'] = $order ? [
                'id' => $order->id,
                'number' => $order->number,
                'url' => '/admin/orders/'.$order->id,
            ] : null;
            $data['last_renewal_order'] = $row->lastRenewalOrder ? [
                'id' => $row->lastRenewalOrder->id,
                'number' => $row->lastRenewalOrder->number,
                'url' => '/admin/orders/'.$row->lastRenewalOrder->id,
            ] : null;
            $data['last_renewal_order_id'] = $row->last_renewal_order_id;
            $data['can_skip'] = in_array($row->status, [
                CustomerSubscription::STATUS_ACTIVE,
                CustomerSubscription::STATUS_PAUSED,
                CustomerSubscription::STATUS_TRIALING,
            ], true);
        }

        return $data;
    }

    protected function presentPaymentStatus(CustomerSubscription $row, ?\App\Domain\Order\Models\Order $order): string
    {
        if ($row->status === CustomerSubscription::STATUS_PENDING) {
            return 'pending';
        }
        if ($row->status === CustomerSubscription::STATUS_PAST_DUE) {
            return 'failed';
        }
        $pay = $order?->payment_status;
        if (in_array($pay, ['failed', 'refunded'], true)) {
            return 'failed';
        }
        if ($pay === 'paid') {
            return 'paid';
        }

        return 'pending';
    }

    protected function authorizeSubscriptions(string $ability): void
    {
        $admin = $this->admin();
        $map = [
            'view' => 'catalog.subscriptions.view',
            'update' => 'catalog.subscriptions.update',
        ];
        $slug = $map[$ability] ?? 'catalog.subscriptions.view';

        if ($admin && ($admin->hasPermission('catalog.manage') || $admin->hasPermission($slug))) {
            return;
        }

        abort(403, 'You do not have permission to manage customer subscriptions.');
    }

    protected function admin(): ?Admin
    {
        return auth('admin')->user();
    }
}
