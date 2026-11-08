<?php

namespace Tests\Feature;

use App\Domain\Promo\Models\DiscountRule;
use App\Domain\Settings\ShopSetting;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDiscountTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_and_create_a_catalog_discount(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->get('/admin/discounts')->assertOk()->assertSee('Discounts');
        $this->get('/admin/discounts/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Discounts/Form', false)
                ->has('options.currencies')
                ->where('options.currencies.0.code', 'USD')
                ->has('defaultCurrency')
            );
        $this->get('/admin/coupons')->assertRedirect('/admin/discounts');

        ShopSetting::put('customers.group_discounts', false);
        $this->get('/admin/discounts/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('conditionTypes', function ($types) {
                return collect($types)->pluck('value')->doesntContain('customer_group');
            }));

        $this->post('/admin/discounts', [
            'name' => 'Summer sale',
            'apply_as' => 'catalog',
            'action' => 'amount',
            'value' => 15,
            'currency_code' => 'USD',
            'priority' => 10,
            'stop_further' => false,
            'is_enabled' => true,
            'conditions' => [],
        ])->assertRedirect('/admin/discounts');

        $this->assertDatabaseHas('discount_rules', [
            'name' => 'Summer sale',
            'apply_as' => 'catalog',
            'action' => 'amount',
            'currency_code' => 'USD',
        ]);
    }

    public function test_coupon_requires_a_code_and_set_price_is_catalog_only(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->from('/admin/discounts/create')->post('/admin/discounts', [
            'name' => 'Missing code',
            'apply_as' => 'coupon',
            'action' => 'percent',
            'value' => 10,
            'currency_code' => 'USD',
            'is_enabled' => true,
        ])->assertRedirect('/admin/discounts/create')->assertSessionHasErrors('code');

        $this->from('/admin/discounts/create')->post('/admin/discounts', [
            'name' => 'Bad set price',
            'apply_as' => 'cart',
            'action' => 'set_price',
            'value' => 9,
            'currency_code' => 'USD',
            'is_enabled' => true,
        ])->assertRedirect('/admin/discounts/create')->assertSessionHasErrors('action');
    }

    public function test_admin_can_toggle_and_delete_a_discount(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $rule = DiscountRule::query()->create([
            'name' => 'Toggle me',
            'apply_as' => 'catalog',
            'action' => 'amount',
            'value' => 2,
            'is_enabled' => true,
        ]);

        $this->post("/admin/discounts/{$rule->id}/toggle")->assertRedirect();
        $this->assertFalse($rule->fresh()->is_enabled);

        $this->delete("/admin/discounts/{$rule->id}")->assertRedirect('/admin/discounts');
        $this->assertDatabaseMissing('discount_rules', ['id' => $rule->id]);
    }

    public function test_admin_can_bulk_status_duplicate_and_delete_discounts(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $a = DiscountRule::query()->create([
            'name' => 'Bulk A',
            'apply_as' => 'catalog',
            'action' => 'percent',
            'value' => 10,
            'is_enabled' => true,
        ]);
        $b = DiscountRule::query()->create([
            'name' => 'Bulk B',
            'apply_as' => 'coupon',
            'code' => 'SAVE10',
            'action' => 'percent',
            'value' => 10,
            'is_enabled' => true,
        ]);

        $this->post('/admin/discounts/bulk-status', [
            'ids' => [$a->id, $b->id],
            'enabled' => 0,
        ])->assertRedirect();
        $this->assertFalse($a->fresh()->is_enabled);
        $this->assertFalse($b->fresh()->is_enabled);

        $this->post('/admin/discounts/bulk-duplicate', [
            'ids' => [$b->id],
        ])->assertRedirect();
        $this->assertDatabaseHas('discount_rules', ['name' => 'Bulk B (copy)']);
        $this->assertNotEquals('SAVE10', DiscountRule::query()->where('name', 'Bulk B (copy)')->value('code'));

        $this->post('/admin/discounts/bulk-delete', [
            'ids' => [$a->id],
        ])->assertRedirect();
        $this->assertDatabaseMissing('discount_rules', ['id' => $a->id]);
    }

    public function test_discounts_index_supports_id_range_filter(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $first = DiscountRule::query()->create([
            'name' => 'First',
            'apply_as' => 'catalog',
            'action' => 'percent',
            'value' => 5,
            'is_enabled' => true,
        ]);
        $second = DiscountRule::query()->create([
            'name' => 'Second',
            'apply_as' => 'catalog',
            'action' => 'percent',
            'value' => 8,
            'is_enabled' => true,
        ]);

        $this->get('/admin/discounts?id_min='.$second->id.'&id_max='.$second->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Discounts/Index', false)
                ->has('rules.data', 1)
                ->where('rules.data.0.id', $second->id)
                ->where('filters.id_min', (string) $second->id)
            );

        $this->assertNotSame($first->id, $second->id);
    }

    public function test_discounts_index_supports_condition_filter_and_export_ids(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $catalogRule = DiscountRule::query()->create([
            'name' => 'All Catalog Rule',
            'apply_as' => 'catalog',
            'action' => 'percent',
            'value' => 5,
            'is_enabled' => true,
        ]);

        $categoryRule = DiscountRule::query()->create([
            'name' => 'Category Only Rule',
            'apply_as' => 'catalog',
            'action' => 'percent',
            'value' => 10,
            'is_enabled' => true,
        ]);
        $categoryRule->conditions()->create([
            'type' => 'category',
            'value_ids' => [1],
        ]);

        // Filter by category condition
        $this->get('/admin/discounts?condition=category')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Discounts/Index', false)
                ->where('filters.condition', 'category')
                ->where('rules.data.0.id', $categoryRule->id)
            );

        // Filter by catalog condition
        $this->get('/admin/discounts?condition=catalog')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Discounts/Index', false)
                ->where('filters.condition', 'catalog')
            );

        // Export with ids
        $response = $this->get('/admin/discounts/export?ids='.$categoryRule->id);
        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('Category Only Rule', $content);
        $this->assertStringNotContainsString('All Catalog Rule', $content);
    }
}
