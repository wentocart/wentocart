<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Promo\DiscountContext;
use App\Domain\Promo\DiscountEngine;
use App\Domain\Promo\Models\DiscountRedemption;
use App\Domain\Promo\Models\DiscountRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscountPerCustomerLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_rule_without_cap_applies_regardless_of_history(): void
    {
        $this->seed();
        $rule = $this->coupon(['max_uses_per_customer' => null]);
        $customer = Customer::factory()->create();
        DiscountRedemption::create([
            'discount_rule_id' => $rule->id,
            'customer_id' => $customer->id,
            'email' => $customer->email,
        ]);

        $engine = app(DiscountEngine::class);

        $this->assertTrue($engine->withinPerCustomerLimit($rule, new DiscountContext(customer: $customer)));
    }

    public function test_redeemed_customer_hits_the_cap(): void
    {
        $this->seed();
        $rule = $this->coupon(['max_uses_per_customer' => 1]);
        $customer = Customer::factory()->create();
        DiscountRedemption::create([
            'discount_rule_id' => $rule->id,
            'customer_id' => $customer->id,
            'email' => $customer->email,
        ]);

        $engine = app(DiscountEngine::class);

        $this->assertFalse($engine->withinPerCustomerLimit($rule, new DiscountContext(customer: $customer)));
        $this->assertSame(1, DiscountRedemption::usedByRule($rule->id, $customer->id, $customer->email));
    }

    public function test_guest_cap_is_tracked_by_email(): void
    {
        $this->seed();
        $rule = $this->coupon(['max_uses_per_customer' => 2]);
        DiscountRedemption::create([
            'discount_rule_id' => $rule->id,
            'customer_id' => null,
            'email' => 'guest@example.com',
        ]);

        $this->assertSame(1, DiscountRedemption::usedByRule($rule->id, null, 'guest@example.com'));
        $this->assertSame(0, DiscountRedemption::usedByRule($rule->id, null, 'other@example.com'));
        $this->assertSame(0, DiscountRedemption::usedByRule($rule->id, null, null));
    }

    public function test_admin_can_set_the_cap_and_it_round_trips_through_csv(): void
    {
        $this->seed();
        $admin = \App\Models\Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $this->post('/admin/discounts', [
            'name' => 'One per customer',
            'apply_as' => 'coupon',
            'code' => 'ONCEPP',
            'action' => 'percent',
            'value' => 10,
            'currency_code' => 'USD',
            'priority' => 100,
            'stop_further' => false,
            'max_uses_per_customer' => 1,
            'is_enabled' => true,
            'conditions' => [],
        ])->assertRedirect('/admin/discounts');

        $this->assertDatabaseHas('discount_rules', [
            'code' => 'ONCEPP',
            'max_uses_per_customer' => 1,
        ]);

        $csv = \App\Domain\Promo\Support\DiscountCsvSchema::exportCsv();
        $this->assertStringContainsString('max_uses_per_customer', $csv);
        $this->assertStringContainsString('ONCEPP', $csv);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function coupon(array $overrides = []): DiscountRule
    {
        return DiscountRule::query()->create(array_merge([
            'name' => 'Per customer cap',
            'apply_as' => 'coupon',
            'code' => 'CAP'.random_int(1000, 9999),
            'action' => 'percent',
            'value' => 10,
            'currency_code' => 'USD',
            'priority' => 100,
            'stop_further' => false,
            'is_enabled' => true,
        ], $overrides));
    }
}
