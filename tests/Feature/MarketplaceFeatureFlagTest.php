<?php

namespace Tests\Feature;

use App\Domain\Auth\Models\Role;
use App\Domain\Auth\StaffAcl;
use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Settings\Models\Setting;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Global Multi-Vendor feature flag (multivendor_enabled).
 */
class MarketplaceFeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        StaffAcl::ensure();
    }

    protected function adminWithRole(string $slug, string $email): Admin
    {
        $admin = Admin::factory()->create(['email' => $email]);
        $roleId = Role::query()->where('slug', $slug)->value('id');
        $this->assertNotNull($roleId, "Missing role {$slug}");
        $admin->roles()->sync([$roleId]);

        return $admin->fresh(['roles.permissions']);
    }

    protected function approvedSeller(string $slug = 'flag-shop'): array
    {
        $customer = Customer::createTrusted([
            'name' => 'Flag Seller',
            'email' => $slug.'@seller.test',
            'password' => bcrypt('password123'),
        ]);
        $seller = Seller::query()->create([
            'customer_id' => $customer->id,
            'first_name' => 'Flag',
            'last_name' => 'Seller',
            'shop_name' => 'Flag Shop',
            'shop_slug' => $slug,
            'profile_name' => 'Flag Shop',
            'email' => $customer->email,
            'phone' => '+1 555 0199',
            'contact_name' => 'Flag Seller',
            'contact_phone' => '+1 555 0199',
            'address_line1' => '1 Flag St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'status' => Seller::STATUS_APPROVED,
            'is_active' => true,
            'commission_rate' => 10,
        ]);

        return compact('customer', 'seller');
    }

    public function test_setting_defaults_false_and_can_toggle(): void
    {
        $this->assertFalse(MarketplaceSettings::enabled());
        $this->assertSame('0', (string) Setting::getValue('multivendor_enabled', '0'));

        MarketplaceSettings::setEnabled(true);
        $this->assertTrue(MarketplaceSettings::enabled());

        MarketplaceSettings::setEnabled(false);
        $this->assertFalse(MarketplaceSettings::enabled());
    }

    public function test_admin_store_toggle_persists_and_shares_inertia_shop_flag(): void
    {
        $admin = Admin::factory()->create(['email' => 'store-flag@admin.test']);
        $admin->roles()->sync([Role::query()->where('slug', 'super_admin')->value('id')]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/store')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Store', false)
                ->where('shop.multivendor', false));

        $this->actingAs($admin, 'admin')
            ->post('/admin/shop/multivendor', ['enabled' => 1])
            ->assertRedirect();

        $this->assertTrue(MarketplaceSettings::enabled());

        $this->actingAs($admin, 'admin')
            ->get('/admin/store')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('shop.multivendor', true));

        $this->actingAs($admin, 'admin')
            ->post('/admin/shop/multivendor', ['enabled' => 0])
            ->assertRedirect();

        $this->assertFalse(MarketplaceSettings::enabled());
    }

    public function test_disabled_blocks_admin_marketplace_even_for_super_admin(): void
    {
        $admin = $this->adminWithRole('super_admin', 'super-off@admin.test');
        $this->assertFalse(MarketplaceSettings::enabled());

        $this->actingAs($admin, 'admin')
            ->get('/admin/multivendor/dashboard')
            ->assertNotFound();

        $this->actingAs($admin, 'admin')
            ->get('/admin/sellers')
            ->assertNotFound();
    }

    public function test_disabled_blocks_marketplace_manager(): void
    {
        $admin = $this->adminWithRole('marketplace_manager', 'mgr-off@admin.test');

        $this->actingAs($admin, 'admin')
            ->get('/admin/multivendor/dashboard')
            ->assertNotFound();
    }

    public function test_enabled_allows_super_admin_and_manager_but_not_unauthorized(): void
    {
        MarketplaceSettings::setEnabled(true);

        $super = $this->adminWithRole('super_admin', 'super-on@admin.test');
        $this->actingAs($super, 'admin')
            ->get('/admin/multivendor/dashboard')
            ->assertOk();

        $manager = $this->adminWithRole('marketplace_manager', 'mgr-on@admin.test');
        $this->actingAs($manager, 'admin')
            ->get('/admin/multivendor/dashboard')
            ->assertOk();

        $catalog = $this->adminWithRole('catalog_manager', 'catalog-on@admin.test');
        $this->actingAs($catalog, 'admin')
            ->get('/admin/multivendor/dashboard')
            ->assertForbidden();
    }

    public function test_disabled_blocks_seller_portal_and_application_apis(): void
    {
        ['customer' => $customer] = $this->approvedSeller('portal-off');

        $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/seller')
            ->assertForbidden();

        $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/seller/dashboard')
            ->assertForbidden();

        MarketplaceSettings::setEnabled(true);

        $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/seller')
            ->assertOk();

        $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/seller/dashboard')
            ->assertOk();
    }

    public function test_disabled_blocks_public_seller_shop_api(): void
    {
        $this->approvedSeller('public-off');

        $this->getJson('/api/v1/catalog/seller/public-off')
            ->assertForbidden();

        MarketplaceSettings::setEnabled(true);

        $this->getJson('/api/v1/catalog/seller/public-off')
            ->assertOk();
    }

    public function test_shop_bootstrap_exposes_features_multivendor(): void
    {
        $this->getJson('/api/v1/store')
            ->assertOk()
            ->assertJsonPath('features.multivendor', false);

        MarketplaceSettings::setEnabled(true);

        $this->getJson('/api/v1/store')
            ->assertOk()
            ->assertJsonPath('features.multivendor', true);
    }

    public function test_disabling_preserves_seller_data(): void
    {
        MarketplaceSettings::setEnabled(true);
        ['seller' => $seller] = $this->approvedSeller('preserve-shop');
        $sellerId = $seller->id;

        MarketplaceSettings::setEnabled(false);
        $this->assertFalse(MarketplaceSettings::enabled());
        $this->assertTrue(Seller::query()->whereKey($sellerId)->exists());

        MarketplaceSettings::setEnabled(true);
        $this->assertTrue(Seller::query()->whereKey($sellerId)->exists());
        $this->assertSame('preserve-shop', Seller::query()->find($sellerId)?->shop_slug);
    }

    public function test_single_vendor_catalog_and_cart_still_work_when_disabled(): void
    {
        $this->assertFalse(MarketplaceSettings::enabled());

        $this->getJson('/api/v1/catalog/home')->assertOk();
        $this->getJson('/api/v1/cart')->assertOk();

        $product = Product::query()->whereNull('seller_id')->where('is_enabled', true)->first()
            ?? Product::query()->create([
                'type' => 'simple',
                'sku' => 'STORE-FLAG-1',
                'price' => 12.5,
                'stock_qty' => 10,
                'manage_stock' => true,
                'is_enabled' => true,
                'available_for_order' => true,
                'visibility' => 'both',
            ]);

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();
    }
}
