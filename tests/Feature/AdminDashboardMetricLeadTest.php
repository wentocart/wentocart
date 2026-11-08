<?php

namespace Tests\Feature;

use App\Domain\Dashboard\MetricService;
use App\Domain\Dashboard\Models\Dashboard;
use App\Domain\Dashboard\Models\DashboardWidget;
use App\Domain\Order\Models\Order;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardMetricLeadTest extends TestCase
{
    use RefreshDatabase;

    public function test_metric_service_resolves_non_empty_series_for_seeded_board(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        Order::createTrusted([
            'number' => 'DASH-'.strtoupper(substr(uniqid(), -6)),
            'email' => 'dash-metric@example.test',
            'status' => 'processing',
            'payment_status' => 'paid',
            'currency_code' => 'USD',
            'subtotal' => 50,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'fee_total' => 0,
            'grand_total' => 50,
            'refunded_total' => 0,
            'amount_paid' => 50,
            'amount_due' => 0,
            'billing_address' => ['country_code' => 'US', 'line1' => '1 Dash St', 'city' => 'Austin', 'postal_code' => '78701'],
            'shipping_address' => ['country_code' => 'US', 'line1' => '1 Dash St', 'city' => 'Austin', 'postal_code' => '78701'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $board = Dashboard::query()->where('slug', 'store-overview')->first()
            ?? Dashboard::query()->firstOrFail();
        $widget = DashboardWidget::query()
            ->where('dashboard_id', $board->id)
            ->where('metric', 'revenue_by_day')
            ->first();
        if (! $widget) {
            $widget = DashboardWidget::query()->create([
                'dashboard_id' => $board->id,
                'title' => 'Revenue',
                'chart_type' => 'area',
                'metric' => 'revenue_by_day',
                'range_days' => 7,
                'col_span' => 12,
                'sort_order' => 0,
            ]);
        }

        $resolved = app(MetricService::class)->resolve($widget->fresh());
        $this->assertNotSame('empty', (string) ($resolved['kind'] ?? ''));
        $this->assertNotSame('Unknown metric.', (string) ($resolved['message'] ?? ''));

        $hasSeries = isset($resolved['labels']) || isset($resolved['points']) || isset($resolved['datasets'])
            || isset($resolved['series']) || (($resolved['kind'] ?? '') === 'series');
        $hasKpi = ($resolved['kind'] ?? '') === 'kpi' && array_key_exists('value', $resolved);
        $this->assertTrue($hasSeries || $hasKpi, json_encode($resolved));

        $this->actingAs($admin, 'admin')
            ->get('/admin/dashboards/'.$board->id)
            ->assertOk();
    }
}
