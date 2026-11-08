<?php

namespace App\Domain\Dashboard;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Dashboard\Models\Dashboard;
use App\Domain\Dashboard\Models\DashboardWidget;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class MetricService
{
    public function present(Dashboard $dashboard): array
    {
        $dashboard->loadMissing(['widgets', 'assignees', 'creator']);
        $widgets = $dashboard->widgets->sortBy('position')->values();

        return [
            'id' => $dashboard->id,
            'name' => $dashboard->name,
            'description' => $dashboard->description,
            'visibility' => $dashboard->visibility,
            'visibility_label' => $dashboard->visibilityLabel(),
            'is_default' => $dashboard->is_default,
            'is_enabled' => $dashboard->is_enabled,
            'assignees' => $dashboard->assignees->map(fn ($admin) => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
            ])->values(),
            'widgets' => $widgets->map(fn (DashboardWidget $widget) => $this->presentWidget($widget))->values(),
        ];
    }

    /** Card payload for the dashboards index — same widget data as the board view. */
    public function presentCard(Dashboard $dashboard): array
    {
        $dashboard->loadMissing(['widgets', 'assignees']);
        $widgets = $dashboard->widgets->sortBy('position')->values();
        $chartsCount = $widgets->filter(fn (DashboardWidget $widget) => ! in_array($widget->chart_type, ['kpi', 'gauge'], true))->count();

        return [
            'id' => $dashboard->id,
            'name' => $dashboard->name,
            'slug' => $dashboard->slug,
            'description' => $dashboard->description,
            'visibility' => $dashboard->visibility,
            'visibility_label' => $dashboard->visibilityLabel(),
            'is_default' => $dashboard->is_default,
            'is_enabled' => $dashboard->is_enabled,
            'updated_at' => optional($dashboard->updated_at)?->diffForHumans(),
            'widgets_count' => $widgets->count(),
            'charts_count' => $chartsCount,
            'assignees' => $dashboard->assignees->map(fn ($admin) => $admin->name)->values(),
            'widgets' => $widgets->map(fn (DashboardWidget $widget) => $this->presentWidget($widget))->values(),
        ];
    }

    /** @return array<string, mixed> */
    public function presentWidget(DashboardWidget $widget): array
    {
        try {
            $data = $this->resolve($widget);
        } catch (Throwable $e) {
            $data = [
                'kind' => 'error',
                'message' => 'This widget could not load its data.',
            ];
        }

        return [
            'id' => $widget->id,
            'title' => $widget->title,
            'chart_type' => $widget->chart_type,
            'metric' => $widget->metric,
            'metric_label' => ChartCatalog::METRICS[$widget->metric]['label'] ?? $widget->metric,
            'metric_label_key' => isset(ChartCatalog::METRICS[$widget->metric])
                ? 'admin.dashboards.metric_'.$widget->metric
                : null,
            'range_days' => $widget->range_days,
            'range_label' => ChartCatalog::rangeLabel((int) $widget->range_days),
            'range_label_key' => isset(ChartCatalog::RANGES[(int) $widget->range_days])
                ? 'admin.dashboards.range_'.(int) $widget->range_days
                : null,
            'col_span' => $widget->col_span,
            'row_span' => max(ChartCatalog::ROW_MIN, min(ChartCatalog::ROW_MAX, (int) $widget->option('row_span', $widget->chart_type === 'kpi' ? 1 : 2))),
            'grid_x' => $widget->option('grid_x') === null ? null : (int) $widget->option('grid_x'),
            'grid_y' => $widget->option('grid_y') === null ? null : (int) $widget->option('grid_y'),
            'palette' => ChartCatalog::normalizePalette($widget->option('palette', 'violet')),
            'target' => (float) $widget->option('target', 100),
            'data' => $data,
        ];
    }

    /** @return array<string, mixed> */
    public function resolve(DashboardWidget $widget): array
    {
        $days = max(1, (int) ($widget->range_days ?: 30));
        $metric = $widget->metric ?: ChartCatalog::defaultMetricFor($widget->chart_type);

        return match ($metric) {
            'revenue' => $this->kpi('revenue', $days, fn ($from, $to) => (float) $this->sales()->whereBetween('created_at', [$from, $to])->sum('grand_total'), true),
            'orders' => $this->kpi('orders', $days, fn ($from, $to) => (float) $this->sales()->whereBetween('created_at', [$from, $to])->count(), false),
            'customers' => $this->kpi('customers', $days, fn ($from, $to) => (float) Customer::query()->whereBetween('created_at', [$from, $to])->count(), false),
            'aov' => $this->kpi('aov', $days, function ($from, $to) {
                $avg = $this->sales()->whereBetween('created_at', [$from, $to])->avg('grand_total');

                return round((float) $avg, 2);
            }, true),
            'products' => $this->staticKpi((float) Product::query()->count(), false),
            'low_stock' => $this->staticKpi((float) Product::query()->where('manage_stock', true)->whereColumn('stock_qty', '<=', 'low_stock_threshold')->count(), false),
            'revenue_by_day' => $this->seriesByDay($days, 'Revenue', fn ($from, $to) => $this->sales()
                ->selectRaw($this->dayExpr().' as bucket, SUM(grand_total) as value')
                ->whereBetween('created_at', [$from, $to])
                ->groupBy('bucket')
                ->pluck('value', 'bucket')),
            'orders_by_day' => $this->seriesByDay($days, 'Orders', fn ($from, $to) => Order::query()
                ->selectRaw($this->dayExpr().' as bucket, COUNT(*) as value')
                ->whereBetween('created_at', [$from, $to])
                ->groupBy('bucket')
                ->pluck('value', 'bucket')),
            'customers_by_day' => $this->seriesByDay($days, 'Customers', fn ($from, $to) => Customer::query()
                ->selectRaw($this->dayExpr().' as bucket, COUNT(*) as value')
                ->whereBetween('created_at', [$from, $to])
                ->groupBy('bucket')
                ->pluck('value', 'bucket')),
            'revenue_vs_orders' => $this->dualByDay($days),
            'orders_by_status' => $this->slicesFrom(
                Order::query()->whereBetween('created_at', $this->bounds($days))->selectRaw('status, COUNT(*) as value')->groupBy('status')->pluck('value', 'status'),
                fn ($key) => ucfirst(str_replace('_', ' ', (string) $key)),
            ),
            'orders_by_payment' => $this->slicesFrom(
                Order::query()
                    ->with('paymentMethod')
                    ->whereBetween('created_at', $this->bounds($days))
                    ->get()
                    ->groupBy(fn (Order $order) => $order->paymentMethod?->name ?: 'None')
                    ->map->count(),
            ),
            'customers_by_group' => $this->slicesFrom(
                Customer::query()
                    ->with('group')
                    ->get()
                    ->groupBy(fn (Customer $customer) => $customer->group?->name ?: ($customer->customer_group ?: 'Ungrouped'))
                    ->map->count(),
            ),
            'top_products' => $this->topProducts($days),
            'revenue_by_category' => $this->revenueByCategory($days),
            'stock_status' => $this->stockStatus(),
            'order_funnel' => $this->orderFunnel($days),
            'sales_mix' => $this->salesMix($days),
            'order_value_vs_items' => $this->scatter($days),
            'orders_heatmap' => $this->heatmap($days),
            'manual' => $this->manual($widget),
            default => ['kind' => 'empty', 'message' => 'Unknown metric.'],
        };
    }

    /** @param  callable(Carbon, Carbon): float  $fn */
    protected function kpi(string $format, int $days, callable $fn, bool $money): array
    {
        [$from, $to] = $this->bounds($days);
        $prevTo = $from->copy()->subSecond();
        $prevFrom = $from->copy()->subDays($days);
        $current = round($fn($from, $to), 2);
        $previous = round($fn($prevFrom, $prevTo), 2);
        $delta = $previous == 0.0 ? ($current > 0 ? 100.0 : 0.0) : round((($current - $previous) / $previous) * 100, 1);

        return [
            'kind' => 'kpi',
            'value' => $current,
            'previous' => $previous,
            'delta' => $delta,
            'money' => $money,
            'empty' => $current == 0.0 && $previous == 0.0,
        ];
    }

    protected function staticKpi(float $value, bool $money): array
    {
        return [
            'kind' => 'kpi',
            'value' => $value,
            'previous' => null,
            'delta' => null,
            'money' => $money,
            'empty' => $value == 0.0,
        ];
    }

    /** @param  callable(Carbon, Carbon): Collection  $fn */
    protected function seriesByDay(int $days, string $name, callable $fn): array
    {
        [$from, $to] = $this->bounds($days);
        $map = $fn($from, $to);
        $labels = [];
        $data = [];
        for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addDay()) {
            $key = $cursor->toDateString();
            $labels[] = $cursor->format('M j');
            $data[] = round((float) ($map[$key] ?? 0), 2);
        }

        return [
            'kind' => 'series',
            'labels' => $labels,
            'series' => [['name' => $name, 'data' => $data]],
            'empty' => collect($data)->sum() == 0,
        ];
    }

    protected function dualByDay(int $days): array
    {
        $revenue = $this->seriesByDay($days, 'Revenue', fn ($from, $to) => $this->sales()
            ->selectRaw($this->dayExpr().' as bucket, SUM(grand_total) as value')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('bucket')
            ->pluck('value', 'bucket'));
        $orders = $this->seriesByDay($days, 'Orders', fn ($from, $to) => Order::query()
            ->selectRaw($this->dayExpr().' as bucket, COUNT(*) as value')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('bucket')
            ->pluck('value', 'bucket'));

        return [
            'kind' => 'series',
            'labels' => $revenue['labels'],
            'series' => [
                $revenue['series'][0],
                $orders['series'][0],
            ],
            'empty' => $revenue['empty'] && $orders['empty'],
        ];
    }

    protected function slicesFrom(Collection $rows, ?callable $label = null): array
    {
        $slices = $rows->map(function ($value, $key) use ($label) {
            return [
                'name' => $label ? $label($key) : (string) $key,
                'value' => round((float) $value, 2),
            ];
        })->values()->all();

        return [
            'kind' => 'slices',
            'slices' => $slices,
            'empty' => collect($slices)->sum('value') == 0,
        ];
    }

    protected function topProducts(int $days): array
    {
        $rows = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNotIn('orders.status', ['cancelled', 'refunded'])
            ->whereBetween('orders.created_at', $this->bounds($days))
            ->selectRaw('order_items.name as label, SUM(order_items.total) as value')
            ->groupBy('order_items.name')
            ->orderByDesc('value')
            ->limit(8)
            ->pluck('value', 'label');

        return $this->slicesFrom($rows);
    }

    protected function revenueByCategory(int $days): array
    {
        $locale = app()->getLocale();
        $rows = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.default_category_id')
            ->leftJoin('category_translations', function ($join) use ($locale) {
                $join->on('category_translations.category_id', '=', 'categories.id')
                    ->where('category_translations.language_code', '=', $locale);
            })
            ->whereNotIn('orders.status', ['cancelled', 'refunded'])
            ->whereBetween('orders.created_at', $this->bounds($days))
            ->selectRaw('COALESCE(category_translations.name, ?) as label, SUM(order_items.total) as value', ['Uncategorized'])
            ->groupBy('label')
            ->orderByDesc('value')
            ->pluck('value', 'label');

        return $this->slicesFrom($rows);
    }

    protected function stockStatus(): array
    {
        $in = Product::query()->where('stock_qty', '>', 0)->count();
        $out = Product::query()->where('stock_qty', '<=', 0)->count();

        return $this->slicesFrom(collect([
            'In stock' => $in,
            'Out of stock' => $out,
        ]));
    }

    protected function orderFunnel(int $days): array
    {
        $counts = Order::query()
            ->whereBetween('created_at', $this->bounds($days))
            ->selectRaw('status, COUNT(*) as value')
            ->groupBy('status')
            ->pluck('value', 'status');

        $order = ['pending', 'processing', 'shipped', 'completed'];
        $rows = collect($order)->mapWithKeys(fn ($status) => [ucfirst($status) => (int) ($counts[$status] ?? 0)]);

        return $this->slicesFrom($rows);
    }

    protected function salesMix(int $days): array
    {
        $row = $this->sales()->whereBetween('created_at', $this->bounds($days))
            ->selectRaw('COALESCE(SUM(subtotal),0) as subtotal, COALESCE(SUM(tax_total),0) as tax, COALESCE(SUM(shipping_total),0) as shipping, COALESCE(SUM(discount_total),0) as discount')
            ->first();

        $values = [
            round((float) ($row->subtotal ?? 0), 2),
            round((float) ($row->tax ?? 0), 2),
            round((float) ($row->shipping ?? 0), 2),
            round((float) ($row->discount ?? 0), 2),
        ];

        return [
            'kind' => 'radar',
            'indicators' => [
                ['name' => 'Subtotal', 'max' => max(1, $values[0])],
                ['name' => 'Tax', 'max' => max(1, $values[1])],
                ['name' => 'Shipping', 'max' => max(1, $values[2])],
                ['name' => 'Discount', 'max' => max(1, $values[3])],
            ],
            'values' => $values,
            'empty' => collect($values)->sum() == 0,
        ];
    }

    protected function scatter(int $days): array
    {
        $points = Order::query()
            ->whereBetween('created_at', $this->bounds($days))
            ->withCount('items')
            ->latest('id')
            ->limit(200)
            ->get(['id', 'grand_total'])
            ->map(fn (Order $order) => [(int) $order->items_count, round((float) $order->grand_total, 2)])
            ->all();

        return [
            'kind' => 'scatter',
            'points' => $points,
            'x_label' => 'Line items',
            'y_label' => 'Order total',
            'empty' => $points === [],
        ];
    }

    protected function heatmap(int $days): array
    {
        [$from, $to] = $this->bounds($days);
        $hourExpr = $this->sqlite() ? "CAST(strftime('%H', created_at) as integer)" : 'HOUR(created_at)';
        $dowExpr = $this->sqlite() ? "CAST(strftime('%w', created_at) as integer)" : 'DAYOFWEEK(created_at) - 1';

        $rows = Order::query()
            ->selectRaw("{$dowExpr} as dow, {$hourExpr} as hour, COUNT(*) as value")
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('dow', 'hour')
            ->get();

        $cells = [];
        foreach ($rows as $row) {
            $cells[] = [(int) $row->hour, (int) $row->dow, (int) $row->value];
        }

        return [
            'kind' => 'heatmap',
            'x' => range(0, 23),
            'y' => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
            'cells' => $cells,
            'empty' => $cells === [],
        ];
    }

    protected function manual(DashboardWidget $widget): array
    {
        $labels = $this->csv($widget->option('manual_labels', 'A,B,C'));
        $values = array_map(fn ($v) => round((float) $v, 2), $this->csv($widget->option('manual_values', '10,20,15')));
        $count = max(count($labels), count($values), 1);
        $labels = array_pad($labels, $count, '');
        $values = array_pad($values, $count, 0);
        $slices = [];
        for ($i = 0; $i < $count; $i++) {
            $slices[] = ['name' => $labels[$i] !== '' ? $labels[$i] : 'Item '.($i + 1), 'value' => $values[$i]];
        }

        return [
            'kind' => 'series',
            'labels' => array_column($slices, 'name'),
            'series' => [['name' => $widget->title ?: 'Custom', 'data' => array_column($slices, 'value')]],
            'slices' => $slices,
            'empty' => collect($values)->sum() == 0,
        ];
    }

    protected function sales()
    {
        return Order::query()->whereNotIn('status', ['cancelled', 'refunded']);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    protected function bounds(int $days): array
    {
        return [now()->subDays($days - 1)->startOfDay(), now()->endOfDay()];
    }

    protected function dayExpr(string $column = 'created_at'): string
    {
        return $this->sqlite()
            ? "strftime('%Y-%m-%d', {$column})"
            : "DATE({$column})";
    }

    protected function sqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    /** @return list<string> */
    protected function csv(mixed $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn ($part) => $part !== ''));
    }
}
