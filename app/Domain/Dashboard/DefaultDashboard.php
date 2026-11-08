<?php

namespace App\Domain\Dashboard;

use App\Domain\Dashboard\Models\Dashboard;
use App\Models\Admin;
use Illuminate\Support\Str;

class DefaultDashboard
{
    public function install(): Dashboard
    {
        $creator = Admin::query()->orderBy('id')->first();
        $default = null;

        foreach ($this->boards() as $spec) {
            $board = Dashboard::query()->where('slug', $spec['slug'])->first();
            if (! $board) {
                $board = Dashboard::query()->create([
                    'name' => $spec['name'],
                    'slug' => $spec['slug'],
                    'description' => $spec['description'],
                    'visibility' => 'all_admins',
                    'is_default' => false,
                    'is_enabled' => true,
                    'created_by_admin_id' => $creator?->id,
                ]);
                foreach ($spec['widgets'] as $index => $widget) {
                    $board->widgets()->create($widget + ['position' => $index]);
                }
            }
            if ($spec['is_default']) {
                $default = $board;
            }
        }

        if ($default && ! Dashboard::query()->where('is_default', true)->exists()) {
            $default->update(['is_default' => true]);
        }

        return $default
            ?? Dashboard::query()->where('is_default', true)->first()
            ?? Dashboard::query()->orderBy('id')->first();
    }

