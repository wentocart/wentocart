<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductQtyPrice;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Order\Models\Order;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave42Test extends TestCase
{
    use RefreshDatabase;

    public function test_qty_prices_record_payment_customer_groups_and_invoices(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $sku = 'W42-'.strtoupper(substr(uniqid(), -6));
        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createSimpleProduct(sku: $sku, name: "Wave42 Tier", price: 20, stockQty: 10, enabled: true) { id }
            }',
            'variables' => ['sku' => $sku],
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? []);
        $productId = (string) $create->json('data.createSimpleProduct.id');

        $tier = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($pid: ID!) {
                createProductQtyPrice(productId: $pid, minQty: 5, maxQty: 20, price: 15.5, position: 1) {
                    id product_id min_qty max_qty price
                }
            }',
            'variables' => ['pid' => $productId],
        ]);
        $tier->assertOk();
        $this->assertArrayNotHasKey('errors', $tier->json() ?? []);
        $this->assertEqualsWithDelta(5.0, (float) $tier->json('data.createProductQtyPrice.min_qty'), 0.001);
        $tierId = (string) $tier->json('data.createProductQtyPrice.id');
        $this->assertNotNull(ProductQtyPrice::query()->find((int) $tierId));

        $list = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($pid: ID!) { productQtyPrices(productId: $pid) { id price min_qty } }',
            'variables' => ['pid' => $productId],
        ]);
        $list->assertOk();
        $this->assertNotEmpty($list->json('data.productQtyPrices'));

        $del = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { deleteProductQtyPrice(id: $id) { ok id } }',
            'variables' => ['id' => $tierId],
        ]);
        $del->assertOk();
        $this->assertTrue((bool) $del->json('data.deleteProductQtyPrice.ok'));

        $groups = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ customerGroups(limit: 20) { id code name is_enabled } }',
        ]);
        $groups->assertOk();
        $this->assertArrayNotHasKey('errors', $groups->json() ?? []);
        $this->assertNotEmpty($groups->json('data.customerGroups'));
        $this->assertGreaterThan(0, CustomerGroup::query()->count());

        $invoices = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ invoices(limit: 10) { order_number invoice_number grand_total } }',
        ]);
        $invoices->assertOk();
        $this->assertArrayNotHasKey('errors', $invoices->json() ?? []);
        $this->assertIsArray($invoices->json('data.invoices'));

        $order = Order::query()
            ->where('payment_status', '!=', 'refunded')
            ->where(function ($q) {
                $q->whereNull('amount_paid')->orWhereColumn('amount_paid', '<', 'grand_total');
            })
            ->first();
        if (! $order) {
            $order = Order::query()->first();
        }
        if ($order) {
            $order->forceFill([
                'payment_status' => 'pending',
                'amount_paid' => 0,
                'amount_due' => $order->grand_total,
            ])->save();

            $pay = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
                'query' => 'mutation ($n: String!) {
                    recordPayment(number: $n, amount: 1.00) {
                        order_number amount_recorded amount_paid amount_due payment_status
                    }
                }',
                'variables' => ['n' => $order->number],
            ]);
            $pay->assertOk();
            $this->assertArrayNotHasKey('errors', $pay->json() ?? [], json_encode($pay->json()));
            $this->assertEqualsWithDelta(1.0, (float) $pay->json('data.recordPayment.amount_recorded'), 0.001);
            $this->assertContains(
                (string) $pay->json('data.recordPayment.payment_status'),
                ['partial', 'paid']
            );
            $this->assertNotNull(Product::query()->find((int) $productId));
        }
    }
}
