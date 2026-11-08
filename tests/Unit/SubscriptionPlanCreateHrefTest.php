<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Keep the product Subscription tab Create Plan link contract stable.
 * The React helper subscriptionPlanCreateHref() must match these URLs.
 */
class SubscriptionPlanCreateHrefTest extends TestCase
{
    public function test_create_plan_url_without_product(): void
    {
        $this->assertSame('/admin/subscription-plans/create', $this->href(null));
    }

    public function test_create_plan_url_returns_to_product_subscription_tab(): void
    {
        $this->assertSame(
            '/admin/subscription-plans/create?return='.rawurlencode('/admin/products/42/edit?tab=subscription'),
            $this->href(42)
        );
    }

    private function href(?int $productId): string
    {
        $base = '/admin/subscription-plans/create';
        if ($productId === null) {
            return $base;
        }

        $returnTo = '/admin/products/'.$productId.'/edit?tab=subscription';

        return $base.'?return='.rawurlencode($returnTo);
    }
}
