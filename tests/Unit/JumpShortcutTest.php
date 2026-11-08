<?php

namespace Tests\Unit;

use App\Domain\Ops\JumpShortcut;
use App\Domain\Settings\ShopSetting;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JumpShortcutTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalize_and_reject_reserved(): void
    {
        $this->assertSame('ctrl+f', JumpShortcut::normalize('Ctrl + F'));
        $this->assertSame('meta+shift+p', JumpShortcut::normalize('cmd+Shift+p'));
        $this->assertTrue(JumpShortcut::isValid('ctrl+f'));
        $this->assertFalse(JumpShortcut::isValid('ctrl+l'));
        $this->assertNull(JumpShortcut::normalize('f'));
        $this->assertSame('Ctrl+F', JumpShortcut::label('ctrl+f'));
    }

    public function test_resolve_staff_over_shop_over_default(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $this->assertSame('default', JumpShortcut::resolve($admin)['source']);
        $this->assertSame('ctrl+f', JumpShortcut::resolve($admin)['shortcut']);

        JumpShortcut::putShop('alt+j');
        $this->assertSame('shop', JumpShortcut::resolve($admin->fresh())['source']);
        $this->assertSame('alt+j', JumpShortcut::resolve($admin->fresh())['shortcut']);

        $admin->jump_shortcut = 'ctrl+g';
        $admin->save();
        $resolved = JumpShortcut::resolve($admin->fresh());
        $this->assertSame('staff', $resolved['source']);
        $this->assertSame('ctrl+g', $resolved['shortcut']);

        $admin->jump_shortcut = null;
        $admin->save();
        ShopSetting::put(JumpShortcut::SHOP_KEY, '');
        $this->assertSame('default', JumpShortcut::resolve($admin->fresh())['source']);
    }
}
