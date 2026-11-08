<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Settings\GdprSettings;
use App\Domain\Settings\ShopSetting;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminGraphqlWave134Test extends TestCase
{
    use RefreshDatabase;

    public function test_shop_rest_cancel_reorder_gdpr_input_shapes(): void
    {
        $this->seed();
        ShopSetting::put('gdpr.enabled', true);
        $this->assertTrue(GdprSettings::enabled());

        $customer = Customer::factory()->create([
            'password' => Hash::make('password'),
            'is_enabled' => true,
            'email' => 'w134-'.uniqid().'@example.com',
        ]);
        $product = Product::query()->where('is_enabled', true)->where('type', 'simple')->first()
            ?? Product::query()->where('is_enabled', true)->firstOrFail();
        Product::query()->whereKey($product->id)->update([
            'manage_stock' => true,
            'stock_qty' => max(50, (int) $product->stock_qty),
            'is_enabled' => true,
        ]);

        $pending = Order::createTrusted([
            'number' => 'W134-PEND-1',
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'pending',
            'currency_code' => 'USD',
            'payment_status' => 'pending',
            'grand_total' => 19,
            'billing_address' => ['country_code' => 'US', 'first_name' => 'W', 'line1' => '1 Main'],
            'shipping_address' => ['country_code' => 'US', 'first_name' => 'W', 'line1' => '1 Main'],
        ]);

        $cancel = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) {
                createCancelOrder(input: { orderId: $id }) {
                    success
                    cancelOrder { success message orderId status }
                    order { id status }
                }
            }',
            'variables' => ['id' => (string) $pending->id],
        ]);
        $cancel->assertOk();
        $this->assertArrayNotHasKey('errors', $cancel->json() ?? [], json_encode($cancel->json()));
        $this->assertTrue((bool) $cancel->json('data.createCancelOrder.success'));
        $this->assertTrue((bool) $cancel->json('data.createCancelOrder.cancelOrder.success'));
        $this->assertSame((string) $pending->id, (string) $cancel->json('data.createCancelOrder.cancelOrder.orderId'));

        $completed = Order::createTrusted([
            'number' => 'W134-REORDER-1',
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'completed',
            'currency_code' => 'USD',
            'payment_status' => 'paid',
            'grand_total' => 20,
            'billing_address' => ['country_code' => 'US', 'first_name' => 'W', 'line1' => '1 Main'],
            'shipping_address' => ['country_code' => 'US', 'first_name' => 'W', 'line1' => '1 Main'],
        ]);
        $completed->items()->create([
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => 'Wave134 item',
            'qty' => 1,
            'unit_price' => 20,
            'total' => 20,
        ]);

        $reorder = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) {
                createReorderOrder(input: { orderId: $id }) {
                    success itemsAddedCount orderId
                }
            }',
            'variables' => ['id' => (string) $completed->id],
        ]);
        $reorder->assertOk();
        $this->assertArrayNotHasKey('errors', $reorder->json() ?? [], json_encode($reorder->json()));
        $this->assertTrue((bool) $reorder->json('data.createReorderOrder.success'));
        $this->assertGreaterThanOrEqual(1, (int) $reorder->json('data.createReorderOrder.itemsAddedCount'));

        $gdpr = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                createGdprRequest(input: { type: "delete", message: "Wave134 erase me" }) {
                    id type status email successMessage
                }
            }',
        ]);
        $gdpr->assertOk();
        $this->assertArrayNotHasKey('errors', $gdpr->json() ?? [], json_encode($gdpr->json()));
        $this->assertSame('delete', (string) $gdpr->json('data.createGdprRequest.type'));
        $this->assertSame($customer->email, (string) $gdpr->json('data.createGdprRequest.email'));
        $this->assertNotSame('', (string) $gdpr->json('data.createGdprRequest.successMessage'));
    }

    public function test_admin_attribute_detail_options_and_translations(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $code = 'w134_'.substr(uniqid(), -6);

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($code: String!) {
                createAdminAttribute(input: { adminName: "Wave134 Color", code: $code, type: "select" }) {
                    id code
                }
            }',
            'variables' => ['code' => $code],
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? [], json_encode($create->json()));
        $attrId = (string) $create->json('data.createAdminAttribute.id');

        $opt = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($aid: ID!) {
                createAdminAttributeOption(attributeId: $aid, label: "Blue", code: "blue") { id }
            }',
            'variables' => ['aid' => $attrId],
        ]);
        $opt->assertOk();
        $this->assertArrayNotHasKey('errors', $opt->json() ?? [], json_encode($opt->json()));

        $detail = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($id: ID!) {
                adminAttribute(id: $id) {
                    id adminName
                    translations { locale name }
                    options { id label code }
                }
            }',
            'variables' => ['id' => $attrId],
        ]);
        $detail->assertOk();
        $this->assertArrayNotHasKey('errors', $detail->json() ?? [], json_encode($detail->json()));
        $this->assertSame('Wave134 Color', (string) $detail->json('data.adminAttribute.adminName'));
        $labels = collect($detail->json('data.adminAttribute.options') ?? [])->pluck('label')->all();
        $this->assertContains('Blue', $labels);
        $this->assertNotEmpty($detail->json('data.adminAttribute.translations'));
        $this->assertNotNull(Feature::query()->find((int) $attrId));
    }
}
