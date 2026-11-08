<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Models\Admin;

/**
 * Phase 05 — mixed-seller cart grouping and purchasability validation.
 */
class MarketplaceCartTest extends MarketplaceTestCase
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
    protected function sellerWithApprovedProduct(string $email, string $shopSlug, string $productName): array
    {
        $this->postJson('/api/v1/auth/logout');
        $owner = $this->registerCustomer($email);
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload([
            'email' => str_replace('@', '.shop@', $email),
            'shop_name' => $shopSlug.' Shop',
            'shop_slug' => $shopSlug,
        ]))->assertCreated();
        $seller = Seller::query()->where('customer_id', $owner->id)->firstOrFail();
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', ['action' => 'approve'])
            ->assertRedirect();

        \Illuminate\Support\Facades\Auth::guard('admin')->logout();
        $this->actingAs($owner, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => $productName,
            'sku' => 'sku-'.substr(md5($shopSlug.$productName), 0, 6),
            'price' => 15,
            'stock_qty' => 10,
            'is_enabled' => true,
        ])->assertCreated();

        $product = Product::query()->where('seller_id', $seller->id)->firstOrFail();
        SellerProduct::query()->where('product_id', $product->id)->update([
            'approval_status' => SellerProduct::STATUS_APPROVED,
            'is_enabled' => true,
        ]);
        $product->update(['is_enabled' => true, 'available_for_order' => true]);

        return [$seller->fresh(), $owner, $product->fresh()];
    }

    public function test_platform_and_single_seller_cart_exposes_seller_groups(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        $platform = Product::query()->whereNull('seller_id')->where('is_enabled', true)->firstOrFail();
        [, , $sellerProduct] = $this->sellerWithApprovedProduct('a@example.com', 'shop-a', 'Clay mug');

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');

        $this->postJson('/api/v1/cart', ['product_id' => $platform->id, 'qty' => 1])->assertOk();
        $response = $this->postJson('/api/v1/cart', ['product_id' => $sellerProduct->id, 'qty' => 2])->assertOk();

        $response->assertJsonPath('cart.items.1.product_id', $sellerProduct->id)
            ->assertJsonCount(2, 'cart.sellers')
            ->assertJsonCount(2, 'sellers');

        $sellers = collect($response->json('sellers'));
        $this->assertTrue($sellers->contains(fn ($row) => $row['seller_id'] === null && $row['is_marketplace'] === false));
        $this->assertTrue($sellers->contains(fn ($row) => (int) $row['seller_id'] === (int) $sellerProduct->seller_id));

        $marketplace = $sellers->first(fn ($row) => (int) ($row['seller_id'] ?? 0) === (int) $sellerProduct->seller_id);
        $this->assertSame(1, $marketplace['item_count']);
        $this->assertSame(30.0, (float) $marketplace['subtotal']);
    }

    public function test_mixed_seller_cart_groups_items_by_seller(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [, , $productA] = $this->sellerWithApprovedProduct('a@example.com', 'shop-a', 'Mug A');
        [, , $productB] = $this->sellerWithApprovedProduct('b@example.com', 'shop-b', 'Mug B');

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');

        $this->postJson('/api/v1/cart', ['product_id' => $productA->id, 'qty' => 1])->assertOk();
        $response = $this->postJson('/api/v1/cart', ['product_id' => $productB->id, 'qty' => 1])->assertOk();

        $response->assertJsonCount(2, 'cart.items')
            ->assertJsonCount(2, 'cart.sellers');

        $sellerIds = collect($response->json('cart.sellers'))->pluck('seller_id')->sort()->values()->all();
        $this->assertSame(
            [(int) $productA->seller_id, (int) $productB->seller_id],
            $sellerIds
        );

        $this->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonCount(2, 'sellers')
            ->assertJsonPath('totals.subtotal', 30);
    }

    public function test_cannot_add_product_from_inactive_or_vacation_seller(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, , $product] = $this->sellerWithApprovedProduct('a@example.com', 'shop-a', 'Vacation mug');

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');

        $seller->update(['vacation_enabled' => true]);
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])
            ->assertStatus(422);

        $seller->update(['vacation_enabled' => false, 'is_active' => false]);
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])
            ->assertStatus(422);

        $seller->update(['is_active' => true, 'status' => Seller::STATUS_BLOCKED]);
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])
            ->assertStatus(422);
    }

    public function test_cannot_add_pending_listing_and_client_cannot_send_price(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => false]);

        $this->postJson('/api/v1/auth/logout');
        $owner = $this->registerCustomer('a@example.com');
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload([
            'email' => 'a.shop@example.com',
            'shop_slug' => 'shop-a',
        ]))->assertCreated();
        $seller = Seller::query()->where('customer_id', $owner->id)->firstOrFail();
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', ['action' => 'approve'])
            ->assertRedirect();
        \Illuminate\Support\Facades\Auth::guard('admin')->logout();

        $this->actingAs($owner, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Pending mug',
            'sku' => 'pending-1',
            'price' => 12,
            'stock_qty' => 3,
            'is_enabled' => true,
        ])->assertCreated();
        $product = Product::query()->where('seller_id', $seller->id)->firstOrFail();
        $this->assertSame(SellerProduct::STATUS_PENDING, SellerProduct::query()->where('product_id', $product->id)->value('approval_status'));
        $this->assertFalse((bool) $product->fresh()->is_enabled);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertNotFound();

        $platform = Product::query()->whereNull('seller_id')->where('is_enabled', true)->firstOrFail();
        $listPrice = (float) $platform->price;
        $response = $this->postJson('/api/v1/cart', [
            'product_id' => $platform->id,
            'qty' => 1,
            'unit_price' => 0.01,
            'price' => 0.01,
        ])->assertOk();

        $unit = (float) collect($response->json('cart.items'))->firstWhere('product_id', $platform->id)['unit_price'];
        $this->assertNotEquals(0.01, $unit);
        $this->assertEqualsWithDelta($listPrice, $unit, 0.01);
    }

    public function test_qty_update_revalidates_and_uses_server_price(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [, , $product] = $this->sellerWithApprovedProduct('a@example.com', 'shop-a', 'Stock mug');

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $add = $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $itemId = (int) $add->json('cart.items.0.id');

        $this->patchJson('/api/v1/cart/items/'.$itemId, ['qty' => 3])
            ->assertOk()
            ->assertJsonPath('cart.items.0.qty', 3);
        $this->assertEquals(15.0, (float) $this->getJson('/api/v1/cart')->json('cart.items.0.unit_price'));

        $this->patchJson('/api/v1/cart/items/'.$itemId, ['qty' => 99])
            ->assertStatus(422);
    }
}
