<?php

namespace Tests\Feature;

use App\Domain\Auth\PageCatalog;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerBrand;
use App\Domain\Marketplace\Models\SellerCategory;
use App\Domain\Marketplace\Models\SellerFeature;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Marketplace\Models\SellerSupplier;
use App\Models\Admin;

class AdminMultivendorTest extends MarketplaceTestCase
{

    /** @return array<string, mixed> */
    protected function applyPayload(): array
    {
        return [
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
        ];
    }

    protected function approvedSeller(): Seller
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Seller',
            'email' => 'ada@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertOk();

        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload())->assertCreated();
        $seller = Seller::query()->firstOrFail();
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', ['action' => 'approve'])
            ->assertRedirect();

        return $seller->fresh();
    }

    public function test_multivendor_menu_pages_open(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        foreach ([
            '/admin/multivendor/dashboard',
            '/admin/multivendor/seller-requests',
            '/admin/multivendor/sellers',
            '/admin/multivendor/seller-profiles',
            '/admin/multivendor/seller-shops',
            '/admin/multivendor/products',
            '/admin/multivendor/discounts',
            '/admin/multivendor/categories',
            '/admin/multivendor/features',
            '/admin/multivendor/brands',
            '/admin/multivendor/suppliers',
            '/admin/multivendor/orders',
            '/admin/multivendor/returns',
            '/admin/multivendor/earnings',
            '/admin/multivendor/ledger',
            '/admin/multivendor/commissions',
            '/admin/multivendor/shipping',
            '/admin/multivendor/payments',
            '/admin/multivendor/payouts',
            '/admin/multivendor/reviews',
            '/admin/multivendor/messages',
            '/admin/multivendor/settings',
        ] as $url) {
            $this->get($url)->assertOk();
        }

        $this->get('/admin/multivendor/seller-requests')->assertSee('Seller Request');
    }

    public function test_seller_requests_list_customer_is_display_string(): void
    {
        $this->seed();
        $this->approvedSeller();

        $this->actingAs(Admin::query()->first(), 'admin')
            ->get('/admin/multivendor/seller-requests')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows.data', 1)
                ->where('rows.data.0.customer', fn ($value) => is_string($value) && $value !== ''));
    }

    public function test_admin_updates_seller_profile_and_shop(): void
    {
        $this->seed();
        $seller = $this->approvedSeller();

        $this->actingAs(Admin::query()->first(), 'admin');
        $this->put('/admin/multivendor/seller-profiles/'.$seller->id, [
            'first_name' => 'Ada',
            'last_name' => 'Updated',
            'profile_name' => 'Ada Studio',
            'email' => 'ada.seller@example.com',
            'phone' => '+1 555 0100',
            'contact_name' => 'Ada Seller',
            'contact_phone' => '+1 555 0100',
        ])->assertRedirect('/admin/multivendor/seller-profiles');
        $this->assertSame('Ada Studio', $seller->fresh()->profile_name);

        $this->put('/admin/multivendor/seller-shops/'.$seller->id, [
            'shop_name' => 'Ada Market West',
            'shop_slug' => 'ada-market',
            'address_line1' => '90 West Street',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Updated shop copy',
        ])->assertRedirect('/admin/multivendor/seller-shops');
        $this->assertSame('Ada Market West', $seller->fresh()->shop_name);
    }

    public function test_admin_dual_writes_seller_catalog_entities(): void
    {
        $this->seed();
        $seller = $this->approvedSeller();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->post('/admin/multivendor/categories', [
            'seller_id' => $seller->id,
            'name' => 'Handmade',
            'is_enabled' => true,
        ])->assertRedirect('/admin/multivendor/categories');
        $category = SellerCategory::query()->firstOrFail();
        $this->assertNotNull($category->category_id);
        $this->assertSame('Handmade', Category::query()->find($category->category_id)?->translation()?->name);
        $this->assertSame($seller->id, (int) Category::query()->find($category->category_id)?->seller_id);

        $this->post('/admin/multivendor/features', [
            'seller_id' => $seller->id,
            'name' => 'Material',
            'type' => 'select',
            'is_enabled' => true,
        ])->assertRedirect('/admin/multivendor/features');
        $feature = SellerFeature::query()->firstOrFail();
        $this->assertNotNull($feature->feature_id);
        $this->assertSame($seller->id, (int) Feature::query()->find($feature->feature_id)?->seller_id);

        $this->post('/admin/multivendor/brands', [
            'seller_id' => $seller->id,
            'name' => 'Ada Goods',
            'is_enabled' => true,
        ])->assertRedirect('/admin/multivendor/brands');
        $brand = SellerBrand::query()->firstOrFail();
        $this->assertNotNull($brand->brand_id);
        $this->assertSame($seller->id, (int) Brand::query()->find($brand->brand_id)?->seller_id);

        $this->post('/admin/multivendor/suppliers', [
            'seller_id' => $seller->id,
            'name' => 'Austin Supply',
            'city' => 'Austin',
            'is_enabled' => true,
        ])->assertRedirect('/admin/multivendor/suppliers');
        $supplier = SellerSupplier::query()->firstOrFail();
        $this->assertNotNull($supplier->supplier_id);
        $this->assertSame($seller->id, (int) Supplier::query()->find($supplier->supplier_id)?->seller_id);

        $this->post('/admin/multivendor/products', [
            'seller_id' => $seller->id,
            'name' => 'Clay mug',
            'price' => 18.5,
            'stock_qty' => 4,
            'category_id' => $category->category_id,
            'is_enabled' => true,
        ])->assertRedirect('/admin/multivendor/products');
        $this->assertDatabaseHas('seller_products', [
            'seller_id' => $seller->id,
            'name' => 'Clay mug',
        ]);
        $this->assertSame(1, SellerProduct::query()->count());
        $this->assertSame('approved', SellerProduct::query()->value('approval_status'));
    }

    public function test_admin_can_save_marketplace_settings_and_moderate_products(): void
    {
        $this->seed();
        $seller = $this->approvedSeller();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $this->put('/admin/multivendor/settings', [
            'default_commission' => 15,
            'auto_approve_products' => false,
            'earning_release_mode' => 'on_payment',
            'return_window_days' => 7,
        ])->assertRedirect();
        $this->assertFalse(MarketplaceSettings::autoApproveProducts());
        $this->assertSame(15.0, MarketplaceSettings::defaultCommission());

        $this->get('/admin/multivendor/products/export')->assertOk();
        $this->get('/admin/multivendor/seller-profiles/export')->assertOk();
        $this->get('/admin/multivendor/orders/export')->assertOk();
        $this->get('/admin/multivendor/earnings/export')->assertOk();

        $customer = $seller->customer;
        $this->actingAs($customer, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Pending mug',
            'price' => 12,
            'stock_qty' => 2,
            'is_enabled' => true,
        ])->assertCreated();
        $copy = SellerProduct::query()->where('name', 'Pending mug')->firstOrFail();
        $this->assertSame('pending', $copy->approval_status);
        $this->assertFalse((bool) $copy->product->is_enabled);

        $this->actingAs($admin, 'admin');
        $this->post('/admin/multivendor/products/'.$copy->id.'/review', ['action' => 'approve'])->assertRedirect();
        $this->assertSame('approved', $copy->fresh()->approval_status);
        $this->assertTrue((bool) $copy->fresh()->product->is_enabled);
    }

    public function test_multivendor_manage_unlocks_marketplace_pages(): void
    {
        $this->assertTrue(PageCatalog::grants(['multivendor.manage'], 'multivendor.seller-requests'));
        $this->assertTrue(PageCatalog::grants(['multivendor.manage'], 'multivendor.products'));
        $this->assertTrue(PageCatalog::grants(['customers.manage'], 'multivendor.seller-requests'));
        $this->assertTrue(PageCatalog::grants(['multivendor.manage'], 'multivendor.reviews'));
        $this->assertTrue(PageCatalog::grants(['multivendor.manage'], 'multivendor.settings'));
        $this->assertFalse(PageCatalog::grants(['customers.manage'], 'multivendor.products'));
        $this->assertFalse(PageCatalog::grants(['catalog.manage'], 'multivendor.brands'));
    }
}
