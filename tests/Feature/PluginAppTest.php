<?php

namespace Tests\Feature;

use App\Domain\Plugin\PluginInstaller;
use App\Domain\Plugin\PluginKernel;
use App\Domain\Plugin\PluginRegistry;
use App\Models\Admin;
use App\Support\Hooks\HookManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class PluginAppTest extends TestCase
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
        File::deleteDirectory(base_path('extensions/wento'));
        File::deleteDirectory(base_path('extensions/acme'));
        File::deleteDirectory(storage_path('app/plugin-backups/wento'));
        File::deleteDirectory(storage_path('app/plugin-backups/acme'));
        parent::tearDown();
    }

    public function test_admin_apps_lists_uploaded_sample(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        $this->installFixtureExtension('wento', 'sample-notice');

        $this->get('/admin/apps')
            ->assertOk()
            ->assertSee('Sample notice');
    }

    public function test_enabling_sample_notice_mutates_storefront_header_hook(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        $this->installFixtureExtension('wento', 'sample-notice');

        $this->post('/admin/apps/wento/sample-notice/enable', ['enabled' => true])
            ->assertRedirect();

        $announcement = $this->get('/api/v1/store')->assertOk()->json('shop.announcement');
        $this->assertIsString($announcement);
        $this->assertStringContainsString('This notice comes from a Safe app, not from core PHP.', $announcement);
    }

    public function test_eval_zip_is_rejected(): void
    {
        $this->seed();
        $installer = app(PluginInstaller::class);
        $path = $this->makeZip([
            'app.json' => json_encode([
                'vendor' => 'evil',
                'name' => 'eval-app',
                'display_name' => 'Eval',
                'type' => 'safe',
                'capabilities' => ['hook.listen'],
                'hooks' => ['storefront.header.notice'],
            ]),
            'src/Plugin.php' => "<?php\nnamespace Modules\\Evil\\EvalApp;\nuse App\\Domain\\Plugin\\Support\\BasePlugin;\nclass Plugin extends BasePlugin { public function boot(): void { eval('1;'); } }\n",
        ]);

        try {
            $installer->install(new UploadedFile($path, 'evil.zip', 'application/zip', null, true));
            $this->fail('eval zip should be rejected');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    public function test_javascript_and_certified_zips_are_rejected(): void
    {
        $this->seed();
        $installer = app(PluginInstaller::class);

        $jsZip = $this->makeZip([
            'app.json' => json_encode([
                'vendor' => 'acme',
                'name' => 'with-js',
                'type' => 'safe',
                'capabilities' => ['admin.page'],
            ]),
            'src/Plugin.php' => $this->validPluginPhp('Acme', 'WithJs'),
            'assets/app.js' => 'alert(1)',
        ]);
        try {
            $installer->install(new UploadedFile($jsZip, 'js.zip', 'application/zip', null, true));
            $this->fail('js zip should be rejected');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }

        $certifiedTables = $this->makeZip([
            'app.json' => json_encode([
                'vendor' => 'acme',
                'name' => 'tables-only',
                'type' => 'certified',
                'capabilities' => ['schema.tables'],
            ]),
            'src/Plugin.php' => $this->validPluginPhp('Acme', 'TablesOnly'),
        ]);
        try {
            $installer->install(new UploadedFile($certifiedTables, 'tables.zip', 'application/zip', null, true));
            $this->fail('schema.tables certified zip should be rejected without signing');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }

        $pay = $this->makeZip([
            'app.json' => json_encode([
                'vendor' => 'acme',
                'name' => 'stripe-like',
                'type' => 'certified',
                'category' => 'payment',
                'capabilities' => ['payment.driver', 'settings', 'admin.page'],
            ]),
            'src/Plugin.php' => $this->validPluginPhp('Acme', 'StripeLike'),
        ]);
        $manifest = $installer->install(new UploadedFile($pay, 'pay.zip', 'application/zip', null, true));
        $this->assertSame('acme/stripe-like', $manifest->id());
    }

    public function test_shop_signed_schema_tables_zip_installs(): void
    {
        $this->seed();
        $raw = [
            'vendor' => 'acme',
            'name' => 'tables-signed',
            'type' => 'certified',
            'capabilities' => ['schema.tables'],
        ];
        $unsigned = \App\Domain\Plugin\PluginManifest::fromArray($raw, sys_get_temp_dir(), false);
        $raw['signature'] = \App\Domain\Plugin\PluginSigner::sign($unsigned);
        $zip = $this->makeZip([
            'app.json' => json_encode($raw),
            'src/Plugin.php' => $this->validPluginPhp('Acme', 'TablesSigned'),
        ]);
        $manifest = app(PluginInstaller::class)->install(new UploadedFile($zip, 'signed.zip', 'application/zip', null, true));
        $this->assertSame('acme/tables-signed', $manifest->id());
    }

    public function test_safe_zip_installs_disabled_until_enable(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        $path = $this->makeZip([
            'app.json' => json_encode([
                'vendor' => 'acme',
                'name' => 'hello-banner',
                'display_name' => 'Hello banner',
                'type' => 'safe',
                'capabilities' => ['admin.page', 'settings', 'hook.listen'],
                'hooks' => ['storefront.header.notice'],
            ]),
            'src/Plugin.php' => $this->validPluginPhp('Acme', 'HelloBanner', 'Hello from zip'),
        ]);

        $this->post('/admin/apps', ['zip' => new UploadedFile($path, 'hello-banner.zip', 'application/zip', null, true)])
            ->assertRedirect();

        $this->assertDatabaseHas('plugins', [
            'vendor' => 'acme',
            'name' => 'hello-banner',
            'is_enabled' => 0,
        ]);

        $this->post('/admin/apps/acme/hello-banner/enable', ['enabled' => true])->assertRedirect();

        HookManager::flush();
        PluginKernel::reset();
        app(PluginKernel::class)->boot();
        $payload = HookManager::fire('storefront.header.notice', ['notice' => '']);
        $this->assertSame('Hello from zip', $payload['notice']);
    }

    public function test_make_app_scaffolds_under_extensions(): void
    {
        $dir = base_path('extensions/acme/docs-demo');
        File::deleteDirectory($dir);

        $code = Artisan::call('wento:make-app', ['id' => 'acme/docs-demo', '--title' => 'Docs demo']);
        $this->assertSame(0, $code);
        $this->assertFileExists($dir.'/app.json');
        $this->assertFileExists($dir.'/README.md');
        $this->assertFileExists($dir.'/src/Plugin.php');

        $manifest = app(PluginRegistry::class)->read($dir, false);
        $this->assertSame('acme/docs-demo', $manifest->id());
        $this->assertSame('Modules\\Acme\\DocsDemo\\Plugin', $manifest->className());

        File::deleteDirectory($dir);
        File::deleteDirectory(base_path('extensions/acme'));
    }

    protected function validPluginPhp(string $vendor, string $name, string $message = 'Hi'): string
    {
        $messageExport = var_export($message, true);

        return <<<PHP
<?php

namespace Modules\\{$vendor}\\{$name};

use App\\Domain\\Plugin\\Support\\BasePlugin;

class Plugin extends BasePlugin
{
    public function boot(): void
    {
        \$this->hooks()->listen('storefront.header.notice', function (array \$payload) {
            \$payload['notice'] = {$messageExport};
            return \$payload;
        });
    }

    public function adminSchema(): array
    {
        return ['title' => 'Test', 'fields' => []];
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
        $path = storage_path('framework/testing/'.uniqid('app_', true).'.zip');
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
