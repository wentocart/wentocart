<?php

namespace Tests\Feature;

use App\Domain\Plugin\Models\InstalledPlugin;
use App\Domain\Plugin\PluginInstaller;
use App\Domain\Plugin\PluginKernel;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class ExtensionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PluginKernel::reset();
    }

    protected function tearDown(): void
    {
        PluginKernel::reset();
        File::deleteDirectory(base_path('extensions/acme'));
        File::deleteDirectory(base_path('extensions/wento'));
        File::deleteDirectory(storage_path('app/plugin-backups/acme'));
        File::deleteDirectory(storage_path('app/plugin-backups/wento'));
        File::deleteDirectory(storage_path('app/extension-stages/acme'));
        parent::tearDown();
    }

    public function test_extensions_route_alias_lists_installed_extension(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        $this->installFixtureExtension('wento', 'sample-notice');

        $this->get('/admin/extensions')
            ->assertOk()
            ->assertSee('Sample notice');
    }

    public function test_incompatible_platform_version_blocks_install(): void
    {
        $this->seed();
        $installer = app(PluginInstaller::class);
        $path = $this->makeZip([
            'app.json' => json_encode([
                'vendor' => 'acme',
                'name' => 'future-only',
                'type' => 'safe',
                'capabilities' => ['admin.page'],
                'min_app_version' => '99.0.0',
            ]),
            'src/Plugin.php' => $this->pluginPhp('Acme', 'FutureOnly'),
        ]);

        try {
            $installer->install(new UploadedFile($path, 'future.zip', 'application/zip', null, true));
            $this->fail('incompatible platform should block');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Unsupported platform version', $e->errors()['zip'][0] ?? '');
        }
    }

    public function test_newer_zip_is_staged_then_upgrade_keeps_settings(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        $installer = app(PluginInstaller::class);

        $v1 = $this->makeZip([
            'app.json' => json_encode([
                'vendor' => 'acme',
                'name' => 'lifecycle',
                'display_name' => 'Lifecycle',
                'version' => '1.0.0',
                'type' => 'safe',
                'capabilities' => ['admin.page', 'settings'],
            ]),
            'src/Plugin.php' => $this->pluginPhp('Acme', 'Lifecycle', 'v1'),
        ]);
        $installer->install(new UploadedFile($v1, 'v1.zip', 'application/zip', null, true));
        $row = InstalledPlugin::query()->where('vendor', 'acme')->where('name', 'lifecycle')->firstOrFail();
        $row->settings()->create(['key' => 'note', 'value' => json_encode('keep-me')]);
        $this->assertTrue((bool) $row->fresh()->is_enabled);

        $v2 = $this->makeZip([
            'app.json' => json_encode([
                'vendor' => 'acme',
                'name' => 'lifecycle',
                'display_name' => 'Lifecycle',
                'version' => '2.0.0',
                'type' => 'safe',
                'capabilities' => ['admin.page', 'settings'],
                'setup_steps' => ['Confirm settings'],
            ]),
            'src/Plugin.php' => $this->pluginPhp('Acme', 'Lifecycle', 'v2'),
        ]);

        $staged = $installer->receiveUpload(new UploadedFile($v2, 'v2.zip', 'application/zip', null, true));
        $this->assertSame('staged', $staged['action']);
        $this->assertSame('2.0.0', $staged['pending_version']);
        $this->assertSame('1.0.0', InstalledPlugin::query()->where('vendor', 'acme')->where('name', 'lifecycle')->value('version'));

        $this->post('/admin/extensions/acme/lifecycle/upgrade')->assertRedirect();

        $this->assertDatabaseHas('plugins', [
            'vendor' => 'acme',
            'name' => 'lifecycle',
            'version' => '2.0.0',
            'is_enabled' => 1,
        ]);
        $this->assertDatabaseHas('plugin_settings', [
            'plugin_id' => $row->id,
            'key' => 'note',
        ]);
        $this->assertStringContainsString('v2', File::get(base_path('extensions/acme/lifecycle/src/Plugin.php')));
        $this->assertNull($installer->pendingStage('acme', 'lifecycle'));
    }

    public function test_same_version_upload_is_noop_success(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        $installer = app(PluginInstaller::class);

        $v1 = $this->makeZip([
            'app.json' => json_encode([
                'vendor' => 'acme',
                'name' => 'lifecycle',
                'display_name' => 'Lifecycle',
                'version' => '1.0.0',
                'type' => 'safe',
                'capabilities' => ['admin.page'],
            ]),
            'src/Plugin.php' => $this->pluginPhp('Acme', 'Lifecycle'),
        ]);
        $installer->install(new UploadedFile($v1, 'v1.zip', 'application/zip', null, true));

        $again = $this->makeZip([
            'app.json' => json_encode([
                'vendor' => 'acme',
                'name' => 'lifecycle',
                'display_name' => 'Lifecycle',
                'version' => '1.0.0',
                'type' => 'safe',
                'capabilities' => ['admin.page'],
            ]),
            'src/Plugin.php' => $this->pluginPhp('Acme', 'Lifecycle'),
        ]);
        $result = $installer->receiveUpload(new UploadedFile($again, 'again.zip', 'application/zip', null, true));
        $this->assertSame('noop', $result['action']);
        $this->assertNull($installer->pendingStage('acme', 'lifecycle'));
    }

    public function test_reset_clears_settings_and_reinstalls_enabled(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        $installer = app(PluginInstaller::class);

        $v1 = $this->makeZip([
            'app.json' => json_encode([
                'vendor' => 'acme',
                'name' => 'lifecycle',
                'display_name' => 'Lifecycle',
                'version' => '1.0.0',
                'type' => 'safe',
                'capabilities' => ['admin.page', 'settings'],
            ]),
            'src/Plugin.php' => $this->pluginPhp('Acme', 'Lifecycle', 'v1'),
        ]);
        $installer->install(new UploadedFile($v1, 'v1.zip', 'application/zip', null, true));
        $row = InstalledPlugin::query()->where('vendor', 'acme')->where('name', 'lifecycle')->firstOrFail();
        $row->settings()->create(['key' => 'note', 'value' => json_encode('wipe-me')]);

        $this->post('/admin/extensions/acme/lifecycle/reset')->assertRedirect();

        $fresh = InstalledPlugin::query()->where('vendor', 'acme')->where('name', 'lifecycle')->firstOrFail();
        $this->assertTrue((bool) $fresh->is_enabled);
        $this->assertSame(0, $fresh->settings()->count());
    }

    public function test_upgrade_without_stage_is_rejected(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        $installer = app(PluginInstaller::class);

        $v1 = $this->makeZip([
            'app.json' => json_encode([
                'vendor' => 'acme',
                'name' => 'lifecycle',
                'version' => '1.0.0',
                'type' => 'safe',
                'capabilities' => ['admin.page'],
            ]),
            'src/Plugin.php' => $this->pluginPhp('Acme', 'Lifecycle'),
        ]);
        $installer->install(new UploadedFile($v1, 'v1.zip', 'application/zip', null, true));

        $this->post('/admin/extensions/acme/lifecycle/upgrade')->assertSessionHasErrors('app');
    }

    public function test_uninstall_keeps_files_delete_removes_disk(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        $installer = app(PluginInstaller::class);

        $zip = $this->makeZip([
            'app.json' => json_encode([
                'vendor' => 'acme',
                'name' => 'lifecycle',
                'display_name' => 'Lifecycle',
                'version' => '1.0.0',
                'type' => 'safe',
                'capabilities' => ['admin.page'],
            ]),
            'src/Plugin.php' => $this->pluginPhp('Acme', 'Lifecycle'),
        ]);
        $installer->install(new UploadedFile($zip, 'v1.zip', 'application/zip', null, true));
        $dir = base_path('extensions/acme/lifecycle');
        $this->assertDirectoryExists($dir);

        $this->delete('/admin/extensions/acme/lifecycle')->assertRedirect('/admin/extensions');
        $this->assertDatabaseMissing('plugins', ['vendor' => 'acme', 'name' => 'lifecycle']);
        $this->assertDirectoryExists($dir);

        $this->delete('/admin/extensions/acme/lifecycle/files')->assertRedirect('/admin/extensions');
        $this->assertDirectoryDoesNotExist($dir);
        $this->assertDirectoryDoesNotExist(base_path('extensions/acme'));
    }

    protected function pluginPhp(string $vendor, string $name, string $marker = 'ok'): string
    {
        $markerExport = var_export($marker, true);

        return <<<PHP
<?php
namespace Modules\\{$vendor}\\{$name};
use App\\Domain\\Plugin\\Support\\BasePlugin;
class Plugin extends BasePlugin {
    public function boot(): void {}
    public function adminSchema(): array {
        return ['title' => {$markerExport}, 'fields' => [['name' => 'note', 'type' => 'text', 'label' => 'Note']]];
    }
}
PHP;
    }

    /**
     * @param  array<string, string>  $files
     */
    protected function makeZip(array $files): string
    {
        if (! isset($files['README.md'])) {
            $files['README.md'] = "# Test extension\n";
        }
        $path = storage_path('framework/testing/'.uniqid('life_', true).'.zip');
        @mkdir(dirname($path), 0777, true);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE) === true);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }
}
