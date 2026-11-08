<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Marketplace\Models\SellerReview;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;
use App\Domain\Auth\Models\Role;

/**
 * Phase 16 — verified-purchase seller reviews.
 */
class MarketplaceReviewsTest extends MarketplaceTestCase
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
    protected function sellerWithProduct(string $email, string $slug, string $name, float $price = 40): array
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
            'stock_qty' => 20,
            'is_enabled' => true,
        ])->assertCreated();
        $product = Product::query()->where('seller_id', $seller->id)->latest('id')->firstOrFail();
        SellerProduct::query()->where('product_id', $product->id)->update([
            'approval_status' => SellerProduct::STATUS_APPROVED,
            'is_enabled' => true,
        ]);
        $product->update(['is_enabled' => true, 'available_for_order' => true, 'manage_stock' => true]);

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

    protected function shipAndDeliver(Customer $owner, SellerOrder $sellerOrder): void
    {
        $this->loginAs($owner);
        $shipmentId = $this->postJson('/api/v1/account/seller/orders/'.$sellerOrder->id.'/shipments', [
            'tracking_number' => 'REV-'.$sellerOrder->id,
        ])->assertCreated()->json('shipment.id');
        $this->postJson('/api/v1/account/seller/shipments/'.$shipmentId.'/ship', [
            'tracking_number' => 'REV-'.$sellerOrder->id,
        ])->assertOk();
        $this->postJson('/api/v1/account/seller/shipments/'.$shipmentId.'/deliver')->assertOk();
    }

    /** @return array{0: Order, 1: SellerOrder, 2: Customer} */
    protected function purchaseShipped(Seller $seller, Customer $owner, Product $product, string $buyerEmail = 'buyer@example.com'): array
    {
        $this->postJson('/api/v1/auth/logout');
        $buyer = $this->registerCustomer($buyerEmail);
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $placed = $this->postJson('/api/v1/checkout', $this->checkoutPayload($buyerEmail))->assertCreated();
        $order = Order::query()->findOrFail($placed->json('order.id'));
        $order->update(['payment_status' => 'paid']);
        $so = SellerOrder::query()->where('seller_id', $seller->id)->firstOrFail();
        $this->shipAndDeliver($owner, $so);

        return [$order, $so->fresh(), $buyer];
    }

    public function test_eligible_customer_can_create_verified_review(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, $owner, $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug');
        [, $so, $buyer] = $this->purchaseShipped($seller, $owner, $product);

        $this->loginAs($buyer);
        $this->postJson('/api/v1/account/seller-reviews', [
            'seller_id' => $seller->id,
            'rating' => 5,
            'title' => 'Great seller',
            'body' => 'Fast shipping.',
            'status' => 'approved', // mass-assignment attempt
        ])->assertCreated()
            ->assertJsonPath('review.status', SellerReview::STATUS_PENDING)
            ->assertJsonPath('review.verified_purchase', true);

        $review = SellerReview::query()->firstOrFail();
        $this->assertSame((int) $so->id, (int) $review->seller_order_id);
        $this->assertSame(SellerReview::STATUS_PENDING, $review->status);
    }

    public function test_non_purchaser_cannot_review(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug');

        $this->postJson('/api/v1/auth/logout');
        $stranger = $this->registerCustomer('stranger@example.com');
        $this->loginAs($stranger);
        $this->postJson('/api/v1/account/seller-reviews', [
            'seller_id' => $seller->id,
            'rating' => 4,
            'body' => 'Nope',
        ])->assertStatus(422);
        $this->assertSame(0, SellerReview::query()->count());
    }

    public function test_duplicate_review_is_denied(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, $owner, $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug');
        [, , $buyer] = $this->purchaseShipped($seller, $owner, $product);

        $this->loginAs($buyer);
        $this->postJson('/api/v1/account/seller-reviews', [
            'seller_id' => $seller->id,
            'rating' => 5,
            'title' => 'First',
        ])->assertCreated();
        $this->postJson('/api/v1/account/seller-reviews', [
            'seller_id' => $seller->id,
            'rating' => 1,
            'title' => 'Second',
        ])->assertStatus(422);
        $this->assertSame(1, SellerReview::query()->count());
        $this->assertSame('First', SellerReview::query()->value('title'));
    }

    public function test_self_review_denied_and_unshipped_purchase_denied(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, $owner, $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug');

        $this->loginAs($owner);
        $this->postJson('/api/v1/account/seller-reviews', [
            'seller_id' => $seller->id,
            'rating' => 5,
            'body' => 'Myself',
        ])->assertStatus(422);

        $this->postJson('/api/v1/auth/logout');
        $buyer = $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        $this->loginAs($buyer);
        $this->postJson('/api/v1/account/seller-reviews', [
            'seller_id' => $seller->id,
            'rating' => 4,
        ])->assertStatus(422);
    }

    public function test_admin_moderation_and_public_summary(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, $owner, $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug');
        [, , $buyer] = $this->purchaseShipped($seller, $owner, $product);

        $this->loginAs($buyer);
        $this->postJson('/api/v1/account/seller-reviews', [
            'seller_id' => $seller->id,
            'rating' => 4,
            'title' => 'Solid',
            'body' => 'Good pack.',
        ])->assertCreated();
        $review = SellerReview::query()->firstOrFail();

        $this->getJson('/api/v1/catalog/seller/'.$seller->shop_slug)
            ->assertOk()
            ->assertJsonPath('reviews.data', [])
            ->assertJsonPath('reviews.meta.total', 0)
            ->assertJsonPath('shop.rating.count', 0);

        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/reviews/'.$review->id.'/moderate', ['action' => 'approve'])
            ->assertRedirect();

        \Illuminate\Support\Facades\Auth::guard('admin')->logout();
        $this->postJson('/api/v1/auth/logout');

        $shop = $this->getJson('/api/v1/catalog/seller/'.$seller->shop_slug)->assertOk();
        $shop->assertJsonPath('reviews.data.0.title', 'Solid')
            ->assertJsonPath('shop.rating.count', 1)
            ->assertJsonPath('shop.rating.average', 4)
            ->assertJsonPath('shop.rating.distribution.4', 1);
        $this->assertArrayNotHasKey('moderation_note', $shop->json('reviews.data.0'));
        $this->assertArrayNotHasKey('email', $shop->json('reviews.data.0'));
    }

    public function test_seller_isolation_read_only_and_cannot_moderate(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$sellerA, $ownerA, $productA] = $this->sellerWithProduct('a@example.com', 'shop-a', 'A');
        [, $ownerB] = $this->sellerWithProduct('b@example.com', 'shop-b', 'B');
        [, , $buyer] = $this->purchaseShipped($sellerA, $ownerA, $productA);

        $this->loginAs($buyer);
        $this->postJson('/api/v1/account/seller-reviews', [
            'seller_id' => $sellerA->id,
            'rating' => 5,
            'title' => 'A only',
        ])->assertCreated();

        $this->loginAs($ownerA);
        $this->getJson('/api/v1/account/seller/reviews')->assertOk()
            ->assertJsonPath('reviews.data.0.title', 'A only');

        $this->loginAs($ownerB);
        $this->getJson('/api/v1/account/seller/reviews')->assertOk()
            ->assertJsonPath('reviews.data', []);

        // No seller moderation endpoint — admin route denied to customer.
        $reviewId = SellerReview::query()->value('id');
        $this->postJson('/api/v1/account/seller/reviews/'.$reviewId.'/moderate', ['action' => 'approve'])
            ->assertNotFound();
        $this->patchJson('/api/v1/account/seller-reviews/'.$reviewId, ['rating' => 1])
            ->assertNotFound();
    }

    public function test_unauthorized_admin_cannot_moderate(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, $owner, $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug');
        [, , $buyer] = $this->purchaseShipped($seller, $owner, $product);
        $this->loginAs($buyer);
        $this->postJson('/api/v1/account/seller-reviews', [
            'seller_id' => $seller->id,
            'rating' => 3,
        ])->assertCreated();
        $reviewId = SellerReview::query()->value('id');

        $catalog = Admin::factory()->create(['email' => 'catalog-rev@store.test']);
        $catalog->roles()->sync([Role::query()->where('slug', 'catalog_manager')->value('id')]);
        $this->actingAs($catalog->fresh(['roles.permissions']), 'admin');
        $this->post('/admin/multivendor/reviews/'.$reviewId.'/moderate', ['action' => 'approve'])
            ->assertForbidden();
        $this->assertSame(SellerReview::STATUS_PENDING, SellerReview::query()->value('status'));
    }

    public function test_invalid_rating_rejected(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, $owner, $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug');
        [, , $buyer] = $this->purchaseShipped($seller, $owner, $product);
        $this->loginAs($buyer);
        $this->postJson('/api/v1/account/seller-reviews', [
            'seller_id' => $seller->id,
            'rating' => 9,
        ])->assertStatus(422);
    }
}
