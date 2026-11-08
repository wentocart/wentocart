<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminGraphqlWave36Test extends TestCase
{
    use RefreshDatabase;

    public function test_update_product_customer_address_role_invoice_and_images(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $sku = 'W36-'.strtoupper(substr(uniqid(), -6));
        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createSimpleProduct(sku: $sku, name: "Wave36 Product", price: 12.5, stockQty: 4, enabled: true) {
                    id sku name price stock_qty
                }
            }',
            'variables' => ['sku' => $sku],
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? []);
        $productId = (string) $create->json('data.createSimpleProduct.id');

        $update = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateProduct(id: $id, name: "Wave36 Renamed", price: 18.25, stockQty: 9, enabled: true, shortDescription: "Short") {
                    id name price stock_qty is_enabled
                }
            }',
            'variables' => ['id' => $productId],
        ]);
        $update->assertOk();
        $this->assertArrayNotHasKey('errors', $update->json() ?? []);
        $update->assertJsonPath('data.updateProduct.name', 'Wave36 Renamed');
        $this->assertEqualsWithDelta(18.25, (float) $update->json('data.updateProduct.price'), 0.001);
        $this->assertEqualsWithDelta(9.0, (float) $update->json('data.updateProduct.stock_qty'), 0.001);

        Storage::disk('public')->put('catalog/products/'.$productId.'/wave36.png', 'fake-image');
        $image = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($pid: ID!, $path: String!) {
                addProductImage(productId: $pid, path: $path, alt: "Wave36", cover: true) {
                    id product_id path alt position
                }
            }',
            'variables' => [
                'pid' => $productId,
                'path' => 'catalog/products/'.$productId.'/wave36.png',
            ],
        ]);
        $image->assertOk();
        $this->assertArrayNotHasKey('errors', $image->json() ?? []);
        $imageId = (string) $image->json('data.addProductImage.id');
        $this->assertNotNull(ProductImage::query()->find((int) $imageId));
        $this->assertSame(
            'catalog/products/'.$productId.'/wave36.png',
            Product::query()->find((int) $productId)?->cover_path
        );

        $delImage = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($pid: ID!, $iid: ID!) {
                deleteProductImage(productId: $pid, imageId: $iid) { ok id }
            }',
            'variables' => ['pid' => $productId, 'iid' => $imageId],
        ]);
        $delImage->assertOk();
        $this->assertArrayNotHasKey('errors', $delImage->json() ?? []);
        $this->assertTrue((bool) $delImage->json('data.deleteProductImage.ok'));
        $this->assertNull(ProductImage::query()->find((int) $imageId));

        $email = 'w36-'.uniqid().'@example.com';
        $customer = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createCustomer(email: $e, firstName: "Wave", lastName: "Six", phone: "+15550001") { id email name }
            }',
            'variables' => ['e' => $email],
        ]);
        $customer->assertOk();
        $this->assertArrayNotHasKey('errors', $customer->json() ?? []);
        $customerId = (string) $customer->json('data.createCustomer.id');

        $updCustomer = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateCustomer(id: $id, firstName: "Wave36", lastName: "Buyer", phone: "+15550002") {
                    id name phone
                }
            }',
            'variables' => ['id' => $customerId],
        ]);
        $updCustomer->assertOk();
        $this->assertArrayNotHasKey('errors', $updCustomer->json() ?? []);
        $this->assertStringContainsString('Wave36', (string) $updCustomer->json('data.updateCustomer.name'));
        $updCustomer->assertJsonPath('data.updateCustomer.phone', '+15550002');

        $addr = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!) {
                createCustomerAddress(
                    customerId: $cid
                    firstName: "Wave36"
                    lastName: "Buyer"
                    line1: "12 Market St"
                    city: "Austin"
                    state: "TX"
                    postalCode: "78701"
                    countryCode: "US"
                    defaultShipping: true
                    defaultBilling: true
                ) { id customer_id city country_code is_default_shipping is_default_billing }
            }',
            'variables' => ['cid' => $customerId],
        ]);
        $addr->assertOk();
        $this->assertArrayNotHasKey('errors', $addr->json() ?? []);
        $addr->assertJsonPath('data.createCustomerAddress.city', 'Austin');
        $addrId = (string) $addr->json('data.createCustomerAddress.id');

        $delAddr = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!, $id: ID!) {
                deleteCustomerAddress(customerId: $cid, id: $id) { ok id }
            }',
            'variables' => ['cid' => $customerId, 'id' => $addrId],
        ]);
        $delAddr->assertOk();
        $this->assertArrayNotHasKey('errors', $delAddr->json() ?? []);
        $this->assertTrue((bool) $delAddr->json('data.deleteCustomerAddress.ok'));
        $this->assertNull(Customer::query()->find((int) $customerId)?->addresses()->whereKey((int) $addrId)->first());

        $role = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createAdminRole(name: "Wave36 Catalog Ops", permissions: ["catalog", "catalog.categories"]) {
                    id slug name permissions
                }
            }',
        ]);
        $role->assertOk();
        $this->assertArrayNotHasKey('errors', $role->json() ?? []);
        $role->assertJsonPath('data.createAdminRole.name', 'Wave36 Catalog Ops');
        $permissions = $role->json('data.createAdminRole.permissions') ?? [];
        $this->assertContains('catalog', $permissions);

        $order = Order::query()->first();
        if ($order) {
            $invoice = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
                'query' => 'mutation ($n: String!) {
                    createInvoice(number: $n, withHtml: true) {
                        available enabled order_number invoice_number grand_total
                    }
                }',
                'variables' => ['n' => $order->number],
            ]);
            $invoice->assertOk();
            $this->assertArrayNotHasKey('errors', $invoice->json() ?? []);
            if ((bool) $invoice->json('data.createInvoice.enabled')) {
                $this->assertNotEmpty($invoice->json('data.createInvoice.invoice_number'));
            }
        }
    }
}
