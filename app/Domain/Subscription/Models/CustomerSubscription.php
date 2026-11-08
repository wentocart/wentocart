<?php

namespace App\Domain\Subscription\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'customer_id', 'order_id', 'order_item_id', 'product_id', 'subscription_plan_id',
    'status', 'started_at', 'next_renewal_at', 'cancelled_at', 'cycles_completed',
    'last_renewal_order_id', 'dunning_attempts', 'last_dunning_at',
    'trial_ends_at', 'cancel_at_period_end', 'renewal_attempts', 'last_renewal_error',
    'last_pending_action_url', 'ended_at',
])]
class CustomerSubscription extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_TRIALING = 'trialing';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_CANCELLED = 'cancelled';

    /** Statuses the renewal tick should pick up. */
    public const RENEWABLE_STATUSES = [self::STATUS_ACTIVE, self::STATUS_TRIALING];

    /** Dunning gives up after this many failed attempts. */
    public const MAX_DUNNING_ATTEMPTS = 5;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'next_renewal_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'ended_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'last_dunning_at' => 'datetime',
            'dunning_attempts' => 'integer',
            'renewal_attempts' => 'integer',
            'cancel_at_period_end' => 'boolean',
        ];
    }

    /** Currently inside the free trial window. */
    public function onTrial(): bool
    {
        return $this->status === self::STATUS_TRIALING
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isFuture();
    }

    /** Dunning has run out of retries — no further automatic attempts. */
    public function dunningExhausted(): bool
    {
        return (int) $this->dunning_attempts >= self::MAX_DUNNING_ATTEMPTS;
    }

    /** The customer may still resume/pause/skip this subscription. */
    public function isManageable(): bool
    {
        return in_array($this->status, [
            self::STATUS_ACTIVE,
            self::STATUS_TRIALING,
            self::STATUS_PAUSED,
            self::STATUS_PAST_DUE,
        ], true);
    }

    public function canReactivate(): bool
    {
        return $this->status === self::STATUS_CANCELLED && (int) $this->customer_id > 0;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function lastRenewalOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'last_renewal_order_id');
    }

    /**
     * Renewal invoices for this subscription (oldest first).
     *
     * Renewals are not FK-linked; they are tagged with customer_note and a line
     * for the same product. Scoped to this customer and (when known) after start.
     *
     * @return Builder<Order>
     */
    public function renewalOrdersQuery(): Builder
    {
        return Order::query()
            ->where('customer_id', (int) $this->customer_id)
            ->where('customer_note', 'Subscription renewal')
            ->when($this->order_id, fn (Builder $q) => $q->where('id', '!=', (int) $this->order_id))
            ->where(function (Builder $q) {
                $q->whereHas('items', fn (Builder $items) => $items->where('product_id', (int) $this->product_id));
                if ($this->last_renewal_order_id) {
                    $q->orWhere('id', (int) $this->last_renewal_order_id);
                }
            })
            ->when(
                $this->started_at,
                fn (Builder $q) => $q->where(function (Builder $inner) {
                    $inner->where('created_at', '>=', $this->started_at->copy()->subMinute());
                    if ($this->last_renewal_order_id) {
                        $inner->orWhere('id', (int) $this->last_renewal_order_id);
                    }
                }),
            )
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /** @return Collection<int, Order> */
    public function renewalOrders(): Collection
    {
        return $this->renewalOrdersQuery()->get([
            'id', 'number', 'created_at', 'payment_status', 'status', 'grand_total', 'currency_code',
        ]);
    }
}
