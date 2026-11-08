<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCustomerSubscriptionsIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_subscriptions_index_includes_renewal_scheduler_help(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->get('/admin/customer-subscriptions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/Subscriptions/Customers', false)
                ->where(
                    'renewalScheduler.cron',
                    '* * * * * cd /path/to/wentocart && php artisan schedule:run >> /dev/null 2>&1',
                )
                ->where('renewalScheduler.verify', [
                    'php artisan schedule:list',
                    'php artisan wento:subscriptions-tick',
                ]));
    }

    public function test_renewal_scheduler_help_does_not_expose_local_install_path(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->get('/admin/customer-subscriptions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('renewalScheduler.cron', fn (string $cron) => ! str_contains($cron, 'localhost')
                    && ! str_contains($cron, '/opt/lampp/')
                    && str_contains($cron, '/path/to/wentocart')));
    }
}
