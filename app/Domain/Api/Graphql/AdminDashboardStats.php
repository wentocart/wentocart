<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Ops\Models\CommerceEvent;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use Carbon\Carbon;
use GraphQL\Error\UserError;
use Illuminate\Support\Facades\Schema;

/**
 * Admin REST Dashboard — statsAdminDashboard / GET /admin/dashboard/stats.
 */
final class AdminDashboardStats
{
    /** @var list<string> */
    public const TYPES = [
        'overview',
        'today',
        'low_stock_products',
        'sales_total',
        'visitors_total',
        'top_products',
        'top_customers',
    ];

    /**
     * @param  array<string, mixed>  $args
     * @return array{type: string, dateRange: string|null, statistics: mixed}
     */
    public static function stats(array $args, bool $forGraphql = false): array
    {
        AdminReportingStats::assertAdmin();
        $type = trim((string) ($args['type'] ?? 'overview')) ?: 'overview';
        if (! in_array($type, self::TYPES, true)) {
            throw new UserError('Invalid dashboard stat type.');
        }

        $w = AdminReportingStats::window($args);
        $statistics = match ($type) {
            'overview' => self::overAll($w),
            'today' => self::today($w),
            'low_stock_products' => self::stockThreshold(),
            'sales_total' => self::totalSales($w),
            'visitors_total' => self::totalVisitors($w),
            'top_products' => self::topProducts($w),
            'top_customers' => self::topCustomers($w),
            default => [],
        };

        $label = $w['start']->format('d M').' - '.$w['end']->format('d M');

        return [
            'type' => $type,
            'dateRange' => $label,
            'statistics' => $forGraphql
                ? json_encode($statistics, JSON_UNESCAPED_UNICODE)
                : $statistics,
        ];
    }

    /**
     * @param  array{start: Carbon, end: Carbon, prevStart: Carbon, prevEnd: Carbon, storeId: string}  $w
     * @return array<string, mixed>
     */
    protected static function overAll(array $w): array
    {
        $prevCustomers = (float) Customer::query()->whereBetween('created_at', [$w['prevStart'], $w['prevEnd']])->count();
        $currCustomers = (float) Customer::query()->whereBetween('created_at', [$w['start'], $w['end']])->count();
        $prevOrders = (float) AdminReportingStats::ordersQuery($w['prevStart'], $w['prevEnd'], $w['storeId'])->count();
        $currOrders = (float) AdminReportingStats::ordersQuery($w['start'], $w['end'], $w['storeId'])->count();
        $prevSales = (float) AdminReportingStats::ordersQuery($w['prevStart'], $w['prevEnd'], $w['storeId'])->sum('grand_total');
        $currSales = (float) AdminReportingStats::ordersQuery($w['start'], $w['end'], $w['storeId'])->sum('grand_total');
        $prevAvg = $prevOrders > 0 ? $prevSales / $prevOrders : 0.0;
        $currAvg = $currOrders > 0 ? $currSales / $currOrders : 0.0;
        $unpaid = self::unpaidTotal($w['storeId']);

        return [
            'total_customers' => [
                'previous' => $prevCustomers,
                'current' => $currCustomers,
                'progress' => AdminReportingStats::progress($prevCustomers, $currCustomers),
            ],
            'total_orders' => [
                'previous' => $prevOrders,
                'current' => $currOrders,
                'progress' => AdminReportingStats::progress($prevOrders, $currOrders),
            ],
            'total_sales' => [
                'previous' => round($prevSales, 2),
                'current' => round($currSales, 2),
                'formatted_total' => number_format($currSales, 2, '.', ''),
                'progress' => AdminReportingStats::progress($prevSales, $currSales),
            ],
            'avg_sales' => [
                'previous' => round($prevAvg, 2),
                'current' => round($currAvg, 2),
                'formatted_total' => number_format($currAvg, 2, '.', ''),
                'progress' => AdminReportingStats::progress($prevAvg, $currAvg),
            ],
            'total_unpaid_invoices' => [
                'total' => round($unpaid, 2),
                'formatted_total' => number_format($unpaid, 2, '.', ''),
            ],
        ];
    }

