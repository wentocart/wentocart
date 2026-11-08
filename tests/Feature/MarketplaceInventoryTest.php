<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\InventoryMovement;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Marketplace\Services\SellerInventoryService;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;
use Illuminate\Validation\ValidationException;

/**
 * Phase 13 — seller inventory ownership, atomic stock, auditable movements.
 */
class MarketplaceInventoryTest extends MarketplaceTestCase
{

    /** @return array<string, mixed> */
    protected function applyPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ada',
            'last_name' => 'Seller',
            'shop_name' => 'Ada Market',
            'shop_slug' => 'ada-market',
            'profile_name' => 'Ada Shop',
            'email' => 'ada.seller@example.com',
            'phone' => '+1 555 0100',
            'contact_name' => 'Ada Seller',
            'contact_phone' => '+1 555 0100',
            'address_line1' => '12 Market Street',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Handmade goods',
        ], $overrides);
    }

    protected function registerCustomer(string $email = 'buyer@example.com'): Customer
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Pat Buyer',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertOk();

        return Customer::query()->where('email', $email)->firstOrFail();
    }

    /** @return array{0: Seller, 1: Customer, 2: Product} */
    protected function sellerWithProduct(string $email, string $slug, string $name, float $price = 20, int $stock = 10): array
    {
        $this->postJson('/api/v1/auth/logout');
        $owner = $this->registerCustomer($email);
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload([
            'email' => str_replace('@', '.shop@', $email),
            'shop_name' => $slug.' Shop',
            'shop_slug' => $slug,
        ]))->assertCreated();
        $seller = Seller::query()->where('customer_id', $owner->id)->firstOrFail();
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', ['action' => 'approve'])
            ->assertRedirect();
        \Illuminate\Support\Facades\Auth::guard('admin')->logout();

        $this->actingAs($owner, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => $name,
            'sku' => 'sku-'.substr(md5($slug.$name), 0, 6),
            'price' => $price,
            'stock_qty' => $stock,
            'is_enabled' => true,
        ])->assertCreated();
        $product = Product::query()->where('seller_id', $seller->id)->latest('id')->firstOrFail();
        SellerProduct::query()->where('product_id', $product->id)->update([
            'approval_status' => SellerProduct::STATUS_APPROVED,
            'is_enabled' => true,
        ]);
        $product->update([
            'is_enabled' => true,
            'available_for_order' => true,
            'manage_stock' => true,
            'stock_qty' => $stock,
        ]);

        return [$seller->fresh(), $owner, $product->fresh()];
    }

    /** @return array<string, mixed> */
    protected function checkoutPayload(string $email = 'buyer@example.com'): array
    {
        $payment = PaymentMethod::query()->whereNull('seller_id')->where('is_enabled', true)->firstOrFail();
        $shipping = ShippingMethod::query()->whereNull('seller_id')->where('is_enabled', true)->firstOrFail();

        return [
            'email' => $email,
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ];
    }

    protected function loginAs(Customer $customer): void
    {
        $this->postJson('/api/v1/auth/logout');
        $this->actingAs($customer, 'customer');
    }

    public function test_checkout_sale_stamps_seller_id_on_movement(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, , $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 20, 5);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 2])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();

        $movement = InventoryMovement::query()
            ->where('product_id', $product->id)
            ->where('action', InventoryMovement::ACTION_SALE)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame((int) $seller->id, (int) $movement->seller_id);
        $this->assertSame(-2, (int) $movement->qty);
        $this->assertSame(3, (int) $product->fresh()->stock_qty);
    }

    public function test_cancel_restocks_idempotently_with_seller_stamp(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, $owner, $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 20, 5);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 2])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        $so = SellerOrder::query()->where('seller_id', $seller->id)->firstOrFail();

        $this->loginAs($owner);
        $this->patchJson('/api/v1/account/seller/orders/'.$so->id, ['status' => 'cancelled'])->assertOk();
        $this->assertSame(5, (int) $product->fresh()->stock_qty);

        $this->patchJson('/api/v1/account/seller/orders/'.$so->id, ['status' => 'cancelled'])->assertOk();
        $this->assertSame(5, (int) $product->fresh()->stock_qty);

        $restocks = InventoryMovement::query()
            ->where('product_id', $product->id)
            ->where('action', InventoryMovement::ACTION_RESTOCK)
            ->where('seller_id', $seller->id)
            ->count();
        $this->assertSame(1, $restocks);
    }

    public function test_refund_restores_stock_as_return_movement(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, , $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 20, 8);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 3])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        $this->assertSame(5, (int) $product->fresh()->stock_qty);

        Order::query()->latest('id')->firstOrFail()->update([
            'status' => 'refunded',
            'payment_status' => 'refunded',
        ]);

        $this->assertSame(8, (int) $product->fresh()->stock_qty);
        $this->assertSame(1, InventoryMovement::query()
            ->where('product_id', $product->id)
            ->where('action', InventoryMovement::ACTION_RETURN)
            ->where('seller_id', $seller->id)
            ->count());
    }

    public function test_seller_cannot_access_other_sellers_inventory(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [, , $productA] = $this->sellerWithProduct('a@example.com', 'shop-a', 'A');
        [, $ownerB] = $this->sellerWithProduct('b@example.com', 'shop-b', 'B');

        $this->loginAs($ownerB);
        $this->getJson('/api/v1/account/seller/products/'.$productA->id.'/inventory')->assertForbidden();
        $this->postJson('/api/v1/account/seller/products/'.$productA->id.'/inventory/adjust', [
            'delta' => -1,
        ])->assertForbidden();
    }

    public function test_seller_can_view_and_adjust_own_inventory(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, $owner, $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 20, 4);

        $this->loginAs($owner);
        $this->getJson('/api/v1/account/seller/products/'.$product->id.'/inventory')
            ->assertOk()
            ->assertJsonPath('summary.stock_qty', 4)
            ->assertJsonPath('summary.available', 4);

        $this->postJson('/api/v1/account/seller/products/'.$product->id.'/inventory/adjust', [
            'delta' => 3,
            'note' => 'Inbound',
        ])->assertOk()->assertJsonPath('summary.stock_qty', 7);

        $this->assertSame((int) $seller->id, (int) InventoryMovement::query()
            ->where('product_id', $product->id)
            ->where('action', InventoryMovement::ACTION_ADJUSTMENT)
            ->value('seller_id'));
    }

    public function test_atomic_deduct_rejects_oversell_without_backorder(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [, , $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 20, 1);
        $product->update(['allow_backorder' => false, 'manage_stock' => true]);

        $inventory = app(InventoryService::class);
        $inventory->deduct($product->fresh(), 1, InventoryMovement::ACTION_SALE, 'race-a');

        $this->expectException(ValidationException::class);
        $inventory->deduct($product->fresh(), 1, InventoryMovement::ACTION_SALE, 'race-b');
    }

    public function test_reservation_and_release_update_reserved_qty(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [, , $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 20, 10);
        $inventory = app(InventoryService::class);

        $inventory->reserve($product->fresh(), 3, 'hold:1');
        $this->assertSame(3, (int) $product->fresh()->reserved_qty);
        $this->assertEquals(7, $product->fresh()->availableStock());

        $inventory->release($product->fresh(), 3, 'hold:1:release');
        $this->assertSame(0, (int) $product->fresh()->reserved_qty);
        $this->assertSame(1, InventoryMovement::query()->where('action', InventoryMovement::ACTION_RESERVATION)->count());
        $this->assertSame(1, InventoryMovement::query()->where('action', InventoryMovement::ACTION_RELEASE)->count());
    }

    public function test_platform_product_movements_have_null_seller_id(): void
    {
        $this->seed();
        $product = Product::query()->whereNull('seller_id')->where('manage_stock', true)->where('stock_qty', '>', 0)->first();
        if (! $product) {
            $this->markTestSkipped('No platform stocked product in seed.');
        }

        app(InventoryService::class)->deduct($product, 1, InventoryMovement::ACTION_SALE, 'platform-sale-'.$product->id);
        $movement = InventoryMovement::query()
            ->where('product_id', $product->id)
            ->where('action', InventoryMovement::ACTION_SALE)
            ->latest('id')
            ->firstOrFail();

        $this->assertNull($movement->seller_id);
    }

    public function test_seller_inventory_service_blocks_cross_seller_adjust(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [, , $productA] = $this->sellerWithProduct('a@example.com', 'shop-a', 'A');
        [$sellerB] = $this->sellerWithProduct('b@example.com', 'shop-b', 'B');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(SellerInventoryService::class)->adjust($sellerB, $productA, 1);
    }

    public function test_seller_inventory_list_supports_pagination_and_bulk_adjust(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [, $owner, $product] = $this->sellerWithProduct('inv-list@example.com', 'inv-list', 'Listed Mug', 20, 8);
        $this->actingAs($owner, 'customer');

        $this->getJson('/api/v1/account/seller/inventory?page=1&sort=id&dir=desc')
            ->assertOk()
            ->assertJsonPath('inventory.total', 1)
            ->assertJsonPath('inventory.data.0.product_id', $product->id)
            ->assertJsonPath('inventory.data.0.available', 8)
            ->assertJsonFragment(['product_id' => $product->id]);

        $this->postJson('/api/v1/account/seller/inventory/bulk-adjust', [
            'ids' => [$product->id],
            'delta' => 2,
        ])->assertOk()
            ->assertJsonPath('updated', 1);

        $this->assertSame(10, (int) $product->fresh()->stock_qty);
    }
}
