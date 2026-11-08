<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\Models\SellerOrderItem;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Models\Admin;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 01 — marketplace database foundation (relations, uniqueness, FKs).
 */
class MarketplaceFoundationTest extends MarketplaceTestCase
{

    public function test_seller_belongs_to_customer_and_enforces_unique_shop_slug(): void
    {
        $this->seed();

        $customer = Customer::createTrusted([
            'name' => 'Ada Seller',
            'email' => 'ada.foundation@example.com',
            'password' => bcrypt('password123'),
        ]);

        $seller = Seller::query()->create($this->sellerAttributes($customer->id, [
            'shop_slug' => 'foundation-shop',
            'status' => Seller::STATUS_PENDING,
        ]));

        $this->assertTrue($seller->customer->is($customer));
        $this->assertTrue($customer->fresh()->seller->is($seller));
        $this->assertSame(Seller::STATUS_PENDING, $seller->status);
        $this->assertContains($seller->status, Seller::STATUSES);

        $other = Customer::createTrusted([
            'name' => 'Other Seller',
            'email' => 'other.foundation@example.com',
            'password' => bcrypt('password123'),
        ]);

        $this->expectException(QueryException::class);
        Seller::query()->create($this->sellerAttributes($other->id, [
            'shop_slug' => 'foundation-shop',
            'email' => 'other.shop@example.com',
        ]));
    }

    public function test_seller_product_links_seller_and_product_uniquely(): void
    {
        $this->seed();
        [$seller, $product] = $this->makeSellerProductPair();

        $row = SellerProduct::query()->create([
            'seller_id' => $seller->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => 'Foundation mug',
            'price' => 12.5,
            'stock_qty' => 3,
            'is_enabled' => false,
            'approval_status' => 'pending',
        ]);

        $this->assertTrue($row->seller->is($seller));
        $this->assertTrue($row->product->is($product));
        $this->assertTrue($product->fresh()->seller->is($seller));
        $this->assertTrue($seller->catalogProducts->contains('id', $product->id));

        $this->expectException(QueryException::class);
        SellerProduct::query()->create([
            'seller_id' => $seller->id,
            'product_id' => $product->id,
            'sku' => 'DUP-SKU',
            'name' => 'Duplicate',
            'price' => 1,
            'stock_qty' => 1,
            'approval_status' => 'pending',
        ]);
    }

