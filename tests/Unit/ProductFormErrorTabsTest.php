<?php

namespace Tests\Unit;

use App\Domain\Catalog\Support\ProductFormErrorTabs;
use PHPUnit\Framework\TestCase;

class ProductFormErrorTabsTest extends TestCase
{
    public function test_single_tab_validation_error(): void
    {
        $this->assertSame(
            ['subscription'],
            ProductFormErrorTabs::tabsForErrors([
                'subscription_plans' => 'Please add at least one active subscription plan before saving this product.',
            ], ['general', 'pricing', 'subscription']),
        );
        $this->assertSame('subscription', ProductFormErrorTabs::tabForKey('translations.en.subscription_message'));
        $this->assertSame('general', ProductFormErrorTabs::tabForKey('translations.en.name'));
        $this->assertSame('pricing', ProductFormErrorTabs::tabForKey('price'));
    }

    public function test_multiple_tab_validation_errors(): void
    {
        $this->assertSame(
            ['general', 'pricing', 'subscription'],
            ProductFormErrorTabs::tabsForErrors([
                'category_ids' => 'Select at least one category.',
                'price' => 'Price cannot be negative.',
                'subscription_plans' => 'Please add at least one active subscription plan before saving this product.',
            ], ['general', 'details', 'pricing', 'inventory', 'subscription']),
        );
    }

    public function test_unrelated_tabs_are_not_marked(): void
    {
        $tabs = ProductFormErrorTabs::tabsForErrors([
            'subscription_plans' => 'Please add at least one active subscription plan before saving this product.',
        ], ['general', 'pricing', 'inventory', 'subscription']);

        $this->assertSame(['subscription'], $tabs);
        $this->assertNotContains('general', $tabs);
        $this->assertNotContains('pricing', $tabs);
    }

    public function test_successful_save_has_no_error_tabs(): void
    {
        $this->assertSame([], ProductFormErrorTabs::tabsForErrors([], ['general', 'subscription']));
    }

    public function test_components_map_to_bundle_when_products_tab_is_absent(): void
    {
        $this->assertSame('bundle', ProductFormErrorTabs::tabForKey('components', ['general', 'bundle', 'pricing']));
    }
}
