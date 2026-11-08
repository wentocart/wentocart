<?php

namespace Tests\Feature;

use App\Domain\Ops\JumpShortcut;
use App\Domain\Settings\ShopSetting;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AdminJumpPaletteTest extends TestCase
{
    use RefreshDatabase;

    public function test_inertia_shares_resolved_jump_shortcut(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $this->get('/admin/account')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('jump.shortcut', 'ctrl+f')
                ->where('jump.source', 'default')
                ->where('jump.label', 'Ctrl+F'));

        JumpShortcut::putShop('alt+j');
        $this->get('/admin/account')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('jump.shortcut', 'alt+j')
                ->where('jump.source', 'shop'));

        $admin->jump_shortcut = 'ctrl+p';
        $admin->save();
        $this->get('/admin/account')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('jump.shortcut', 'ctrl+p')
                ->where('jump.source', 'staff'));
    }

    public function test_account_can_set_and_clear_staff_shortcut(): void
    {
        Storage::fake('public');
        $this->seed();
        $admin = Admin::query()->first();
        $admin->avatar_path = 'admins/1/avatar.jpg';
        $admin->save();
        $this->actingAs($admin, 'admin');

        $this->post('/admin/account', [
            '_method' => 'put',
            'first_name' => $admin->first_name ?: 'Ada',
            'last_name' => $admin->last_name ?: 'Admin',
            'email' => $admin->email,
            'job_title' => $admin->job_title,
            'phone' => $admin->phone,
            'note' => $admin->note,
            'jump_shortcut' => 'ctrl+h',
        ])->assertRedirect('/admin/account');

        $this->assertSame('ctrl+h', $admin->fresh()->jump_shortcut);

        $this->post('/admin/account', [
            '_method' => 'put',
            'first_name' => $admin->first_name ?: 'Ada',
            'last_name' => $admin->last_name ?: 'Admin',
            'email' => $admin->email,
            'jump_shortcut_clear' => 1,
        ])->assertRedirect('/admin/account');

        $this->assertNull($admin->fresh()->jump_shortcut);
    }

    public function test_shop_jump_setting_and_palette_actions(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $this->put('/admin/store/search-shortcut', ['shortcut' => 'alt+k'])
            ->assertRedirect();
        $this->assertSame('alt+k', ShopSetting::get(JumpShortcut::SHOP_KEY));

        $this->get('/admin/general')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/General', false)
                ->where('searchShortcut.shortcut', 'alt+k'));

        $this->get('/admin/advanced?tab=jump')
            ->assertRedirect('/admin/general');

        $this->getJson('/admin/command-palette?q=Performance')
            ->assertOk()
            ->assertJsonFragment(['href' => '/admin/performance'])
            ->assertJsonStructure(['pages', 'records', 'actions']);
    }
}
