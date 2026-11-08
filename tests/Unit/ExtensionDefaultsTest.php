<?php

namespace Tests\Unit;

use App\Domain\Plugin\ExtensionDefaults;
use App\Domain\Plugin\Models\InstalledPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExtensionDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_applies_enabled_and_installed_from_config(): void
    {
        $id = is_dir(base_path('extensions/weblytic/wtsstripe'))
            ? 'weblytic/wtsstripe'
            : 'weblytic/mainmenu';

        config([
            'wento.apps.defaults' => [
                $id => 'installed',
                'learn/admin-menus' => 'available',
            ],
        ]);

        app(ExtensionDefaults::class)->apply();

        [$vendor, $name] = explode('/', $id, 2);
        $row = InstalledPlugin::query()->where('vendor', $vendor)->where('name', $name)->first();
        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->is_enabled);

        $this->assertFalse(
            InstalledPlugin::query()->where('vendor', 'learn')->where('name', 'admin-menus')->exists()
        );
    }

    public function test_rehomes_legacy_wento_main_menu_row(): void
    {
        InstalledPlugin::query()->create([
            'vendor' => 'wento',
            'name' => 'main-menu',
            'display_name' => 'Main menu',
            'version' => '1.0.0',
            'author' => 'Test',
            'description' => '',
            'type' => 'safe',
            'capabilities' => ['admin.menu'],
            'hooks' => [],
            'is_bundled' => true,
            'is_enabled' => true,
            'path' => base_path('extensions/wento/main-menu'),
        ]);

        app(ExtensionDefaults::class)->rehomeLegacy();

        $this->assertTrue(
            InstalledPlugin::query()->where('vendor', 'weblytic')->where('name', 'mainmenu')->exists()
        );
        $this->assertFalse(
            InstalledPlugin::query()->where('vendor', 'wento')->where('name', 'main-menu')->exists()
        );
    }
}
