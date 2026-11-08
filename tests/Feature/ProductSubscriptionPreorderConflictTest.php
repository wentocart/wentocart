<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Support\ProductModeConflict;
use App\Domain\Subscription\Models\SubscriptionPlan;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSubscriptionPreorderConflictTest extends TestCase
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
            'name' => 'Mode Conflict Product',
            'sku' => 'MODE-CONFLICT-1',
            'price' => 40,
            'is_enabled' => true,
            'subscription_enabled' => false,
            'purchase_option' => 'one_time',
            'subscription_plans' => [],
            'preorder_enabled' => false,
        ], $overrides));
    }

    protected function makePlan(): SubscriptionPlan
    {
        $this->post('/admin/subscription-plans', [
            'name' => 'Conflict Monthly',
            'is_enabled' => true,
            'duration' => 0,
            'cycle' => 1,
            'frequency' => 'month',
        ])->assertRedirect();

        return SubscriptionPlan::query()->orderByDesc('id')->firstOrFail();
    }

    public function test_subscription_only_product_saves_successfully(): void
    {
        $plan = $this->makePlan();

        $this->post('/admin/products', $this->payload([
            'sku' => 'MODE-SUB-OK',
            'subscription_enabled' => true,
            'purchase_option' => 'subscription',
            'subscription_plans' => [[
                'subscription_plan_id' => $plan->id,
                'price' => 36,
                'is_enabled' => true,
                'is_default' => true,
            ]],
            'preorder_enabled' => false,
        ]))->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'MODE-SUB-OK')->firstOrFail();
        $this->assertTrue((bool) $product->subscription_enabled);
        $this->assertFalse((bool) $product->preorder_enabled);
    }

    public function test_preorder_only_product_saves_successfully(): void
    {
        $this->post('/admin/products', $this->payload([
            'sku' => 'MODE-PRE-OK',
            'subscription_enabled' => false,
            'preorder_enabled' => true,
            'preorder' => [
                'start_at' => '2026-10-01 09:00:00',
                'release_at' => '2026-11-15 00:00:00',
                'payment_type' => 'full',
                'min_quantity' => 1,
            ],
        ]))->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'MODE-PRE-OK')->firstOrFail();
        $this->assertFalse((bool) $product->subscription_enabled);
        $this->assertTrue((bool) $product->preorder_enabled);
    }

    public function test_both_modes_cannot_be_saved_together(): void
    {
        $plan = $this->makePlan();

        $this->from('/admin/products/create')->post('/admin/products', $this->payload([
            'sku' => 'MODE-BOTH-BAD',
            'subscription_enabled' => true,
            'purchase_option' => 'subscription',
            'subscription_plans' => [[
                'subscription_plan_id' => $plan->id,
                'price' => 36,
                'is_enabled' => true,
                'is_default' => true,
            ]],
            'preorder_enabled' => true,
            'preorder' => [
                'start_at' => '2026-10-01 09:00:00',
                'release_at' => '2026-11-15 00:00:00',
                'payment_type' => 'full',
                'min_quantity' => 1,
            ],
        ]))->assertSessionHasErrors([
            'subscription_enabled' => ProductModeConflict::MESSAGE,
            'preorder_enabled' => ProductModeConflict::MESSAGE,
        ]);

        $this->assertNull(Product::query()->where('sku', 'MODE-BOTH-BAD')->first());
    }

    public function test_subscription_product_cannot_enable_preorder_on_update(): void
    {
        $plan = $this->makePlan();
        $this->post('/admin/products', $this->payload([
            'sku' => 'MODE-SUB-LOCK',
            'subscription_enabled' => true,
            'purchase_option' => 'subscription',
            'subscription_plans' => [[
                'subscription_plan_id' => $plan->id,
                'price' => 36,
                'is_enabled' => true,
                'is_default' => true,
            ]],
        ]))->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'MODE-SUB-LOCK')->firstOrFail();
        $edit = '/admin/products/'.$product->id.'/edit';

        $this->from($edit)->put('/admin/products/'.$product->id, $this->payload([
            'sku' => 'MODE-SUB-LOCK',
            'subscription_enabled' => true,
            'purchase_option' => 'subscription',
            'subscription_plans' => [[
                'subscription_plan_id' => $plan->id,
                'price' => 36,
                'is_enabled' => true,
                'is_default' => true,
            ]],
            'preorder_enabled' => true,
            'preorder' => [
                'start_at' => '2026-10-01 09:00:00',
                'release_at' => '2026-11-15 00:00:00',
                'payment_type' => 'full',
                'min_quantity' => 1,
            ],
        ]))->assertSessionHasErrors(['preorder_enabled', 'subscription_enabled']);

        $product->refresh();
        $this->assertTrue((bool) $product->subscription_enabled);
        $this->assertFalse((bool) $product->preorder_enabled);
    }

    public function test_preorder_product_cannot_enable_subscription_on_update(): void
    {
        $plan = $this->makePlan();
        $this->post('/admin/products', $this->payload([
            'sku' => 'MODE-PRE-LOCK',
            'subscription_enabled' => false,
            'preorder_enabled' => true,
            'preorder' => [
                'start_at' => '2026-10-01 09:00:00',
                'release_at' => '2026-11-15 00:00:00',
                'payment_type' => 'full',
                'min_quantity' => 1,
            ],
        ]))->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'MODE-PRE-LOCK')->firstOrFail();
        $edit = '/admin/products/'.$product->id.'/edit';

        $this->from($edit)->put('/admin/products/'.$product->id, $this->payload([
            'sku' => 'MODE-PRE-LOCK',
            'subscription_enabled' => true,
            'purchase_option' => 'subscription',
            'subscription_plans' => [[
                'subscription_plan_id' => $plan->id,
                'price' => 36,
                'is_enabled' => true,
                'is_default' => true,
            ]],
            'preorder_enabled' => true,
            'preorder' => [
                'start_at' => '2026-10-01 09:00:00',
                'release_at' => '2026-11-15 00:00:00',
                'payment_type' => 'full',
                'min_quantity' => 1,
            ],
        ]))->assertSessionHasErrors(['preorder_enabled', 'subscription_enabled']);

        $product->refresh();
        $this->assertFalse((bool) $product->subscription_enabled);
        $this->assertTrue((bool) $product->preorder_enabled);
    }

    public function test_legacy_product_with_both_flags_keeps_data_until_fixed(): void
    {
        $plan = $this->makePlan();
        $this->post('/admin/products', $this->payload([
            'sku' => 'MODE-LEGACY',
            'subscription_enabled' => true,
            'purchase_option' => 'subscription',
            'subscription_plans' => [[
                'subscription_plan_id' => $plan->id,
                'price' => 36,
                'is_enabled' => true,
                'is_default' => true,
            ]],
        ]))->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'MODE-LEGACY')->firstOrFail();
        // Simulate legacy rows that already have both modes without going through validation.
        $product->forceFill(['preorder_enabled' => true])->saveQuietly();
        $this->assertTrue((bool) $product->fresh()->subscription_enabled);
        $this->assertTrue((bool) $product->fresh()->preorder_enabled);

        $edit = '/admin/products/'.$product->id.'/edit';
        $this->from($edit)->put('/admin/products/'.$product->id, $this->payload([
            'sku' => 'MODE-LEGACY',
            'subscription_enabled' => true,
            'purchase_option' => 'subscription',
            'subscription_plans' => [[
                'subscription_plan_id' => $plan->id,
                'price' => 36,
                'is_enabled' => true,
                'is_default' => true,
            ]],
            'preorder_enabled' => true,
            'preorder' => [
                'start_at' => '2026-10-01 09:00:00',
                'release_at' => '2026-11-15 00:00:00',
                'payment_type' => 'full',
                'min_quantity' => 1,
            ],
        ]))->assertSessionHasErrors(['subscription_enabled', 'preorder_enabled']);

        $product->refresh();
        $this->assertTrue((bool) $product->subscription_enabled);
        $this->assertTrue((bool) $product->preorder_enabled);
        $this->assertSame(1, $product->productSubscriptionPlans()->count());

        // Admin can resolve by turning pre-order off.
        $this->from($edit)->put('/admin/products/'.$product->id, $this->payload([
            'sku' => 'MODE-LEGACY',
            'subscription_enabled' => true,
            'purchase_option' => 'subscription',
            'subscription_plans' => [[
                'subscription_plan_id' => $plan->id,
                'price' => 36,
                'is_enabled' => true,
                'is_default' => true,
            ]],
            'preorder_enabled' => false,
        ]))->assertRedirect($edit);

        $product->refresh();
        $this->assertTrue((bool) $product->subscription_enabled);
        $this->assertFalse((bool) $product->preorder_enabled);
    }

    public function test_ui_source_guards_the_conflict(): void
    {
        $form = (string) file_get_contents(resource_path('js/admin/Pages/Catalog/Products/Form.jsx'));
        $message = (string) file_get_contents(resource_path('js/admin/Pages/Catalog/Products/productModeConflict.js'));
        $this->assertStringContainsString('SUBSCRIPTION_PREORDER_CONFLICT', $form);
        $this->assertStringContainsString(ProductModeConflict::MESSAGE, $message);
        $this->assertStringContainsString('blockedByPreorder', $form);
        $this->assertStringContainsString('blockedBySubscription', $form);
    }
}
