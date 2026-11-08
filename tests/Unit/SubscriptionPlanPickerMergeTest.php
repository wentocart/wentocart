<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Keep the product Subscription tab multi-select merge helpers stable.
 * Mirrors mergeSelectedPlans() / selectedPlansLabel() in ProductSubscriptionTab.jsx.
 */
class SubscriptionPlanPickerMergeTest extends TestCase
{
    public function test_selected_plans_label(): void
    {
        $this->assertSame('0 plans selected', $this->selectedPlansLabel(0));
        $this->assertSame('1 plan selected', $this->selectedPlansLabel(1));
        $this->assertSame('3 plans selected', $this->selectedPlansLabel(3));
    }

    public function test_merge_adds_one_plan(): void
    {
        $next = $this->mergeSelectedPlans([], [
            ['id' => 10, 'name' => 'Monthly'],
        ], 40);

        $this->assertCount(1, $next);
        $this->assertSame(10, $next[0]['subscription_plan_id']);
        $this->assertTrue($next[0]['is_default']);
        $this->assertSame(40.0, $next[0]['price']);
    }

    public function test_merge_adds_multiple_plans_and_sets_default_once(): void
    {
        $next = $this->mergeSelectedPlans([], [
            ['id' => 1, 'name' => 'Hourly'],
            ['id' => 2, 'name' => 'Weekly'],
            ['id' => 3, 'name' => 'Monthly'],
        ], 25);

        $this->assertCount(3, $next);
        $this->assertTrue($next[0]['is_default']);
        $this->assertFalse($next[1]['is_default']);
        $this->assertFalse($next[2]['is_default']);
        $this->assertSame([1, 2, 3], array_column($next, 'subscription_plan_id'));
    }

    public function test_merge_skips_already_assigned_plans(): void
    {
        $rows = [
            ['subscription_plan_id' => 1, 'name' => 'Hourly', 'is_default' => true],
            ['subscription_plan_id' => 2, 'name' => 'Weekly', 'is_default' => false],
        ];

        $next = $this->mergeSelectedPlans($rows, [
            ['id' => 1, 'name' => 'Hourly'],
            ['id' => 2, 'name' => 'Weekly'],
            ['id' => 3, 'name' => 'Monthly'],
        ], 25);

        $this->assertCount(3, $next);
        $this->assertSame([1, 2, 3], array_column($next, 'subscription_plan_id'));
        $this->assertTrue($next[0]['is_default']);
        $this->assertFalse($next[2]['is_default']);
    }

    public function test_merge_with_no_new_plans_returns_unchanged_rows(): void
    {
        $rows = [
            ['subscription_plan_id' => 5, 'name' => 'Yearly', 'is_default' => true],
        ];

        $next = $this->mergeSelectedPlans($rows, [
            ['id' => 5, 'name' => 'Yearly'],
        ], 25);

        $this->assertSame($rows, $next);
    }

    public function test_merge_with_empty_selection_returns_unchanged_rows(): void
    {
        $rows = [
            ['subscription_plan_id' => 5, 'name' => 'Yearly', 'is_default' => true],
        ];

        $this->assertSame($rows, $this->mergeSelectedPlans($rows, [], 25));
    }

    public function test_jsx_exposes_multi_select_controls(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/admin/Pages/Catalog/Products/ProductSubscriptionTab.jsx');
        $this->assertStringContainsString('Add Selected Plans', $source);
        $this->assertStringContainsString('mergeSelectedPlans', $source);
        $this->assertStringContainsString('selectedPlansLabel', $source);
        $this->assertStringContainsString('SelectionCheckbox', $source);
        $this->assertStringContainsString('Select at least one plan.', $source);
        $this->assertStringContainsString('useListSelection', $source);
    }

    private function selectedPlansLabel(int $count): string
    {
        $n = max(0, $count);
        if ($n === 1) {
            return '1 plan selected';
        }

        return $n.' plans selected';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $selectedPlans
     * @return list<array<string, mixed>>
     */
    private function mergeSelectedPlans(array $rows, array $selectedPlans, float $regularPrice): array
    {
        $used = [];
        foreach ($rows as $row) {
            $id = (int) ($row['subscription_plan_id'] ?? 0);
            if ($id > 0) {
                $used[$id] = true;
            }
        }

        $additions = [];
        $needsDefault = $rows === [];
        foreach ($selectedPlans as $plan) {
            $id = (int) ($plan['id'] ?? 0);
            if ($id < 1 || isset($used[$id])) {
                continue;
            }
            $used[$id] = true;
            $additions[] = [
                'subscription_plan_id' => $id,
                'name' => $plan['name'] ?? '',
                'price' => round($regularPrice, 2),
                'is_enabled' => true,
                'is_default' => $needsDefault && $additions === [],
            ];
        }

        return [...$rows, ...$additions];
    }
}
