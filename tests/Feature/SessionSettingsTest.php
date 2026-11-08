<?php

namespace Tests\Feature;

use App\Domain\Settings\SessionLifetimes;
use App\Domain\Settings\ShopSetting;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_follow_session_config(): void
    {
        $this->assertSame(
            min((int) config('session.admin_lifetime'), SessionLifetimes::ADMIN_MAX_MINUTES),
            SessionLifetimes::adminMinutes(),
        );
        $this->assertSame(
            min((int) config('session.customer_lifetime'), SessionLifetimes::CUSTOMER_MAX_MINUTES),
            SessionLifetimes::customerMinutes(),
        );
    }

    public function test_idle_timeouts_cannot_exceed_the_public_caps(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $this->put('/admin/shop/sessions', [
            'admin_minutes' => 50000,
            'customer_minutes' => 50000,
        ])->assertSessionHasErrors(['admin_minutes', 'customer_minutes']);
    }

    public function test_admin_can_save_separate_session_lengths(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $this->put('/admin/shop/sessions', [
            'admin_minutes' => 90,
            'customer_minutes' => 30,
        ])->assertRedirect();

        $this->assertSame(90, SessionLifetimes::adminMinutes());
        $this->assertSame(30, SessionLifetimes::customerMinutes());
        $this->assertSame(90, (int) ShopSetting::get('session.admin_lifetime'));
        $this->assertSame(30, (int) ShopSetting::get('session.customer_lifetime'));
    }
}
