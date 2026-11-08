<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Localization\Models\Language;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Models\Admin;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 03 — seller store profile, public shop, media, policies, ownership.
 */
class MarketplaceStoreTest extends MarketplaceTestCase
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
            'contact_email' => 'shop@ada.example',
            'address_line1' => '12 Market Street',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Handmade goods',
            'tax_id' => 'TX-SECRET-99',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    protected function shopPayload(array $overrides = []): array
    {
        return array_merge([
            'shop_name' => 'Ada Market',
            'shop_slug' => 'ada-market',
            'address_line1' => '12 Market Street',
            'address_line2' => 'Suite 2',
            'city' => 'Austin',
            'state' => 'TX',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Updated handmade goods',
            'store_policy' => 'Be kind.',
            'shipping_policy' => 'Ships in 3 days.',
            'return_policy' => '30 day returns.',
            'is_active' => true,
            'vacation_enabled' => false,
        ], $overrides);
    }

    protected function registerCustomer(string $email = 'ada@example.com'): Customer
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Seller',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertOk();

        return Customer::query()->where('email', $email)->firstOrFail();
    }

    /**
     * @return array{0: Seller, 1: Customer}
     */
    protected function approvedSeller(string $email = 'ada@example.com', array $apply = []): array
    {
        $this->postJson('/api/v1/auth/logout');
        $customer = $this->registerCustomer($email);
        $payload = $this->applyPayload(array_merge([
            'email' => str_replace('@', '.shop@', $email),
            'shop_slug' => 'shop-'.substr(md5($email), 0, 8),
        ], $apply));
        $this->postJson('/api/v1/account/seller/apply', $payload)->assertCreated();
        $seller = Seller::query()
            ->where('customer_id', $customer->id)
            ->firstOrFail();
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', ['action' => 'approve'])
            ->assertRedirect();
        $this->actingAs($customer, 'customer');

        return [$seller->fresh(), $customer];
    }

    public function test_active_seller_can_view_and_update_own_store(): void
    {
        $this->seed();
        $customer = $this->registerCustomer();
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload())->assertCreated();
        $seller = Seller::query()->firstOrFail();
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', ['action' => 'approve'])
            ->assertRedirect();

        $this->actingAs($customer, 'customer');
        $this->getJson('/api/v1/account/seller')
            ->assertOk()
            ->assertJsonPath('seller.shop_slug', 'ada-market')
            ->assertJsonPath('seller.tax_id', 'TX-SECRET-99');

        $this->patchJson('/api/v1/account/seller/shop', $this->shopPayload([
            'shop_name' => 'Ada Market Plus',
            'shop_slug' => 'ada-market-plus',
        ]))
            ->assertOk()
            ->assertJsonPath('seller.shop_name', 'Ada Market Plus')
            ->assertJsonPath('seller.shop_slug', 'ada-market-plus')
            ->assertJsonPath('seller.store_policy', 'Be kind.')
            ->assertJsonPath('seller.shipping_policy', 'Ships in 3 days.')
            ->assertJsonPath('seller.return_policy', '30 day returns.');

        $this->assertDatabaseHas('sellers', [
            'id' => $seller->id,
            'shop_slug' => 'ada-market-plus',
            'store_policy' => 'Be kind.',
        ]);
    }

    public function test_shop_update_stores_translated_policies_and_description(): void
    {
        $this->seed();
        Language::query()->whereIn('code', ['en', 'hi'])->update(['is_enabled' => true]);
        Language::query()->whereNotIn('code', ['en', 'hi'])->update(['is_enabled' => false]);
        [$seller, $customer] = $this->approvedSeller('shop.i18n@example.com', [
            'email' => 'shop.i18n.seller@example.com',
            'shop_slug' => 'shop-i18n-market',
        ]);

        $this->actingAs($customer, 'customer')
            ->patchJson('/api/v1/account/seller/shop', $this->shopPayload([
                'shop_slug' => 'shop-i18n-market',
                'description' => '<p>English description</p>',
                'store_policy' => '<p>English store policy</p>',
                'shipping_policy' => '<p>English shipping</p>',
                'return_policy' => '<p>English returns</p>',
                'vacation_message' => '<p>English vacation</p>',
                'translations' => [
                    'en' => [
                        'language_code' => 'en',
                        'shop_name' => 'Ada Market',
                        'description' => '<p>English description</p>',
                        'store_policy' => '<p>English store policy</p>',
                        'shipping_policy' => '<p>English shipping</p>',
                        'return_policy' => '<p>English returns</p>',
                        'vacation_message' => '<p>English vacation</p>',
                    ],
                    'hi' => [
                        'language_code' => 'hi',
                        'shop_name' => 'Ada Market',
                        'description' => '<p>Hindi description</p>',
                        'store_policy' => '<p>Hindi store policy</p>',
                        'shipping_policy' => '<p>Hindi shipping</p>',
                        'return_policy' => '<p>Hindi returns</p>',
                        'vacation_message' => '<p>Hindi vacation</p>',
                    ],
                ],
            ]))
            ->assertOk()
            ->assertJsonPath('seller.description', '<p>English description</p>');

        $this->assertDatabaseHas('seller_translations', [
            'seller_id' => $seller->id,
            'language_code' => 'hi',
            'description' => '<p>Hindi description</p>',
            'store_policy' => '<p>Hindi store policy</p>',
        ]);

        $this->getJson('/api/v1/catalog/seller/shop-i18n-market?locale=hi')
            ->assertOk()
            ->assertJsonPath('shop.description', '<p>Hindi description</p>')
            ->assertJsonPath('shop.store_policy', '<p>Hindi store policy</p>');
    }

    public function test_store_validation_and_slug_uniqueness(): void
    {
        $this->seed();
        $this->approvedSeller('one@example.com', [
            'shop_slug' => 'taken-slug',
            'shop_name' => 'Taken',
            'email' => 'taken@example.com',
        ]);
        $this->postJson('/api/v1/auth/logout');
        $customer = $this->registerCustomer('two@example.com');
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload([
            'shop_slug' => 'free-slug',
            'email' => 'two.shop@example.com',
        ]))->assertCreated();
        $seller = Seller::query()->where('shop_slug', 'free-slug')->firstOrFail();
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', ['action' => 'approve'])
            ->assertRedirect();

        $this->actingAs($customer, 'customer');
        $this->patchJson('/api/v1/account/seller/shop', $this->shopPayload([
            'shop_slug' => 'taken-slug',
        ]))->assertStatus(422)->assertJsonValidationErrors(['shop_slug']);

        $this->patchJson('/api/v1/account/seller/shop', $this->shopPayload([
            'shop_slug' => 'Bad Slug!!',
        ]))->assertStatus(422)->assertJsonValidationErrors(['shop_slug']);

        $this->patchJson('/api/v1/account/seller/shop', $this->shopPayload([
            'shop_name' => '',
        ]))->assertStatus(422)->assertJsonValidationErrors(['shop_name']);

        $this->patchJson('/api/v1/account/seller/shop', $this->shopPayload([
            'shop_slug' => 'free-slug',
        ]))->assertOk();
    }

    public function test_seller_id_spoofing_cannot_update_another_store(): void
    {
        $this->seed();
        [$sellerA, $customerA] = $this->approvedSeller('a@example.com', [
            'shop_slug' => 'shop-a',
            'shop_name' => 'Shop A',
            'email' => 'a.shop@example.com',
        ]);
        [$sellerB] = $this->approvedSeller('b@example.com', [
            'shop_slug' => 'shop-b',
            'shop_name' => 'Shop B',
            'email' => 'b.shop@example.com',
        ]);

        $this->postJson('/api/v1/auth/logout');
        $this->postJson('/api/v1/auth/login', [
            'email' => 'a@example.com',
            'password' => 'password123',
        ])->assertOk();
        $this->patchJson('/api/v1/account/seller/shop', $this->shopPayload([
            'seller_id' => $sellerB->id,
            'shop_name' => 'Hijacked',
            'shop_slug' => 'shop-a',
            'address_line1' => $sellerA->address_line1,
            'city' => $sellerA->city,
            'postal_code' => $sellerA->postal_code,
            'country_code' => $sellerA->country_code,
        ]))->assertOk()->assertJsonPath('seller.id', $sellerA->id);

        $this->assertDatabaseHas('sellers', ['id' => $sellerA->id, 'shop_name' => 'Hijacked']);
        $this->assertDatabaseHas('sellers', ['id' => $sellerB->id, 'shop_name' => 'Shop B']);
    }

    public function test_unauthenticated_and_non_seller_cannot_update_store(): void
    {
        $this->seed();
        $this->patchJson('/api/v1/account/seller/shop', $this->shopPayload())
            ->assertUnauthorized();

        $this->registerCustomer('buyer@example.com');
        $this->patchJson('/api/v1/account/seller/shop', $this->shopPayload())
            ->assertForbidden();
    }

    public function test_pending_rejected_and_suspended_sellers_cannot_manage_store(): void
    {
        $this->seed();
        $customer = $this->registerCustomer();
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload())->assertCreated();
        $seller = Seller::query()->firstOrFail();

        $this->actingAs($customer, 'customer');
        $this->patchJson('/api/v1/account/seller/shop', $this->shopPayload())
            ->assertForbidden();

        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', [
                'action' => 'reject',
                'note' => 'Incomplete docs',
            ])
            ->assertRedirect();
        $this->actingAs($customer, 'customer');
        $this->patchJson('/api/v1/account/seller/shop', $this->shopPayload())
            ->assertForbidden();

        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload([
            'shop_slug' => 'ada-market-2',
        ]))->assertOk();
        $seller = $seller->fresh();
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', ['action' => 'approve'])
            ->assertRedirect();
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', [
                'action' => 'suspend',
                'note' => 'Policy breach',
            ])
            ->assertRedirect();

        $this->actingAs($customer, 'customer');
        $this->patchJson('/api/v1/account/seller/shop', $this->shopPayload([
            'shop_slug' => 'ada-market-2',
        ]))->assertForbidden();
    }

    public function test_public_store_visibility_and_private_fields_are_hidden(): void
    {
        $this->seed();
        [$seller, $customer] = $this->approvedSeller('ada@example.com', [
            'shop_slug' => 'ada-market',
            'email' => 'ada.seller@example.com',
        ]);

        $this->actingAs($customer, 'customer');
        $this->patchJson('/api/v1/account/seller/shop', $this->shopPayload([
            'shop_slug' => 'ada-market',
            'store_policy' => 'Public store rules',
            'shipping_policy' => 'Public shipping',
            'return_policy' => 'Public returns',
        ]))->assertOk();

        $response = $this->getJson('/api/v1/catalog/seller/ada-market')
            ->assertOk()
            ->assertJsonPath('shop.shop_name', 'Ada Market')
            ->assertJsonPath('shop.store_policy', 'Public store rules')
            ->assertJsonPath('shop.shipping_policy', 'Public shipping')
            ->assertJsonPath('shop.return_policy', 'Public returns')
            ->assertJsonPath('shop.contact_email', 'shop@ada.example');

        $json = $response->json('shop');
        $this->assertArrayNotHasKey('tax_id', $json);
        $this->assertArrayNotHasKey('commission_rate', $json);
        $this->assertArrayNotHasKey('review_note', $json);
        $this->assertArrayNotHasKey('email', $json);
        $this->assertArrayNotHasKey('phone', $json);
        $this->assertArrayNotHasKey('status', $json);

        $this->actingAs($customer, 'customer');
        $this->patchJson('/api/v1/account/seller/shop', $this->shopPayload([
            'shop_slug' => 'ada-market',
            'is_active' => false,
        ]))->assertOk();
        $this->getJson('/api/v1/catalog/seller/ada-market')->assertNotFound();

        $seller->update(['is_active' => true, 'status' => Seller::STATUS_BLOCKED]);
        $this->getJson('/api/v1/catalog/seller/ada-market')->assertNotFound();
    }

    public function test_public_catalog_only_lists_enabled_visible_products(): void
    {
        $this->seed();
        [$seller, $customer] = $this->approvedSeller('ada@example.com', [
            'shop_slug' => 'ada-market',
            'email' => 'ada.seller@example.com',
        ]);

        $this->actingAs($customer, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Shown mug',
            'sku' => 'mug-show',
            'price' => 12,
            'stock_qty' => 4,
            'is_enabled' => true,
        ])->assertCreated();
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Hidden mug',
            'sku' => 'mug-hide',
            'price' => 9,
            'stock_qty' => 2,
            'is_enabled' => false,
        ])->assertCreated();

        $shown = Product::query()->where('seller_id', $seller->id)->where('sku', 'like', '%MUG-SHOW')->firstOrFail();
        $hidden = Product::query()->where('seller_id', $seller->id)->where('sku', 'like', '%MUG-HIDE')->firstOrFail();
        SellerProduct::query()->where('product_id', $shown->id)->update(['approval_status' => 'approved', 'is_enabled' => true]);
        SellerProduct::query()->where('product_id', $hidden->id)->update(['approval_status' => 'approved', 'is_enabled' => false]);
        $shown->update(['is_enabled' => true]);
        $hidden->update(['is_enabled' => false]);

        $this->getJson('/api/v1/catalog/seller/ada-market')
            ->assertOk()
            ->assertJsonPath('products.meta.total', 1)
            ->assertJsonPath('products.data.0.name', 'Shown mug');
    }

    public function test_seller_can_upload_replace_and_remove_store_media(): void
    {
        $this->seed();
        Storage::fake('public');
        [$seller, $customer] = $this->approvedSeller('ada@example.com', [
            'shop_slug' => 'ada-market',
            'email' => 'ada.seller@example.com',
        ]);

        $this->actingAs($customer, 'customer');
        $this->post('/api/v1/account/seller/shop', array_merge($this->shopPayload(['shop_slug' => 'ada-market']), [
            '_method' => 'PATCH',
            'logo' => UploadedFile::fake()->image('logo.jpg', 120, 120),
        ]))->assertOk();

        $seller->refresh();
        $this->assertNotNull($seller->logo_path);
        Storage::disk('public')->assertExists($seller->logo_path);

        $oldPath = $seller->logo_path;
        $this->post('/api/v1/account/seller/shop', array_merge($this->shopPayload(['shop_slug' => 'ada-market']), [
            '_method' => 'PATCH',
            'logo' => UploadedFile::fake()->image('logo2.png', 100, 100),
        ]))->assertOk();
        $seller->refresh();
        $this->assertNotSame($oldPath, $seller->logo_path);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($seller->logo_path);

        $this->getJson('/api/v1/catalog/seller/ada-market')
            ->assertOk()
            ->assertJsonPath('shop.logo_url', asset('storage/'.$seller->logo_path));

        $this->post('/api/v1/account/seller/shop', array_merge($this->shopPayload(['shop_slug' => 'ada-market']), [
            '_method' => 'PATCH',
            'remove_logo' => '1',
        ]))->assertOk();
        $seller->refresh();
        $this->assertNull($seller->logo_path);

        $this->post('/api/v1/account/seller/shop', array_merge($this->shopPayload(['shop_slug' => 'ada-market']), [
            '_method' => 'PATCH',
            'logo' => UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload'),
        ]))->assertStatus(422);
    }

    public function test_admin_can_edit_seller_store_policies(): void
    {
        $this->seed();
        [$seller] = $this->approvedSeller('ada@example.com', [
            'shop_slug' => 'ada-market',
            'email' => 'ada.seller@example.com',
        ]);

        $this->actingAs(Admin::query()->first(), 'admin')
            ->put('/admin/multivendor/seller-shops/'.$seller->id, $this->shopPayload([
                'shop_slug' => 'ada-market',
                'store_policy' => 'Admin store policy',
                'shipping_policy' => 'Admin shipping',
                'return_policy' => 'Admin returns',
            ]))
            ->assertRedirect('/admin/multivendor/seller-shops');

        $this->assertDatabaseHas('sellers', [
            'id' => $seller->id,
            'store_policy' => 'Admin store policy',
            'shipping_policy' => 'Admin shipping',
            'return_policy' => 'Admin returns',
        ]);
    }
}
