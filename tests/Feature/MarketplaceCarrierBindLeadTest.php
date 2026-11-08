<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceFulfilmentCarriers;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;

/**
 * Carrier codes on seller ship must match marketplace fulfilment carriers (self_ship always ok).
 */
class MarketplaceCarrierBindLeadTest extends MarketplaceTestCase
{
    /** @return array{0: Seller, 1: Customer, 2: Product} */
    protected function approvedSellerWithProduct(string $email, string $slug): array
    {
        $this->postJson('/api/v1/auth/logout');
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Carrier Seller',
            'email' => $email,
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', $email)->firstOrFail();

        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'first_name' => 'Ada',
            'last_name' => 'Seller',
            'shop_name' => $slug.' Shop',
            'shop_slug' => $slug,
            'profile_name' => $slug.' Profile',
            'email' => str_replace('@', '.shop@', $email),
            'phone' => '+1 555 0100',
            'contact_name' => 'Ada Seller',
            'contact_phone' => '+1 555 0100',
            'address_line1' => '12 Market Street',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Carrier bind test',
        ])->assertCreated();

        $seller = Seller::query()->where('customer_id', $owner->id)->firstOrFail();
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', [
                'action' => 'approve',
                'commission_rate' => 10,
            ])
            ->assertRedirect();
        \Illuminate\Support\Facades\Auth::guard('admin')->logout();

        $this->actingAs($owner, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => $slug.' Item',
            'sku' => 'sku-'.substr(md5($slug), 0, 8),
            'price' => 40,
            'stock_qty' => 10,
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
            'stock_qty' => 10,
        ]);

        return [$seller->fresh(), $owner->fresh(), $product->fresh()];
    }

    public function test_seller_ship_rejects_unknown_carrier_and_accepts_configured(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);

        $admin = Admin::query()->firstOrFail();
        MarketplaceFulfilmentCarriers::save([
            'self_ship_title' => 'Own Courier',
            'carriers' => [
                ['code' => 'dhl', 'title' => 'DHL Express', 'is_enabled' => true],
                ['code' => 'fedex', 'title' => 'FedEx', 'is_enabled' => false],
            ],
        ]);

        [$seller, $owner, $product] = $this->approvedSellerWithProduct(
            'carrier-bind-seller@example.test',
            'carrier-bind-shop',
        );

        $this->postJson('/api/v1/auth/logout');
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Buyer',
            'email' => 'carrier-bind-buyer@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $payment = PaymentMethod::query()->whereNull('seller_id')->where('is_enabled', true)->firstOrFail();
        $shipping = ShippingMethod::query()->whereNull('seller_id')->where('is_enabled', true)->firstOrFail();
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', [
            'email' => 'carrier-bind-buyer@example.test',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated();

        $sellerOrder = SellerOrder::query()->where('seller_id', $seller->id)->firstOrFail();
        $this->assertInstanceOf(Order::class, $sellerOrder->order ?? Order::query()->find($sellerOrder->order_id));

        $this->postJson('/api/v1/auth/logout');
        $this->actingAs($owner, 'customer');

        $this->postJson('/api/v1/account/seller/orders/'.$sellerOrder->id.'/shipments', [
            'carrier' => 'ups_unknown',
            'tracking_number' => 'BAD-1',
        ])->assertStatus(422)->assertJsonValidationErrors(['carrier']);

        $this->postJson('/api/v1/account/seller/orders/'.$sellerOrder->id.'/shipments', [
            'carrier' => 'fedex',
            'tracking_number' => 'BAD-2',
        ])->assertStatus(422)->assertJsonValidationErrors(['carrier']);

        $created = $this->postJson('/api/v1/account/seller/orders/'.$sellerOrder->id.'/shipments', [
            'carrier' => 'self_ship',
            'tracking_number' => 'SELF-1',
        ]);
        $created->assertCreated();
        $this->assertSame('self_ship', (string) $created->json('shipment.carrier'));
        $shipmentId = (int) $created->json('shipment.id');

        $this->patchJson('/api/v1/account/seller/shipments/'.$shipmentId, [
            'carrier' => 'ups_unknown',
        ])->assertStatus(422)->assertJsonValidationErrors(['carrier']);

        $bound = $this->patchJson('/api/v1/account/seller/shipments/'.$shipmentId, [
            'carrier' => 'dhl',
            'tracking_number' => 'DHL-1',
        ]);
        $bound->assertOk();
        $this->assertSame('dhl', (string) $bound->json('shipment.carrier'));

        $this->postJson('/api/v1/auth/logout');
        $landing = $this->getJson('/api/v1/marketplace/landing');
        $landing->assertOk();

        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')
            ->getJson('/api/v1/console/marketplace/fulfilment-carriers')
            ->assertOk();
    }
}
