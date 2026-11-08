<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Order\Models\Order;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Shipping\Models\Shipment;
use App\Models\Admin;

/**
 * Phase 17 — seller portal surface: auth gate, isolation, inventory/shipments/transactions APIs.
 */
class MarketplacePortalTest extends MarketplaceTestCase
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

    protected function registerCustomer(string $email): Customer
    {
        $this->postJson('/api/v1/auth/logout');
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Portal User',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertOk();

        return Customer::query()->where('email', $email)->firstOrFail();
    }

    /** @return array{0: Seller, 1: Customer, 2: Product} */
    protected function approvedSellerWithProduct(string $email, string $slug): array
    {
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
            'name' => $slug.' Mug',
            'sku' => 'sku-'.substr(md5($slug), 0, 6),
            'price' => 18,
            'stock_qty' => 12,
            'is_enabled' => true,
        ])->assertCreated();

        $product = Product::query()->where('seller_id', $seller->id)->latest('id')->firstOrFail();
        SellerProduct::query()->where('product_id', $product->id)->update([
            'approval_status' => SellerProduct::STATUS_APPROVED,
            'is_enabled' => true,
        ]);
        $product->update(['is_enabled' => true, 'manage_stock' => true, 'stock_qty' => 12]);

        return [$seller->fresh(), $owner, $product->fresh()];
    }

    public function test_approved_seller_can_access_portal_dashboard_and_surfaces(): void
    {
        $this->seed();
        [, $owner] = $this->approvedSellerWithProduct('portal-a@example.com', 'portal-a');

        $this->actingAs($owner, 'customer');

        $this->getJson('/api/v1/account/seller/dashboard')
            ->assertOk()
            ->assertJsonPath('stats.products', 1)
            ->assertJsonStructure(['stats' => ['pending_orders', 'open_returns', 'reviews', 'available']]);

        $this->getJson('/api/v1/account/seller/inventory')
            ->assertOk()
            ->assertJsonCount(1, 'items');

        $this->getJson('/api/v1/account/seller/shipments')
            ->assertOk()
            ->assertJsonPath('shipments.total', 0);

        $this->getJson('/api/v1/account/seller/transactions')
            ->assertOk()
            ->assertJsonStructure(['balance', 'entries']);

        $this->getJson('/api/v1/account/seller/earnings')->assertOk();
        $this->getJson('/api/v1/account/seller/reviews')->assertOk();
        $this->getJson('/api/v1/account/seller/orders')->assertOk();
    }

    public function test_unapproved_seller_and_plain_customer_cannot_access_portal(): void
    {
        $this->seed();
        $pending = $this->registerCustomer('pending-seller@example.com');
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload([
            'email' => 'pending.shop@example.com',
            'shop_slug' => 'pending-shop',
            'shop_name' => 'Pending Shop',
        ]))->assertCreated();

        $this->actingAs($pending, 'customer');
        $this->getJson('/api/v1/account/seller/dashboard')->assertForbidden();
        $this->getJson('/api/v1/account/seller/inventory')->assertForbidden();
        $this->getJson('/api/v1/account/seller/shipments')->assertForbidden();

        $buyer = $this->registerCustomer('plain-buyer@example.com');
        $this->actingAs($buyer, 'customer');
        $this->getJson('/api/v1/account/seller/dashboard')->assertForbidden();
        $this->getJson('/api/v1/account/seller/products')->assertForbidden();
    }

    public function test_seller_isolation_on_inventory_orders_and_shipments(): void
    {
        $this->seed();
        [$sellerA, $ownerA, $productA] = $this->approvedSellerWithProduct('iso-a@example.com', 'iso-a');
        [$sellerB, $ownerB] = $this->approvedSellerWithProduct('iso-b@example.com', 'iso-b');

        $order = Order::createTrusted([
            'number' => 'PORTAL-ISO-1',
            'customer_id' => $ownerA->id,
            'email' => $ownerA->email,
            'status' => 'processing',
            'currency_code' => 'USD',
            'subtotal' => 18,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 18,
            'payment_status' => 'pending',
            'billing_address' => ['line1' => '1 Main'],
            'shipping_address' => ['line1' => '1 Main'],
        ]);
        $sellerOrder = SellerOrder::createTrusted([
            'order_id' => $order->id,
            'seller_id' => $sellerA->id,
            'number' => 'PORTAL-ISO-1-A',
            'customer_email' => $ownerA->email,
            'status' => 'processing',
            'payment_status' => 'pending',
            'currency_code' => 'USD',
            'subtotal' => 18,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 18,
            'commission_total' => 0,
            'earning_total' => 18,
        ]);
        $shipment = Shipment::query()->create([
            'order_id' => $order->id,
            'seller_order_id' => $sellerOrder->id,
            'seller_id' => $sellerA->id,
            'status' => 'processing',
            'shipping_cost' => 0,
        ]);

        $this->postJson('/api/v1/auth/logout');
        $this->actingAs($ownerB, 'customer');

        $this->getJson('/api/v1/account/seller/inventory')
            ->assertOk()
            ->assertJsonMissing(['product_id' => $productA->id]);

        $this->getJson('/api/v1/account/seller/products/'.$productA->id.'/inventory')->assertForbidden();
        $this->postJson('/api/v1/account/seller/products/'.$productA->id.'/inventory/adjust', ['delta' => 1])->assertForbidden();
        $this->getJson('/api/v1/account/seller/orders/'.$sellerOrder->id)->assertNotFound();
        $this->getJson('/api/v1/account/seller/shipments')
            ->assertOk()
            ->assertJsonPath('shipments.total', 0);
        $this->postJson('/api/v1/account/seller/shipments/'.$shipment->id.'/ship')->assertNotFound();

        $this->postJson('/api/v1/auth/logout');
        $this->actingAs($ownerA, 'customer');
        $this->getJson('/api/v1/account/seller/inventory')
            ->assertOk()
            ->assertJsonFragment(['product_id' => $productA->id]);
        $this->getJson('/api/v1/account/seller/orders/'.$sellerOrder->id)->assertOk();
        $this->getJson('/api/v1/account/seller/shipments')
            ->assertOk()
            ->assertJsonPath('shipments.total', 1);
    }

    public function test_seller_cannot_access_admin_and_cannot_mass_assign_ownership(): void
    {
        $this->seed();
        [, $owner, $product] = $this->approvedSellerWithProduct('admin-sep@example.com', 'admin-sep');
        $this->actingAs($owner, 'customer');

        $this->get('/admin/multivendor/reviews')->assertRedirect();
        $this->getJson('/api/v1/account/seller/dashboard')->assertOk();

        $this->patchJson('/api/v1/account/seller/products/'.$product->id, [
            'name' => 'Hijack',
            'price' => 9,
            'seller_id' => 999999,
            'status' => 'approved',
        ])->assertOk();

        $this->assertSame((int) Seller::query()->where('customer_id', $owner->id)->value('id'), (int) $product->fresh()->seller_id);
    }

    public function test_seller_portal_identity_exposes_shop_name_and_logo_url(): void
    {
        $this->seed();
        [$seller, $owner] = $this->approvedSellerWithProduct('brand-a@example.com', 'brand-a');
        $seller->update(['logo_path' => 'sellers/brand-a/logo.png', 'shop_name' => "John's Electronics"]);
        $this->actingAs($owner, 'customer');

        $this->getJson('/api/v1/account/seller')
            ->assertOk()
            ->assertJsonPath('seller.shop_name', "John's Electronics")
            ->assertJsonPath('seller.is_approved', true)
            ->assertJsonPath('seller.can_access_portal', true)
            ->assertJsonPath('seller.logo_url', asset('storage/sellers/brand-a/logo.png'));

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('customer.seller.is_approved', true)
            ->assertJsonPath('customer.seller.shop_name', "John's Electronics");
    }

    public function test_blocked_seller_cannot_access_portal_apis(): void
    {
        $this->seed();
        [$seller, $owner] = $this->approvedSellerWithProduct('blocked-a@example.com', 'blocked-a');
        $seller->update(['status' => Seller::STATUS_BLOCKED]);
        $this->actingAs($owner, 'customer');

        $this->getJson('/api/v1/account/seller/dashboard')->assertForbidden();
        $this->getJson('/api/v1/account/seller/products')->assertForbidden();
        $this->getJson('/api/v1/account/seller/earnings')->assertForbidden();
    }

    public function test_account_sidebar_seller_dashboard_opens_in_new_tab(): void
    {
        $source = file_get_contents(base_path('storefront/src/pages/Account.jsx'));
        $this->assertNotFalse($source);
        $this->assertStringContainsString('data-seller-portal-link="1"', $source);
        $this->assertStringContainsString('target="_blank"', $source);
        $this->assertStringContainsString('rel="noopener noreferrer"', $source);
        $this->assertStringContainsString("to: '/account/seller'", $source);
        $this->assertStringContainsString('data-seller-apply-link="1"', $source);
        $this->assertStringContainsString("/account/become-seller", $source);
        $this->assertStringContainsString("kind: 'apply'", $source);
        $this->assertStringContainsString("kind: 'portal'", $source);
    }

    public function test_become_seller_stays_in_account_not_portal_shell(): void
    {
        $account = file_get_contents(base_path('storefront/src/pages/Account.jsx'));
        $layout = file_get_contents(base_path('storefront/src/pages/seller/SellerLayout.jsx'));
        $home = file_get_contents(base_path('storefront/src/pages/seller/SellerHome.jsx'));
        $main = file_get_contents(base_path('storefront/src/main.jsx'));

        $this->assertStringContainsString('path="become-seller"', $main);
        $this->assertStringContainsString('SELLER_APPLY_PATH', $layout);
        $this->assertStringContainsString('/account/become-seller', $layout);
        // Non-approved sellers must be redirected out of the portal shell.
        $this->assertStringContainsString('canAccessSellerPortal', $layout);
        $this->assertStringContainsString('Navigate to={SELLER_APPLY_PATH}', $layout);
        // Approved/active sellers must leave become-seller for the portal.
        $this->assertStringContainsString('Navigate to="/account/seller" replace', $home);
        // Become Seller must never use target=_blank in the apply branch.
        $this->assertMatchesRegularExpression(
            '/data-seller-apply-link="1"[\s\S]*?<\/NavLink>/',
            $account
        );
        $this->assertDoesNotMatchRegularExpression(
            '/data-seller-apply-link="1"[\s\S]*?target="_blank"/',
            $account
        );
    }

    public function test_seller_portal_is_routed_outside_customer_account_layout(): void
    {
        $main = file_get_contents(base_path('storefront/src/main.jsx'));
        $this->assertNotFalse($main);
        $this->assertMatchesRegularExpression(
            '/<Route path="\/account\/seller" element=\{<SellerLayout \/>\}>/',
            $main
        );
        $this->assertStringContainsString('path="become-seller" element={<SellerHome />}', $main);
    }

    public function test_auth_me_exposes_can_access_portal_from_seller_lifecycle(): void
    {
        $this->seed();
        [, $owner] = $this->approvedSellerWithProduct('payload-a@example.com', 'payload-a');
        $this->actingAs($owner, 'customer');

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('customer.seller.is_approved', true)
            ->assertJsonPath('customer.seller.can_access_portal', true)
            ->assertJsonPath('customer.seller.status', Seller::STATUS_APPROVED);

        $pending = $this->registerCustomer('payload-pending@example.com');
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload([
            'email' => 'payload.pending.shop@example.com',
            'shop_slug' => 'payload-pending',
            'shop_name' => 'Payload Pending',
        ]))->assertCreated();

        $this->actingAs($pending, 'customer');
        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('customer.seller.status', Seller::STATUS_PENDING)
            ->assertJsonPath('customer.seller.is_approved', false)
            ->assertJsonPath('customer.seller.can_access_portal', false);

        $this->getJson('/api/v1/account/seller/dashboard')->assertForbidden();
    }

    public function test_seller_can_view_invoice_and_delivery_slip_for_own_orders(): void
    {
        $this->seed();
        [$sellerA, $ownerA] = $this->approvedSellerWithProduct('docs-a@example.com', 'docs-a');
        [, $ownerB] = $this->approvedSellerWithProduct('docs-b@example.com', 'docs-b');

        $order = Order::createTrusted([
            'number' => 'PORTAL-DOC-1',
            'customer_id' => $ownerA->id,
            'email' => $ownerA->email,
            'status' => 'shipped',
            'currency_code' => 'USD',
            'subtotal' => 18,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 18,
            'payment_status' => 'paid',
            'billing_address' => ['first_name' => 'Ada', 'last_name' => 'Buyer', 'line1' => '1 Main'],
            'shipping_address' => ['first_name' => 'Ada', 'last_name' => 'Buyer', 'line1' => '1 Main'],
        ]);
        $sellerOrder = SellerOrder::createTrusted([
            'order_id' => $order->id,
            'seller_id' => $sellerA->id,
            'number' => 'PORTAL-DOC-1-A',
            'customer_email' => $ownerA->email,
            'status' => 'shipped',
            'payment_status' => 'paid',
            'currency_code' => 'USD',
            'subtotal' => 18,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 18,
            'commission_total' => 0,
            'earning_total' => 18,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'docs-a@example.com',
            'password' => 'password123',
        ])->assertOk();

        $this->get('/api/v1/account/seller/orders/'.$sellerOrder->id.'/invoice')
            ->assertOk()
            ->assertHeader('content-type', 'text/html; charset=UTF-8')
            ->assertSee('INVOICE #'.$sellerOrder->number, false);

        $this->get('/api/v1/account/seller/orders/'.$sellerOrder->id.'/invoice/download')
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="invoice-'.$sellerOrder->number.'.html"');

        $this->get('/api/v1/account/seller/orders/'.$sellerOrder->id.'/delivery-slip')
            ->assertOk()
            ->assertSee('DELIVERY SLIP #'.$sellerOrder->number, false);

        $this->postJson('/api/v1/auth/logout');
        $this->postJson('/api/v1/auth/login', [
            'email' => 'docs-b@example.com',
            'password' => 'password123',
        ])->assertOk();
        $this->get('/api/v1/account/seller/orders/'.$sellerOrder->id.'/invoice')->assertNotFound();
    }

    public function test_seller_invoice_and_delivery_slip_require_eligible_status(): void
    {
        $this->seed();
        [, $owner] = $this->approvedSellerWithProduct('docs-pending@example.com', 'docs-pending');
        $seller = Seller::query()->where('customer_id', $owner->id)->firstOrFail();

        $order = Order::createTrusted([
            'number' => 'PORTAL-DOC-2',
            'customer_id' => $owner->id,
            'email' => $owner->email,
            'status' => 'pending',
            'currency_code' => 'USD',
            'subtotal' => 18,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 18,
            'payment_status' => 'pending',
            'billing_address' => ['line1' => '1 Main'],
            'shipping_address' => ['line1' => '1 Main'],
        ]);
        $sellerOrder = SellerOrder::createTrusted([
            'order_id' => $order->id,
            'seller_id' => $seller->id,
            'number' => 'PORTAL-DOC-2-A',
            'customer_email' => $owner->email,
            'status' => 'pending',
            'payment_status' => 'pending',
            'currency_code' => 'USD',
            'subtotal' => 18,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 18,
            'commission_total' => 0,
            'earning_total' => 18,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'docs-pending@example.com',
            'password' => 'password123',
        ])->assertOk();
        $this->get('/api/v1/account/seller/orders/'.$sellerOrder->id.'/invoice')->assertNotFound();
        $this->get('/api/v1/account/seller/orders/'.$sellerOrder->id.'/delivery-slip')->assertNotFound();
    }
}