    public function test_seller_order_and_item_link_parent_order_and_order_item(): void
    {
        $this->seed();
        [$seller, $product] = $this->makeSellerProductPair();

        $order = Order::createTrusted([
            'number' => 'WENTO-FOUNDATION-1',
            'email' => 'buyer@example.com',
            'status' => 'pending',
            'currency_code' => 'USD',
            'subtotal' => 12.5,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 12.5,
            'payment_status' => 'pending',
            'billing_address' => ['line1' => '1 Main'],
            'shipping_address' => ['line1' => '1 Main'],
        ]);

        $orderItem = OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => 'Foundation mug',
            'qty' => 1,
            'unit_price' => 12.5,
            'tax_amount' => 0,
            'total' => 12.5,
        ]);

        $sellerOrder = SellerOrder::createTrusted([
            'seller_id' => $seller->id,
            'order_id' => $order->id,
            'number' => $order->number,
            'customer_email' => $order->email,
            'status' => 'pending',
            'payment_status' => 'pending',
            'currency_code' => 'USD',
            'subtotal' => 12.5,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'commission_total' => 1.25,
            'earning_total' => 11.25,
            'grand_total' => 12.5,
        ]);

        $sellerOrderItem = SellerOrderItem::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'order_item_id' => $orderItem->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => 'Foundation mug',
            'qty' => 1,
            'unit_price' => 12.5,
            'tax_amount' => 0,
            'total' => 12.5,
        ]);

        $this->assertTrue($sellerOrder->seller->is($seller));
        $this->assertTrue($sellerOrder->order->is($order));
        $this->assertTrue($order->fresh()->sellerOrders->contains('id', $sellerOrder->id));
        $this->assertTrue($sellerOrderItem->sellerOrder->is($sellerOrder));
        $this->assertTrue($sellerOrderItem->orderItem->is($orderItem));
        $this->assertTrue($sellerOrderItem->product->is($product));
        $this->assertTrue($orderItem->fresh()->sellerOrderItems->contains('id', $sellerOrderItem->id));

        $this->expectException(QueryException::class);
        SellerOrderItem::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'order_item_id' => $orderItem->id,
            'product_id' => $product->id,
            'sku' => 'DUP',
            'name' => 'Dup line',
            'qty' => 1,
            'unit_price' => 1,
            'tax_amount' => 0,
            'total' => 1,
        ]);
    }

    public function test_marketplace_foundation_indexes_and_foreign_keys_exist(): void
    {
        $this->seed();

        $sellerIndexes = collect(Schema::getIndexes('sellers'))->pluck('name');
        $this->assertTrue($sellerIndexes->contains('sellers_customer_id_unique'));
        $this->assertTrue($sellerIndexes->contains('sellers_shop_slug_unique'));
        $this->assertTrue($sellerIndexes->contains('sellers_status_index'));

        $productIndexes = collect(Schema::getIndexes('seller_products'))->pluck('name');
        $this->assertTrue($productIndexes->contains('seller_products_seller_id_product_id_unique'));
        $this->assertTrue($productIndexes->contains('seller_products_approval_status_index'));

        $orderIndexes = collect(Schema::getIndexes('seller_orders'))->pluck('name');
        $this->assertTrue($orderIndexes->contains('seller_orders_seller_id_order_id_unique'));
        $this->assertTrue($orderIndexes->contains('seller_orders_status_index'));

        $itemIndexes = collect(Schema::getIndexes('seller_order_items'))->pluck('name');
        $this->assertTrue($itemIndexes->contains('seller_order_items_seller_order_id_order_item_id_unique'));

        $itemForeigns = collect(Schema::getForeignKeys('seller_order_items'));
        $this->assertTrue($itemForeigns->contains(fn ($fk) => in_array('order_item_id', $fk['columns'] ?? [], true)));
        $this->assertTrue($itemForeigns->contains(fn ($fk) => in_array('product_id', $fk['columns'] ?? [], true)));

        $sellerForeigns = collect(Schema::getForeignKeys('sellers'));
        $this->assertTrue($sellerForeigns->contains(fn ($fk) => in_array('customer_id', $fk['columns'] ?? [], true)));
        $this->assertTrue($sellerForeigns->contains(fn ($fk) => in_array('reviewed_by', $fk['columns'] ?? [], true)));

        $admin = Admin::query()->first();
        if ($admin) {
            $customer = Customer::createTrusted([
                'name' => 'Reviewed Seller',
                'email' => 'reviewed.foundation@example.com',
                'password' => bcrypt('password123'),
            ]);
            $seller = Seller::query()->create($this->sellerAttributes($customer->id, [
                'shop_slug' => 'reviewed-shop',
                'email' => 'reviewed.shop@example.com',
                'status' => Seller::STATUS_APPROVED,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]));
            $this->assertTrue($seller->reviewedBy->is($admin));
        }
    }

    /** @return array{0: Seller, 1: Product} */
    protected function makeSellerProductPair(): array
    {
        $customer = Customer::createTrusted([
            'name' => 'Catalog Seller',
            'email' => 'catalog.foundation@example.com',
            'password' => bcrypt('password123'),
        ]);
        $seller = Seller::query()->create($this->sellerAttributes($customer->id, [
            'shop_slug' => 'catalog-foundation',
            'email' => 'catalog.shop@example.com',
            'status' => Seller::STATUS_APPROVED,
            'is_active' => true,
        ]));

        $product = Product::query()->create([
            'type' => 'simple',
            'sku' => 'FOUND-MUG-1',
            'seller_id' => $seller->id,
            'price' => 12.5,
            'stock_qty' => 3,
            'manage_stock' => true,
            'is_enabled' => true,
            'available_for_order' => true,
            'visibility' => 'both',
        ]);

        return [$seller, $product];
    }

    /** @param  array<string, mixed>  $overrides */
    protected function sellerAttributes(int $customerId, array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $customerId,
            'status' => Seller::STATUS_PENDING,
            'first_name' => 'Ada',
            'last_name' => 'Seller',
            'shop_name' => 'Foundation Shop',
            'shop_slug' => 'foundation-shop',
            'profile_name' => 'Ada Shop',
            'email' => 'ada.shop@example.com',
            'phone' => '+1 555 0100',
            'contact_name' => 'Ada Seller',
            'contact_phone' => '+1 555 0100',
            'address_line1' => '12 Market Street',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'commission_rate' => 10,
            'is_active' => true,
        ], $overrides);
    }
}
