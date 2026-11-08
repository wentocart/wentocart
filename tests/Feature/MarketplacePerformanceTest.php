<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Marketplace\Services\MarketplaceStatsService;
use App\Domain\Marketplace\Services\SellerEarningAvailabilityService;
use App\Domain\Marketplace\Services\SellerReviewService;
use App\Models\Admin;
use Illuminate\Support\Facades\DB;

/**
 * Phase 21 — marketplace performance and query-count regression tests.
 */
class MarketplacePerformanceTest extends MarketplaceTestCase
{

    /** @var list<string> */
    protected array $queries = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->queries = [];
        DB::listen(function ($query) {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'sqlite_master') || str_contains($sql, 'pragma')) {
                return;
            }
            $this->queries[] = $query->sql;
        });
    }

    protected function queryCount(): int
    {
        return count($this->queries);
    }

    /** Count queries excluding multistore/i18n bootstrap tables unrelated to marketplace. */
    protected function marketplaceQueryCount(): int
    {
        $ignore = [
            'store_groups', 'stores', 'shop_urls', 'store_sharing_policies',
            'languages', 'currencies', 'cache', 'sessions', 'jobs', 'migrations',
        ];

        return collect($this->queries)->filter(function (string $sql) use ($ignore) {
            $lower = strtolower($sql);
            foreach ($ignore as $table) {
                if (str_contains($lower, '"'.$table.'"') || str_contains($lower, '`'.$table.'`')) {
                    return false;
                }
            }

            return true;
        })->count();
    }

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
            'name' => 'Perf User',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertOk();

        return Customer::query()->where('email', $email)->firstOrFail();
    }

    /** @return array{0: Seller, 1: Customer} */
    protected function approvedSeller(string $email, string $slug): array
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

        return [$seller->fresh(), $owner];
    }

    public function test_seller_portal_dashboard_stays_within_query_budget(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [, $owner] = $this->approvedSeller('perf-a@example.com', 'perf-a');
        $this->actingAs($owner, 'customer');
        $this->queries = [];

        $this->getJson('/api/v1/account/seller/dashboard')->assertOk();

        $this->assertLessThanOrEqual(12, $this->marketplaceQueryCount(), implode("\n", array_slice($this->queries, 0, 20)));
    }

    public function test_wallet_balances_uses_bounded_aggregate_queries(): void
    {
        $this->seed();
        [, $owner] = $this->approvedSeller('perf-b@example.com', 'perf-b');
        $seller = Seller::query()->where('customer_id', $owner->id)->firstOrFail();
        $this->queries = [];

        app(SellerEarningAvailabilityService::class)->walletBalances($seller);

        $this->assertLessThanOrEqual(6, $this->queryCount());
    }

    public function test_public_review_summary_uses_aggregate_query(): void
    {
        $this->seed();
        [$seller] = $this->approvedSeller('perf-c@example.com', 'perf-c');
        $this->queries = [];

        app(SellerReviewService::class)->publicSummary($seller);

        $this->assertLessThanOrEqual(2, $this->queryCount());
    }

    public function test_marketplace_stats_service_consolidates_admin_counts(): void
    {
        $this->seed();
        $this->queries = [];

        app(MarketplaceStatsService::class)->adminDashboardCounts();

        $this->assertLessThanOrEqual(8, $this->queryCount());
    }

    public function test_public_seller_shop_page_stays_within_query_budget(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, $owner] = $this->approvedSeller('perf-d@example.com', 'perf-d');
        $this->actingAs($owner, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Perf Mug',
            'sku' => 'perf-mug',
            'price' => 20,
            'stock_qty' => 5,
            'is_enabled' => true,
        ])->assertCreated();
        $product = Product::query()->where('seller_id', $seller->id)->firstOrFail();
        SellerProduct::query()->where('product_id', $product->id)->update([
            'approval_status' => SellerProduct::STATUS_APPROVED,
            'is_enabled' => true,
        ]);
        $product->update(['is_enabled' => true, 'available_for_order' => true]);

        $this->postJson('/api/v1/auth/logout');
        $this->queries = [];

        $this->getJson('/api/v1/catalog/seller/'.$seller->shop_slug)->assertOk();

        $this->assertLessThanOrEqual(15, $this->marketplaceQueryCount());
    }
}
