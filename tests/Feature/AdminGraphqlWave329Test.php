<?php

namespace Tests\Feature;

use App\Domain\Marketplace\MarketplaceSettings;
use App\Models\Admin;

class AdminGraphqlWave329Test extends MarketplaceTestCase
{
    public function test_seller_order_capability_settings_rest(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketplace/settings');
        $show->assertOk();
        $this->assertArrayHasKey('canCreateInvoice', $show->json('data'));
        $this->assertArrayHasKey('canCreateShipment', $show->json('data'));
        $this->assertArrayHasKey('canCancelOrder', $show->json('data'));
        $this->assertArrayHasKey('canViewCustomers', $show->json('data'));
        $this->assertTrue((bool) $show->json('data.canCreateInvoice'));

        $saved = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/marketplace/settings', [
            'canCreateInvoice' => false,
            'canCreateShipment' => false,
            'canCancelOrder' => false,
            'canViewCustomers' => false,
            'enableMinimumOrderAmount' => true,
        ]);
        $saved->assertOk();
        $this->assertFalse((bool) $saved->json('data.canCreateInvoice'));
        $this->assertFalse((bool) $saved->json('data.canCreateShipment'));
        $this->assertFalse((bool) $saved->json('data.canCancelOrder'));
        $this->assertFalse((bool) $saved->json('data.canViewCustomers'));
        $this->assertTrue((bool) $saved->json('data.enableMinimumOrderAmount'));

        $this->assertFalse(MarketplaceSettings::canCreateInvoice());
        $this->assertFalse(MarketplaceSettings::canCreateShipment());
        $this->assertTrue(MarketplaceSettings::enableMinimumOrderAmount());

        // Restore defaults for other tests in the same process.
        MarketplaceSettings::save([
            'can_create_invoice' => true,
            'can_create_shipment' => true,
            'can_cancel_order' => true,
            'can_view_customers' => true,
            'enable_minimum_order_amount' => false,
        ]);
    }
}
