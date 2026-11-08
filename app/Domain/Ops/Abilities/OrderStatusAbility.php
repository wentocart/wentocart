<?php

namespace App\Domain\Ops\Abilities;

use App\Domain\Ops\Ability;
use App\Domain\Order\Models\Order;
use InvalidArgumentException;

final class OrderStatusAbility implements Ability
{
    /** @var list<string> */
    public const STATUSES = ['pending', 'processing', 'shipped', 'delivered', 'completed', 'cancelled', 'refunded', 'on_hold'];

    public function code(): string
    {
        return 'orders.status.update';
    }

    public function label(): string
    {
        return 'Update order status';
    }

    public function permission(): string
    {
        return 'orders.manage';
    }

    public function plan(array $input): array
    {
        $order = Order::query()->find((int) ($input['id'] ?? 0));
        if (! $order) {
            throw new InvalidArgumentException('Order not found.');
        }
        $status = strtolower(trim((string) ($input['status'] ?? $input['fields']['status'] ?? '')));
        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Invalid order status.');
        }

        return [[
            'entity_type' => 'order',
            'entity_id' => (int) $order->id,
            'action' => 'update',
            'label' => (string) ($order->number ?: '#'.$order->id),
            'before' => ['status' => (string) $order->status],
            'after' => ['status' => $status],
            'reversible' => true,
        ]];
    }

    public function execute(array $item, array $input): array
    {
        $order = Order::query()->findOrFail((int) $item['entity_id']);
        $order->status = (string) ($item['after']['status'] ?? $order->status);
        $order->save();

        return ['status' => (string) $order->status, 'id' => (int) $order->id];
    }

    public function rollback(array $item): void
    {
        $order = Order::query()->find((int) $item['entity_id']);
        if (! $order) {
            return;
        }
        $order->status = (string) ($item['before']['status'] ?? $order->status);
        $order->save();
    }

    public function eventName(): ?string
    {
        return 'order.status.updated';
    }
}
