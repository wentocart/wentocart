<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Customer\Models\Customer;
use App\Domain\Database\DbGrammar;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\ReturnMessage;
use App\Domain\Order\Models\ReturnReason;
use App\Domain\Order\Models\ReturnStatus;
use App\Domain\Order\Models\ShopReturn;
use App\Domain\Order\Services\ShopReturnService;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 */
class AdminRmaGraphql
{
    /** @var array<string, string> */
    protected static array $statusTitles = [
        ShopReturn::STATUS_REQUESTED => 'Pending Review',
        ShopReturn::STATUS_APPROVED => 'Approved',
        ShopReturn::STATUS_REJECTED => 'Request Declined',
        ShopReturn::STATUS_RECEIVED => 'Received Package',
        ShopReturn::STATUS_REFUNDED => 'Refunded',
    ];

    /** @var array<string, list<string>> */
    protected static array $transitions = [
        ShopReturn::STATUS_REQUESTED => [ShopReturn::STATUS_APPROVED, ShopReturn::STATUS_REJECTED],
        ShopReturn::STATUS_APPROVED => [ShopReturn::STATUS_RECEIVED, ShopReturn::STATUS_REJECTED, ShopReturn::STATUS_REFUNDED],
        ShopReturn::STATUS_RECEIVED => [ShopReturn::STATUS_REFUNDED],
        ShopReturn::STATUS_REJECTED => [],
        ShopReturn::STATUS_REFUNDED => [],
    ];

