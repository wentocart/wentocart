<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\CatalogPresenter;
use App\Domain\Customer\Models\Customer;
use App\Domain\Localization\Models\Language;
use App\Domain\Subscription\Models\SubscriptionPlan;
use App\Domain\Subscription\Services\SubscriptionService;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontProductSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
    }

    protected function makePlan(string $name, array $overrides = []): SubscriptionPlan
    {
        $this->post('/admin/subscription-plans', array_merge([
            'name' => $name,
            'is_enabled' => true,
            'duration' => 0,
            'cycle' => 1,
            'frequency' => 'month',
            'trial_enabled' => false,
        ], $overrides));

        return SubscriptionPlan::query()->orderByDesc('id')->firstOrFail();
    }

    /** @param  list<array<string, mixed>>  $plans */
    protected function makeSubscribedProduct(array $plans, array $overrides = []): Product
    {
        $sku = $overrides['sku'] ?? 'FO-SUB-'.uniqid();
        unset($overrides['sku']);
        $this->post('/admin/products', $this->withProductCategories(array_merge([
            'type' => 'simple',
            'name' => 'FO Subscription Product',
            'sku' => $sku,
            'price' => 40,
            'is_enabled' => true,
            'manage_stock' => false,
            'subscription_enabled' => true,
            'purchase_option' => 'subscription',
            'subscription_plans' => $plans,
        ], $overrides)))->assertProductSaveRedirect();

        return Product::query()->where('sku', $sku)->firstOrFail()
            ->load(['translations', 'productSubscriptionPlans.plan.translations']);
    }

    public function test_storefront_returns_all_active_plans_with_interval_trial_and_price(): void
    {
        $monthly = $this->makePlan('Monthly Essential', [
            'cycle' => 1,
            'frequency' => 'month',
            'trial_enabled' => true,
            'trial_duration' => 1,
            'trial_cycle' => 1,
            'trial_frequency' => 'week',
        ]);
        $biannual = $this->makePlan('Biannual Business', [
            'cycle' => 6,
            'frequency' => 'month',
        ]);
        $hidden = $this->makePlan('Hidden Plan', [
            'cycle' => 1,
            'frequency' => 'week',
        ]);

        $product = $this->makeSubscribedProduct([
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
            [
                'subscription_plan_id' => $hidden->id,
                'price' => 20,
                'is_enabled' => false,
                'is_default' => false,
            ],
        ], ['sku' => 'FO-SUB-MULTI', 'purchase_option' => 'both']);

        $presented = app(CatalogPresenter::class)->product(
            $product->fresh(['translations', 'productSubscriptionPlans.plan.translations']),
            'en',
            true,
        );
        $this->assertCount(2, $presented['subscription_plans']);
        $ids = collect($presented['subscription_plans'])->pluck('id')->all();
        $this->assertSame([$monthly->id, $biannual->id], $ids);

        $default = collect($presented['subscription_plans'])->firstWhere('is_default', true);
        $this->assertSame($monthly->id, $default['id']);
        $this->assertSame('Monthly Essential', $default['name']);
        $this->assertSame('Every 1 Month', $default['billing_summary']);
        $this->assertTrue($default['trial_enabled']);
        $this->assertSame('1 Week', $default['trial_summary']);
        $this->assertSame('percent', $default['discount_type']);
        $this->assertEqualsWithDelta(10.0, (float) $default['discount_value'], 0.01);
        $this->assertEqualsWithDelta(36.0, (float) $default['price'], 0.01);

        $other = collect($presented['subscription_plans'])->firstWhere('id', $biannual->id);
        $this->assertSame('Every 6 Months', $other['billing_summary']);
        $this->assertFalse($other['is_default']);
        $this->assertFalse((bool) $other['trial_enabled']);

        $slug = $product->translation('en')->slug;
        $json = $this->getJson('/api/v1/catalog/product/'.$slug)->assertOk()->json('product');
        $this->assertCount(2, $json['subscription_plans']);
        $this->assertSame($monthly->id, collect($json['subscription_plans'])->firstWhere('is_default', true)['id']);
        $this->assertNull($json['subscription_message']);
    }

    public function test_globally_disabled_plan_is_hidden_from_the_storefront(): void
    {
        $active = $this->makePlan('Live Plan');
        $globalOff = $this->makePlan('Later Disabled');
        $product = $this->makeSubscribedProduct([
            ['subscription_plan_id' => $active->id, 'price' => 12, 'is_enabled' => true, 'is_default' => true],
            ['subscription_plan_id' => $globalOff->id, 'price' => 15, 'is_enabled' => true, 'is_default' => false],
        ], ['sku' => 'FO-SUB-GLOBAL']);

        $globalOff->update(['is_enabled' => false]);
        $plans = app(SubscriptionService::class)->storefrontPlans(
            $product->fresh(['productSubscriptionPlans.plan.translations']),
        );
        $this->assertCount(1, $plans);
        $this->assertSame($active->id, $plans[0]['id']);
    }

    public function test_selected_plan_is_stored_on_the_cart_and_one_time_purchase_omits_it(): void
    {
        $monthly = $this->makePlan('Monthly Cart');
        $weekly = $this->makePlan('Weekly Cart', ['frequency' => 'week']);
        $product = $this->makeSubscribedProduct([
            ['subscription_plan_id' => $monthly->id, 'price' => 30, 'is_enabled' => true, 'is_default' => true],
            ['subscription_plan_id' => $weekly->id, 'price' => 12, 'is_enabled' => true, 'is_default' => false],
        ], ['sku' => 'FO-SUB-CART', 'purchase_option' => 'both']);

        $customer = Customer::factory()->create();
        $this->actingAs($customer, 'customer');

        $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'qty' => 1,
            'subscription_plan_id' => $weekly->id,
        ])->assertOk()
            ->assertJsonPath('cart.items.0.subscription_plan_id', $weekly->id);

        $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'qty' => 1,
            'subscription_plan_id' => $monthly->id,
        ])->assertOk()
            ->assertJsonPath('cart.items.1.subscription_plan_id', $monthly->id);

        $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'qty' => 1,
        ])->assertOk();

        $items = collect($this->getJson('/api/v1/cart')->assertOk()->json('cart.items'));
        $oneTime = $items->first(fn ($row) => empty($row['subscription_plan_id']));
        $this->assertNotNull($oneTime);
        $this->assertSame($product->id, $oneTime['product_id']);
    }

    public function test_localized_subscription_message_falls_back_and_hides_when_blank(): void
    {
        Language::query()->updateOrCreate(
            ['code' => 'fr'],
            ['name' => 'French', 'is_enabled' => true, 'is_default' => false, 'position' => 2],
        );
        $plan = $this->makePlan('Message Plan');
        $product = $this->makeSubscribedProduct([
            ['subscription_plan_id' => $plan->id, 'price' => 18, 'is_enabled' => true, 'is_default' => true],
        ], ['sku' => 'FO-SUB-MSG']);

        $enRow = $product->translation('en');
        $this->from('/admin/products/'.$product->id.'/edit')->put('/admin/products/'.$product->id, $this->withProductCategories([
            'type' => 'simple',
            'name' => $enRow->name,
            'sku' => $product->sku,
            'price' => 40,
            'is_enabled' => true,
            'manage_stock' => false,
            'subscription_enabled' => true,
            'purchase_option' => 'subscription',
            'subscription_plans' => [[
                'subscription_plan_id' => $plan->id,
                'price' => 18,
                'is_enabled' => true,
                'is_default' => true,
            ]],
            'translations' => [
                'en' => [
                    'language_code' => 'en',
                    'name' => $enRow->name,
                    'slug' => $enRow->slug,
                    'subscription_message' => 'Billed automatically until you cancel.',
                ],
                'fr' => [
                    'language_code' => 'fr',
                    'name' => 'Produit abonnement',
                    'slug' => 'fo-sub-msg-fr',
                    'subscription_message' => 'Facturé automatiquement.',
                ],
            ],
        ]))->assertProductSaveRedirect();

        $product = $product->fresh(['translations', 'productSubscriptionPlans.plan.translations']);

        $en = app(CatalogPresenter::class)->product($product->fresh(['translations', 'productSubscriptionPlans.plan.translations']), 'en', true);
        $this->assertSame('Billed automatically until you cancel.', $en['subscription_message']);

        $fr = app(CatalogPresenter::class)->product($product->fresh(['translations', 'productSubscriptionPlans.plan.translations']), 'fr', true);
        $this->assertSame('Facturé automatiquement.', $fr['subscription_message']);

        $slug = $product->translation('en')->slug;
        $enJson = $this->withHeader('X-Wento-Locale', 'en')
            ->getJson('/api/v1/catalog/product/'.$slug)
            ->assertOk()
            ->json('product');
        $this->assertSame('Billed automatically until you cancel.', $enJson['subscription_message']);

        $product->translations()->where('language_code', 'fr')->update(['subscription_message' => '']);
        $fallback = app(CatalogPresenter::class)->product($product->fresh(['translations', 'productSubscriptionPlans.plan.translations']), 'fr', true);
        $this->assertSame('Billed automatically until you cancel.', $fallback['subscription_message']);

        $product->translations()->update(['subscription_message' => null]);
        $empty = app(CatalogPresenter::class)->product($product->fresh(['translations', 'productSubscriptionPlans.plan.translations']), 'en', true);
        $this->assertNull($empty['subscription_message']);

        $slug = $product->translation('en')->slug;
        $json = $this->withHeader('X-Wento-Locale', 'en')
            ->getJson('/api/v1/catalog/product/'.$slug)
            ->assertOk()
            ->json('product');
        $this->assertNull($json['subscription_message']);
    }

    public function test_storefront_pdp_lists_every_active_plan_not_only_the_default(): void
    {
        $pdp = (string) file_get_contents(base_path('storefront/src/pdp/SimplePdp.jsx'));
        $this->assertStringContainsString('product.subscription_plans || []', $pdp);
        $this->assertStringContainsString('plans.map((plan)', $pdp);
        $this->assertStringContainsString('subscription_plan_id', $pdp);
        $this->assertStringContainsString('product.subscription_message', $pdp);
        $this->assertStringNotContainsString('plans.length > 1', $pdp);
        $this->assertStringContainsString('plan.billing_summary', $pdp);
        $this->assertStringContainsString('plan.trial_summary', $pdp);
        $this->assertStringContainsString('plan.is_default', $pdp);
        $this->assertStringNotContainsString("t('storefront.pdp.default_plan'", $pdp);
    }
}
