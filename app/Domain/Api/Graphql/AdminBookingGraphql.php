<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Order\Models\OrderItem;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Support\Facades\Schema;

/**
 */
class AdminBookingGraphql
{
    protected static function assertView(): Admin
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
    public static function map(OrderItem $item): array
    {
        $item->loadMissing('order');
        $order = $item->order;
        $from = $item->booking_date?->startOfDay();
        $to = ($item->booking_end_date ?? $item->booking_date)?->endOfDay();
        $fromTs = $from?->timestamp;
        $toTs = $to?->timestamp;
        $bookingType = 'default';
        if (is_array($item->rental_data) && $item->rental_data !== []) {
            $bookingType = 'rental';
        }

        return [
            'id' => (string) $item->id,

            'order_id' => (int) $item->order_id,
            'orderId' => (string) $item->order_id,
            'qty' => (float) $item->qty,
            'product_id' => $item->product_id ? (int) $item->product_id : null,
            'productId' => $item->product_id ? (string) $item->product_id : null,
            'bookingType' => $bookingType,
            'from' => $fromTs,
            'to' => $toTs,
            'fromFormatted' => $from?->format('d M, Y h:iA'),
            'toFormatted' => $to?->format('d M, Y h:iA'),
            'order' => json_encode([
                'id' => (int) ($order?->id ?? 0),
                'increment_id' => (string) ($order?->number ?? ''),
                'status' => (string) ($order?->status ?? ''),
                'email' => (string) ($order?->email ?? ''),
                'grand_total' => (float) ($order?->grand_total ?? 0),
            ], JSON_UNESCAPED_UNICODE),
            'orderItem' => json_encode([
                'id' => (int) $item->id,
                'sku' => (string) $item->sku,
                'name' => (string) $item->name,
                'qty' => (float) $item->qty,
                'unit_price' => (float) $item->unit_price,
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => optional($item->created_at)?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return \Illuminate\Database\Eloquent\Builder<\App\Domain\Order\Models\OrderItem>
     */
    protected static function filteredQuery(array $args)
    {
        $q = OrderItem::query()
            ->with('order')
            ->whereNotNull('booking_date')
            ->orderByDesc('id');

        if (! empty($args['id'])) {
            $q->whereKey((int) $args['id']);
        }
        if (! empty($args['order_id']) || ! empty($args['orderId'])) {
            $q->where('order_id', (int) ($args['order_id'] ?? $args['orderId']));
        }
        if (! empty($args['product_id']) || ! empty($args['productId'])) {
            $q->where('product_id', (int) ($args['product_id'] ?? $args['productId']));
        }
        foreach ([
            'from_from' => ['booking_date', '>='],
            'from_to' => ['booking_date', '<='],
            'to_from' => ['booking_end_date', '>='],
            'to_to' => ['booking_end_date', '<='],
            'created_at_from' => ['created_at', '>='],
            'created_at_to' => ['created_at', '<='],
            'date_from' => ['booking_date', '>='],
            'date_to' => ['booking_date', '<='],
        ] as $arg => [$column, $op]) {
            $raw = trim((string) ($args[$arg] ?? ''));
            if ($raw === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
                continue;
            }
            $day = substr($raw, 0, 10);
            if ($op === '>=') {
                $q->whereDate($column, '>=', $day);
            } else {
                $q->whereDate($column, '<=', $day);
            }
        }

        return $q;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    public static function list(array $args): array
    {
        self::assertView();
        if (! Schema::hasColumn('order_items', 'booking_date')) {
            return [];
        }

        $limit = (int) ($args['first'] ?? $args['limit'] ?? 50);
        $limit = max(1, min(100, $limit));

        return self::filteredQuery($args)->limit($limit)->get()
            ->map(fn (OrderItem $item) => self::map($item))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public static function paginate(array $filters = []): array
    {
        self::assertView();
        if (! Schema::hasColumn('order_items', 'booking_date')) {
            return [
                'data' => [],
                'meta' => [
                    'currentPage' => 1,
                    'perPage' => 20,
                    'lastPage' => 1,
                    'total' => 0,
                    'from' => null,
                    'to' => null,
                ],
            ];
        }
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? $filters['limit'] ?? 20)));
        $paginator = self::filteredQuery($filters)->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())
                ->map(fn (OrderItem $item) => self::map($item))
                ->values()
                ->all(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>|null
     */
    public static function get(array $args): ?array
    {
        self::assertView();
        if (! Schema::hasColumn('order_items', 'booking_date')) {
            return null;
        }
        $item = OrderItem::query()
            ->with('order')
            ->whereNotNull('booking_date')
            ->find((int) $args['id']);

        return $item ? self::map($item) : null;
    }
}
