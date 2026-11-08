<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Customer\Models\Review;
use App\Domain\Ops\Models\CommerceEvent;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Search\Models\SearchSynonym;
use App\Domain\Store\Models\Store;
use App\Models\Admin;
use Carbon\Carbon;
use GraphQL\Error\UserError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 */
class AdminReportingStats
{
    /**
     * @param  array<string, mixed>  $args
     * @return array{start: Carbon, end: Carbon, prevStart: Carbon, prevEnd: Carbon, storeId: string}
     */
    public static function window(array $args): array
    {
        $end = ! empty($args['end']) ? Carbon::parse((string) $args['end'])->endOfDay() : now()->endOfDay();
        $start = ! empty($args['start']) ? Carbon::parse((string) $args['start'])->startOfDay() : now()->subDays(30)->startOfDay();
        if ($start->gt($end)) {
            throw new UserError('start must be on or before end.');
        }
        $days = max(1, (int) $start->diffInDays($end) + 1);
        $prevEnd = (clone $start)->subDay()->endOfDay();
        $prevStart = (clone $prevEnd)->subDays($days - 1)->startOfDay();

        return [
            'start' => $start,
            'end' => $end,
            'prevStart' => $prevStart,
            'prevEnd' => $prevEnd,
            'storeId' => trim((string) ($args['storeId'] ?? '')),
        ];
    }

