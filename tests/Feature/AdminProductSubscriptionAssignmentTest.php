<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Subscription\Models\ProductSubscriptionPlan;
use App\Domain\Subscription\Models\SubscriptionPlan;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProductSubscriptionAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
    }

    /** @return array{0: Product, 1: SubscriptionPlan, 2: ProductSubscriptionPlan} */
    protected function assignedProduct(): array
    {
        $this->post('/admin/subscription-plans', [
            'name' => 'Monthly Essential',
            'is_enabled' => true,
            'duration' => 0,
            'cycle' => 1,
            'frequency' => 'month',
        ]);
        $plan = SubscriptionPlan::query()->orderByDesc('id')->firstOrFail();
        $categoryId = (int) \App\Domain\Catalog\Models\Category::query()->value('id');

        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'simple',
            'name' => 'Plan Edit Product',
            'sku' => 'PLAN-EDIT-1',
            'price' => 44.99,
            'is_enabled' => true,
            'subscription_enabled' => true,
            'purchase_option' => 'subscription',
            'subscription_plans' => [[
                'subscription_plan_id' => $plan->id,
                'price' => 44.99,
                'is_enabled' => true,
                'is_default' => true,
            ]],
            'category_ids' => [$categoryId],
            'default_category_id' => $categoryId,
        ]))->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'PLAN-EDIT-1')->firstOrFail();
        $assignment = $product->productSubscriptionPlans()->firstOrFail();

        return [$product, $plan, $assignment];
    }

    public function test_editing_an_assigned_plan_persists_price_and_flags(): void
    {
        [$product, $plan, $assignment] = $this->assignedProduct();

        $this->putJson('/admin/products/'.$product->id.'/subscription-plans/'.$plan->id, [
            'price' => 39.5,
            'discount_type' => null,
            'is_enabled' => true,
            'is_default' => true,
        ])->assertOk()
            ->assertJsonPath('message', 'Subscription plan saved.')
            ->assertJsonPath('plans.0.price', 39.5)
            ->assertJsonPath('plans.0.is_enabled', true)
            ->assertJsonPath('plans.0.is_default', true);

        $assignment->refresh();
        $this->assertEqualsWithDelta(39.5, (float) $assignment->price, 0.01);
        $this->assertTrue((bool) $assignment->is_enabled);
        $this->assertTrue((bool) $assignment->is_default);
        $this->assertNull($assignment->discount_type);
    }

    public function test_percent_discount_over_100_is_rejected_without_changing_the_assignment(): void
    {
        [$product, $plan, $assignment] = $this->assignedProduct();
        $original = (float) $assignment->price;

        $this->putJson('/admin/products/'.$product->id.'/subscription-plans/'.$plan->id, [
            'discount_type' => 'percent',
            'discount_value' => 150,
            'is_enabled' => true,
            'is_default' => true,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('discount_value');

        $this->assertEqualsWithDelta($original, (float) $assignment->fresh()->price, 0.01);
    }

    public function test_updating_a_plan_that_is_not_assigned_fails(): void
    {
        [$product] = $this->assignedProduct();
        $this->post('/admin/subscription-plans', [
            'name' => 'Other Plan',
            'is_enabled' => true,
            'duration' => 0,
            'cycle' => 1,
            'frequency' => 'week',
        ]);
        $other = SubscriptionPlan::query()->orderByDesc('id')->firstOrFail();

        $this->putJson('/admin/products/'.$product->id.'/subscription-plans/'.$other->id, [
            'price' => 10,
            'is_enabled' => true,
            'is_default' => true,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('subscription_plans');
    }

    public function test_disabling_the_last_active_plan_is_blocked_when_subscription_is_required(): void
    {
        [$product, $plan, $assignment] = $this->assignedProduct();

        $this->putJson('/admin/products/'.$product->id.'/subscription-plans/'.$plan->id, [
            'price' => 44.99,
            'is_enabled' => false,
            'is_default' => false,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('subscription_plans');

        $this->assertTrue((bool) $assignment->fresh()->is_enabled);
    }

    /** @return array{0: Product, 1: SubscriptionPlan, 2: SubscriptionPlan} */
    protected function assignedTwoPlans(): array
    {
        $this->post('/admin/subscription-plans', [
            'name' => 'Monthly Essential',
            'is_enabled' => true,
            'duration' => 0,
            'cycle' => 1,
            'frequency' => 'month',
        ]);
        $monthly = SubscriptionPlan::query()->orderByDesc('id')->firstOrFail();
        $this->post('/admin/subscription-plans', [
            'name' => 'Biannual Business',
            'is_enabled' => true,
            'duration' => 0,
            'cycle' => 6,
            'frequency' => 'month',
        ]);
        $biannual = SubscriptionPlan::query()->orderByDesc('id')->firstOrFail();
        $categoryId = (int) \App\Domain\Catalog\Models\Category::query()->value('id');

        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'simple',
            'name' => 'Two Plan Product',
            'sku' => 'PLAN-EDIT-2',
            'price' => 44.99,
            'is_enabled' => true,
            'subscription_enabled' => true,
            'purchase_option' => 'subscription',
            'subscription_plans' => [
                [
                    'subscription_plan_id' => $monthly->id,
                    'price' => 40.49,
                    'discount_type' => 'percent',
                    'discount_value' => 10,
                    'is_enabled' => true,
                    'is_default' => true,
                ],
                [
                    'subscription_plan_id' => $biannual->id,
                    'price' => 44.99,
                    'is_enabled' => true,
                    'is_default' => false,
                ],
            ],
            'category_ids' => [$categoryId],
            'default_category_id' => $categoryId,
        ]))->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'PLAN-EDIT-2')->firstOrFail();

        return [$product, $monthly, $biannual];
    }

    public function test_inline_disable_keeps_price_and_moves_default_to_the_remaining_plan(): void
    {
        [$product, $monthly, $biannual] = $this->assignedTwoPlans();
        $monthlyRow = $product->productSubscriptionPlans()->where('subscription_plan_id', $monthly->id)->firstOrFail();
        $originalPrice = (float) $monthlyRow->price;
        $originalDiscount = $monthlyRow->discount_type;

        $this->putJson('/admin/products/'.$product->id.'/subscription-plans/'.$monthly->id, [
            'price' => $originalPrice,
            'discount_type' => $originalDiscount,
            'discount_value' => $monthlyRow->discount_value,
            'is_enabled' => false,
            'is_default' => false,
        ])->assertOk()
            ->assertJsonPath('message', 'Subscription plan saved.');

        $monthlyRow->refresh();
        $this->assertFalse((bool) $monthlyRow->is_enabled);
        $this->assertFalse((bool) $monthlyRow->is_default);
        $this->assertEqualsWithDelta($originalPrice, (float) $monthlyRow->price, 0.01);
        $this->assertSame('percent', $monthlyRow->discount_type);

        $biannualRow = $product->productSubscriptionPlans()->where('subscription_plan_id', $biannual->id)->firstOrFail();
        $this->assertTrue((bool) $biannualRow->is_enabled);
        $this->assertTrue((bool) $biannualRow->is_default);
        $this->assertSame(1, $product->productSubscriptionPlans()->where('is_default', true)->count());
    }

    public function test_inline_enable_restores_a_disabled_assignment(): void
    {
        [$product, $monthly, $biannual] = $this->assignedTwoPlans();

        $this->putJson('/admin/products/'.$product->id.'/subscription-plans/'.$biannual->id, [
            'price' => 44.99,
            'is_enabled' => false,
            'is_default' => false,
        ])->assertOk();

        $this->assertFalse((bool) $product->productSubscriptionPlans()->where('subscription_plan_id', $biannual->id)->value('is_enabled'));

        $this->putJson('/admin/products/'.$product->id.'/subscription-plans/'.$biannual->id, [
            'price' => 44.99,
            'is_enabled' => true,
            'is_default' => false,
        ])->assertOk()
            ->assertJsonPath('message', 'Subscription plan saved.');

        $biannualRow = $product->productSubscriptionPlans()->where('subscription_plan_id', $biannual->id)->firstOrFail();
        $this->assertTrue((bool) $biannualRow->is_enabled);
        $this->assertFalse((bool) $biannualRow->is_default);
        $this->assertTrue((bool) $product->productSubscriptionPlans()->where('subscription_plan_id', $monthly->id)->value('is_default'));
    }

    public function test_setting_default_clears_the_previous_default(): void
    {
        [$product, $monthly, $biannual] = $this->assignedTwoPlans();

        $response = $this->putJson('/admin/products/'.$product->id.'/subscription-plans/'.$biannual->id, [
            'price' => 44.99,
            'is_enabled' => true,
            'is_default' => true,
        ])->assertOk()
            ->assertJsonPath('message', 'Subscription plan saved.');

        $plans = collect($response->json('plans'));
        $this->assertCount(2, $plans);
        $this->assertSame(1, $plans->where('is_default', true)->count());
        $this->assertFalse((bool) $plans->firstWhere('subscription_plan_id', $monthly->id)['is_default']);
        $this->assertTrue((bool) $plans->firstWhere('subscription_plan_id', $biannual->id)['is_default']);

        $monthlyRow = $product->productSubscriptionPlans()->where('subscription_plan_id', $monthly->id)->firstOrFail();
        $biannualRow = $product->productSubscriptionPlans()->where('subscription_plan_id', $biannual->id)->firstOrFail();
        $this->assertFalse((bool) $monthlyRow->is_default);
        $this->assertTrue((bool) $biannualRow->is_default);
        $this->assertTrue((bool) $biannualRow->is_enabled);
    }

    public function test_disabled_plan_cannot_become_the_default(): void
    {
        [$product, $monthly, $biannual] = $this->assignedTwoPlans();

        $this->putJson('/admin/products/'.$product->id.'/subscription-plans/'.$biannual->id, [
            'price' => 44.99,
            'is_enabled' => false,
            'is_default' => true,
        ])->assertOk();

        $biannualRow = $product->productSubscriptionPlans()->where('subscription_plan_id', $biannual->id)->firstOrFail();
        $this->assertFalse((bool) $biannualRow->is_enabled);
        $this->assertFalse((bool) $biannualRow->is_default);
        $this->assertTrue((bool) $product->productSubscriptionPlans()->where('subscription_plan_id', $monthly->id)->value('is_default'));
        $this->assertSame(1, $product->productSubscriptionPlans()->where('is_default', true)->count());
    }

    public function test_rejected_disable_leaves_assignment_unchanged(): void
    {
        [$product, $plan, $assignment] = $this->assignedProduct();

        $this->putJson('/admin/products/'.$product->id.'/subscription-plans/'.$plan->id, [
            'price' => 44.99,
            'is_enabled' => false,
            'is_default' => false,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('subscription_plans');

        $assignment->refresh();
        $this->assertTrue((bool) $assignment->is_enabled);
        $this->assertTrue((bool) $assignment->is_default);
    }

    public function test_product_subscription_tab_exposes_inline_status_and_default_actions(): void
    {
        $source = (string) file_get_contents(resource_path('js/admin/Pages/Catalog/Products/ProductSubscriptionTab.jsx'));
        $this->assertStringContainsString('StatusToggle', $source);
        $this->assertStringContainsString('Set as default', $source);
        $this->assertStringContainsString('toggleEnabled', $source);
        $this->assertStringContainsString('setAsDefault', $source);
        $this->assertStringContainsString('Billing interval', $source);
        $this->assertStringContainsString('PlanDetailRows', $source);
        $this->assertStringContainsString('Trial frequency', $source);
        $this->assertStringContainsString('Plan name', $source);
        $this->assertStringContainsString('!max-w-4xl', $source);
        $this->assertStringContainsString('Add Selected Plans', $source);
        $this->assertStringContainsString('mergeSelectedPlans', $source);
        $this->assertStringContainsString('if (!res.ok)', $source);
        $this->assertStringNotContainsString('dragHandle', $source);
        $this->assertStringNotContainsString('Reorder plan', $source);
        $this->assertStringNotContainsString('name="grip"', $source);
    }

    public function test_product_edit_exposes_plan_trial_cycle_and_frequency_for_picker(): void
    {
        $this->post('/admin/subscription-plans', [
            'name' => 'Hourly Trial Plan',
            'is_enabled' => true,
            'trial_enabled' => true,
            'trial_duration' => 2,
            'trial_cycle' => 1,
            'trial_frequency' => 'hour',
            'duration' => 0,
            'cycle' => 3,
            'frequency' => 'hour',
        ])->assertRedirect();

        $plan = SubscriptionPlan::query()
            ->whereHas('translations', fn ($q) => $q->where('name', 'Hourly Trial Plan'))
            ->firstOrFail();
        $categoryId = (int) \App\Domain\Catalog\Models\Category::query()->value('id');

        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'simple',
            'name' => 'Picker Detail Product',
            'sku' => 'PLAN-PICKER-1',
            'price' => 19.99,
            'is_enabled' => true,
            'subscription_enabled' => false,
            'purchase_option' => 'one_time',
            'subscription_plans' => [],
            'category_ids' => [$categoryId],
            'default_category_id' => $categoryId,
        ]))->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'PLAN-PICKER-1')->firstOrFail();

        $this->get('/admin/products/'.$product->id.'/edit')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/Products/Form', false)
                ->where('availableSubscriptionPlans', function ($plans) use ($plan) {
                    $row = collect($plans)->firstWhere('id', $plan->id);

                    return is_array($row)
                        && ($row['name'] ?? null) === 'Hourly Trial Plan'
                        && (int) ($row['cycle'] ?? 0) === 3
                        && ($row['frequency'] ?? null) === 'hour'
                        && ($row['trial_enabled'] ?? null) === true
                        && (int) ($row['trial_duration'] ?? 0) === 2
                        && (int) ($row['trial_cycle'] ?? 0) === 1
                        && ($row['trial_frequency'] ?? null) === 'hour'
                        && ($row['billing_summary'] ?? null) === 'Every 3 Hours'
                        && ($row['trial_summary'] ?? null) === '2 Hours';
                }));
    }
}
