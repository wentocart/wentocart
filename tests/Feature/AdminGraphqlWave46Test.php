<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Order\Models\Order;
use App\Domain\Promo\Models\DiscountRule;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave46Test extends TestCase
{
    use RefreshDatabase;

    public function test_update_promotion_and_reorder_admin_order(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $promo = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createPromotion(name: "Wave46 Cart", applyAs: "cart", action: "percent", value: 10, enabled: true) {
                    id name value is_enabled
                }
            }',
        ]);
        $promo->assertOk();
        $this->assertArrayNotHasKey('errors', $promo->json() ?? [], json_encode($promo->json()));
        $promoId = (string) $promo->json('data.createPromotion.id');

        $update = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updatePromotion(id: $id, name: "Wave46 Cart Plus", value: 15, priority: 50, enabled: true) {
                    id name value is_enabled
                }
            }',
            'variables' => ['id' => $promoId],
        ]);
        $update->assertOk();
        $this->assertArrayNotHasKey('errors', $update->json() ?? [], json_encode($update->json()));
        $this->assertSame('Wave46 Cart Plus', (string) $update->json('data.updatePromotion.name'));
        $this->assertEqualsWithDelta(15.0, (float) $update->json('data.updatePromotion.value'), 0.001);
        $fresh = DiscountRule::query()->find((int) $promoId);
        $this->assertNotNull($fresh);
        $this->assertSame(50, (int) $fresh->priority);

        $sku = 'W46-'.strtoupper(substr(uniqid(), -6));
        $productRes = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createSimpleProduct(sku: $sku, name: "Wave46 Reorder", price: 18, stockQty: 20, enabled: true) { id }
            }',
            'variables' => ['sku' => $sku],
        ]);
        $productRes->assertOk();
        $productId = (string) $productRes->json('data.createSimpleProduct.id');
        Product::query()->whereKey((int) $productId)->update(['manage_stock' => true, 'stock_qty' => 20]);

        $email = 'w46-'.uniqid().'@example.com';
        $customer = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createCustomer(email: $e, firstName: "Wave", lastName: "FortySix") { id }
            }',
            'variables' => ['e' => $email],
        ]);
        $customer->assertOk();
        $customerId = (string) $customer->json('data.createCustomer.id');
        $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!) {
                createCustomerAddress(
                    customerId: $cid
                    firstName: "Wave"
                    lastName: "FortySix"
                    line1: "46 Reorder Ave"
                    city: "Seattle"
                    state: "WA"
                    postalCode: "98101"
                    countryCode: "US"
                    defaultBilling: true
                    defaultShipping: true
                ) { id }
            }',
            'variables' => ['cid' => $customerId],
        ])->assertOk();

        $source = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!, $pid: ID!) {
                createAdminOrder(customerId: $cid, items: [{ productId: $pid, quantity: 2 }]) {
                    number
                }
            }',
            'variables' => ['cid' => $customerId, 'pid' => $productId],
        ]);
        $source->assertOk();
        $this->assertArrayNotHasKey('errors', $source->json() ?? [], json_encode($source->json()));
        $sourceNumber = (string) $source->json('data.createAdminOrder.number');

        $reorder = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($n: String!) {
                reorderAdminOrder(number: $n, customerNote: "Wave46 reorder") {
                    id number email status items { sku qty }
                }
            }',
            'variables' => ['n' => $sourceNumber],
        ]);
        $reorder->assertOk();
        $this->assertArrayNotHasKey('errors', $reorder->json() ?? [], json_encode($reorder->json()));
        $newNumber = (string) $reorder->json('data.reorderAdminOrder.number');
        $this->assertNotSame($sourceNumber, $newNumber);
        $this->assertSame($email, (string) $reorder->json('data.reorderAdminOrder.email'));
        $this->assertEqualsWithDelta(2.0, (float) $reorder->json('data.reorderAdminOrder.items.0.qty'), 0.001);
        $this->assertNotNull(Order::query()->where('number', $newNumber)->first());
    }
}