    public static function slugFrom(string $name): string
    {
        $base = Str::slug($name) ?: 'dashboard';
        $slug = $base;
        $i = 2;
        while (Dashboard::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }

    /**
     * @return list<array{name: string, slug: string, description: string, is_default: bool, widgets: list<array<string, mixed>>}>
     */
    protected function boards(): array
    {
        return [
            [
                'name' => 'Store overview',
                'slug' => 'store-overview',
                'description' => 'Sales, orders, and catalog at a glance. This board opens first after login.',
                'is_default' => true,
                'widgets' => [
                    ['title' => 'Revenue', 'chart_type' => 'kpi', 'metric' => 'revenue', 'range_days' => 30, 'col_span' => 3, 'options' => ['palette' => 'violet']],
                    ['title' => 'Orders', 'chart_type' => 'kpi', 'metric' => 'orders', 'range_days' => 30, 'col_span' => 3, 'options' => ['palette' => 'sky']],
                    ['title' => 'New customers', 'chart_type' => 'kpi', 'metric' => 'customers', 'range_days' => 30, 'col_span' => 3, 'options' => ['palette' => 'emerald']],
                    ['title' => 'Average order', 'chart_type' => 'kpi', 'metric' => 'aov', 'range_days' => 30, 'col_span' => 3, 'options' => ['palette' => 'amber']],
                    ['title' => 'Revenue trend', 'chart_type' => 'area', 'metric' => 'revenue_by_day', 'range_days' => 30, 'col_span' => 8, 'options' => ['palette' => 'violet']],
                    ['title' => 'Orders by status', 'chart_type' => 'doughnut', 'metric' => 'orders_by_status', 'range_days' => 30, 'col_span' => 4, 'options' => ['palette' => 'indigo']],
                    ['title' => 'Top products', 'chart_type' => 'hbar', 'metric' => 'top_products', 'range_days' => 30, 'col_span' => 6, 'options' => ['palette' => 'sky']],
                    ['title' => 'Customer signups', 'chart_type' => 'line', 'metric' => 'customers_by_day', 'range_days' => 30, 'col_span' => 6, 'options' => ['palette' => 'emerald']],
                ],
            ],
            [
                'name' => 'Sales',
                'slug' => 'sales',
                'description' => 'Revenue, payments, and the order pipeline for the last 30 days.',
                'is_default' => false,
                'widgets' => [
                    ['title' => 'Revenue', 'chart_type' => 'kpi', 'metric' => 'revenue', 'range_days' => 30, 'col_span' => 4, 'options' => ['palette' => 'violet']],
                    ['title' => 'Orders', 'chart_type' => 'kpi', 'metric' => 'orders', 'range_days' => 30, 'col_span' => 4, 'options' => ['palette' => 'sky']],
                    ['title' => 'Average order', 'chart_type' => 'kpi', 'metric' => 'aov', 'range_days' => 30, 'col_span' => 4, 'options' => ['palette' => 'amber']],
                    ['title' => 'Revenue vs orders', 'chart_type' => 'stacked_bar', 'metric' => 'revenue_vs_orders', 'range_days' => 30, 'col_span' => 8, 'options' => ['palette' => 'violet']],
                    ['title' => 'Payments', 'chart_type' => 'pie', 'metric' => 'orders_by_payment', 'range_days' => 30, 'col_span' => 4, 'options' => ['palette' => 'sky']],
                    ['title' => 'Order pipeline', 'chart_type' => 'funnel', 'metric' => 'order_funnel', 'range_days' => 90, 'col_span' => 6, 'options' => ['palette' => 'amber']],
                    ['title' => 'Orders by status', 'chart_type' => 'polar', 'metric' => 'orders_by_status', 'range_days' => 30, 'col_span' => 6, 'options' => ['palette' => 'indigo']],
                ],
            ],
            [
                'name' => 'Catalog',
                'slug' => 'catalog',
                'description' => 'What you sell, what is low on stock, and which products bring in revenue.',
                'is_default' => false,
                'widgets' => [
                    ['title' => 'Products', 'chart_type' => 'kpi', 'metric' => 'products', 'range_days' => 30, 'col_span' => 4, 'options' => ['palette' => 'sky']],
                    ['title' => 'Low stock', 'chart_type' => 'kpi', 'metric' => 'low_stock', 'range_days' => 30, 'col_span' => 4, 'options' => ['palette' => 'rose']],
                    ['title' => 'Revenue', 'chart_type' => 'kpi', 'metric' => 'revenue', 'range_days' => 30, 'col_span' => 4, 'options' => ['palette' => 'violet']],
                    ['title' => 'Stock status', 'chart_type' => 'doughnut', 'metric' => 'stock_status', 'range_days' => 30, 'col_span' => 4, 'options' => ['palette' => 'emerald']],
                    ['title' => 'Revenue by category', 'chart_type' => 'treemap', 'metric' => 'revenue_by_category', 'range_days' => 90, 'col_span' => 8, 'options' => ['palette' => 'rose']],
                    ['title' => 'Top products', 'chart_type' => 'hbar', 'metric' => 'top_products', 'range_days' => 30, 'col_span' => 6, 'options' => ['palette' => 'sky']],
                    ['title' => 'Sales mix', 'chart_type' => 'radar', 'metric' => 'sales_mix', 'range_days' => 30, 'col_span' => 6, 'options' => ['palette' => 'indigo']],
                ],
            ],
            [
                'name' => 'Customers',
                'slug' => 'customers',
                'description' => 'Who is joining, how they group, and what they spend.',
                'is_default' => false,
                'widgets' => [
                    ['title' => 'New customers', 'chart_type' => 'kpi', 'metric' => 'customers', 'range_days' => 30, 'col_span' => 4, 'options' => ['palette' => 'emerald']],
                    ['title' => 'Orders', 'chart_type' => 'kpi', 'metric' => 'orders', 'range_days' => 30, 'col_span' => 4, 'options' => ['palette' => 'sky']],
                    ['title' => 'Average order', 'chart_type' => 'kpi', 'metric' => 'aov', 'range_days' => 30, 'col_span' => 4, 'options' => ['palette' => 'amber']],
                    ['title' => 'Signups', 'chart_type' => 'area', 'metric' => 'customers_by_day', 'range_days' => 30, 'col_span' => 8, 'options' => ['palette' => 'emerald']],
                    ['title' => 'By group', 'chart_type' => 'pie', 'metric' => 'customers_by_group', 'range_days' => 30, 'col_span' => 4, 'options' => ['palette' => 'violet']],
                    ['title' => 'Order value vs items', 'chart_type' => 'scatter', 'metric' => 'order_value_vs_items', 'range_days' => 30, 'col_span' => 12, 'options' => ['palette' => 'sky']],
                ],
            ],
            [
                'name' => 'Operations',
                'slug' => 'operations',
                'description' => 'Busy hours, order volume, and progress toward a 30-day order goal.',
                'is_default' => false,
                'widgets' => [
                    ['title' => 'Orders', 'chart_type' => 'kpi', 'metric' => 'orders', 'range_days' => 30, 'col_span' => 4, 'options' => ['palette' => 'sky']],
                    ['title' => 'Revenue', 'chart_type' => 'kpi', 'metric' => 'revenue', 'range_days' => 30, 'col_span' => 4, 'options' => ['palette' => 'violet']],
                    ['title' => 'Order goal', 'chart_type' => 'gauge', 'metric' => 'orders', 'range_days' => 30, 'col_span' => 4, 'options' => ['palette' => 'amber', 'target' => 50]],
                    ['title' => 'Orders by day', 'chart_type' => 'bar', 'metric' => 'orders_by_day', 'range_days' => 30, 'col_span' => 12, 'options' => ['palette' => 'sky']],
                    ['title' => 'Busy hours', 'chart_type' => 'heatmap', 'metric' => 'orders_heatmap', 'range_days' => 30, 'col_span' => 12, 'options' => ['palette' => 'violet']],
                ],
            ],
        ];
    }
}
