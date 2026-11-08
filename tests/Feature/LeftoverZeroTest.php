<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\PurchaseOrder;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Catalog\Models\Warehouse;
use App\Domain\Catalog\Services\PurchaseOrderService;
use App\Domain\Order\Models\Order;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Mail\ShopHtmlMail;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Modules\Community\Fedex\Support\FormulaCarrier;
use Tests\TestCase;

class LeftoverZeroTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_order_receive_increments_warehouse_stock(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $before = (float) $product->stock_qty;
        $warehouseQty = (float) ($warehouse->stocks()->where('product_id', $product->id)->value('qty') ?? 0);
        $supplier = Supplier::query()->first();

        $sample = $this->actingAs($admin, 'admin')
            ->get('/admin/purchase-orders/import/sample')
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('sku', explode("\n", $sample)[0]);

        $order = app(PurchaseOrderService::class)->save(null, [
            'supplier_id' => $supplier?->id,
            'warehouse_id' => $warehouse->id,
            'items' => [
                ['sku' => $product->sku, 'qty' => 5, 'unit_cost' => 2.5],
            ],
        ]);
        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $order->status);

        $this->actingAs($admin, 'admin')
            ->post('/admin/purchase-orders/'.$order->id.'/receive')
            ->assertRedirect();

        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $order->fresh()->status);
        $this->assertEqualsWithDelta($before + 5, (float) $product->fresh()->stock_qty, 0.01);
        $this->assertEqualsWithDelta($warehouseQty + 5, (float) $warehouse->stocks()->where('product_id', $product->id)->value('qty'), 0.01);
    }

    public function test_fedex_formula_carrier_buys_a_sandbox_label(): void
    {
        $carrier = base_path('unused-extensions/community/fedex/src/Support/FormulaCarrier.php');
        if (! is_file($carrier)) {
            $this->markTestSkipped('FedEx sample lives on branch archive/unused-extensions.');
        }

        $this->seed();
        $order = new Order(['number' => 'WENTO-LABEL']);
        $method = new ShippingMethod(['name' => 'FedEx']);
        require_once $carrier;
        $label = (new FormulaCarrier('fedex', 12, 4, 8, 'US'))->buyLabel($order, $method);
        $this->assertNotEmpty($label['tracking_number']);
        $this->assertStringStartsWith('FED', $label['tracking_number']);
    }

    public function test_admin_passkey_options_return_a_challenge(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $this->actingAs($admin, 'admin')
            ->getJson('/admin/mfa/passkey/register-options')
            ->assertOk()
            ->assertJsonPath('rp.id', parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost')
            ->assertJsonStructure(['challenge', 'user', 'pubKeyCredParams']);
    }

    public function test_passkey_register_rejects_garbage(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $this->actingAs($admin, 'admin')
            ->getJson('/admin/mfa/passkey/register-options')
            ->assertOk();
        $this->actingAs($admin, 'admin')
            ->postJson('/admin/mfa/passkey/register', [
                'credential_id' => 'abc',
                'client_data_json' => base64_encode('{"type":"nope"}'),
                'public_key' => 'abc',
            ])
            ->assertStatus(422);
    }

    public function test_stock_alerts_tick_emails_admin_once_until_stock_recovers(): void
    {
        $this->seed();
        Mail::fake();
        Product::query()->update(['notify_low_stock' => false, 'low_stock_notified_at' => null]);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->update([
            'manage_stock' => true,
            'is_enabled' => true,
            'notify_low_stock' => true,
            'stock_qty' => 2,
            'reserved_qty' => 0,
            'low_stock_alert' => 5,
            'low_stock_threshold' => 5,
            'stock_status' => 'in_stock',
        ]);

        $this->artisan('wento:stock-alerts-tick')->assertSuccessful();
        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('admin@wentocart.test')
            && str_contains($mail->emailSubject, 'low stock'));
        $this->assertNotNull($product->fresh()->low_stock_notified_at);

        Mail::fake();
        $this->artisan('wento:stock-alerts-tick')->assertSuccessful();
        Mail::assertNothingSent();

        $product->update(['stock_qty' => 20]);
        $this->artisan('wento:stock-alerts-tick')->assertSuccessful();
        $this->assertNull($product->fresh()->low_stock_notified_at);

        $product->update(['stock_qty' => 1]);
        Mail::fake();
        $this->artisan('wento:stock-alerts-tick')->assertSuccessful();
        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('admin@wentocart.test'));
    }
}
