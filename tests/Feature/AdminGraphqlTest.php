<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_query_admin_graphql(): void
    {
        $this->postJson('/api/v1/console/graphql', [
            'query' => '{ products(limit: 1) { id sku } }',
        ])->assertUnauthorized();
    }

    public function test_admin_can_list_products_and_orders(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $this->actingAs($admin, 'admin')
            ->postJson('/api/v1/console/graphql', [
                'query' => '{ products(limit: 3) { id sku name } orders(limit: 3) { id number } }',
            ])
            ->assertOk()
            ->assertJsonStructure(['data' => ['products', 'orders']]);

        $this->assertArrayNotHasKey('errors', $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ products(limit: 1) { id } }',
        ])->json() ?? []);
    }

    public function test_admin_can_update_order_status_via_graphql(): void
    {
        $this->seed();

        $product = \App\Domain\Catalog\Models\Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $payment = \App\Domain\Payment\Models\PaymentMethod::query()->firstOrFail();
        $shipping = \App\Domain\Shipping\Models\ShippingMethod::query()->firstOrFail();

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $placed = $this->postJson('/api/v1/checkout', [
            'email' => 'gql-admin-status@example.com',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated();
        $number = $placed->json('order.number');
        $this->assertNotEmpty($number);

        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $response = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($n: String!) { updateOrderStatus(number: $n, status: "processing", comment: "via gql") { number status } }',
            'variables' => ['n' => $number],
        ]);
        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? []);
        $response->assertJsonPath('data.updateOrderStatus.status', 'processing');
    }

    public function test_admin_can_list_customers_via_graphql(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $response = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ customers(limit: 5) { id email name } }',
        ]);
        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? []);
        $this->assertIsArray($response->json('data.customers'));
    }

    public function test_admin_can_set_product_enabled_via_graphql(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);
        $product = \App\Domain\Catalog\Models\Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);

        $disable = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { setProductEnabled(id: $id, enabled: false) { id is_enabled } }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $disable->assertOk();
        $this->assertArrayNotHasKey('errors', $disable->json() ?? []);
        $this->assertFalse((bool) $disable->json('data.setProductEnabled.is_enabled'));
        $this->assertFalse((bool) $product->fresh()->is_enabled);

        $enable = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { setProductEnabled(id: $id, enabled: true) { is_enabled } }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $enable->assertOk();
        $this->assertTrue((bool) $enable->json('data.setProductEnabled.is_enabled'));
    }

    public function test_admin_can_update_product_price_via_graphql(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);
        $product = \App\Domain\Catalog\Models\Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);

        $response = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!, $price: Float!) { updateProductPrice(id: $id, price: $price) { id price } }',
            'variables' => ['id' => (string) $product->id, 'price' => 42.5],
        ]);
        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? []);
        $this->assertEqualsWithDelta(42.5, (float) $response->json('data.updateProductPrice.price'), 0.001);
        $this->assertEqualsWithDelta(42.5, (float) $product->fresh()->price, 0.001);
    }

    public function test_admin_can_update_product_stock_via_graphql(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);
        $product = \App\Domain\Catalog\Models\Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);

        $response = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!, $qty: Float!) { updateProductStock(id: $id, qty: $qty) { id stock_qty } }',
            'variables' => ['id' => (string) $product->id, 'qty' => 17],
        ]);
        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? []);
        $this->assertEqualsWithDelta(17.0, (float) $response->json('data.updateProductStock.stock_qty'), 0.001);
        $this->assertEqualsWithDelta(17.0, (float) $product->fresh()->stock_qty, 0.001);
    }

    public function test_admin_can_create_simple_product_via_graphql(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);
        $category = \App\Domain\Catalog\Models\Category::query()->where('is_enabled', true)->first();
        $this->assertNotNull($category);

        $sku = 'GQL-SIMPLE-'.uniqid();
        $response = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => <<<'GQL'
