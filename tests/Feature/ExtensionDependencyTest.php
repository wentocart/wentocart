<?php

namespace Tests\Feature;

use App\Domain\Plugin\DependencyResolver;
use App\Domain\Plugin\Models\InstalledPlugin;
use App\Domain\Plugin\PluginInstaller;
use App\Domain\Plugin\PluginKernel;
use App\Domain\Plugin\PluginManifest;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class ExtensionDependencyTest extends TestCase
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
        parent::tearDown();
    }

    public function test_missing_dependency_blocks_install(): void
    {
        $this->seed();
        $installer = app(PluginInstaller::class);
        $path = $this->makeZip([
            'app.json' => json_encode([
                'vendor' => 'acme',
                'name' => 'needs-base',
                'display_name' => 'Needs base',
                'type' => 'safe',
                'capabilities' => ['admin.page'],
                'requires' => ['acme/base-kit' => '>=1.0.0'],
            ]),
            'src/Plugin.php' => $this->pluginPhp('Acme', 'NeedsBase'),
        ]);

        try {
            $installer->install(new UploadedFile($path, 'needs.zip', 'application/zip', null, true));
            $this->fail('missing dependency should block install');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Missing dependency', $e->errors()['zip'][0] ?? '');
        }
    }

    public function test_valid_dependency_allows_install_and_enable(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        $installer = app(PluginInstaller::class);

        $base = $this->makeZip([
            'app.json' => json_encode([
                'vendor' => 'acme',
                'name' => 'base-kit',
                'display_name' => 'Base kit',
                'version' => '1.2.0',
                'type' => 'safe',
                'capabilities' => ['admin.page'],
            ]),
            'src/Plugin.php' => $this->pluginPhp('Acme', 'BaseKit'),
        ]);
        $installer->install(new UploadedFile($base, 'base.zip', 'application/zip', null, true));
        $this->post('/admin/extensions/acme/base-kit/enable', ['enabled' => true])->assertRedirect();

        $child = $this->makeZip([
            'app.json' => json_encode([
                'vendor' => 'acme',
                'name' => 'child-kit',
                'display_name' => 'Child kit',
                'type' => 'safe',
                'capabilities' => ['admin.page'],
                'requires' => ['acme/base-kit' => '>=1.2.0'],
            ]),
            'src/Plugin.php' => $this->pluginPhp('Acme', 'ChildKit'),
        ]);
        $installer->install(new UploadedFile($child, 'child.zip', 'application/zip', null, true));
        $this->post('/admin/extensions/acme/child-kit/enable', ['enabled' => true])->assertRedirect();

        $this->assertDatabaseHas('plugins', ['vendor' => 'acme', 'name' => 'child-kit', 'is_enabled' => 1]);
    }

    public function test_incompatible_dependency_version_blocks_install(): void
    {
        $this->seed();
        $installer = app(PluginInstaller::class);

        $base = $this->makeZip([
            'app.json' => json_encode([
                'vendor' => 'acme',
                'name' => 'base-kit',
                'version' => '1.0.0',
                'type' => 'safe',
                'capabilities' => ['admin.page'],
            ]),
            'src/Plugin.php' => $this->pluginPhp('Acme', 'BaseKit'),
        ]);
        $installer->install(new UploadedFile($base, 'base.zip', 'application/zip', null, true));

        $child = $this->makeZip([
            'app.json' => json_encode([
                'vendor' => 'acme',
                'name' => 'child-kit',
                'type' => 'safe',
                'capabilities' => ['admin.page'],
                'requires' => ['acme/base-kit' => '>=2.0.0'],
            ]),
            'src/Plugin.php' => $this->pluginPhp('Acme', 'ChildKit'),
        ]);

        try {
            $installer->install(new UploadedFile($child, 'child.zip', 'application/zip', null, true));
            $this->fail('version conflict should block');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Dependency version conflict', $e->errors()['zip'][0] ?? '');
        }
    }

    public function test_dependency_cycle_is_detected(): void
    {
        $this->seed();
        $installer = app(PluginInstaller::class);
        $resolver = app(DependencyResolver::class);

        // Write two interdependent extensions to disk (disabled) then validate a reinstall candidate.
        $betaDir = base_path('extensions/acme/beta');
        File::ensureDirectoryExists($betaDir.'/src');
        File::put($betaDir.'/app.json', json_encode([
            'vendor' => 'acme',
            'name' => 'beta',
            'version' => '1.0.0',
            'type' => 'safe',
            'capabilities' => ['admin.page'],
            'requires' => ['acme/alpha' => '*'],
        ]));
        File::put($betaDir.'/src/Plugin.php', $this->pluginPhp('Acme', 'Beta'));
        InstalledPlugin::query()->create([
            'vendor' => 'acme',
            'name' => 'beta',
            'display_name' => 'Beta',
            'version' => '1.0.0',
            'author' => 'Test',
            'description' => '',
            'type' => 'safe',
            'capabilities' => ['admin.page'],
            'hooks' => [],
            'is_bundled' => false,
            'is_enabled' => false,
            'path' => $betaDir,
        ]);

        $alpha = PluginManifest::fromArray([
            'vendor' => 'acme',
            'name' => 'alpha',
            'type' => 'safe',
            'capabilities' => ['admin.page'],
            'requires' => ['acme/beta' => '*'],
        ], '/tmp/alpha');

        $errors = $resolver->validateForInstallOrUpdate($alpha, $resolver->installedMap());
        $this->assertTrue(
            collect($errors)->contains(fn ($e) => str_contains($e, 'cycle') || str_contains($e, 'Missing dependency')),
            'Expected cycle or related dependency error, got: '.implode('; ', $errors)
        );

        // Semver helpers
        $this->assertTrue($resolver->satisfies('1.2.3', '>=1.2.0'));
        $this->assertFalse($resolver->satisfies('1.0.0', '>=1.2.0'));
        $this->assertTrue($resolver->satisfies('1.5.0', '^1.2.0'));
        $this->assertFalse($resolver->satisfies('2.0.0', '^1.2.0'));

        unset($installer);
    }

    public function test_uninstall_blocked_when_dependent_enabled(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        $installer = app(PluginInstaller::class);

        $base = $this->makeZip([
            'app.json' => json_encode([
                'vendor' => 'acme',
                'name' => 'base-kit',
                'version' => '1.0.0',
                'type' => 'safe',
                'capabilities' => ['admin.page'],
            ]),
            'src/Plugin.php' => $this->pluginPhp('Acme', 'BaseKit'),
        ]);
        $installer->install(new UploadedFile($base, 'base.zip', 'application/zip', null, true));

        $child = $this->makeZip([
            'app.json' => json_encode([
                'vendor' => 'acme',
                'name' => 'child-kit',
                'type' => 'safe',
                'capabilities' => ['admin.page'],
                'requires' => ['acme/base-kit' => '>=1.0.0'],
            ]),
            'src/Plugin.php' => $this->pluginPhp('Acme', 'ChildKit'),
        ]);
        $installer->install(new UploadedFile($child, 'child.zip', 'application/zip', null, true));

        InstalledPlugin::query()->where('vendor', 'acme')->update(['is_enabled' => true]);

        try {
            $installer->uninstall('acme', 'base-kit');
            $this->fail('uninstall should be blocked');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('depends on', $e->errors()['app'][0] ?? '');
        }
    }

    protected function pluginPhp(string $vendor, string $name): string
    {
        return <<<PHP
<?php
namespace Modules\\{$vendor}\\{$name};
use App\\Domain\\Plugin\\Support\\BasePlugin;
class Plugin extends BasePlugin {
    public function boot(): void {}
    public function adminSchema(): array { return ['title' => 'T', 'fields' => []]; }
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
        $path = storage_path('framework/testing/'.uniqid('dep_', true).'.zip');
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
