<?php

namespace Tests\Feature;

use App\Domain\Auth\Models\Role;
use App\Domain\Auth\PageCatalog;
use App\Domain\Auth\StaffAcl;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\MarketplaceAuditLog;
use App\Domain\Marketplace\Models\Seller;
use App\Models\Admin;

/**
 * Phase 18 — Admin Marketplace ACL and surface completion.
 */
class MarketplaceAdminTest extends MarketplaceTestCase
{

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

    public function test_marketplace_manager_can_open_audit_page(): void
    {
        MarketplaceAuditLog::query()->create([
            'action' => 'seller.approved',
            'entity_type' => 'seller',
            'entity_id' => 1,
            'actor_type' => 'admin',
            'actor_id' => 1,
            'created_at' => now(),
        ]);

        $admin = $this->adminWithRole('marketplace_manager', 'audit-admin@store.test');
        $this->actingAs($admin, 'admin')
            ->get('/admin/multivendor/audits')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Multivendor/Audits', false));
    }

    public function test_marketplace_manager_can_open_phase_18_pages(): void
    {
        $admin = $this->adminWithRole('marketplace_manager', 'market-mgr@store.test');
        $this->actingAs($admin, 'admin');

        foreach ([
            '/admin/multivendor/dashboard',
            '/admin/multivendor/seller-requests',
            '/admin/multivendor/sellers',
            '/admin/multivendor/products',
            '/admin/multivendor/orders',
            '/admin/multivendor/returns',
            '/admin/multivendor/commissions',
            '/admin/multivendor/earnings',
            '/admin/multivendor/payouts',
            '/admin/multivendor/reviews',
            '/admin/multivendor/settings',
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_super_admin_can_open_marketplace_dashboard_and_settings(): void
    {
        $admin = $this->adminWithRole('super_admin', 'super-market@store.test');
        $this->actingAs($admin, 'admin');

        $this->get('/admin/multivendor/dashboard')->assertOk();
        $this->get('/admin/multivendor/settings')->assertOk();
        $this->get('/admin/multivendor/sellers')->assertOk();
    }

    public function test_admin_without_marketplace_permission_is_denied(): void
    {
        $catalog = $this->adminWithRole('catalog_manager', 'catalog-deny@store.test');
        $this->actingAs($catalog, 'admin');

        foreach ([
            '/admin/multivendor/dashboard',
            '/admin/multivendor/seller-requests',
            '/admin/multivendor/sellers',
            '/admin/multivendor/products',
            '/admin/multivendor/orders',
            '/admin/multivendor/returns',
            '/admin/multivendor/earnings',
            '/admin/multivendor/payouts',
            '/admin/multivendor/settings',
        ] as $url) {
            $this->get($url)->assertForbidden();
        }

        $orders = $this->adminWithRole('order_manager', 'orders-deny@store.test');
        $this->actingAs($orders, 'admin');
        $this->get('/admin/multivendor/orders')->assertForbidden();
        $this->get('/admin/multivendor/returns')->assertForbidden();
        $this->put('/admin/multivendor/settings', [])->assertForbidden();
    }

    public function test_multivendor_manage_grants_phase_18_page_slugs(): void
    {
        $this->assertTrue(PageCatalog::grants(['multivendor.manage'], 'multivendor.dashboard'));
        $this->assertTrue(PageCatalog::grants(['multivendor.manage'], 'multivendor.sellers'));
        $this->assertTrue(PageCatalog::grants(['multivendor.manage'], 'multivendor.returns'));
        $this->assertTrue(PageCatalog::grants(['multivendor.manage'], 'multivendor.settings'));
        $this->assertFalse(PageCatalog::grants(['catalog.manage'], 'multivendor.dashboard'));
        $this->assertFalse(PageCatalog::grants(['orders.manage'], 'multivendor.payouts'));
    }

    public function test_seller_and_customer_cannot_access_admin_marketplace(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Portal Seller',
            'email' => 'portal-seller-admin@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertOk();
        $customer = Customer::query()->where('email', 'portal-seller-admin@example.com')->firstOrFail();
        $this->postJson('/api/v1/account/seller/apply', [
            'first_name' => 'Ada',
            'last_name' => 'Seller',
            'shop_name' => 'Portal Gate Shop',
            'shop_slug' => 'portal-gate-shop',
            'profile_name' => 'Portal Gate',
            'email' => 'portal.gate.shop@example.com',
            'phone' => '+1 555 0100',
            'contact_name' => 'Ada',
            'contact_phone' => '+1 555 0100',
            'address_line1' => '1 Main',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
        ])->assertCreated();

        $seller = Seller::query()->where('customer_id', $customer->id)->firstOrFail();
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', ['action' => 'approve'])
            ->assertRedirect();
        \Illuminate\Support\Facades\Auth::guard('admin')->logout();

        $this->actingAs($customer->fresh(), 'customer');
        $this->get('/admin/multivendor/dashboard')->assertRedirect();
        $this->get('/admin/multivendor/settings')->assertRedirect();
        $this->getJson('/api/v1/account/seller/dashboard')->assertOk();
    }

    public function test_seller_detail_hub_opens_for_marketplace_manager(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Detail Seller',
            'email' => 'detail-seller@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertOk();
        $this->postJson('/api/v1/account/seller/apply', [
            'first_name' => 'Dee',
            'last_name' => 'Seller',
            'shop_name' => 'Detail Shop',
            'shop_slug' => 'detail-shop',
            'profile_name' => 'Detail',
            'email' => 'detail.shop@example.com',
            'phone' => '+1 555 0200',
            'contact_name' => 'Dee',
            'contact_phone' => '+1 555 0200',
            'address_line1' => '2 Main',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
        ])->assertCreated();
        $seller = Seller::query()->where('shop_slug', 'detail-shop')->firstOrFail();

        $admin = $this->adminWithRole('marketplace_manager', 'detail-mgr@store.test');
        $this->actingAs($admin, 'admin')
            ->get('/admin/multivendor/sellers/'.$seller->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Multivendor/RequestShow', false)
                ->has('seller.links')
                ->has('seller.wallet')
                ->where('seller.shop_name', 'Detail Shop'));
    }
}
