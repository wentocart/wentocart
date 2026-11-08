<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Services\SellerService;
use App\Models\Admin;

/**
 * Phase 02 — seller lifecycle transitions and authorization.
 *
 * Status vocabulary (existing DB values):
 * - pending
 * - approved  (= ACTIVE)
 * - rejected
 * - blocked   (= SUSPENDED)
 */
class MarketplaceLifecycleTest extends MarketplaceTestCase
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

    protected function pendingSeller(string $email = 'ada@example.com'): Seller
    {
        $this->registerCustomer($email);
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload([
            'email' => str_replace('@', '.shop@', $email),
            'shop_slug' => 'shop-'.substr(md5($email), 0, 8),
        ]))->assertCreated();

        return Seller::query()->where('customer_id', Customer::query()->where('email', $email)->value('id'))->firstOrFail();
    }

    public function test_duplicate_pending_and_approved_applications_are_rejected(): void
    {
        $this->seed();
        $customer = $this->registerCustomer();
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload())->assertCreated();
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload())
            ->assertStatus(422);

        $seller = Seller::query()->firstOrFail();
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', ['action' => 'approve'])
            ->assertRedirect();

        $this->actingAs($customer, 'customer');
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload(['shop_slug' => 'ada-again']))
            ->assertStatus(422);
        $this->assertSame(1, Seller::query()->where('customer_id', $customer->id)->count());
    }

    public function test_client_seller_id_cannot_hijack_application_owner(): void
    {
        $this->seed();
        $this->registerCustomer('owner@example.com');
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload([
            'shop_slug' => 'owner-shop',
            'email' => 'owner.shop@example.com',
            'seller_id' => 999999,
            'customer_id' => 999999,
            'status' => 'approved',
        ]))->assertCreated();

        $seller = Seller::query()->where('shop_slug', 'owner-shop')->firstOrFail();
        $this->assertSame(Seller::STATUS_PENDING, $seller->status);
        $this->assertSame(
            (int) Customer::query()->where('email', 'owner@example.com')->value('id'),
            (int) $seller->customer_id
        );
    }

    public function test_pending_to_active_and_idempotent_approve(): void
    {
        $this->seed();
        $seller = $this->pendingSeller();
        $admin = Admin::query()->first();

        $this->actingAs($admin, 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', [
                'action' => 'approve',
                'commission_rate' => 11,
            ])->assertRedirect();

        $seller->refresh();
        $this->assertTrue($seller->isActive());
        $this->assertSame(11.0, (float) $seller->commission_rate);
        $this->assertNotNull($seller->reviewed_at);
        $this->assertSame((int) $admin->id, (int) $seller->reviewed_by);

        $reviewedAt = $seller->reviewed_at?->toIso8601String();
        $this->actingAs($admin, 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', ['action' => 'approve'])
            ->assertRedirect();
        $this->assertSame($reviewedAt, $seller->fresh()->reviewed_at?->toIso8601String());
    }

    public function test_pending_to_rejected_requires_reason_and_allows_reapply(): void
    {
        $this->seed();
        $customer = $this->registerCustomer();
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload())->assertCreated();
        $seller = Seller::query()->firstOrFail();
        $admin = Admin::query()->first();

        $this->actingAs($admin, 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', ['action' => 'reject'])
            ->assertSessionHasErrors('note');

        $this->actingAs($admin, 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', [
                'action' => 'reject',
                'note' => 'Incomplete paperwork',
            ])->assertRedirect();

        $seller->refresh();
        $this->assertTrue($seller->isRejected());
        $this->assertSame('Incomplete paperwork', $seller->review_note);
        $this->assertDatabaseHas('sellers', ['id' => $seller->id]);

        $this->actingAs($customer, 'customer');
        $this->getJson('/api/v1/account/seller/dashboard')->assertForbidden();
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload([
            'shop_name' => 'Ada Market 2',
        ]))->assertOk()->assertJsonPath('seller.status', 'pending');
    }

    public function test_active_to_suspended_and_reactivate(): void
    {
        $this->seed();
        $customer = $this->registerCustomer();
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload())->assertCreated();
        $seller = Seller::query()->firstOrFail();
        $admin = Admin::query()->first();

        $this->actingAs($admin, 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', ['action' => 'approve'])
            ->assertRedirect();

        $this->actingAs($customer, 'customer');
        $this->getJson('/api/v1/account/seller/dashboard')->assertOk();

        $this->actingAs($admin, 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', ['action' => 'suspend'])
            ->assertSessionHasErrors('note');

        $this->actingAs($admin, 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', [
                'action' => 'suspend',
                'note' => 'Policy review',
            ])->assertRedirect();

        $seller->refresh();
        $this->assertTrue($seller->isSuspended());
        $this->assertNotNull($seller->blocked_at);

        $this->actingAs($customer, 'customer');
        $this->getJson('/api/v1/account/seller/dashboard')->assertForbidden();
        $this->getJson('/api/v1/account/seller/products')->assertForbidden();
        // Customer account (non-seller) still works.
        $this->getJson('/api/v1/account')->assertOk();

        $this->actingAs($admin, 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', ['action' => 'reactivate'])
            ->assertRedirect();
        $this->assertTrue($seller->fresh()->isActive());

        $this->actingAs($customer, 'customer');
        $this->getJson('/api/v1/account/seller/dashboard')
            ->assertOk()
            ->assertJsonPath('seller.can_access_portal', true);
    }

    public function test_invalid_lifecycle_transitions_are_rejected(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $service = app(SellerService::class);

        $pending = $this->pendingSeller('pending@example.com');

        try {
            $service->review($pending, 'suspend', 'Nope', $admin->id);
            $this->fail('Pending seller must not be suspended.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $service->review($pending, 'reject', 'No', $admin->id);
        $rejected = $pending->fresh();
        $this->assertTrue($rejected->isRejected());

        try {
            $service->review($rejected, 'approve', null, $admin->id);
            $this->fail('Rejected seller must not be approved directly.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        try {
            $service->review($rejected, 'block', 'Nope', $admin->id);
            $this->fail('Rejected seller must not be suspended.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $active = $this->pendingSeller('active@example.com');
        $service->review($active, 'approve', null, $admin->id);
        try {
            $service->review($active->fresh(), 'reject', 'Nope', $admin->id);
            $this->fail('Active seller must not be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_seller_cannot_review_another_seller_via_customer_session(): void
    {
        $this->seed();
        $a = $this->pendingSeller('a@example.com');
        $b = $this->pendingSeller('b@example.com');
        $admin = Admin::query()->first();
        app(SellerService::class)->review($a, 'approve', null, $admin->id);
        app(SellerService::class)->review($b, 'approve', null, $admin->id);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'a@example.com',
            'password' => 'password123',
        ])->assertOk();

        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload([
            'shop_slug' => 'hijack',
            'seller_id' => $b->id,
        ]))->assertStatus(422);

        $this->post('/admin/multivendor/seller-requests/'.$b->id.'/review', [
            'action' => 'suspend',
            'note' => 'Nope',
        ])->assertRedirect();

        $this->assertTrue($b->fresh()->isActive());
    }

    public function test_guest_and_unprivileged_staff_cannot_review_sellers(): void
    {
        $this->seed();
        $seller = $this->pendingSeller('gate@example.com');

        $this->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', [
            'action' => 'approve',
        ])->assertRedirect();

        $this->assertTrue($seller->fresh()->isPending());

        $staff = Admin::factory()->create([
            'name' => 'Clerk',
            'email' => 'clerk@example.com',
        ]);
        $this->actingAs($staff, 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', [
                'action' => 'approve',
            ])->assertForbidden();

        $this->assertTrue($seller->fresh()->isPending());
    }
}
