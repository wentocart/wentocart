<?php

namespace Tests\Feature;

use App\Domain\Settings\MailEvents;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_sign_in_and_open_catalog(): void
    {
        $this->seed();

        $this->post('/admin/login', [
            'email' => 'admin@wentocart.test',
            'password' => 'password',
        ])->assertRedirect('/admin/dashboards');

        $this->get('/admin/products')->assertOk();
        $this->assertAuthenticatedAs(Admin::query()->first(), 'admin');
    }

    public function test_admin_chrome_shares_storefront_home_url(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $this->actingAs($admin, 'admin')
            ->get('/admin/products')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('shop.url', fn ($url) => is_string($url) && str_ends_with($url, '/')));
    }

    public function test_login_is_rate_limited(): void
    {
        $this->seed();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/admin/login', ['email' => 'nobody@test.com', 'password' => 'wrong']);
        }

        $this->from('/admin/login')
            ->post('/admin/login', ['email' => 'nobody@test.com', 'password' => 'wrong'])
            ->assertRedirect('/admin/login')
            ->assertSessionHasErrors('email');
    }

    public function test_forgot_password_page_renders(): void
    {
        $this->seed();

        $this->get('/admin/forgot-password')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/ForgotPassword'));
    }

    public function test_forgot_password_does_not_reveal_unknown_emails(): void
    {
        $this->seed();
        Mail::fake();

        $this->from('/admin/forgot-password')
            ->post('/admin/forgot-password', ['email' => 'nobody@test.com'])
            ->assertRedirect('/admin/forgot-password');

        Mail::assertNothingSent();
    }

    public function test_admin_can_reset_password_from_email_link(): void
    {
        $this->seed();
        MailEvents::ensure();
        Mail::fake();
        $admin = Admin::query()->firstOrFail();

        $this->from('/admin/forgot-password')
            ->post('/admin/forgot-password', ['email' => $admin->email])
            ->assertRedirect('/admin/forgot-password');

        Mail::assertSentCount(1);

        $token = Password::broker('admins')->createToken($admin);

        $this->post('/admin/reset-password', [
            'token' => $token,
            'email' => $admin->email,
            'password' => 'New-password1',
            'password_confirmation' => 'New-password1',
        ])->assertRedirect('/admin/login');

        $this->assertTrue(Hash::check('New-password1', $admin->fresh()->password));
    }
}
