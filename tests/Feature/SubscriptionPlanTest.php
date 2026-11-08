<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Subscription\Models\SubscriptionPlan;
use App\Domain\Subscription\Services\SubscriptionService;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_subscription_plans_and_product_pricing(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->get('/admin/subscription-plans')->assertOk();
        $this->get('/admin/subscription-plans/create')->assertOk();

        $this->from('/admin/subscription-plans/create')->post('/admin/subscription-plans', [
            'name' => '',
            'is_enabled' => true,
            'duration' => 0,
            'cycle' => 0,
            'frequency' => 'month',
        ])->assertSessionHasErrors(['name', 'cycle']);

        $this->post('/admin/subscription-plans', [
            'name' => 'Monthly',
            'is_enabled' => true,
            'trial_enabled' => false,
            'duration' => 0,
            'cycle' => 1,
            'frequency' => 'month',
        ])->assertRedirect('/admin/subscription-plans');

        $monthly = SubscriptionPlan::query()->first();
        $this->assertNotNull($monthly);
        $this->assertSame('Monthly', $monthly->translation()->name);
        $this->assertSame(1, (int) $monthly->cycle);

        $this->post('/admin/subscription-plans', [
            'name' => 'Weekly',
            'is_enabled' => true,
            'trial_enabled' => true,
            'trial_duration' => 1,
            'trial_cycle' => 1,
            'trial_frequency' => 'week',
            'duration' => 0,
            'cycle' => 1,
            'frequency' => 'week',
        ])->assertRedirect();

        $weekly = SubscriptionPlan::query()->orderByDesc('id')->first();

        $this->post('/admin/subscription-plans', [
            'name' => 'Disabled plan',
            'is_enabled' => false,
            'duration' => 0,
            'cycle' => 1,
            'frequency' => 'day',
        ])->assertRedirect();

        $disabled = SubscriptionPlan::query()->orderByDesc('id')->first();
        $this->assertFalse($disabled->is_enabled);

        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $svc = app(SubscriptionService::class);

        try {
            $svc->syncProduct($product, true, 'both', [[
                'subscription_plan_id' => $disabled->id,
                'price' => 10,
                'is_enabled' => true,
                'is_default' => true,
            ]]);
            $this->fail('Disabled plans must not be assigned to products.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('subscription_plans', $e->errors());
        }
    }

    public function test_product_assignment_pricing_default_and_delete_guard(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->post('/admin/subscription-plans', [
            'name' => 'Monthly',
            'is_enabled' => true,
            'duration' => 0,
            'cycle' => 1,
            'frequency' => 'month',
        ]);
        $this->post('/admin/subscription-plans', [
            'name' => 'Weekly',
            'is_enabled' => true,
            'duration' => 0,
            'cycle' => 1,
            'frequency' => 'week',
        ]);

        $monthly = SubscriptionPlan::query()->whereHas('translations', fn ($q) => $q->where('name', 'Monthly'))->firstOrFail();
        $weekly = SubscriptionPlan::query()->whereHas('translations', fn ($q) => $q->where('name', 'Weekly'))->firstOrFail();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $regular = (float) $product->sellingPrice();
        $svc = app(SubscriptionService::class);

        $svc->syncProduct($product, true, 'both', [
            [
                'subscription_plan_id' => $monthly->id,
                'discount_type' => 'percent',
                'discount_value' => 25,
                'is_enabled' => true,
                'is_default' => true,
            ],
            [
                'subscription_plan_id' => $weekly->id,
                'price' => max(0, $regular - 5),
                'is_enabled' => true,
                'is_default' => true,
            ],
        ]);

        $product->refresh()->load(['productSubscriptionPlans.plan.translations']);
        $this->assertTrue($product->subscription_enabled);
        $this->assertSame('both', $product->purchase_option);
        $this->assertCount(2, $product->productSubscriptionPlans);
        $this->assertSame(1, $product->productSubscriptionPlans->where('is_default', true)->count());
        $this->assertTrue((bool) $product->productSubscriptionPlans->firstWhere('subscription_plan_id', $weekly->id)->is_default);
        $this->assertEqualsWithDelta(round($regular * 0.75, 2), (float) $product->productSubscriptionPlans->firstWhere('subscription_plan_id', $monthly->id)->price, 0.01);

        $this->delete('/admin/subscription-plans/'.$monthly->id)
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertNotNull($monthly->fresh());

        $storefront = $svc->storefrontPlans($product->fresh(['productSubscriptionPlans.plan.translations']));
        $this->assertCount(2, $storefront);

        $monthly->update(['is_enabled' => false]);
        $storefront = $svc->storefrontPlans($product->fresh(['productSubscriptionPlans.plan.translations']));
        $this->assertCount(1, $storefront);
        $this->assertTrue($product->productSubscriptionPlans()->where('subscription_plan_id', $monthly->id)->exists());

        $slug = $product->translation()->slug;
        $this->getJson('/api/v1/catalog/product/'.$slug)
            ->assertOk()
            ->assertJsonPath('product.subscription_enabled', true)
            ->assertJsonCount(1, 'product.subscription_plans');

        $customer = \App\Domain\Customer\Models\Customer::factory()->create();
        $this->actingAs($customer, 'customer');

        $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'qty' => 1,
            'subscription_plan_id' => $weekly->id,
        ])->assertOk()->assertJsonPath('cart.items.0.subscription_plan_id', $weekly->id);

        $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'qty' => 1,
            'subscription_plan_id' => $monthly->id,
        ])->assertStatus(422);
    }

    public function test_admin_can_save_subscription_plan_translations(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        \App\Domain\Localization\Models\Language::query()->updateOrCreate(
            ['code' => 'fr'],
            ['name' => 'French', 'is_enabled' => true, 'is_default' => false, 'position' => 2],
        );

        $this->post('/admin/subscription-plans', [
            'is_enabled' => true,
            'duration' => 0,
            'cycle' => 1,
            'frequency' => 'month',
            'translations' => [
                'en' => ['language_code' => 'en', 'name' => 'Monthly EN'],
                'fr' => ['language_code' => 'fr', 'name' => 'Mensuel'],
            ],
        ])->assertRedirect('/admin/subscription-plans');

        $plan = SubscriptionPlan::query()
            ->whereHas('translations', fn ($q) => $q->where('language_code', 'en')->where('name', 'Monthly EN'))
            ->firstOrFail();

        $this->assertSame('Monthly EN', $plan->translation('en')?->name);
        $this->assertSame('Mensuel', $plan->translation('fr')?->name);

        $this->put('/admin/subscription-plans/'.$plan->id, [
            'is_enabled' => true,
            'duration' => 0,
            'cycle' => 1,
            'frequency' => 'month',
            'translations' => [
                'en' => ['language_code' => 'en', 'name' => 'Monthly EN'],
                'fr' => ['language_code' => 'fr', 'name' => ''],
            ],
        ])->assertRedirect('/admin/subscription-plans');

        $this->assertNull($plan->fresh()->translations()->where('language_code', 'fr')->first());
        $this->assertSame('Monthly EN', $plan->fresh()->translation('en')?->name);
    }

    public function test_subscription_plan_list_supports_column_filters(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->post('/admin/subscription-plans', [
            'name' => 'Filter Monthly',
            'is_enabled' => true,
            'trial_enabled' => true,
            'trial_duration' => 1,
            'trial_cycle' => 1,
            'trial_frequency' => 'week',
            'duration' => 0,
            'cycle' => 2,
            'frequency' => 'month',
        ])->assertRedirect('/admin/subscription-plans');

        $this->get('/admin/subscription-plans?name=Filter&frequency=month&trial=yes&status=active&id_min=1&cycle_min=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/Subscriptions/Index', false)
                ->has('plans.data')
                ->where('filters.name', 'Filter')
                ->where('filters.frequency', 'month')
                ->where('filters.trial', 'yes'));
    }

    public function test_subscription_plan_import_sample_and_csv_import(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $response = $this->get('/admin/subscription-plans/import/sample?delimiter=;&multivalue_separator=,');
        $response->assertOk();
        $this->assertStringContainsString('subscription-plans-sample.csv', (string) $response->headers->get('content-disposition'));

        $csv = $response->streamedContent();
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        $header = str_getcsv($lines[0], ';');
        $this->assertSame(\App\Domain\Subscription\Support\SubscriptionPlanCsvSchema::columns(), $header);
        $this->assertGreaterThanOrEqual(5, count($lines) - 1);

        $count = app(\App\Domain\Subscription\Services\SubscriptionService::class)->importCsv($csv, 'en', ';', ',');
        $this->assertSame(count(\App\Domain\Subscription\Support\SubscriptionPlanCsvSchema::samplePlans()), $count);

        $monthly = SubscriptionPlan::query()
            ->whereHas('translations', fn ($q) => $q->where('name', 'Monthly'))
            ->first();
        $this->assertNotNull($monthly);
        $this->assertSame('month', $monthly->frequency);
        $this->assertTrue($monthly->is_enabled);
        $this->assertFalse($monthly->trial_enabled);

        $weekly = SubscriptionPlan::query()
            ->whereHas('translations', fn ($q) => $q->where('name', 'Weekly'))
            ->first();
        $this->assertNotNull($weekly);
        $this->assertTrue($weekly->trial_enabled);
        $this->assertSame('week', $weekly->frequency);

        $legacy = SubscriptionPlan::query()
            ->whereHas('translations', fn ($q) => $q->where('name', 'Legacy Daily'))
            ->first();
        $this->assertNotNull($legacy);
        $this->assertFalse($legacy->is_enabled);

        $this->get('/admin/subscription-plans/export')->assertOk();

        $exported = app(\App\Domain\Subscription\Services\SubscriptionService::class)->exportCsv('en', null, ';', ',');
        $reimport = app(\App\Domain\Subscription\Services\SubscriptionService::class)->importCsv($exported, 'en', ';', ',');
        $this->assertSame(count(\App\Domain\Subscription\Support\SubscriptionPlanCsvSchema::samplePlans()), $reimport);
        $this->assertSame(1, SubscriptionPlan::query()
            ->whereHas('translations', fn ($q) => $q->where('name', 'Monthly'))
            ->count());
    }


    public function test_admin_can_create_plan_with_hour_trial_and_billing_frequency(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->get('/admin/subscription-plans/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/Subscriptions/Form')
                ->where('frequencies.0.value', 'hour')
                ->where('frequencies.0.label', 'Hour')
                ->where('frequencies.1.value', 'day')
                ->where('frequencies.4.value', 'year')
            );

        $this->post('/admin/subscription-plans', [
            'name' => 'Hourly trial plan',
            'is_enabled' => true,
            'trial_enabled' => true,
            'trial_duration' => 2,
            'trial_cycle' => 1,
            'trial_frequency' => 'hour',
            'duration' => 0,
            'cycle' => 3,
            'frequency' => 'hour',
        ])->assertRedirect('/admin/subscription-plans');

        $plan = SubscriptionPlan::query()
            ->whereHas('translations', fn ($q) => $q->where('name', 'Hourly trial plan'))
            ->first();

        $this->assertNotNull($plan);
        $this->assertSame('hour', $plan->frequency);
        $this->assertSame('hour', $plan->trial_frequency);
        $this->assertTrue($plan->trial_enabled);
        $this->assertSame(2, (int) $plan->trial_duration);
        $this->assertSame(3, (int) $plan->cycle);

        $svc = app(SubscriptionService::class);
        $this->assertSame('Every 3 Hours', $svc->billingLabel((int) $plan->cycle, $plan->frequency));
        $this->assertSame('2 Hours', $svc->trialSummary($plan));

        $this->from('/admin/subscription-plans/create')->post('/admin/subscription-plans', [
            'name' => 'Bad frequency',
            'is_enabled' => true,
            'duration' => 0,
            'cycle' => 1,
            'frequency' => 'minute',
            'trial_enabled' => true,
            'trial_duration' => 1,
            'trial_cycle' => 1,
            'trial_frequency' => 'minute',
        ])->assertSessionHasErrors(['frequency', 'trial_frequency']);
    }

    public function test_create_form_accepts_a_safe_admin_return_url(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $return = '/admin/products/12/edit?tab=subscription';

        $this->get('/admin/subscription-plans/create?return='.urlencode($return))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/Subscriptions/Form')
                ->where('return_to', $return)
            );
    }

    public function test_create_form_ignores_external_return_urls(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->get('/admin/subscription-plans/create?return='.urlencode('https://evil.example/phish'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/Subscriptions/Form')
                ->where('return_to', null)
            );
    }

    public function test_storing_a_plan_always_redirects_to_the_plan_list(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $return = '/admin/products/12/edit?tab=subscription';

        $this->from('/admin/subscription-plans/create?return='.urlencode($return))
            ->post('/admin/subscription-plans', [
                'name' => 'Return Monthly',
                'is_enabled' => true,
                'duration' => 0,
                'cycle' => 1,
                'frequency' => 'month',
                'return_to' => $return,
            ])
            ->assertRedirect(route('admin.subscription-plans.index'));

        $this->assertTrue(
            SubscriptionPlan::query()
                ->whereHas('translations', fn ($q) => $q->where('name', 'Return Monthly'))
                ->exists()
        );
    }

}
