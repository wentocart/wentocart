<?php

namespace App\Domain\Dashboard;

class ChartCatalog
{
    public const CHARTS = [
        'kpi' => ['label' => 'KPI number', 'hint' => 'Big figure with period change'],
        'line' => ['label' => 'Line', 'hint' => 'Trend over time'],
        'area' => ['label' => 'Area', 'hint' => 'Filled trend over time'],
        'bar' => ['label' => 'Bar', 'hint' => 'Compare categories or days'],
        'stacked_bar' => ['label' => 'Stacked bar', 'hint' => 'Parts of a total by day'],
        'hbar' => ['label' => 'Horizontal bar', 'hint' => 'Ranked list'],
        'pie' => ['label' => 'Pie', 'hint' => 'Share of a whole'],
        'doughnut' => ['label' => 'Doughnut', 'hint' => 'Share with a hollow center'],
        'polar' => ['label' => 'Polar bar', 'hint' => 'Circular category comparison'],
        'radar' => ['label' => 'Radar', 'hint' => 'Several measures on one shape'],
        'funnel' => ['label' => 'Funnel', 'hint' => 'Pipeline stages'],
        'scatter' => ['label' => 'Scatter', 'hint' => 'Two values per order'],
        'gauge' => ['label' => 'Gauge', 'hint' => 'Progress toward a target'],
        'heatmap' => ['label' => 'Heatmap', 'hint' => 'Weekday × hour intensity'],
        'treemap' => ['label' => 'Treemap', 'hint' => 'Nested rectangles by size'],
    ];

    public const RANGES = [
        1 => 'Today',
        7 => 'Last 7 days',
        14 => 'Last 14 days',
        30 => 'Last 30 days',
        90 => 'Last quarter',
        180 => 'Last 6 months',
        365 => 'Last 12 months',
        730 => 'Last 2 years',
    ];

    public const RANGE_MIN = 1;

    public const RANGE_MAX = 3650;

    public const SPAN_MIN = 1;

    public const SPAN_MAX = 12;

    public const ROW_MIN = 1;

    public const ROW_MAX = 8;

    public const GRID_Y_MAX = 80;

    public const SPANS = [
        3 => 'Quarter width',
        4 => 'Third width',
        6 => 'Half width',
        8 => 'Two thirds',
        12 => 'Full width',
    ];

    public const PALETTES = [
        'violet' => ['#7C3AED', '#A78BFA', '#C4B5FD'],
        'sky' => ['#0284C7', '#38BDF8', '#7DD3FC'],
        'emerald' => ['#059669', '#34D399', '#6EE7B7'],
        'amber' => ['#D97706', '#FBBF24', '#FDE68A'],
        'rose' => ['#E11D48', '#FB7185', '#FECDD3'],
        'indigo' => ['#4F46E5', '#818CF8', '#C7D2FE'],
    ];

