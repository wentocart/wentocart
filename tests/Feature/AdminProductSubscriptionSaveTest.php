<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Subscription\Models\SubscriptionPlan;
use App\Domain\Subscription\Services\SubscriptionService;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProductSubscriptionSaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
    }

    /** @param  array<string, mixed>  $overrides */
    protected function payload(array $overrides = []): array
    {
        return $this->withProductCategories(array_merge([
            'type' => 'simple',
            'name' => 'Subscription Save Product',
            'sku' => 'SUB-SAVE-1',
            'price' => 40,
            'is_enabled' => true,
            'subscription_enabled' => false,
            'purchase_option' => 'one_time',
            'subscription_plans' => [],
        ], $overrides));
    }

    protected function makePlan(bool $enabled = true): SubscriptionPlan
    {
        $this->post('/admin/subscription-plans', [
            'name' => $enabled ? 'Active Monthly' : 'Disabled Daily',
            'is_enabled' => $enabled,
            'duration' => 0,
            'cycle' => 1,
            'frequency' => $enabled ? 'month' : 'day',
        ]);

        return SubscriptionPlan::query()->orderByDesc('id')->firstOrFail();
    }

    public function test_subscription_selected_without_an_active_plan_is_blocked_on_create(): void
    {
        $response = $this->from('/admin/products/create')->post('/admin/products', $this->payload([
            'subscription_enabled' => true,
            'purchase_option' => 'subscription',
            'subscription_plans' => [],
        ]));

        $response->assertSessionHasErrors([
            'subscription_plans' => SubscriptionService::PRODUCT_REQUIRES_ACTIVE_PLAN,
        ]);
        $this->assertNull(Product::query()->where('sku', 'SUB-SAVE-1')->first());
        $this->assertNotSame('/admin/products', parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));
    }

    public function test_subscription_and_one_time_without_an_active_plan_is_blocked(): void
    {
        $this->from('/admin/products/create')->post('/admin/products', $this->payload([
            'subscription_enabled' => true,
            'purchase_option' => 'both',
        ]))->assertSessionHasErrors('subscription_plans');

        $this->assertNull(Product::query()->where('sku', 'SUB-SAVE-1')->first());
    }

    public function test_subscription_with_an_active_plan_saves_and_stays_on_edit(): void
    {
        $plan = $this->makePlan();

        $response = $this->from('/admin/products/create')->post('/admin/products', $this->payload([
            'subscription_enabled' => true,
            'purchase_option' => 'subscription',
            'subscription_plans' => [[
                'subscription_plan_id' => $plan->id,
                'price' => 36,
                'is_enabled' => true,
                'is_default' => true,
            ]],
        ]));

        $product = $this->assertRedirectToProductEdit($response, 'SUB-SAVE-1');
        $response->assertSessionHas('success', 'Product saved successfully.');
        $this->assertTrue($product->fresh()->subscription_enabled);
        $this->assertSame(1, $product->productSubscriptionPlans()->count());
    }

    public function test_enabled_one_time_only_does_not_require_a_plan(): void
    {
        $response = $this->from('/admin/products/create')->post('/admin/products', $this->payload([
            'subscription_enabled' => true,
            'purchase_option' => 'one_time',
            'subscription_plans' => [],
        ]));

        $this->assertRedirectToProductEdit($response, 'SUB-SAVE-1');
        $this->assertTrue(Product::query()->where('sku', 'SUB-SAVE-1')->firstOrFail()->subscription_enabled);
    }

    public function test_disabled_subscription_does_not_require_a_plan(): void
    {
        $response = $this->from('/admin/products/create')->post('/admin/products', $this->payload([
            'subscription_enabled' => false,
            'purchase_option' => 'subscription',
            'subscription_plans' => [],
        ]));

        $this->assertRedirectToProductEdit($response, 'SUB-SAVE-1');
    }

    public function test_disabled_assignment_does_not_count_as_an_active_plan(): void
    {
        $plan = $this->makePlan();

        $this->from('/admin/products/create')->post('/admin/products', $this->payload([
            'subscription_enabled' => true,
            'purchase_option' => 'subscription',
            'subscription_plans' => [[
                'subscription_plan_id' => $plan->id,
                'price' => 36,
                'is_enabled' => false,
                'is_default' => true,
            ]],
        ]))->assertSessionHasErrors('subscription_plans');

        $this->assertNull(Product::query()->where('sku', 'SUB-SAVE-1')->first());
    }

    public function test_removing_the_last_active_plan_on_update_is_blocked(): void
    {
        $plan = $this->makePlan();
        $this->post('/admin/products', $this->payload([
            'subscription_enabled' => true,
            'purchase_option' => 'subscription',
            'subscription_plans' => [[
                'subscription_plan_id' => $plan->id,
                'price' => 36,
                'is_enabled' => true,
                'is_default' => true,
            ]],
        ]))->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'SUB-SAVE-1')->firstOrFail();
        $edit = '/admin/products/'.$product->id.'/edit';

        $response = $this->from($edit)->put('/admin/products/'.$product->id, $this->payload([
            'name' => 'Subscription Save Product',
            'subscription_enabled' => true,
            'purchase_option' => 'subscription',
            'subscription_plans' => [],
        ]));

        $response->assertSessionHasErrors([
            'subscription_plans' => SubscriptionService::PRODUCT_REQUIRES_ACTIVE_PLAN,
        ]);
        $this->assertSame($edit, parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));
        $this->assertSame(1, $product->fresh()->productSubscriptionPlans()->count());
        $this->assertTrue($product->fresh()->subscription_enabled);
    }

    public function test_sync_product_rejects_required_subscription_without_active_plan(): void
    {
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $svc = app(SubscriptionService::class);

        try {
            $svc->syncProduct($product, true, 'subscription', []);
            $this->fail('Expected a validation exception when no active plan is assigned.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertSame(
                [SubscriptionService::PRODUCT_REQUIRES_ACTIVE_PLAN],
                $e->errors()['subscription_plans'] ?? null,
            );
        }

        $this->assertFalse((bool) $product->fresh()->subscription_enabled);
        $this->assertSame((int) $product->stock_qty, (int) $product->fresh()->stock_qty);
    }

    public function test_adding_a_subscription_does_not_change_existing_quantity(): void
    {
        $this->from('/admin/products/create')->post('/admin/products', $this->payload([
            'sku' => 'SUB-QTY-KEEP',
            'stock_qty' => 25,
            'manage_stock' => true,
            'stock_status' => 'in_stock',
        ]))->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'SUB-QTY-KEEP')->firstOrFail();
        $this->assertSame(25, (int) $product->stock_qty);

        $plan = $this->makePlan();
        $edit = '/admin/products/'.$product->id.'/edit';

        $this->from($edit)->put('/admin/products/'.$product->id, $this->payload([
            'sku' => 'SUB-QTY-KEEP',
            'subscription_enabled' => true,
            'purchase_option' => 'both',
            'subscription_plans' => [[
                'subscription_plan_id' => $plan->id,
                'price' => 36,
                'is_enabled' => true,
                'is_default' => true,
            ]],
        ]))->assertRedirect($edit);

        $fresh = $product->fresh();
        $this->assertTrue((bool) $fresh->subscription_enabled);
        $this->assertSame(25, (int) $fresh->stock_qty);
        $this->assertSame('in_stock', $fresh->stock_status);
        $this->assertTrue((bool) $fresh->manage_stock);
    }

    public function test_editing_or_disabling_a_plan_does_not_change_quantity(): void
    {
        $monthly = $this->makePlan();
        $this->post('/admin/subscription-plans', [
            'name' => 'Weekly Keep Qty',
            'is_enabled' => true,
            'duration' => 0,
            'cycle' => 1,
            'frequency' => 'week',
        ]);
        $weekly = SubscriptionPlan::query()->orderByDesc('id')->firstOrFail();

        $this->post('/admin/products', $this->payload([
            'sku' => 'SUB-QTY-TOGGLE',
            'stock_qty' => 25,
            'manage_stock' => true,
            'subscription_enabled' => true,
            'purchase_option' => 'subscription',
            'subscription_plans' => [
                [
                    'subscription_plan_id' => $monthly->id,
                    'price' => 36,
                    'is_enabled' => true,
                    'is_default' => true,
                ],
                [
                    'subscription_plan_id' => $weekly->id,
                    'price' => 12,
                    'is_enabled' => true,
                    'is_default' => false,
                ],
            ],
        ]))->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'SUB-QTY-TOGGLE')->firstOrFail();
        $this->assertSame(25, (int) $product->stock_qty);

        $this->putJson('/admin/products/'.$product->id.'/subscription-plans/'.$weekly->id, [
            'price' => 11,
            'is_enabled' => false,
            'is_default' => false,
        ])->assertOk();
        $this->assertSame(25, (int) $product->fresh()->stock_qty);
        $this->assertFalse((bool) $product->productSubscriptionPlans()->where('subscription_plan_id', $weekly->id)->value('is_enabled'));

        $this->putJson('/admin/products/'.$product->id.'/subscription-plans/'.$weekly->id, [
            'is_enabled' => true,
            'is_default' => false,
        ])->assertOk();
        $this->assertSame(25, (int) $product->fresh()->stock_qty);
        $this->assertTrue((bool) $product->productSubscriptionPlans()->where('subscription_plan_id', $weekly->id)->value('is_enabled'));
    }

    public function test_subscription_save_with_quantity_omitted_keeps_stock(): void
    {
        $this->post('/admin/products', $this->payload([
            'sku' => 'SUB-QTY-OMIT',
            'stock_qty' => 25,
            'manage_stock' => true,
        ]))->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'SUB-QTY-OMIT')->firstOrFail();
        $plan = $this->makePlan();

        $this->put('/admin/products/'.$product->id, $this->payload([
            'sku' => 'SUB-QTY-OMIT',
            'subscription_enabled' => true,
            'purchase_option' => 'subscription',
            'subscription_plans' => [[
                'subscription_plan_id' => $plan->id,
                'price' => 36,
                'is_enabled' => true,
                'is_default' => true,
            ]],
        ]))->assertProductSaveRedirect();

        $this->assertSame(25, (int) $product->fresh()->stock_qty);
        $this->assertTrue((bool) $product->fresh()->subscription_enabled);
    }

    public function test_explicit_inventory_update_still_changes_quantity(): void
    {
        $this->post('/admin/products', $this->payload([
            'sku' => 'SUB-QTY-SET',
            'stock_qty' => 25,
            'manage_stock' => true,
            'subscription_enabled' => true,
            'purchase_option' => 'one_time',
        ]))->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'SUB-QTY-SET')->firstOrFail();

        $this->put('/admin/products/'.$product->id, $this->payload([
            'sku' => 'SUB-QTY-SET',
            'stock_qty' => 10,
            'manage_stock' => true,
            'stock_status' => 'in_stock',
            'subscription_enabled' => true,
            'purchase_option' => 'one_time',
        ]))->assertProductSaveRedirect();

        $this->assertSame(10, (int) $product->fresh()->stock_qty);
    }

    public function test_bulk_assign_multiple_plans_in_one_save(): void
    {
        $hourly = $this->makeNamedPlan('Bulk Hourly', 'hour');
        $weekly = $this->makeNamedPlan('Bulk Weekly', 'week');
        $monthly = $this->makeNamedPlan('Bulk Monthly', 'month');

        $this->post('/admin/products', $this->payload([
            'sku' => 'SUB-BULK-1',
            'subscription_enabled' => true,
            'purchase_option' => 'subscription',
            'subscription_plans' => [
                [
                    'subscription_plan_id' => $hourly->id,
                    'price' => 10,
                    'is_enabled' => true,
                    'is_default' => true,
                ],
                [
                    'subscription_plan_id' => $weekly->id,
                    'price' => 20,
                    'is_enabled' => true,
                    'is_default' => false,
                ],
                [
                    'subscription_plan_id' => $monthly->id,
                    'price' => 30,
                    'is_enabled' => true,
                    'is_default' => false,
                ],
            ],
        ]))->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'SUB-BULK-1')->firstOrFail();
        $assigned = $product->productSubscriptionPlans()->orderBy('position')->get();
        $this->assertCount(3, $assigned);
        $this->assertSame(
            [$hourly->id, $weekly->id, $monthly->id],
            $assigned->pluck('subscription_plan_id')->map(fn ($id) => (int) $id)->all()
        );
        $this->assertSame(1, $assigned->where('is_default', true)->count());
        $this->assertTrue((bool) $assigned->firstWhere('subscription_plan_id', $hourly->id)->is_default);
    }

    public function test_bulk_assign_skips_duplicate_plan_ids_in_the_same_payload(): void
    {
        $plan = $this->makeNamedPlan('Bulk Dup', 'day');

        $this->post('/admin/products', $this->payload([
            'sku' => 'SUB-BULK-DUP',
            'subscription_enabled' => true,
            'purchase_option' => 'subscription',
            'subscription_plans' => [
                [
                    'subscription_plan_id' => $plan->id,
                    'price' => 10,
                    'is_enabled' => true,
                    'is_default' => true,
                ],
                [
                    'subscription_plan_id' => $plan->id,
                    'price' => 99,
                    'is_enabled' => true,
                    'is_default' => false,
                ],
            ],
        ]))->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'SUB-BULK-DUP')->firstOrFail();
        $this->assertSame(1, $product->productSubscriptionPlans()->count());
        $row = $product->productSubscriptionPlans()->firstOrFail();
        $this->assertEqualsWithDelta(10.0, (float) $row->price, 0.01);
        $this->assertTrue((bool) $row->is_default);
    }

    public function test_update_can_add_more_plans_without_dropping_existing_ones(): void
    {
        $first = $this->makeNamedPlan('Keep First', 'week');
        $second = $this->makeNamedPlan('Add Second', 'month');

        $this->post('/admin/products', $this->payload([
            'sku' => 'SUB-BULK-ADD',
            'subscription_enabled' => true,
            'purchase_option' => 'both',
            'subscription_plans' => [[
                'subscription_plan_id' => $first->id,
                'price' => 12,
                'is_enabled' => true,
                'is_default' => true,
            ]],
        ]))->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'SUB-BULK-ADD')->firstOrFail();

        $this->put('/admin/products/'.$product->id, $this->payload([
            'sku' => 'SUB-BULK-ADD',
            'subscription_enabled' => true,
            'purchase_option' => 'both',
            'subscription_plans' => [
                [
                    'subscription_plan_id' => $first->id,
                    'price' => 12,
                    'is_enabled' => true,
                    'is_default' => true,
                ],
                [
                    'subscription_plan_id' => $second->id,
                    'price' => 18,
                    'is_enabled' => true,
                    'is_default' => false,
                ],
            ],
        ]))->assertProductSaveRedirect();

        $ids = $product->fresh()->productSubscriptionPlans()->pluck('subscription_plan_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->assertSame([$first->id, $second->id], $ids);
        $this->assertSame(1, $product->fresh()->productSubscriptionPlans()->where('is_default', true)->count());
    }

    protected function makeNamedPlan(string $name, string $frequency): SubscriptionPlan
    {
        $this->post('/admin/subscription-plans', [
            'name' => $name,
            'is_enabled' => true,
            'duration' => 0,
            'cycle' => 1,
            'frequency' => $frequency,
        ])->assertRedirect();

        return SubscriptionPlan::query()
            ->whereHas('translations', fn ($q) => $q->where('name', $name))
            ->firstOrFail();
    }
}
