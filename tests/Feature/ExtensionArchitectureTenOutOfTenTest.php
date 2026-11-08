<?php

namespace Tests\Feature;

use App\Domain\Plugin\Models\InstalledPlugin;
use App\Domain\Plugin\PluginKernel;
use App\Domain\Plugin\SecurityScanner;
use App\Support\Hooks\HookManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ExtensionArchitectureTenOutOfTenTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $testExtDir = base_path('extensions/testacme');
        if (File::isDirectory($testExtDir)) {
            File::deleteDirectory($testExtDir);
        }

        HookManager::flush();
        parent::tearDown();
    }

    public function test_circuit_breaker_prevents_extension_hook_exception_from_crashing_core(): void
    {
        HookManager::flush();

        // Register a buggy third-party listener that throws an unhandled exception
        HookManager::listen('storefront.cart.updated', function () {
            throw new \RuntimeException('Faulty third-party analytics extension crashed.');
        });

        // Register a well-behaved listener after the buggy one
        HookManager::listen('storefront.cart.updated', function (array $payload) {
            $payload['processed_by_core'] = true;
            return $payload;
        });

        // Fire hook — must NOT throw RuntimeException
        $result = HookManager::fire('storefront.cart.updated', ['cart_id' => 42]);

        $this->assertSame(42, $result['cart_id']);
        $this->assertTrue($result['processed_by_core'] ?? false);
    }

    public function test_make_plugin_artisan_command_scaffolds_standard_extension(): void
    {
        $id = 'testacme/super-widget';
        $dest = base_path('extensions/testacme/super-widget');
        if (File::isDirectory($dest)) {
            File::deleteDirectory($dest);
        }

        $this->artisan('make:plugin', [
            'id' => $id,
            '--title' => 'Super Widget',
        ])->assertSuccessful();

        $this->assertDirectoryExists($dest);
        $this->assertFileExists($dest.'/app.json');
        $this->assertFileExists($dest.'/src/Plugin.php');
        $this->assertFileExists($dest.'/preview.svg');
        $this->assertFileExists($dest.'/README.md');

        $manifest = json_decode((string) File::get($dest.'/app.json'), true);
        $this->assertSame('testacme', $manifest['vendor']);
        $this->assertSame('super-widget', $manifest['name']);
        $this->assertSame('Super Widget', $manifest['display_name']);
        $this->assertSame('safe', $manifest['type']);
        $this->assertContains('hook.listen', $manifest['capabilities']);
    }

    public function test_plugin_kernel_marks_broken_plugins_unhealthy_without_aborting(): void
    {
        $this->seed();

        $broken = InstalledPlugin::create([
            'vendor' => 'ghostvendor',
            'name' => 'ghost-plugin',
            'display_name' => 'Ghost Plugin',
            'version' => '1.0.0',
            'path' => 'extensions/ghostvendor/ghost-plugin',
            'is_enabled' => true,
        ]);

        $kernel = app(PluginKernel::class);
        PluginKernel::reset();

        // Boot should execute cleanly without crashing
        $kernel->boot();

        $broken->refresh();
        $this->assertSame('error', $broken->health_status);
        $this->assertStringContainsString('missing', strtolower((string) $broken->last_error));
    }
}
