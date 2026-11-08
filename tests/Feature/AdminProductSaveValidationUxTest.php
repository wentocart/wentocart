<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Support\ProductFormErrorTabs;
use App\Domain\Subscription\Services\SubscriptionService;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProductSaveValidationUxTest extends TestCase
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
            'name' => 'Validation UX Product',
            'sku' => 'VAL-UX-1',
            'price' => 20,
            'is_enabled' => true,
        ], $overrides));
    }

    public function test_single_tab_validation_error_stays_on_the_edit_page(): void
    {
        $this->post('/admin/products', $this->payload())->assertProductSaveRedirect();
        $product = Product::query()->where('sku', 'VAL-UX-1')->firstOrFail();
        $edit = '/admin/products/'.$product->id.'/edit';

        $response = $this->from($edit)->put('/admin/products/'.$product->id, $this->payload([
            'subscription_enabled' => true,
            'purchase_option' => 'subscription',
            'subscription_plans' => [],
        ]));

        $response->assertSessionHasErrors(['subscription_plans']);
        $messages = session('errors')->getBag('default')->messages();
        $this->assertSame(['subscription'], ProductFormErrorTabs::tabsForErrors(
            $messages,
            ['general', 'pricing', 'subscription'],
        ));
        $this->assertSame($edit, parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));
        $this->assertNotSame('/admin/products', parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));
    }

    public function test_multiple_tab_validation_errors_are_all_reported(): void
    {
        $response = $this->from('/admin/products/create')->post('/admin/products', $this->payload([
            'price' => -5,
            'weight' => -1,
        ]));

        $response->assertSessionHasErrors(['price', 'weight']);
        $messages = session('errors')->getBag('default')->messages();
        $tabs = ProductFormErrorTabs::tabsForErrors(
            $messages,
            ['general', 'pricing', 'inventory', 'shipping', 'subscription'],
        );
        $this->assertSame(['pricing', 'shipping'], $tabs);
        $this->assertNotSame('/admin/products', parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));
        $this->assertNull(Product::query()->where('sku', 'VAL-UX-1')->first());
    }

    public function test_repeated_save_clicks_with_the_same_error_keep_reporting_it(): void
    {
        $this->post('/admin/products', $this->payload())->assertProductSaveRedirect();
        $product = Product::query()->where('sku', 'VAL-UX-1')->firstOrFail();
        $edit = '/admin/products/'.$product->id.'/edit';
        $invalid = $this->payload([
            'subscription_enabled' => true,
            'purchase_option' => 'subscription',
            'subscription_plans' => [],
        ]);

        $first = $this->from($edit)->put('/admin/products/'.$product->id, $invalid);
        $first->assertSessionHasErrors([
            'subscription_plans' => SubscriptionService::PRODUCT_REQUIRES_ACTIVE_PLAN,
        ]);
        $this->assertSame($edit, parse_url((string) $first->headers->get('Location'), PHP_URL_PATH));

        $second = $this->from($edit)->put('/admin/products/'.$product->id, $invalid);
        $second->assertSessionHasErrors([
            'subscription_plans' => SubscriptionService::PRODUCT_REQUIRES_ACTIVE_PLAN,
        ]);
        $this->assertSame($edit, parse_url((string) $second->headers->get('Location'), PHP_URL_PATH));
    }

    public function test_successful_save_clears_validation_errors(): void
    {
        $this->post('/admin/products', $this->payload())->assertProductSaveRedirect();
        $product = Product::query()->where('sku', 'VAL-UX-1')->firstOrFail();
        $edit = '/admin/products/'.$product->id.'/edit';

        $this->from($edit)->put('/admin/products/'.$product->id, $this->payload([
            'subscription_enabled' => true,
            'purchase_option' => 'subscription',
            'subscription_plans' => [],
        ]))->assertSessionHasErrors('subscription_plans');

        $ok = $this->from($edit)->put('/admin/products/'.$product->id, $this->payload([
            'name' => 'Validation UX Product saved',
            'subscription_enabled' => false,
            'purchase_option' => 'one_time',
            'subscription_plans' => [],
        ]));

        $this->assertRedirectToProductEdit($ok, $product);
        $ok->assertSessionHas('success', 'Product saved successfully.');
        $ok->assertSessionHasNoErrors();
        $this->assertFalse((bool) $product->fresh()->subscription_enabled);
    }
}