    public static function assertAdmin(): Admin
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin) {
            throw new UserError('Unauthenticated.');
        }

        return $admin;
    }

    public static function progress(float $previous, float $current): float
    {
        if ($previous <= 0.0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 2);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<\App\Domain\Order\Models\Order>
     */
    public static function ordersQuery(Carbon $from, Carbon $to, string $channel = '')
    {
        $q = Order::query()->where('status', '!=', 'cancelled')->whereBetween('created_at', [$from, $to]);
        if ($channel !== '') {
            $storeId = (int) (Store::query()->where('code', $channel)->value('id') ?? 0);
            if ($storeId > 0 && Schema::hasColumn('orders', 'store_id')) {
                $q->where('store_id', $storeId);
            }
        }

        return $q;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function overTime(Carbon $from, Carbon $to, string $metric, string $channel = ''): array
    {
        $rows = [];
        $cursor = (clone $from)->startOfDay();
        $endDay = (clone $to)->startOfDay();
        $spanDays = max(1, (int) $cursor->diffInDays($endDay) + 1);
        $step = $spanDays > 60 ? (int) ceil($spanDays / 30) : 1;
        while ($cursor->lte($endDay)) {
            $bucketStart = (clone $cursor)->startOfDay();
            $bucketEnd = (clone $cursor)->addDays($step - 1)->endOfDay();
            if ($bucketEnd->gt($to)) {
                $bucketEnd = (clone $to)->endOfDay();
            }
            $q = self::ordersQuery($bucketStart, $bucketEnd, $channel);
            if ($metric === 'sales') {
                $total = (float) (clone $q)->sum('grand_total');
                $count = (int) (clone $q)->count();
                $rows[] = ['label' => $bucketStart->toDateString(), 'total' => round($total, 2), 'count' => $count];
            } elseif ($metric === 'orders') {
                $count = (int) (clone $q)->count();
                $rows[] = ['label' => $bucketStart->toDateString(), 'total' => $count, 'count' => $count];
            } elseif ($metric === 'aov') {
                $count = (int) (clone $q)->count();
                $total = (float) (clone $q)->sum('grand_total');
                $aov = $count > 0 ? round($total / $count, 2) : 0.0;
                $rows[] = ['label' => $bucketStart->toDateString(), 'total' => $aov, 'count' => $count];
            } else {
                $count = (int) Customer::query()->whereBetween('created_at', [$bucketStart, $bucketEnd])->count();
                $rows[] = ['label' => $bucketStart->toDateString(), 'total' => $count];
            }
            $cursor = (clone $bucketStart)->addDays($step)->startOfDay();
        }

        return $rows;
    }

    /**
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function overview(array $args): array
    {
        $type = trim((string) ($args['type'] ?? 'total-sales')) ?: 'total-sales';
        if ($type === 'total-customers') {
            $result = self::customers(array_merge($args, ['type' => 'total-customers']));
            $result['entity'] = 'overview';

            return $result;
        }
        if ($type === 'top-selling-products-by-revenue') {
            $result = self::products(array_merge($args, ['type' => 'top-selling-products-by-revenue']));
            $result['entity'] = 'overview';

            return $result;
        }
        if ($type === 'total-orders') {
            $result = self::sales(array_merge($args, ['type' => 'total-orders']));
            $result['entity'] = 'overview';

            return $result;
        }
        $result = self::sales(array_merge($args, ['type' => 'total-sales']));
        $result['entity'] = 'overview';

        return $result;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function sales(array $args): array
    {
        self::assertAdmin();
        $type = trim((string) ($args['type'] ?? 'total-sales')) ?: 'total-sales';
        $allowed = [
            'total-sales', 'average-sales', 'total-orders', 'purchase-funnel',
            'abandoned-carts', 'refunds', 'tax-collected', 'shipping-collected',
            'top-payment-methods', 'sales-by-coupon',
        ];
        if (! in_array($type, $allowed, true)) {
            throw new UserError('Invalid reporting stat type.');
        }
        $w = self::window($args);
        $statistics = match ($type) {
            'total-sales' => self::panelSales($w),
            'average-sales' => self::panelAverageSales($w),
            'total-orders' => self::panelOrders($w),
            'purchase-funnel' => self::panelFunnel($w),
            'abandoned-carts' => self::panelAbandoned($w),
            'refunds' => self::panelRefunds($w),
            'tax-collected' => self::panelTax($w),
            'shipping-collected' => self::panelShipping($w),
            'top-payment-methods' => self::panelPaymentMethods($w),
            'sales-by-coupon' => self::panelCoupons($w),
            default => [],
        };

        return self::envelope('sales', $type, $w, $statistics);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function customers(array $args): array
    {
        self::assertAdmin();
        $type = trim((string) ($args['type'] ?? 'total-customers')) ?: 'total-customers';
        $allowed = [
            'total-customers', 'customers-traffic', 'customers-with-most-sales',
            'customers-with-most-orders', 'customers-with-most-reviews', 'top-customer-groups',
        ];
        if (! in_array($type, $allowed, true)) {
            throw new UserError('Invalid reporting stat type.');
        }
        $w = self::window($args);
        $statistics = match ($type) {
            'total-customers' => self::panelTotalCustomers($w),
            'customers-traffic' => [
                'total' => ['previous' => 0, 'current' => 0, 'progress' => 0],
                'unique' => ['previous' => 0, 'current' => 0, 'progress' => 0],
                'over_time' => ['previous' => [], 'current' => []],
            ],
            'customers-with-most-sales' => self::panelTopCustomerSales($w),
            'customers-with-most-orders' => self::panelTopCustomerOrders($w),
            'customers-with-most-reviews' => self::panelTopCustomerReviews($w),
            'top-customer-groups' => self::panelTopCustomerGroups($w),
            default => [],
        };

        return self::envelope('customers', $type, $w, $statistics);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function products(array $args): array
    {
        self::assertAdmin();
        $type = trim((string) ($args['type'] ?? 'total-sold-quantities')) ?: 'total-sold-quantities';
        $allowed = [
            'total-sold-quantities', 'total-products-added-to-wishlist',
            'top-selling-products-by-revenue', 'top-selling-products-by-quantity',
            'products-with-most-reviews', 'products-with-most-visits',
            'last-search-terms', 'top-search-terms',
        ];
        if (! in_array($type, $allowed, true)) {
            throw new UserError('Invalid reporting stat type.');
        }
        $w = self::window($args);
        $statistics = match ($type) {
            'total-sold-quantities' => self::panelSoldQty($w),
            'total-products-added-to-wishlist' => self::panelWishlist($w),
            'top-selling-products-by-revenue' => self::panelTopProducts($w, 'revenue'),
            'top-selling-products-by-quantity' => self::panelTopProducts($w, 'qty'),
            'products-with-most-reviews' => self::panelProductsReviews($w),
            'products-with-most-visits' => self::panelProductVisits($w),
            'last-search-terms' => self::panelSearchTerms($w, 'last'),
            'top-search-terms' => self::panelSearchTerms($w, 'top'),
            default => [],
        };

        return self::envelope('products', $type, $w, $statistics);
    }

    /**
     * @param  array{start: Carbon, end: Carbon, prevStart: Carbon, prevEnd: Carbon, storeId: string}  $w
     * @param  array<string, mixed>|list<mixed>  $statistics
     * @return array<string, mixed>
     */
    protected static function envelope(string $entity, string $type, array $w, array $statistics): array
    {
        return [
            'entity' => $entity,
            'type' => $type,
            'dateRange' => [
                'previous' => $w['prevStart']->toDateString().' - '.$w['prevEnd']->toDateString(),
                'current' => $w['start']->toDateString().' - '.$w['end']->toDateString(),
            ],
            'statistics' => json_encode($statistics, JSON_UNESCAPED_UNICODE),
        ];
    }

    /** @param  array{start: Carbon, end: Carbon, prevStart: Carbon, prevEnd: Carbon, storeId: string}  $w */
    protected static function panelSales(array $w): array
    {
        $prev = (float) self::ordersQuery($w['prevStart'], $w['prevEnd'], $w['storeId'])->sum('grand_total');
        $curr = (float) self::ordersQuery($w['start'], $w['end'], $w['storeId'])->sum('grand_total');

        return [
            'sales' => [
                'previous' => round($prev, 2),
                'current' => round($curr, 2),
                'formatted_total' => number_format($curr, 2, '.', ''),
                'progress' => self::progress($prev, $curr),
            ],
            'over_time' => [
                'previous' => self::overTime($w['prevStart'], $w['prevEnd'], 'sales', $w['storeId']),
                'current' => self::overTime($w['start'], $w['end'], 'sales', $w['storeId']),
            ],
        ];
    }

    /** @param  array{start: Carbon, end: Carbon, prevStart: Carbon, prevEnd: Carbon, storeId: string}  $w */
    protected static function panelAverageSales(array $w): array
    {
        $prevOrders = (int) self::ordersQuery($w['prevStart'], $w['prevEnd'], $w['storeId'])->count();
        $currOrders = (int) self::ordersQuery($w['start'], $w['end'], $w['storeId'])->count();
        $prevSales = (float) self::ordersQuery($w['prevStart'], $w['prevEnd'], $w['storeId'])->sum('grand_total');
        $currSales = (float) self::ordersQuery($w['start'], $w['end'], $w['storeId'])->sum('grand_total');
        $prev = $prevOrders > 0 ? $prevSales / $prevOrders : 0.0;
        $curr = $currOrders > 0 ? $currSales / $currOrders : 0.0;

        return [
            'sales' => [
                'previous' => round($prev, 2),
                'current' => round($curr, 2),
                'formatted_total' => number_format($curr, 2, '.', ''),
                'progress' => self::progress($prev, $curr),
            ],
            'over_time' => [
                'previous' => self::overTime($w['prevStart'], $w['prevEnd'], 'aov', $w['storeId']),
                'current' => self::overTime($w['start'], $w['end'], 'aov', $w['storeId']),
            ],
        ];
    }

    /** @param  array{start: Carbon, end: Carbon, prevStart: Carbon, prevEnd: Carbon, storeId: string}  $w */
    protected static function panelOrders(array $w): array
    {
        $prev = (float) self::ordersQuery($w['prevStart'], $w['prevEnd'], $w['storeId'])->count();
        $curr = (float) self::ordersQuery($w['start'], $w['end'], $w['storeId'])->count();

        return [
            'orders' => [
                'previous' => $prev,
                'current' => $curr,
                'progress' => self::progress($prev, $curr),
            ],
            'over_time' => [
                'previous' => self::overTime($w['prevStart'], $w['prevEnd'], 'orders', $w['storeId']),
                'current' => self::overTime($w['start'], $w['end'], 'orders', $w['storeId']),
            ],
        ];
    }

    /** @param  array{start: Carbon, end: Carbon, prevStart: Carbon, prevEnd: Carbon, storeId: string}  $w */
    protected static function panelFunnel(array $w): array
    {
        $orders = (int) self::ordersQuery($w['start'], $w['end'], $w['storeId'])->count();
        $carts = Schema::hasTable('carts')
            ? (int) \Illuminate\Support\Facades\DB::table('carts')->whereBetween('created_at', [$w['start'], $w['end']])->count()
            : $orders;
        $visitors = max($carts, $orders);

        return [
            'visitors' => ['total' => $visitors, 'progress' => 0],
            'product_visitors' => ['total' => (int) round($visitors * 0.8), 'progress' => 0],
            'carts' => ['total' => $carts, 'progress' => 0],
            'orders' => ['total' => $orders, 'progress' => 0],
        ];
    }

    /** @param  array{start: Carbon, end: Carbon, prevStart: Carbon, prevEnd: Carbon, storeId: string}  $w */
    protected static function panelAbandoned(array $w): array
    {
        $currCarts = Schema::hasTable('carts')
            ? (int) \Illuminate\Support\Facades\DB::table('carts')
                ->whereBetween('created_at', [$w['start'], $w['end']])
                ->count()
            : 0;
        $prevCarts = Schema::hasTable('carts')
            ? (int) \Illuminate\Support\Facades\DB::table('carts')
                ->whereBetween('created_at', [$w['prevStart'], $w['prevEnd']])
                ->count()
            : 0;

        return [
            'sales' => ['previous' => 0, 'current' => 0, 'formatted_total' => '0.00', 'progress' => 0],
            'carts' => ['previous' => $prevCarts, 'current' => $currCarts, 'progress' => self::progress((float) $prevCarts, (float) $currCarts)],
            'rate' => ['previous' => 0, 'current' => 0, 'progress' => 0],
            'products' => [],
        ];
    }

    /** @param  array{start: Carbon, end: Carbon, prevStart: Carbon, prevEnd: Carbon, storeId: string}  $w */
    protected static function panelRefunds(array $w): array
    {
        $col = Schema::hasColumn('orders', 'refunded_total') ? 'refunded_total' : null;
        $prev = $col ? (float) self::ordersQuery($w['prevStart'], $w['prevEnd'], $w['storeId'])->sum($col) : 0.0;
        $curr = $col ? (float) self::ordersQuery($w['start'], $w['end'], $w['storeId'])->sum($col) : 0.0;

        return [
            'refunds' => [
                'previous' => round($prev, 2),
                'current' => round($curr, 2),
                'formatted_total' => number_format($curr, 2, '.', ''),
                'progress' => self::progress($prev, $curr),
            ],
            'over_time' => [
                'previous' => self::overTime($w['prevStart'], $w['prevEnd'], 'orders', $w['storeId']),
                'current' => self::overTime($w['start'], $w['end'], 'orders', $w['storeId']),
            ],
        ];
    }

    /** @param  array{start: Carbon, end: Carbon, prevStart: Carbon, prevEnd: Carbon, storeId: string}  $w */
    protected static function panelTax(array $w): array
    {
        $col = Schema::hasColumn('orders', 'tax_total') ? 'tax_total' : null;
        $prev = $col ? (float) self::ordersQuery($w['prevStart'], $w['prevEnd'], $w['storeId'])->sum($col) : 0.0;
        $curr = $col ? (float) self::ordersQuery($w['start'], $w['end'], $w['storeId'])->sum($col) : 0.0;

        return [
            'tax_collected' => [
                'previous' => round($prev, 2),
                'current' => round($curr, 2),
                'formatted_total' => number_format($curr, 2, '.', ''),
                'progress' => self::progress($prev, $curr),
            ],
            'top_categories' => [],
            'over_time' => [
                'previous' => self::overTime($w['prevStart'], $w['prevEnd'], 'sales', $w['storeId']),
                'current' => self::overTime($w['start'], $w['end'], 'sales', $w['storeId']),
            ],
        ];
    }

    /** @param  array{start: Carbon, end: Carbon, prevStart: Carbon, prevEnd: Carbon, storeId: string}  $w */
    protected static function panelShipping(array $w): array
    {
        $col = Schema::hasColumn('orders', 'shipping_total') ? 'shipping_total' : null;
        $prev = $col ? (float) self::ordersQuery($w['prevStart'], $w['prevEnd'], $w['storeId'])->sum($col) : 0.0;
        $curr = $col ? (float) self::ordersQuery($w['start'], $w['end'], $w['storeId'])->sum($col) : 0.0;

        return [
            'shipping_collected' => [
                'previous' => round($prev, 2),
                'current' => round($curr, 2),
                'formatted_total' => number_format($curr, 2, '.', ''),
                'progress' => self::progress($prev, $curr),
            ],
            'top_methods' => [],
            'over_time' => [
                'previous' => self::overTime($w['prevStart'], $w['prevEnd'], 'sales', $w['storeId']),
                'current' => self::overTime($w['start'], $w['end'], 'sales', $w['storeId']),
            ],
        ];
    }

    /** @param  array{start: Carbon, end: Carbon, prevStart: Carbon, prevEnd: Carbon, storeId: string}  $w @return list<array<string, mixed>> */
    protected static function panelPaymentMethods(array $w): array
    {
        if (! Schema::hasColumn('orders', 'payment_method_id')) {
            return [];
        }
        $curr = self::ordersQuery($w['start'], $w['end'], $w['storeId'])
            ->selectRaw('payment_method_id as method_id, SUM(grand_total) as total, COUNT(*) as cnt')
            ->groupBy('payment_method_id')
            ->orderByDesc('total')
            ->limit(10)
            ->get();
        $prev = self::ordersQuery($w['prevStart'], $w['prevEnd'], $w['storeId'])
            ->selectRaw('payment_method_id as method_id, SUM(grand_total) as total')
            ->groupBy('payment_method_id')
            ->pluck('total', 'method_id');

        return $curr->map(function ($row) use ($prev) {
            $id = (string) ($row->method_id ?? '0');
            $method = $id;
            if (Schema::hasTable('payment_methods') && $row->method_id) {
                $method = (string) (\Illuminate\Support\Facades\DB::table('payment_methods')->where('id', $row->method_id)->value('name') ?: $id);
            }
            $total = (float) $row->total;
            $p = (float) ($prev[$row->method_id] ?? 0);

            return [
                'id' => $id,
                'method' => $method,
                'method_title' => $method,
                'title' => $method,
                'total' => round($total, 2),
                'base_total' => round($total, 2),
                'progress' => self::progress($p, $total),
                'formatted_total' => number_format($total, 2, '.', ''),
            ];
        })->values()->all();
    }

    /** @param  array{start: Carbon, end: Carbon, prevStart: Carbon, prevEnd: Carbon, storeId: string}  $w @return list<array<string, mixed>> */
    protected static function panelCoupons(array $w): array
    {
        $curr = self::ordersQuery($w['start'], $w['end'], $w['storeId'])
            ->where('discount_total', '>', 0)
            ->selectRaw('COUNT(*) as total, SUM(discount_total) as discount')
            ->first();
        if (! $curr || (int) $curr->total < 1) {
            return [];
        }

        return [[
            'coupon_code' => 'discounted',
            'discount_rule_id' => null,
            'total' => (int) $curr->total,
            'base_total' => (int) $curr->total,
            'base_discount_total' => round((float) ($curr->discount ?? 0), 2),
            'formatted_total' => (string) (int) $curr->total,
            'formatted_discount_total' => number_format((float) ($curr->discount ?? 0), 2, '.', ''),
            'link' => null,
            'progress' => 0,
            'datetime' => null,
        ]];
    }

    /** @param  array{start: Carbon, end: Carbon, prevStart: Carbon, prevEnd: Carbon, storeId: string}  $w */
    protected static function panelTotalCustomers(array $w): array
    {
        $prev = (float) Customer::query()->whereBetween('created_at', [$w['prevStart'], $w['prevEnd']])->count();
        $curr = (float) Customer::query()->whereBetween('created_at', [$w['start'], $w['end']])->count();

        return [
            'customers' => [
                'previous' => $prev,
                'current' => $curr,
                'progress' => self::progress($prev, $curr),
            ],
            'over_time' => [
                'previous' => self::overTime($w['prevStart'], $w['prevEnd'], 'customers', $w['storeId']),
                'current' => self::overTime($w['start'], $w['end'], 'customers', $w['storeId']),
            ],
        ];
    }

    /** @param  array{start: Carbon, end: Carbon, prevStart: Carbon, prevEnd: Carbon, storeId: string}  $w @return list<array<string, mixed>> */
    protected static function panelTopCustomerSales(array $w): array
    {
        $rows = self::ordersQuery($w['start'], $w['end'], $w['storeId'])
            ->selectRaw('customer_id, COUNT(*) as orders, SUM(grand_total) as total')
            ->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        return $rows->map(function ($row) {
            $customer = Customer::query()->find($row->customer_id);

            return [
                'id' => $row->customer_id ? (int) $row->customer_id : null,
                'email' => $customer?->email,
                'full_name' => trim(($customer?->first_name ?? '').' '.($customer?->last_name ?? '')),
                'total' => round((float) $row->total, 2),
                'orders' => (int) $row->orders,
                'progress' => 0,
                'formatted_total' => number_format((float) $row->total, 2, '.', ''),
            ];
        })->values()->all();
    }

    /** @param  array{start: Carbon, end: Carbon, prevStart: Carbon, prevEnd: Carbon, storeId: string}  $w @return list<array<string, mixed>> */
    protected static function panelTopCustomerOrders(array $w): array
    {
        return collect(self::panelTopCustomerSales($w))->map(fn ($row) => [
            'id' => $row['id'],
            'email' => $row['email'],
            'full_name' => $row['full_name'],
            'orders' => $row['orders'],
            'progress' => 0,
        ])->values()->all();
    }

    /** @param  array{start: Carbon, end: Carbon, prevStart: Carbon, prevEnd: Carbon, storeId: string}  $w @return list<array<string, mixed>> */
    protected static function panelTopCustomerReviews(array $w): array
    {
        if (! Schema::hasTable('reviews')) {
            return [];
        }
        $rows = Review::query()
            ->selectRaw('customer_id, COUNT(*) as reviews')
            ->whereNotNull('customer_id')
            ->whereBetween('created_at', [$w['start'], $w['end']])
            ->groupBy('customer_id')
            ->orderByDesc('reviews')
            ->limit(10)
            ->get();

        return $rows->map(function ($row) {
            $customer = Customer::query()->find($row->customer_id);

            return [
                'id' => $row->customer_id ? (int) $row->customer_id : null,
                'email' => $customer?->email,
                'full_name' => trim(($customer?->first_name ?? '').' '.($customer?->last_name ?? '')),
                'reviews' => (int) $row->reviews,
                'progress' => 0,
            ];
        })->values()->all();
    }

    /** @param  array{start: Carbon, end: Carbon, prevStart: Carbon, prevEnd: Carbon, storeId: string}  $w @return list<array<string, mixed>> */
    protected static function panelTopCustomerGroups(array $w): array
    {
        if (! Schema::hasTable('customers') || ! Schema::hasColumn('customers', 'customer_group_id')) {
            return CustomerGroup::query()->limit(10)->get()->map(fn ($g) => [
                'id' => (int) $g->id,
                'group_name' => (string) $g->name,
                'total' => 0,
                'progress' => 0,
            ])->all();
        }
        $rows = Customer::query()
            ->selectRaw('customer_group_id, COUNT(*) as total')
            ->whereNotNull('customer_group_id')
            ->whereBetween('created_at', [$w['start'], $w['end']])
            ->groupBy('customer_group_id')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        return $rows->map(function ($row) {
            $group = CustomerGroup::query()->find($row->customer_group_id);

            return [
                'id' => (int) $row->customer_group_id,
                'group_name' => (string) ($group?->name ?? 'Group '.$row->customer_group_id),
                'total' => (int) $row->total,
                'progress' => 0,
            ];
        })->values()->all();
    }

    /** @param  array{start: Carbon, end: Carbon, prevStart: Carbon, prevEnd: Carbon, storeId: string}  $w */
    protected static function panelSoldQty(array $w): array
    {
        $curr = (float) OrderItem::query()
            ->whereHas('order', fn ($q) => $q->where('status', '!=', 'cancelled')->whereBetween('created_at', [$w['start'], $w['end']]))
            ->sum('qty');
        $prev = (float) OrderItem::query()
            ->whereHas('order', fn ($q) => $q->where('status', '!=', 'cancelled')->whereBetween('created_at', [$w['prevStart'], $w['prevEnd']]))
            ->sum('qty');

        return [
            'quantities' => [
                'previous' => $prev,
                'current' => $curr,
                'progress' => self::progress($prev, $curr),
            ],
            'over_time' => [
                'previous' => self::overTime($w['prevStart'], $w['prevEnd'], 'orders', $w['storeId']),
                'current' => self::overTime($w['start'], $w['end'], 'orders', $w['storeId']),
            ],
        ];
    }

    /**
     * @param  array{start: Carbon, end: Carbon, prevStart: Carbon, prevEnd: Carbon, storeId: string}  $w
     * @return list<array<string, mixed>>
     */
    protected static function panelTopProducts(array $w, string $mode): array
    {
        $select = $mode === 'qty'
            ? 'product_id, MAX(name) as name, SUM(qty) as metric, MAX(unit_price) as price'
            : 'product_id, MAX(name) as name, SUM(qty * unit_price) as metric, MAX(unit_price) as price';
        $rows = OrderItem::query()
            ->selectRaw($select)
            ->whereHas('order', fn ($q) => $q->where('status', '!=', 'cancelled')->whereBetween('created_at', [$w['start'], $w['end']]))
            ->groupBy('product_id')
            ->orderByDesc('metric')
            ->limit(5)
            ->get();

        return $rows->map(function ($row) use ($mode) {
            $base = [
                'id' => (int) $row->product_id,
                'name' => (string) $row->name,
                'price' => (float) $row->price,
                'formatted_price' => number_format((float) $row->price, 2, '.', ''),
                'progress' => 0,
                'images' => [],
            ];
            if ($mode === 'qty') {
                $base['total_qty_ordered'] = (float) $row->metric;
            } else {
                $base['revenue'] = round((float) $row->metric, 2);
                $base['formatted_revenue'] = number_format((float) $row->metric, 2, '.', '');
            }

            return $base;
        })->values()->all();
    }

    /** @param  array{start: Carbon, end: Carbon, prevStart: Carbon, prevEnd: Carbon, storeId: string}  $w @return list<array<string, mixed>> */
    protected static function panelProductsReviews(array $w): array
    {
        if (! Schema::hasTable('reviews')) {
            return [];
        }
        $rows = Review::query()
            ->selectRaw('product_id, COUNT(*) as reviews')
            ->whereBetween('created_at', [$w['start'], $w['end']])
            ->groupBy('product_id')
            ->orderByDesc('reviews')
            ->limit(10)
            ->get();

        return $rows->map(fn ($row) => [
            'product_id' => (int) $row->product_id,
            'product_name' => (string) (OrderItem::query()->where('product_id', $row->product_id)->value('name') ?? 'Product '.$row->product_id),
            'reviews' => (int) $row->reviews,
            'progress' => 0,
        ])->values()->all();
    }

    /**
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function viewSales(array $args): array
    {
        self::assertAdmin();
        $type = trim((string) ($args['type'] ?? 'total-sales')) ?: 'total-sales';
        if ($type === 'purchase-funnel') {
            throw new UserError('purchase-funnel is chart-only and has no View Details table.');
        }
        $stats = self::sales($args);
        $decoded = json_decode((string) $stats['statistics'], true) ?: [];
        [$columns, $records] = self::tableFromSalesPanel($type, $decoded);

        return [
            'entity' => 'sales',
            'type' => $type,
            'dateRange' => $stats['dateRange'],
            'statistics' => json_encode(['columns' => $columns, 'records' => $records], JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function viewCustomers(array $args): array
    {
        self::assertAdmin();
        $type = trim((string) ($args['type'] ?? 'total-customers')) ?: 'total-customers';
        if ($type === 'customers-traffic') {
            throw new UserError('customers-traffic is chart-only and has no View Details table.');
        }
        $stats = self::customers($args);
        $decoded = json_decode((string) $stats['statistics'], true) ?: [];
        [$columns, $records] = self::tableFromCustomersPanel($type, $decoded);

        return [
            'entity' => 'customers',
            'type' => $type,
            'dateRange' => $stats['dateRange'],
            'statistics' => json_encode(['columns' => $columns, 'records' => $records], JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function viewProducts(array $args): array
    {
        self::assertAdmin();
        $type = trim((string) ($args['type'] ?? 'total-sold-quantities')) ?: 'total-sold-quantities';
        if ($type === 'top-search-terms') {
            throw new UserError('top-search-terms is stats-only and has no View Details table.');
        }
        $stats = self::products($args);
        $decoded = json_decode((string) $stats['statistics'], true) ?: [];
        [$columns, $records] = self::tableFromProductsPanel($type, $decoded);

        return [
            'entity' => 'products',
            'type' => $type,
            'dateRange' => $stats['dateRange'],
            'statistics' => json_encode(['columns' => $columns, 'records' => $records], JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{0: list<array{key: string, label: string}>, 1: list<array<string, mixed>>}
     */
    protected static function tableFromSalesPanel(string $type, array $decoded): array
    {
        if (in_array($type, ['top-payment-methods', 'sales-by-coupon'], true) && array_is_list($decoded)) {
            $columns = $type === 'top-payment-methods'
                ? [['key' => 'title', 'label' => 'Method'], ['key' => 'formatted_total', 'label' => 'Total']]
                : [['key' => 'coupon_code', 'label' => 'Coupon'], ['key' => 'formatted_discount_total', 'label' => 'Discount']];

            return [$columns, $decoded];
        }
        $series = $decoded['over_time']['current'] ?? [];
        $columns = [['key' => 'label', 'label' => 'Date'], ['key' => 'total', 'label' => 'Total'], ['key' => 'count', 'label' => 'Count']];

        return [$columns, is_array($series) ? $series : []];
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{0: list<array{key: string, label: string}>, 1: list<array<string, mixed>>}
     */
    protected static function tableFromCustomersPanel(string $type, array $decoded): array
    {
        if ($type === 'total-customers') {
            $series = $decoded['over_time']['current'] ?? [];

            return [
                [['key' => 'label', 'label' => 'Date'], ['key' => 'total', 'label' => 'Total']],
                is_array($series) ? $series : [],
            ];
        }
        if (array_is_list($decoded)) {
            $columns = match ($type) {
                'customers-with-most-sales' => [
                    ['key' => 'full_name', 'label' => 'Name'],
                    ['key' => 'email', 'label' => 'Email'],
                    ['key' => 'formatted_total', 'label' => 'Total'],
                ],
                'customers-with-most-orders' => [
                    ['key' => 'full_name', 'label' => 'Name'],
                    ['key' => 'email', 'label' => 'Email'],
                    ['key' => 'orders', 'label' => 'Orders'],
                ],
                'customers-with-most-reviews' => [
                    ['key' => 'full_name', 'label' => 'Name'],
                    ['key' => 'email', 'label' => 'Email'],
                    ['key' => 'reviews', 'label' => 'Reviews'],
                ],
                default => [
                    ['key' => 'group_name', 'label' => 'Group'],
                    ['key' => 'total', 'label' => 'Total'],
                ],
            };

            return [$columns, $decoded];
        }

        return [[['key' => 'label', 'label' => 'Label'], ['key' => 'total', 'label' => 'Total']], []];
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{0: list<array{key: string, label: string}>, 1: list<array<string, mixed>>}
     */
    protected static function tableFromProductsPanel(string $type, array $decoded): array
    {
        if (in_array($type, ['total-sold-quantities', 'total-products-added-to-wishlist'], true)) {
            $series = $decoded['over_time']['current'] ?? [];
            $label = $type === 'total-products-added-to-wishlist' ? 'Adds' : 'Qty';

            return [
                [['key' => 'label', 'label' => 'Date'], ['key' => 'total', 'label' => $label]],
                is_array($series) ? $series : [],
            ];
        }
        if (array_is_list($decoded)) {
            $columns = match ($type) {
                'top-selling-products-by-quantity' => [
                    ['key' => 'name', 'label' => 'Product'],
                    ['key' => 'total_qty_ordered', 'label' => 'Qty'],
                ],
                'products-with-most-reviews' => [
                    ['key' => 'product_name', 'label' => 'Product'],
                    ['key' => 'reviews', 'label' => 'Reviews'],
                ],
                'products-with-most-visits' => [
                    ['key' => 'name', 'label' => 'Product'],
                    ['key' => 'visits', 'label' => 'Visits'],
                ],
                'last-search-terms' => [
                    ['key' => 'term', 'label' => 'Term'],
                    ['key' => 'uses', 'label' => 'Uses'],
                    ['key' => 'results', 'label' => 'Results'],
                ],
                default => [
                    ['key' => 'name', 'label' => 'Product'],
                    ['key' => 'formatted_revenue', 'label' => 'Revenue'],
                ],
            };

            return [$columns, $decoded];
        }

        return [[['key' => 'label', 'label' => 'Label'], ['key' => 'total', 'label' => 'Total']], []];
    }

    /** @param  array{start: Carbon, end: Carbon, prevStart: Carbon, prevEnd: Carbon, storeId: string}  $w */
    protected static function panelWishlist(array $w): array
    {
        $prev = 0.0;
        $curr = 0.0;
        $overPrev = [];
        $overCurr = [];
        if (Schema::hasTable('wishlists')) {
            $prev = (float) DB::table('wishlists')->whereBetween('created_at', [$w['prevStart'], $w['prevEnd']])->count();
            $curr = (float) DB::table('wishlists')->whereBetween('created_at', [$w['start'], $w['end']])->count();
            $overPrev = self::overTimeWishlist($w['prevStart'], $w['prevEnd']);
            $overCurr = self::overTimeWishlist($w['start'], $w['end']);
        }

        return [
            'wishlist' => [
                'previous' => $prev,
                'current' => $curr,
                'progress' => self::progress($prev, $curr),
            ],
            'over_time' => [
                'previous' => $overPrev,
                'current' => $overCurr,
            ],
        ];
    }

    /**
     * @return list<array{label: string, total: float|int}>
     */
    protected static function overTimeWishlist(Carbon $start, Carbon $end): array
    {
        $rows = [];
        $cursor = (clone $start)->startOfDay();
        $guard = 0;
        while ($cursor->lte($end) && $guard < 62) {
            $bucketStart = clone $cursor;
            $bucketEnd = (clone $cursor)->endOfDay();
            if ($bucketEnd->gt($end)) {
                $bucketEnd = clone $end;
            }
            $total = (int) DB::table('wishlists')->whereBetween('created_at', [$bucketStart, $bucketEnd])->count();
            $rows[] = ['label' => $bucketStart->toDateString(), 'total' => $total];
            $cursor = (clone $bucketStart)->addDay()->startOfDay();
            $guard++;
        }

        return $rows;
    }

    /**
     * @param  array{start: Carbon, end: Carbon, prevStart: Carbon, prevEnd: Carbon, storeId: string}  $w
     * @return list<array<string, mixed>>
     */
    protected static function panelProductVisits(array $w): array
    {
        if (! Schema::hasTable('commerce_events')) {
            return [];
        }
        $rows = CommerceEvent::query()
            ->where('name', 'catalog.product_view')
            ->whereBetween('created_at', [$w['start'], $w['end']])
            ->orderByDesc('id')
            ->limit(500)
            ->get();
        $counts = [];
        $names = [];
        foreach ($rows as $event) {
            $payload = is_array($event->payload) ? $event->payload : [];
            $pid = (int) ($payload['product_id'] ?? 0);
            if ($pid < 1) {
                continue;
            }
            $counts[$pid] = ($counts[$pid] ?? 0) + 1;
            if (! isset($names[$pid])) {
                $names[$pid] = (string) ($payload['name'] ?? ('Product '.$pid));
            }
        }
        arsort($counts);
        $out = [];
        foreach (array_slice($counts, 0, 10, true) as $pid => $visits) {
            $out[] = [
                'visitable_id' => (int) $pid,
                'name' => $names[$pid] ?? ('Product '.$pid),
                'visits' => (int) $visits,
                'progress' => 0,
            ];
        }

        return $out;
    }

    /**
     * @param  array{start: Carbon, end: Carbon, prevStart: Carbon, prevEnd: Carbon, storeId: string}  $w
     * @return list<array<string, mixed>>
     */
    protected static function panelSearchTerms(array $w, string $mode): array
    {
        $fromEvents = [];
        if (Schema::hasTable('commerce_events')) {
            $events = CommerceEvent::query()
                ->where('name', 'catalog.search')
                ->whereBetween('created_at', [$w['start'], $w['end']])
                ->orderByDesc('id')
                ->limit(500)
                ->get();
            $agg = [];
            foreach ($events as $event) {
                $payload = is_array($event->payload) ? $event->payload : [];
                $term = trim((string) ($payload['term'] ?? $payload['q'] ?? ''));
                if ($term === '') {
                    continue;
                }
                $key = mb_strtolower($term);
                if (! isset($agg[$key])) {
                    $agg[$key] = [
                        'id' => (int) $event->id,
                        'term' => $term,
                        'results' => (int) ($payload['results'] ?? 0),
                        'uses' => 0,
                        'store_id' => null,
                        'locale' => (string) ($payload['locale'] ?? app()->getLocale()),
                    ];
                }
                $agg[$key]['uses']++;
                $agg[$key]['results'] = max($agg[$key]['results'], (int) ($payload['results'] ?? 0));
                if ($mode === 'last') {
                    $agg[$key]['id'] = (int) $event->id;
                }
            }
            $fromEvents = array_values($agg);
        }

        if ($fromEvents === [] && Schema::hasTable('search_synonyms')) {
            $fromEvents = SearchSynonym::query()
                ->orderByDesc('id')
                ->limit(10)
                ->get()
                ->map(fn (SearchSynonym $row) => [
                    'id' => (int) $row->id,
                    'term' => (string) $row->term,
                    'results' => 0,
                    'uses' => 1,
                    'store_id' => null,
                    'locale' => app()->getLocale(),
                ])->all();
        }

        if ($mode === 'top') {
            usort($fromEvents, fn ($a, $b) => ((int) $b['uses']) <=> ((int) $a['uses']));
        } else {
            usort($fromEvents, fn ($a, $b) => ((int) $b['id']) <=> ((int) $a['id']));
        }

        return array_slice($fromEvents, 0, 10);
    }
}
