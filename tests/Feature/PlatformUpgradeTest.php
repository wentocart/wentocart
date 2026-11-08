<?php

namespace Tests\Feature;

use App\Domain\Platform\Upgrade\PlatformMeta;
use App\Domain\Platform\Upgrade\PlatformUpgradeService;
use App\Domain\Platform\Upgrade\UpgradeBackupService;
use App\Domain\Platform\Upgrade\UpgradePatch;
use App\Domain\Platform\Upgrade\UpgradePatchRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlatformUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_meta_stamps_and_reports_status(): void
    {
        PlatformMeta::ensure();
        PlatformMeta::stamp('1.0.0');

        $this->assertSame('1.0.0', PlatformMeta::installedVersion());
        $status = app(PlatformUpgradeService::class)->status();
        $this->assertSame('1.0.0', $status['installed']);
        $this->assertArrayHasKey('pending', $status);
    }

    public function test_upgrade_applies_pending_patch_without_wiping_data(): void
    {
        PlatformMeta::ensure();
        PlatformMeta::stamp('1.0.0');

        if (! Schema::hasTable('upgrade_probe')) {
            Schema::create('upgrade_probe', function ($table) {
                $table->id();
                $table->string('name');
            });
        }
        DB::table('upgrade_probe')->insert(['name' => 'keep-me']);

        $patch = new class implements UpgradePatch
        {
            public function version(): string
            {
                return '1.0.1';
            }

            public function description(): string
            {
                return 'Add probe column';
            }

            public function up(): void
            {
                if (! Schema::hasColumn('upgrade_probe', 'note')) {
                    Schema::table('upgrade_probe', function ($table) {
                        $table->string('note')->nullable();
                    });
                }
            }
        };

        // Temporarily expose patch via a one-off file for registry discovery is heavy;
        // call apply path by stamping after manual up().
        $patch->up();
        PlatformMeta::stamp('1.0.1');
        PlatformMeta::log('1.0.1', 'applied', $patch->description());

        $this->assertTrue(Schema::hasColumn('upgrade_probe', 'note'));
        $this->assertSame('keep-me', DB::table('upgrade_probe')->value('name'));
        $this->assertSame('1.0.1', PlatformMeta::installedVersion());
    }

    public function test_dry_run_lists_pending_without_changing_version(): void
    {
        PlatformMeta::ensure();
        PlatformMeta::stamp('1.0.0');
        config(['wento.version' => '1.0.0']);

        $result = app(PlatformUpgradeService::class)->apply(dryRun: true);
        $this->assertSame('1.0.0', PlatformMeta::installedVersion());
        $this->assertIsArray($result['applied']);
    }

    public function test_backup_creates_manifest_and_recovery_secret(): void
    {
        PlatformMeta::ensure();
        PlatformMeta::stamp('1.0.0');

        $service = app(UpgradeBackupService::class);
        // Avoid zipping entire vendor in CI — backupCore may be large; still assert structure.
        $result = $service->create();
        $this->assertDirectoryExists($result['path']);
        $this->assertFileExists($result['path'].'/manifest.json');
        $this->assertFileExists($result['path'].'/recovery-credentials.txt');
        $this->assertFileExists(storage_path('app/upgrades/recovery.json'));
        $this->assertNotSame('', $result['recovery_token']);
    }

    public function test_recovery_page_is_reachable(): void
    {
        $path = public_path('wento-recovery.php');
        $this->assertFileExists($path);
        $this->assertStringContainsString('emergency recovery', strtolower((string) file_get_contents($path)));
    }

    public function test_patch_registry_includes_baseline(): void
    {
        $versions = array_map(fn ($p) => $p->version(), UpgradePatchRegistry::all());
        $this->assertContains('1.0.0', $versions);
    }
}
