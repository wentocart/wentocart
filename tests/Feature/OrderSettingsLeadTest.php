<?php

namespace Tests\Feature;

use App\Domain\Settings\OrderSettings;
use App\Domain\Settings\ShopSetting;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderSettingsLeadTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_order_settings_field_matrix_and_admin_ui(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $update = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/configuration', [
            'slug' => 'orders',
            'values' => [
                'orders.admin_reorder_enabled' => '1',
                'checkout.guest_checkout' => '1',
                'orders.minimum_order_enabled' => '1',
                'orders.min_amount' => '25.50',
                'orders.minimum_order_include_discount' => '1',
                'orders.minimum_order_description' => 'Cart must reach the minimum.',
            ],
        ]);
        $update->assertOk();
        $this->assertTrue((bool) $update->json('ok'));

        $values = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/configuration?slug=orders');
        $values->assertOk();
        $map = $values->json('values') ?? [];
        $this->assertSame('1', (string) ($map['orders.admin_reorder_enabled'] ?? ''));
        $this->assertSame('1', (string) ($map['checkout.guest_checkout'] ?? ''));
        $this->assertSame('1', (string) ($map['orders.minimum_order_enabled'] ?? ''));
        $this->assertSame('25.50', (string) ($map['orders.min_amount'] ?? ''));
        $this->assertSame('1', (string) ($map['orders.minimum_order_include_discount'] ?? ''));
        $this->assertSame('Cart must reach the minimum.', (string) ($map['orders.minimum_order_description'] ?? ''));

        $this->assertTrue(OrderSettings::adminReorderEnabled());
        $this->assertTrue(OrderSettings::minimumOrderEnabled());
        $this->assertTrue(ShopSetting::bool('orders.minimum_order_include_discount', false));
        $this->assertSame('Cart must reach the minimum.', (string) ShopSetting::get('orders.minimum_order_description', ''));

        $stages = OrderSettings::statusChoices();
        $this->assertNotEmpty($stages);
        $this->assertArrayHasKey('value', $stages[0]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/order-settings')
            ->assertOk();
    }
}