mutation ($sku: String!, $name: String!, $price: Float!, $qty: Float!, $cat: ID!) {
  createSimpleProduct(sku: $sku, name: $name, price: $price, stockQty: $qty, categoryId: $cat, enabled: true) {
    id sku name price stock_qty is_enabled
  }
}
GQL,
            'variables' => [
                'sku' => $sku,
                'name' => 'GraphQL Simple Product',
                'price' => 19.99,
                'qty' => 11,
                'cat' => (string) $category->id,
            ],
        ]);
        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? []);
        $response->assertJsonPath('data.createSimpleProduct.sku', $sku);
        $response->assertJsonPath('data.createSimpleProduct.name', 'GraphQL Simple Product');
        $this->assertEqualsWithDelta(19.99, (float) $response->json('data.createSimpleProduct.price'), 0.001);
        $this->assertEqualsWithDelta(11.0, (float) $response->json('data.createSimpleProduct.stock_qty'), 0.001);
        $this->assertTrue((bool) $response->json('data.createSimpleProduct.is_enabled'));

        $created = \App\Domain\Catalog\Models\Product::query()->where('sku', $sku)->first();
        $this->assertNotNull($created);
        $this->assertTrue($created->categories()->where('categories.id', $category->id)->exists());
    }

    public function test_create_simple_product_rejects_duplicate_sku(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);
        $existing = \App\Domain\Catalog\Models\Product::query()->first();
        $this->assertNotNull($existing);

        $response = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation { createSimpleProduct(sku: "'.$existing->sku.'", name: "Dup", price: 1) { id } }',
        ]);
        $response->assertOk();
        $this->assertNotEmpty($response->json('errors'));
        $this->assertStringContainsString('SKU', (string) data_get($response->json(), 'errors.0.message'));
    }

    public function test_admin_can_query_attributes_roles_and_invoice(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $response = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ attributes(limit: 5) { id code type } adminRoles(limit: 5) { id slug name } salesReport(days: 30) { orders total_revenue avg_order_value } customerReport(days: 30) { new_customers total_customers } productReport(days: 90, limit: 5) { sku units_sold } adminUsers(limit: 5) { id email is_super } }',
        ]);
        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? []);
        $this->assertIsArray($response->json('data.attributes'));
        $this->assertIsArray($response->json('data.adminRoles'));
        $this->assertNotEmpty($response->json('data.adminRoles'));
        $this->assertIsInt((int) $response->json('data.salesReport.orders'));
        $this->assertIsInt((int) $response->json('data.customerReport.total_customers'));
        $this->assertIsArray($response->json('data.adminUsers'));

        $attr = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation { createAttribute(name: "GQL Color", type: "color", filterable: true) { id code type is_enabled } }',
        ]);
        $attr->assertOk();
        $this->assertArrayNotHasKey('errors', $attr->json() ?? []);
        $attr->assertJsonPath('data.createAttribute.type', 'color');
        $this->assertNotEmpty($attr->json('data.createAttribute.code'));
        $attrId = (string) $attr->json('data.createAttribute.id');
        $delAttr = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { deleteAttribute(id: $id) { ok id } }',
            'variables' => ['id' => $attrId],
        ]);
        $delAttr->assertOk();
        $this->assertArrayNotHasKey('errors', $delAttr->json() ?? []);
        $this->assertTrue((bool) $delAttr->json('data.deleteAttribute.ok'));
        $this->assertSame($attrId, (string) $delAttr->json('data.deleteAttribute.id'));

        $email = 'gql-staff-'.uniqid().'@example.com';
        $createAdmin = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) { createAdminUser(name: "GQL Staff", email: $e, password: "password123") { id email is_enabled } }',
            'variables' => ['e' => $email],
        ]);
        $createAdmin->assertOk();
        $this->assertArrayNotHasKey('errors', $createAdmin->json() ?? []);
        $createAdmin->assertJsonPath('data.createAdminUser.email', $email);

        $product = \App\Domain\Catalog\Models\Product::query()->first();
        $this->assertNotNull($product);
        $bulk = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { bulkSetProductEnabled(ids: [$id], enabled: true) { ok updated_count } }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $bulk->assertOk();
        $this->assertArrayNotHasKey('errors', $bulk->json() ?? []);
        $this->assertTrue((bool) $bulk->json('data.bulkSetProductEnabled.ok'));

        $order = \App\Domain\Order\Models\Order::query()->first();
        if ($order) {
            $invoice = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
                'query' => 'query ($n: String!) { invoice(number: $n) { available enabled order_number } }',
                'variables' => ['n' => $order->number],
            ]);
            $invoice->assertOk();
            $this->assertArrayNotHasKey('errors', $invoice->json() ?? []);
            $this->assertNotNull($invoice->json('data.invoice'));

            if ((bool) $invoice->json('data.invoice.enabled')) {
                $ensure = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
                    'query' => 'mutation ($n: String!) { ensureInvoice(number: $n) { available enabled order_number invoice_number } }',
                    'variables' => ['n' => $order->number],
                ]);
                $ensure->assertOk();
                $this->assertArrayNotHasKey('errors', $ensure->json() ?? []);
                $this->assertSame($order->number, $ensure->json('data.ensureInvoice.order_number'));
                $this->assertNotEmpty($ensure->json('data.ensureInvoice.invoice_number'));
            }
        }
    }

    public function test_admin_can_list_and_create_coupon_via_graphql(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $list = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ promotions(limit: 5) { id name code apply_as } }',
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? []);

        $code = 'GQL'.strtoupper(substr(uniqid(), -6));
        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($c: String!) { createCoupon(code: $c, name: "GraphQL coupon", action: "percent", value: 10) { id code action value is_enabled } }',
            'variables' => ['c' => $code],
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? []);
        $create->assertJsonPath('data.createCoupon.code', $code);
        $this->assertEqualsWithDelta(10.0, (float) $create->json('data.createCoupon.value'), 0.001);
        $this->assertTrue(
            \App\Domain\Promo\Models\DiscountRule::query()->where('code', $code)->where('apply_as', 'coupon')->exists()
        );
    }

    public function test_admin_bearer_token_can_query_admin_graphql(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);
        $token = $admin->createToken('admin-gql-test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/console/graphql', [
                'query' => '{ products(limit: 1) { id sku } }',
            ]);
        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? []);
        $this->assertIsArray($response->json('data.products'));
    }

    public function test_admin_can_comment_ship_and_refund_via_graphql(): void
    {
        $this->seed();

        $product = \App\Domain\Catalog\Models\Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $payment = \App\Domain\Payment\Models\PaymentMethod::query()->firstOrFail();
        $shipping = \App\Domain\Shipping\Models\ShippingMethod::query()->firstOrFail();

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $placed = $this->postJson('/api/v1/checkout', [
            'email' => 'gql-fulfill@example.com',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated();
        $number = $placed->json('order.number');
        $order = \App\Domain\Order\Models\Order::query()->where('number', $number)->firstOrFail();
        $order->updateTrusted([
            'payment_status' => 'paid',
            'amount_paid' => $order->grand_total,
            'amount_due' => 0,
            'refunded_total' => 0,
        ]);
        $this->assertSame('paid', $order->fresh()->payment_status);

        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $detail = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($n: String!) { order(number: $n) { number items { id sku qty } } }',
            'variables' => ['n' => $number],
        ]);
        $detail->assertOk();
        $this->assertArrayNotHasKey('errors', $detail->json() ?? []);
        $this->assertNotEmpty($detail->json('data.order.items'));

        $comment = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($n: String!) { addOrderComment(number: $n, body: "via gql", visibility: "private") { id body visibility } }',
            'variables' => ['n' => $number],
        ]);
        $comment->assertOk();
        $this->assertArrayNotHasKey('errors', $comment->json() ?? []);
        $comment->assertJsonPath('data.addOrderComment.body', 'via gql');

        $firstItemId = (string) data_get($detail->json(), 'data.order.items.0.id');
        $this->assertNotEmpty($firstItemId);
        $ship = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($n: String!, $item: ID!) {
                createShipment(
                    number: $n
                    carrier: "ups"
                    trackingNumber: "1ZTEST"
                    items: [{ orderItemId: $item, quantity: 1 }]
                ) { id status carrier tracking_number }
            }',
            'variables' => ['n' => $number, 'item' => $firstItemId],
        ]);
        $ship->assertOk();
        $this->assertArrayNotHasKey('errors', $ship->json() ?? []);
        $ship->assertJsonPath('data.createShipment.carrier', 'ups');
        $ship->assertJsonPath('data.createShipment.tracking_number', '1ZTEST');

        $refund = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($n: String!, $a: Float!) { createRefund(number: $n, amount: $a, comment: "partial") { number payment_status refunded_total } }',
            'variables' => ['n' => $number, 'a' => 1.0],
        ]);
        $refund->assertOk();
        $this->assertArrayNotHasKey('errors', $refund->json() ?? []);
        $this->assertGreaterThanOrEqual(1.0, (float) $refund->json('data.createRefund.refunded_total'));
    }

    public function test_admin_can_create_category_and_delete_product_via_graphql(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $catName = 'GQL Cat '.uniqid();
        $createCat = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($n: String!) { createCategory(name: $n, enabled: true) { id name slug is_enabled } }',
            'variables' => ['n' => $catName],
        ]);
        $createCat->assertOk();
        $this->assertArrayNotHasKey('errors', $createCat->json() ?? []);
        $createCat->assertJsonPath('data.createCategory.name', $catName);
        $this->assertTrue((bool) $createCat->json('data.createCategory.is_enabled'));
        $categoryId = (string) $createCat->json('data.createCategory.id');

        $sku = 'GQL-DEL-'.uniqid();
        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!, $cat: ID!) { createSimpleProduct(sku: $sku, name: "To Delete", price: 5, stockQty: 1, categoryId: $cat) { id } }',
            'variables' => ['sku' => $sku, 'cat' => $categoryId],
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? []);
        $productId = (string) $create->json('data.createSimpleProduct.id');

        $delete = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { deleteProduct(id: $id) { ok id } }',
            'variables' => ['id' => $productId],
        ]);
        $delete->assertOk();
        $this->assertArrayNotHasKey('errors', $delete->json() ?? []);
        $this->assertTrue((bool) $delete->json('data.deleteProduct.ok'));
        $this->assertNull(\App\Domain\Catalog\Models\Product::query()->find((int) $productId));

        $delCat = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { deleteCategory(id: $id) { ok id } }',
            'variables' => ['id' => $categoryId],
        ]);
        $delCat->assertOk();
        $this->assertArrayNotHasKey('errors', $delCat->json() ?? []);
        $this->assertTrue((bool) $delCat->json('data.deleteCategory.ok'));
        $this->assertNull(\App\Domain\Catalog\Models\Category::query()->find((int) $categoryId));
    }
}