    /**
     * @param  array{start: Carbon, end: Carbon, prevStart: Carbon, prevEnd: Carbon, storeId: string}  $w
     * @return array<string, mixed>
     */
    protected static function today(array $w): array
    {
        $start = now()->startOfDay();
        $end = now()->endOfDay();
        $prevStart = now()->subDay()->startOfDay();
        $prevEnd = now()->subDay()->endOfDay();
        $channel = $w['storeId'];

        $prevSales = (float) AdminReportingStats::ordersQuery($prevStart, $prevEnd, $channel)->sum('grand_total');
        $currSales = (float) AdminReportingStats::ordersQuery($start, $end, $channel)->sum('grand_total');
        $prevOrders = (float) AdminReportingStats::ordersQuery($prevStart, $prevEnd, $channel)->count();
        $currOrders = (float) AdminReportingStats::ordersQuery($start, $end, $channel)->count();
        $prevCustomers = (float) Customer::query()->whereBetween('created_at', [$prevStart, $prevEnd])->count();
        $currCustomers = (float) Customer::query()->whereBetween('created_at', [$start, $end])->count();

        $orders = AdminReportingStats::ordersQuery($start, $end, $channel)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(function (Order $o) {
                $customer = $o->customer_id ? Customer::query()->find($o->customer_id) : null;
                $name = $customer
                    ? trim(($customer->first_name ?? '').' '.($customer->last_name ?? ''))
                    : null;

                return [
                    'id' => (int) $o->id,
                    'increment_id' => (string) ($o->number ?? $o->id),
                    'status' => (string) $o->status,
                    'status_label' => (string) $o->status,
                    'payment_method' => (string) ($o->payment_method ?? $o->payment_status ?? ''),
                    'base_grand_total' => round((float) $o->grand_total, 2),
                    'formatted_base_grand_total' => number_format((float) $o->grand_total, 2, '.', ''),
                    'store_name' => null,
                    'customer_email' => $o->email ?? $customer?->email,
                    'customer_name' => $name !== '' ? $name : null,
                    'items' => '',
                    'billing_address' => null,
                    'created_at' => optional($o->created_at)?->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return [
            'total_sales' => [
                'previous' => round($prevSales, 2),
                'current' => round($currSales, 2),
                'formatted_total' => number_format($currSales, 2, '.', ''),
                'progress' => AdminReportingStats::progress($prevSales, $currSales),
            ],
            'total_orders' => [
                'previous' => $prevOrders,
                'current' => $currOrders,
                'progress' => AdminReportingStats::progress($prevOrders, $currOrders),
            ],
            'total_customers' => [
                'previous' => $prevCustomers,
                'current' => $currCustomers,
                'progress' => AdminReportingStats::progress($prevCustomers, $currCustomers),
            ],
            'orders' => $orders,
        ];
    }

    /** @return list<array<string, mixed>> */
    protected static function stockThreshold(): array
    {
        if (! Schema::hasTable('products')) {
            return [];
        }

        $q = Product::query()->where('is_enabled', true)->where('manage_stock', true);
        if (Schema::hasColumn('products', 'stock_qty')) {
            // SQLite has no GREATEST — threshold floor of 5 matches stock-alert list size intent.
            $q->whereRaw(
                'COALESCE(stock_qty, 0) <= CASE'
                .' WHEN COALESCE(low_stock_alert, 0) > COALESCE(low_stock_threshold, 0)'
                .' AND COALESCE(low_stock_alert, 0) > 5 THEN COALESCE(low_stock_alert, 0)'
                .' WHEN COALESCE(low_stock_threshold, 0) > 5 THEN COALESCE(low_stock_threshold, 0)'
                .' ELSE 5 END'
            )->orderBy('stock_qty');
        } else {
            $q->orderBy('id');
        }

        return $q->limit(5)->get()->map(function (Product $p) {
            $price = (float) ($p->price ?? 0);
            $qty = (float) ($p->stock_qty ?? 0);

            return [
                'id' => (int) $p->id,
                'sku' => (string) ($p->sku ?? ''),
                'name' => (string) ($p->name ?? ''),
                'price' => $price,
                'formatted_price' => number_format($price, 2, '.', ''),
                'total_qty' => $qty,
                'image' => null,
            ];
        })->values()->all();
    }

    /**
     * @param  array{start: Carbon, end: Carbon, prevStart: Carbon, prevEnd: Carbon, storeId: string}  $w
     * @return array<string, mixed>
     */
    protected static function totalSales(array $w): array
    {
        $prevOrders = (float) AdminReportingStats::ordersQuery($w['prevStart'], $w['prevEnd'], $w['storeId'])->count();
        $currOrders = (float) AdminReportingStats::ordersQuery($w['start'], $w['end'], $w['storeId'])->count();
        $prevSales = (float) AdminReportingStats::ordersQuery($w['prevStart'], $w['prevEnd'], $w['storeId'])->sum('grand_total');
        $currSales = (float) AdminReportingStats::ordersQuery($w['start'], $w['end'], $w['storeId'])->sum('grand_total');

        return [
            'total_orders' => [
                'previous' => $prevOrders,
                'current' => $currOrders,
                'progress' => AdminReportingStats::progress($prevOrders, $currOrders),
            ],
            'total_sales' => [
                'previous' => round($prevSales, 2),
                'current' => round($currSales, 2),
                'formatted_total' => number_format($currSales, 2, '.', ''),
                'progress' => AdminReportingStats::progress($prevSales, $currSales),
            ],
            'over_time' => AdminReportingStats::overTime($w['start'], $w['end'], 'sales', $w['storeId']),
        ];
    }

    /**
     * @param  array{start: Carbon, end: Carbon, prevStart: Carbon, prevEnd: Carbon, storeId: string}  $w
     * @return array<string, mixed>
     */
    protected static function totalVisitors(array $w): array
    {
        $prev = self::visitorCount($w['prevStart'], $w['prevEnd']);
        $curr = self::visitorCount($w['start'], $w['end']);
        $prevUnique = self::visitorCount($w['prevStart'], $w['prevEnd'], true);
        $currUnique = self::visitorCount($w['start'], $w['end'], true);

        return [
            'total' => [
                'previous' => $prev,
                'current' => $curr,
                'progress' => AdminReportingStats::progress($prev, $curr),
            ],
            'unique' => [
                'previous' => $prevUnique,
                'current' => $currUnique,
                'progress' => AdminReportingStats::progress($prevUnique, $currUnique),
            ],
            'over_time' => self::visitorOverTime($w['start'], $w['end']),
        ];
    }

    /**
     * @param  array{start: Carbon, end: Carbon, prevStart: Carbon, prevEnd: Carbon, storeId: string}  $w
     * @return list<array<string, mixed>>
     */
    protected static function topProducts(array $w): array
    {
        $rows = OrderItem::query()
            ->selectRaw('product_id, MAX(name) as name, SUM(qty * unit_price) as revenue, MAX(unit_price) as price')
            ->whereHas('order', fn ($q) => $q->where('status', '!=', 'cancelled')->whereBetween('created_at', [$w['start'], $w['end']]))
            ->groupBy('product_id')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get();

        return $rows->map(fn ($row) => [
            'id' => (int) $row->product_id,
            'name' => (string) $row->name,
            'price' => (float) $row->price,
            'formatted_price' => number_format((float) $row->price, 2, '.', ''),
            'revenue' => round((float) $row->revenue, 2),
            'formatted_revenue' => number_format((float) $row->revenue, 2, '.', ''),
            'images' => [],
        ])->values()->all();
    }

    /**
     * @param  array{start: Carbon, end: Carbon, prevStart: Carbon, prevEnd: Carbon, storeId: string}  $w
     * @return list<array<string, mixed>>
     */
    protected static function topCustomers(array $w): array
    {
        $emailCol = Schema::hasColumn('orders', 'email') ? 'email' : null;
        $select = $emailCol
            ? 'customer_id, MAX('.$emailCol.') as email, COUNT(*) as orders, SUM(grand_total) as total, MAX(created_at) as datetime'
            : 'customer_id, COUNT(*) as orders, SUM(grand_total) as total, MAX(created_at) as datetime';
        $rows = AdminReportingStats::ordersQuery($w['start'], $w['end'], $w['storeId'])
            ->selectRaw($select)
            ->groupBy('customer_id')
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        return $rows->map(function ($row) {
            $customer = $row->customer_id ? Customer::query()->find($row->customer_id) : null;
            $name = $customer
                ? trim(($customer->first_name ?? '').' '.($customer->last_name ?? ''))
                : '';

            return [
                'id' => $row->customer_id ? (int) $row->customer_id : null,
                'email' => $customer?->email ?? ($row->email ?? null),
                'full_name' => $name !== '' ? $name : null,
                'total' => round((float) $row->total, 2),
                'orders' => (int) $row->orders,
                'formatted_total' => number_format((float) $row->total, 2, '.', ''),
                'datetime' => $row->datetime ? Carbon::parse($row->datetime)->toIso8601String() : null,
            ];
        })->values()->all();
    }

    protected static function unpaidTotal(string $channel): float
    {
        $q = Order::query()->where('status', '!=', 'cancelled');
        if (Schema::hasColumn('orders', 'payment_status')) {
            $q->whereNotIn('payment_status', ['paid', 'refunded', 'partially_refunded']);
        }
        if ($channel !== '' && Schema::hasColumn('orders', 'store_id')) {
            $storeId = (int) (\App\Domain\Store\Models\Store::query()->where('code', $channel)->value('id') ?? 0);
            if ($storeId > 0) {
                $q->where('store_id', $storeId);
            }
        }

        return (float) $q->sum('grand_total');
    }

    protected static function visitorCount(Carbon $from, Carbon $to, bool $unique = false): float
    {
        if (! Schema::hasTable('commerce_events')) {
            return 0.0;
        }
        $q = CommerceEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('name', ['page_view', 'visit', 'storefront.view', 'visitor']);
        if ($unique && Schema::hasColumn('commerce_events', 'visitor_id')) {
            return (float) (clone $q)->distinct('visitor_id')->count('visitor_id');
        }
        if ($unique && Schema::hasColumn('commerce_events', 'session_id')) {
            return (float) (clone $q)->distinct('session_id')->count('session_id');
        }

        return (float) $q->count();
    }

    /** @return list<array{label: string, total: float}> */
    protected static function visitorOverTime(Carbon $from, Carbon $to): array
    {
        $rows = [];
        $cursor = (clone $from)->startOfDay();
        $endDay = (clone $to)->startOfDay();
        while ($cursor->lte($endDay)) {
            $bucketStart = (clone $cursor)->startOfDay();
            $bucketEnd = (clone $cursor)->endOfDay();
            $rows[] = [
                'label' => $bucketStart->toDateString(),
                'total' => self::visitorCount($bucketStart, $bucketEnd),
            ];
            $cursor = $cursor->addDay();
            if (count($rows) > 90) {
                break;
            }
        }

        return $rows;
    }
}
