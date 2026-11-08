<?php

namespace Tests\Feature;

use App\Domain\Auth\Models\Role;
use App\Domain\Auth\StaffAcl;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\MarketplaceAuditLog;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Marketplace\Models\SellerWithdrawal;
use App\Domain\Marketplace\Events\MarketplaceEvent;
use App\Domain\Marketplace\Services\MarketplaceLifecycleBroker;
use App\Domain\Marketplace\Services\SellerService;
use App\Domain\Settings\MailEvents;
use App\Models\Admin;
use Illuminate\Support\Facades\Mail;

/**
 * Phase 19 — marketplace notifications and audit trail.
 */
class MarketplaceNotificationsAuditTest extends MarketplaceTestCase
{

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        StaffAcl::ensure();
        MailEvents::ensure();
        Mail::fake();
    }

    protected function adminWithRole(string $slug): Admin
    {
        $admin = Admin::factory()->create(['email' => $slug.'@audit.test']);
        $roleId = Role::query()->where('slug', $slug)->value('id');
        $this->assertNotNull($roleId);
        $admin->roles()->sync([$roleId]);

        return $admin->fresh(['roles.permissions']);
    }

    /** @return array<string, mixed> */
    protected function applyPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Audit',
            'last_name' => 'Seller',
            'shop_name' => 'Audit Shop',
            'shop_slug' => 'audit-shop',
            'profile_name' => 'Audit Shop',
            'email' => 'audit.seller@example.com',
            'phone' => '+1 555 0100',
            'contact_name' => 'Audit Seller',
            'contact_phone' => '+1 555 0100',
            'address_line1' => '1 Audit Street',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Audit goods',
        ], $overrides);
    }

    protected function registerCustomer(string $email): Customer
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Audit Customer',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertOk();

        return Customer::query()->where('email', $email)->firstOrFail();
    }

    public function test_seller_application_creates_audit_and_admin_notification(): void
    {
        $this->registerCustomer('apply-audit@example.com');
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload([
            'email' => 'apply-audit@example.com',
        ]))->assertCreated();

        $seller = Seller::query()->where('shop_slug', 'audit-shop')->firstOrFail();

        $this->assertDatabaseHas('marketplace_audit_logs', [
            'action' => 'seller.application_submitted',
            'entity_type' => 'seller',
            'entity_id' => $seller->id,
            'actor_type' => 'customer',
            'seller_id' => $seller->id,
        ]);

        Mail::assertSent(\App\Mail\ShopHtmlMail::class);
    }

    public function test_seller_approval_creates_audit_and_seller_notification(): void
    {
        $this->registerCustomer('approve-audit@example.com');
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload([
            'email' => 'approve-audit@example.com',
            'shop_slug' => 'approve-audit-shop',
        ]))->assertCreated();
        $seller = Seller::query()->where('shop_slug', 'approve-audit-shop')->firstOrFail();

        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', ['action' => 'approve'])
            ->assertRedirect();

        $this->assertDatabaseHas('marketplace_audit_logs', [
            'action' => 'seller.approved',
            'entity_type' => 'seller',
            'entity_id' => $seller->id,
            'actor_type' => 'admin',
            'actor_id' => $admin->id,
        ]);

        Mail::assertSent(\App\Mail\ShopHtmlMail::class);
    }

    public function test_seller_rejection_creates_audit_with_reason(): void
    {
        $this->registerCustomer('reject-audit@example.com');
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload([
            'email' => 'reject-audit@example.com',
            'shop_slug' => 'reject-audit-shop',
        ]))->assertCreated();
        $seller = Seller::query()->where('shop_slug', 'reject-audit-shop')->firstOrFail();

        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', [
                'action' => 'reject',
                'note' => 'Incomplete business details',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('marketplace_audit_logs', [
            'action' => 'seller.rejected',
            'entity_id' => $seller->id,
            'reason' => 'Incomplete business details',
        ]);
    }

    public function test_pending_product_submission_creates_audit(): void
    {
        MarketplaceSettings::save(['auto_approve_products' => false]);

        $customer = $this->registerCustomer('product-audit@example.com');
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload([
            'email' => 'product-audit@example.com',
            'shop_slug' => 'product-audit-shop',
        ]))->assertCreated();
        $seller = Seller::query()->where('shop_slug', 'product-audit-shop')->firstOrFail();
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', ['action' => 'approve'])
            ->assertRedirect();

        $this->postJson('/api/v1/auth/logout');
        $this->actingAs($customer, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Audit Mug',
            'slug' => 'audit-mug',
            'sku' => 'MUG-AUDIT',
            'price' => 12,
            'stock_qty' => 5,
        ])->assertCreated();

        $listing = SellerProduct::query()->where('seller_id', $seller->id)->firstOrFail();
        $this->assertDatabaseHas('marketplace_audit_logs', [
            'action' => 'seller.product_submitted',
            'entity_type' => 'seller_product',
            'entity_id' => $listing->id,
            'seller_id' => $seller->id,
        ]);
    }

    public function test_commission_change_creates_audit(): void
    {
        $this->registerCustomer('commission-audit@example.com');
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload([
            'email' => 'commission-audit@example.com',
            'shop_slug' => 'commission-audit-shop',
        ]))->assertCreated();
        $seller = Seller::query()->where('shop_slug', 'commission-audit-shop')->firstOrFail();
        $admin = Admin::query()->first();

        app(SellerService::class)->setCommission($seller, 22, 'Raised for audit test', null, null, $admin->id);

        $this->assertDatabaseHas('marketplace_audit_logs', [
            'action' => 'seller.commission_changed',
            'entity_id' => $seller->id,
            'actor_type' => 'admin',
            'actor_id' => $admin->id,
        ]);
    }

    public function test_payout_request_creates_audit(): void
    {
        $customer = $this->registerCustomer('payout-audit@example.com');
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload([
            'email' => 'payout-audit@example.com',
            'shop_slug' => 'payout-audit-shop',
        ]))->assertCreated();
        $seller = Seller::query()->where('shop_slug', 'payout-audit-shop')->firstOrFail();
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', ['action' => 'approve'])
            ->assertRedirect();

        $withdrawal = SellerWithdrawal::createTrusted([
            'seller_id' => $seller->id,
            'amount' => 25,
            'status' => SellerWithdrawal::STATUS_REQUESTED,
        ]);
        app(MarketplaceLifecycleBroker::class)->dispatch(MarketplaceEvent::payoutRequested($withdrawal));

        $this->assertDatabaseHas('marketplace_audit_logs', [
            'action' => 'seller.payout_requested',
            'entity_type' => 'seller_withdrawal',
            'entity_id' => $withdrawal->id,
            'seller_id' => $seller->id,
        ]);
    }

    public function test_audit_idempotency_prevents_duplicate_reference_rows(): void
    {
        $service = app(\App\Domain\Marketplace\Services\MarketplaceAuditService::class);
        $first = $service->record(
            action: 'seller.approved',
            entityType: 'seller',
            entityId: 99,
            actorType: 'admin',
            actorId: 1,
            reference: 'seller:99:approved:test',
            sellerId: 99,
        );
        $second = $service->record(
            action: 'seller.approved',
            entityType: 'seller',
            entityId: 99,
            actorType: 'admin',
            actorId: 1,
            reference: 'seller:99:approved:test',
            sellerId: 99,
        );

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second?->id);
        $this->assertSame(1, MarketplaceAuditLog::query()->where('reference', 'seller:99:approved:test')->count());
    }

    public function test_audit_sanitizes_sensitive_fields(): void
    {
        $row = app(\App\Domain\Marketplace\Services\MarketplaceAuditService::class)->record(
            action: 'seller.test',
            entityType: 'seller',
            entityId: 1,
            actorType: 'system',
            context: ['password' => 'secret123', 'note' => 'ok'],
        );

        $this->assertNotNull($row);
        $this->assertArrayNotHasKey('password', $row->context ?? []);
        $this->assertSame('ok', $row->context['note'] ?? null);
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

        $admin = $this->adminWithRole('marketplace_manager', 'audit-mgr@store.test');
        $this->actingAs($admin, 'admin')
            ->get('/admin/multivendor/audits')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Multivendor/Audits', false));
    }

    public function test_catalog_manager_cannot_open_audit_page(): void
    {
        $admin = $this->adminWithRole('catalog_manager');
        $this->actingAs($admin, 'admin')
            ->get('/admin/multivendor/audits')
            ->assertForbidden();
    }

    public function test_audit_records_are_append_only_via_model(): void
    {
        $row = MarketplaceAuditLog::query()->create([
            'action' => 'seller.approved',
            'entity_type' => 'seller',
            'entity_id' => 5,
            'actor_type' => 'admin',
            'actor_id' => 1,
            'created_at' => now(),
        ]);

        $this->assertFalse($row->update(['action' => 'tampered']));
        $this->assertDatabaseHas('marketplace_audit_logs', [
            'id' => $row->id,
            'action' => 'seller.approved',
        ]);
    }
}