    protected static function assertOrders(): Admin
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! $admin->hasPermission('orders.manage')) {
            throw new UserError('Forbidden.');
        }

        return $admin;
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(ShopReturn $row, bool $detail = false): array
    {
        $row->loadMissing(['order', 'customer', 'items.orderItem']);
        $order = $row->order;
        $customer = $row->customer;
        $statusCode = (string) $row->status;
        $statusTitle = self::$statusTitles[$statusCode] ?? ucfirst($statusCode);
        $statusId = self::statusIdFor($statusCode, $statusTitle);
        $firstItem = $row->items->first();
        $itemJson = null;
        if ($detail && $firstItem) {
            $oi = $firstItem->orderItem;
            $itemJson = json_encode([
                'id' => (int) $firstItem->id,
                'order_item_id' => (int) $firstItem->order_item_id,
                'sku' => (string) ($oi?->sku ?? ''),
                'name' => (string) ($oi?->name ?? ''),
                'quantity' => (float) $firstItem->qty,
                'resolution' => (string) ($row->resolution === 'replace' ? 'return' : ($row->resolution ?: 'return')),
                'reason_id' => $row->reason_id ? (int) $row->reason_id : null,
                'reason' => (string) ($row->reason ?? ''),
                'variant_id' => null,
            ], JSON_UNESCAPED_UNICODE);
        }

        $available = [];
        if ($detail) {
            foreach (self::$transitions[$statusCode] ?? [] as $next) {
                $title = self::$statusTitles[$next] ?? ucfirst($next);
                $available[] = [
                    'id' => (string) self::statusIdFor($next, $title),
                    'title' => $title,
                    'code' => $next,
                ];
            }
        }

        return [
            'id' => (string) $row->id,
            'orderId' => (string) $row->order_id,
            'order_id' => (int) $row->order_id,
            'orderIncrementId' => (string) ($order?->number ?? ''),
            'orderStatus' => (string) ($order?->status ?? ''),
            'customerName' => trim((string) (($customer?->first_name ?? '').' '.($customer?->last_name ?? '')))
                ?: (string) ($customer?->name ?? $order?->email ?? ''),
            'customerEmail' => (string) ($customer?->email ?? $order?->email ?? ''),
            'isGuest' => $customer === null,
            'statusId' => (string) $statusId,
            'statusTitle' => $statusTitle,
            'statusColor' => self::statusColor($statusCode),
            'status' => $statusCode,
            'number' => (string) $row->number,
            'information' => $row->customer_note,
            'packageCondition' => null,
            'canReopen' => $statusCode === ShopReturn::STATUS_REJECTED,
            'messagesCount' => $detail && Schema::hasTable('return_messages')
                ? (int) ReturnMessage::query()->where('order_return_id', $row->id)->count()
                : null,
            'item' => $itemJson,
            'images' => $detail ? '[]' : null,
            'availableStatuses' => $detail ? json_encode($available, JSON_UNESCAPED_UNICODE) : null,
            'created_at' => optional($row->created_at)?->toIso8601String(),
        ];
    }

    protected static function statusIdFor(string $code, string $title): int
    {
        if (Schema::hasTable('return_statuses')) {
            $id = ReturnStatus::query()->where('title', $title)->value('id');
            if ($id) {
                return (int) $id;
            }
        }

        return abs(crc32($code)) % 100000 + 1;
    }

    protected static function statusColor(string $code): string
    {
        return match ($code) {
            ShopReturn::STATUS_APPROVED => '#12B76A',
            ShopReturn::STATUS_REJECTED => '#F04438',
            ShopReturn::STATUS_RECEIVED => '#6172F3',
            ShopReturn::STATUS_REFUNDED => '#7A5AF8',
            default => '#FDB022',
        };
    }

    protected static function codeFromStatusArg(string|int $statusIdOrTitle): ?string
    {
        $raw = trim((string) $statusIdOrTitle);
        foreach (self::$statusTitles as $code => $title) {
            if (strcasecmp($title, $raw) === 0 || $code === $raw) {
                return $code;
            }
        }
        if (Schema::hasTable('return_statuses') && ctype_digit($raw)) {
            $title = (string) (ReturnStatus::query()->find((int) $raw)?->title ?? '');
            foreach (self::$statusTitles as $code => $mapped) {
                if (strcasecmp($mapped, $title) === 0) {
                    return $code;
                }
            }
        }
        if (ctype_digit($raw)) {
            foreach (self::$statusTitles as $code => $title) {
                if ((string) self::statusIdFor($code, $title) === $raw) {
                    return $code;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    public static function listReturns(array $args): array
    {
        self::assertOrders();
        $limit = max(1, min(100, (int) ($args['limit'] ?? $args['first'] ?? 50)));
        $sort = trim((string) ($args['sort'] ?? 'id'));
        if (! in_array($sort, ['id', 'order_id', 'created_at'], true)) {
            $sort = 'id';
        }
        $orderDir = strtolower(trim((string) ($args['order'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        $q = ShopReturn::query()->with(['order', 'customer'])->orderBy($sort, $orderDir);
        if (! empty($args['id'])) {
            $q->where('id', (int) $args['id']);
        }
        if (! empty($args['order_id']) || ! empty($args['orderId'])) {
            $q->where('order_id', (int) ($args['order_id'] ?? $args['orderId']));
        }
        $status = trim((string) ($args['status'] ?? ''));
        if ($status !== '') {
            $code = self::codeFromStatusArg($status) ?? strtolower($status);
            $q->where('status', $code);
        }
        $name = trim((string) ($args['customer_name'] ?? $args['customerName'] ?? ''));
        if ($name !== '') {
            $op = DbGrammar::likeOperator();
            $q->whereHas('customer', function ($inner) use ($name, $op) {
                $inner->where('name', $op, '%'.$name.'%')
                    ->orWhere('first_name', $op, '%'.$name.'%')
                    ->orWhere('last_name', $op, '%'.$name.'%')
                    ->orWhere('email', $op, '%'.$name.'%');
            });
        }

        return $q->limit($limit)->get()->map(fn (ShopReturn $row) => self::map($row, false))->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>|null
     */
    public static function getReturn(array $args): ?array
    {
        self::assertOrders();
        $id = (int) preg_replace('/\D+/', '', (string) $args['id']);
        $row = ShopReturn::query()->find($id);

        return $row ? self::map($row, true) : null;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    public static function returnableItems(array $args): array
    {
        self::assertOrders();
        $order = Order::query()->with('items')->find((int) $args['orderId']);
        if (! $order) {
            throw new UserError('Order not found.');
        }
        $out = [];
        foreach ($order->items as $item) {
            $qty = (float) $item->returnableQty();
            if ($qty <= 0) {
                continue;
            }
            $out[] = [
                'id' => (string) $item->id,
                'orderItemId' => (string) $item->id,
                'sku' => (string) $item->sku,
                'name' => (string) $item->name,
                'qty' => $qty,
                'rmaQty' => $qty,
                'price' => (float) $item->unit_price,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    public static function returnReasons(array $args): array
    {
        self::assertOrders();

        return ReturnReason::query()
            ->where('is_enabled', true)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (ReturnReason $r) => [
                'id' => (string) $r->id,
                'code' => (string) $r->code,
                'name' => (string) $r->name,
                'title' => (string) $r->name,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function createReturn(array $args): array
    {
        $admin = self::assertOrders();
        $order = Order::query()->find((int) $args['orderId']);
        if (! $order) {
            throw new UserError('Order not found.');
        }
        $linesRaw = json_decode((string) ($args['itemsJson'] ?? '[]'), true);
        if (! is_array($linesRaw) || $linesRaw === []) {
            throw new UserError('itemsJson must be a non-empty JSON array of {order_item_id, qty}.');
        }
        $lines = [];
        foreach ($linesRaw as $line) {
            if (! is_array($line)) {
                continue;
            }
            $lines[] = [
                'order_item_id' => (int) ($line['order_item_id'] ?? $line['orderItemId'] ?? 0),
                'qty' => (float) ($line['qty'] ?? $line['rmaQty'] ?? 0),
            ];
        }
        $customer = $order->customer_id
            ? Customer::query()->find((int) $order->customer_id)
            : null;
        if (! $customer) {
            // Admin-raised return for guest: create a lightweight path via service internals.
            throw new UserError('Order has no customer; guest admin-created returns require a customer account.');
        }
        try {
            $row = app(ShopReturnService::class)->request($customer, $order, $lines, [
                'reason' => $args['information'] ?? $args['reason'] ?? null,
                'reason_id' => $args['reasonId'] ?? null,
                'resolution' => $args['resolution'] ?? 'refund',
                'customer_note' => $args['information'] ?? null,
            ]);
        } catch (ValidationException $e) {
            throw new UserError(collect($e->errors())->flatten()->first() ?: 'Unable to create return.');
        }

        return self::map($row, true);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function updateStatus(array $args): array
    {
        $admin = self::assertOrders();
        $row = ShopReturn::query()->find((int) preg_replace('/\D+/', '', (string) $args['id']));
        if (! $row) {
            throw new UserError('Return not found.');
        }
        $code = self::codeFromStatusArg((string) ($args['statusId'] ?? $args['status'] ?? ''));
        if ($code === null) {
            throw new UserError('Unknown status.');
        }
        $allowed = self::$transitions[$row->status] ?? [];
        if (! in_array($code, $allowed, true)) {
            throw new UserError('Status transition not allowed from '.$row->status.' to '.$code.'.');
        }
        $service = app(ShopReturnService::class);
        $note = isset($args['note']) ? trim((string) $args['note']) : null;
        try {
            $row = match ($code) {
                ShopReturn::STATUS_APPROVED => $service->approve($row, (int) $admin->id, $note),
                ShopReturn::STATUS_REJECTED => $service->reject($row, (int) $admin->id, $note),
                ShopReturn::STATUS_RECEIVED => $service->receive($row, (int) $admin->id, $note),
                ShopReturn::STATUS_REFUNDED => $service->refund($row, (int) $admin->id),
                default => throw new UserError('Unsupported status.'),
            };
        } catch (ValidationException $e) {
            throw new UserError(collect($e->errors())->flatten()->first() ?: 'Unable to update status.');
        }
        if ($note && Schema::hasTable('return_messages')) {
            ReturnMessage::query()->create([
                'order_return_id' => $row->id,
                'admin_id' => $admin->id,
                'body' => $note,
                'is_admin' => true,
            ]);
        }

        return self::map($row->fresh(['order', 'customer', 'items.orderItem']) ?? $row, true);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function reopen(array $args): array
    {
        self::assertOrders();
        $row = ShopReturn::query()->find((int) preg_replace('/\D+/', '', (string) $args['id']));
        if (! $row) {
            throw new UserError('Return not found.');
        }
        if ($row->status !== ShopReturn::STATUS_REJECTED) {
            throw new UserError('Only declined returns can be reopened.');
        }
        $row->update([
            'status' => ShopReturn::STATUS_REQUESTED,
            'rejected_at' => null,
            'rejected_by_admin_id' => null,
        ]);

        return self::map($row->fresh(['order', 'customer', 'items.orderItem']) ?? $row, true);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    public static function listMessages(array $args): array
    {
        self::assertOrders();
        if (! Schema::hasTable('return_messages')) {
            return [];
        }
        $returnId = (int) ($args['returnId'] ?? 0);

        return ReturnMessage::query()
            ->where('order_return_id', $returnId)
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (ReturnMessage $m) => [
                'id' => (string) $m->id,
                'body' => (string) $m->body,
                'isAdmin' => (bool) $m->is_admin,
                'created_at' => optional($m->created_at)?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function sendMessage(array $args): array
    {
        $admin = self::assertOrders();
        if (! Schema::hasTable('return_messages')) {
            throw new UserError('Return messages are unavailable.');
        }
        $returnId = (int) ($args['returnId'] ?? 0);
        if (! ShopReturn::query()->whereKey($returnId)->exists()) {
            throw new UserError('Return not found.');
        }
        $body = trim((string) ($args['body'] ?? ''));
        if ($body === '') {
            throw new UserError('body is required.');
        }
        $m = ReturnMessage::query()->create([
            'order_return_id' => $returnId,
            'admin_id' => $admin->id,
            'body' => $body,
            'is_admin' => true,
        ]);

        return [
            'id' => (string) $m->id,
            'body' => (string) $m->body,
            'isAdmin' => true,
            'created_at' => optional($m->created_at)?->toIso8601String(),
        ];
    }
}
