<?php

namespace App\Domain\Order;

use App\Domain\Order\Models\OrderItem;
use App\Domain\Subscription\Services\SubscriptionBilling;

class OrderItemObserver
{
    public function created(OrderItem $item): void
    {
        if (! $item->subscription_plan_id) {
            return;
        }

        $order = $item->relationLoaded('order') ? $item->order : $item->order()->first();
        if (! $order) {
            return;
        }

        app(SubscriptionBilling::class)->startFromOrder($order);
    }
}