    public const METRICS = [
        'revenue' => ['label' => 'Revenue', 'kind' => 'kpi', 'charts' => ['kpi', 'gauge']],
        'orders' => ['label' => 'Orders', 'kind' => 'kpi', 'charts' => ['kpi', 'gauge']],
        'customers' => ['label' => 'Customers', 'kind' => 'kpi', 'charts' => ['kpi', 'gauge']],
        'aov' => ['label' => 'Average order value', 'kind' => 'kpi', 'charts' => ['kpi', 'gauge']],
        'products' => ['label' => 'Products in catalog', 'kind' => 'kpi', 'charts' => ['kpi']],
        'low_stock' => ['label' => 'Low-stock products', 'kind' => 'kpi', 'charts' => ['kpi']],
        'revenue_by_day' => ['label' => 'Revenue by day', 'kind' => 'series', 'charts' => ['line', 'area', 'bar']],
        'orders_by_day' => ['label' => 'Orders by day', 'kind' => 'series', 'charts' => ['line', 'area', 'bar']],
        'customers_by_day' => ['label' => 'New customers by day', 'kind' => 'series', 'charts' => ['line', 'area', 'bar']],
        'revenue_vs_orders' => ['label' => 'Revenue vs orders', 'kind' => 'dual', 'charts' => ['line', 'area', 'bar', 'stacked_bar']],
        'orders_by_status' => ['label' => 'Orders by status', 'kind' => 'slices', 'charts' => ['pie', 'doughnut', 'bar', 'hbar', 'polar', 'funnel']],
        'orders_by_payment' => ['label' => 'Orders by payment', 'kind' => 'slices', 'charts' => ['pie', 'doughnut', 'bar', 'hbar', 'polar']],
        'customers_by_group' => ['label' => 'Customers by group', 'kind' => 'slices', 'charts' => ['pie', 'doughnut', 'bar', 'hbar', 'polar']],
        'top_products' => ['label' => 'Top products by revenue', 'kind' => 'slices', 'charts' => ['hbar', 'bar', 'treemap', 'pie']],
        'revenue_by_category' => ['label' => 'Revenue by category', 'kind' => 'slices', 'charts' => ['treemap', 'pie', 'doughnut', 'hbar', 'bar']],
        'stock_status' => ['label' => 'Catalog stock status', 'kind' => 'slices', 'charts' => ['pie', 'doughnut', 'bar']],
        'order_funnel' => ['label' => 'Order pipeline', 'kind' => 'slices', 'charts' => ['funnel', 'hbar']],
        'sales_mix' => ['label' => 'Sales mix', 'kind' => 'radar', 'charts' => ['radar']],
        'order_value_vs_items' => ['label' => 'Order value vs items', 'kind' => 'scatter', 'charts' => ['scatter']],
        'orders_heatmap' => ['label' => 'Orders by weekday and hour', 'kind' => 'heatmap', 'charts' => ['heatmap']],
        'manual' => ['label' => 'Custom numbers', 'kind' => 'manual', 'charts' => ['line', 'area', 'bar', 'hbar', 'pie', 'doughnut', 'polar', 'funnel', 'treemap']],
    ];

    /**
     * @return list<array{value: string, label: string, label_key: string, hint: string, hint_key: string}>
     */
    public static function chartOptions(): array
    {
        return collect(self::CHARTS)
            ->map(fn (array $row, string $value) => [
                'value' => $value,
                'label' => $row['label'],
                'label_key' => 'admin.dashboards.chart_'.$value,
                'hint' => $row['hint'],
                'hint_key' => 'admin.dashboards.chart_'.$value.'_hint',
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{value: string, label: string, label_key: string, kind: string, charts: list<string>}>
     */
    public static function metricOptions(): array
    {
        return collect(self::METRICS)
            ->map(fn (array $row, string $value) => [
                'value' => $value,
                'label' => $row['label'],
                'label_key' => 'admin.dashboards.metric_'.$value,
                'kind' => $row['kind'],
                'charts' => $row['charts'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{label: string, label_key: string}>
     */
    public static function rangeOptions(): array
    {
        $out = [];
        foreach (self::RANGES as $days => $label) {
            $out[(int) $days] = [
                'label' => $label,
                'label_key' => 'admin.dashboards.range_'.$days,
            ];
        }

        return $out;
    }

    public static function defaultMetricFor(string $chart): string
    {
        foreach (self::METRICS as $key => $row) {
            if (in_array($chart, $row['charts'], true)) {
                return $key;
            }
        }

        return 'revenue_by_day';
    }

    public static function rangeLabel(int $days): string
    {
        return self::RANGES[$days] ?? ($days === 1 ? 'Today' : 'Last '.$days.' days');
    }

    public static function normalizePalette(?string $value): string
    {
        $value = trim((string) $value);
        if ($value !== '' && isset(self::PALETTES[$value])) {
            return $value;
        }
        if (preg_match('/^#?([0-9A-Fa-f]{6})$/', $value, $match)) {
            return '#'.strtoupper($match[1]);
        }

        return 'violet';
    }

    public static function isValidPalette(?string $value): bool
    {
        $value = trim((string) $value);
        if ($value === '') {
            return true;
        }
        if (isset(self::PALETTES[$value])) {
            return true;
        }

        return (bool) preg_match('/^#?[0-9A-Fa-f]{6}$/', $value);
    }

    /** @return list<array{name: string, hex: string}> */
    public static function paletteSwatches(): array
    {
        return collect(self::PALETTES)
            ->map(fn (array $colors, string $name) => [
                'name' => $name,
                'hex' => $colors[0],
            ])
            ->values()
            ->all();
    }
}
