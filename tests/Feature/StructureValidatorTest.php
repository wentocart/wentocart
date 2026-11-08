<?php

namespace Tests\Feature;

use App\Domain\Plugin\PluginInstaller;
use App\Domain\Plugin\PluginKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class StructureValidatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PluginKernel::reset();
    }

    public function test_standard_fixture_installs(): void
    {
        $this->seed();
        $this->installFixtureExtension('wento', 'sample-notice');
        $this->assertDatabaseHas('plugins', [
            'vendor' => 'wento',
            'name' => 'sample-notice',
        ]);
    }

    public function test_missing_readme_is_rejected(): void
    {
        $this->seed();
        $path = $this->zip([
            'app.json' => $this->manifestJson('acme', 'no-readme'),
            'src/Plugin.php' => $this->pluginPhp('Acme', 'NoReadme'),
        ], withReadme: false);

        $this->expectReject($path, 'README.md');
    }

    public function test_unexpected_root_folder_is_rejected(): void
    {
        $this->seed();
        $path = $this->zip([
            'app.json' => $this->manifestJson('acme', 'extra-dir'),
            'README.md' => "# Extra\n",
            'src/Plugin.php' => $this->pluginPhp('Acme', 'ExtraDir'),
            'tests/foo.txt' => 'nope',
        ]);

        $this->expectReject($path, 'tests');
    }

    public function test_wrong_namespace_is_rejected(): void
    {
        $this->seed();
        $path = $this->zip([
            'app.json' => $this->manifestJson('acme', 'bad-ns'),
            'README.md' => "# Bad ns\n",
            'src/Plugin.php' => "<?php\nnamespace Modules\\Wrong\\Name;\nuse App\\Domain\\Plugin\\Support\\BasePlugin;\nclass Plugin extends BasePlugin {}\n",
        ]);

        $this->expectReject($path, 'namespace');
    }

    public function test_http_file_must_be_controller(): void
    {
        $this->seed();
        $path = $this->zip([
            'app.json' => $this->manifestJson('acme', 'bad-http', ['admin.pages']),
            'README.md' => "# Bad http\n",
            'src/Plugin.php' => $this->pluginPhp('Acme', 'BadHttp'),
            'src/Http/Helper.php' => "<?php\nnamespace Modules\\Acme\\BadHttp\\Http;\nclass Helper {}\n",
        ]);

        $this->expectReject($path, 'Controller.php');
    }

    /**
     * @param  array<string, string>  $files
     */
    protected function zip(array $files, bool $withReadme = true): string
    {
        if ($withReadme && ! isset($files['README.md'])) {
            $files['README.md'] = "# Test\n";
        }
        $path = storage_path('framework/testing/'.uniqid('struct_', true).'.zip');
        @mkdir(dirname($path), 0777, true);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE) === true);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    /**
     * @param  list<string>  $capabilities
     */
    protected function manifestJson(string $vendor, string $name, array $capabilities = ['settings']): string
    {
        return json_encode([
            'vendor' => $vendor,
            'name' => $name,
            'display_name' => $name,
            'version' => '1.0.0',
            'author' => 'test',
            'type' => 'safe',
            'description' => 'structure test',
            'capabilities' => $capabilities,
        ], JSON_THROW_ON_ERROR);
    }

    protected function pluginPhp(string $vendor, string $name): string
    {
        return <<<PHP
<?php

namespace Modules\\{$vendor}\\{$name};

use App\\Domain\\Plugin\\Support\\BasePlugin;

class Plugin extends BasePlugin
{
    public function boot(): void {}
}
PHP;
    }

    protected function expectReject(string $path, string $messageContains): void
    {
        try {
            app(PluginInstaller::class)->install(
                new UploadedFile($path, 'test.zip', 'application/zip', null, true)
            );
            $this->fail('zip should be rejected');
        } catch (ValidationException $e) {
            $flat = json_encode($e->errors());
            $this->assertStringContainsStringIgnoringCase($messageContains, (string) $flat);
        }
    }
}
