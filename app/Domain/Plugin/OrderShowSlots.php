<?php

namespace App\Domain\Plugin;

use App\Domain\Order\Models\Order;
use App\Domain\Plugin\Support\ModuleBuses;

/**
 * View slots on admin order detail. Extensions must return PageDocument blocks
 * (card, table, section, link, button) — never HTML or Inertia.
 */
final class OrderShowSlots
{
    /**
     * Blocks rendered after the Payment card.
     *
     * @return list<array<string, mixed>>
     */
    public static function afterPayment(Order $order): array
    {
        $ctx = [
            'id' => (int) $order->id,
            'number' => (string) $order->number,
        ];

        return [
            ...ModuleBuses::collectSlots('admin.order.show.after_payment', $ctx),
            ...ModuleBuses::collectSlots('admin.order.show', $ctx),
        ];
    }

    /**
     * Buttons rendered in the order header, immediately before More Actions.
     *
     * @return list<array<string, mixed>>
     */
    public static function headerActions(Order $order): array
    {
        return ModuleBuses::collectSlots('admin.order.actions', [
            'id' => (int) $order->id,
            'number' => (string) $order->number,
        ]);
    }
}
