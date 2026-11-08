<?php

namespace App\Console\Commands;

use App\Domain\Platform\Upgrade\PlatformUpgradeService;
use App\Domain\Platform\Upgrade\UpgradeBackupService;
use Illuminate\Console\Command;
use Throwable;

class UpgradeCommand extends Command
{
    protected $signature = 'wento:upgrade
        {--dry-run : List pending patches without applying}
        {--backup : Create DB + core backups before applying}
        {--force : Skip interactive confirm}';

    protected $description = 'Apply pending platform schema upgrades (1.0.0 → current code version).';

    public function handle(PlatformUpgradeService $upgrades, UpgradeBackupService $backups): int
    {
        $status = $upgrades->status();
        $this->info('Code version: '.$status['code']);
        $this->info('Installed version: '.$status['installed']);
        if ($status['pending'] === []) {
            $this->info('No pending patches.');
            if (! $status['needs_upgrade']) {
                return self::SUCCESS;
            }
        } else {
            $this->table(['Version', 'Description'], array_map(
                fn ($p) => [$p['version'], $p['description']],
                $status['pending']
            ));
        }

        if ($this->option('dry-run')) {
            $result = $upgrades->apply(dryRun: true);
            $this->info('Dry-run would apply: '.implode(', ', $result['applied'] ?: ['(none)']));

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Apply pending upgrades now?', true)) {
            return self::SUCCESS;
        }

        if ($this->option('backup')) {
            $this->info('Creating safety backups…');
            try {
                $backup = $backups->create(fn ($m) => $this->line('  '.$m));
                $this->info('Backup ID: '.$backup['id']);
                $this->info('Recovery token saved under storage/app/upgrades/backups/'.$backup['id'].'/recovery-credentials.txt');
            } catch (Throwable $e) {
                $this->error('Backup failed: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        try {
            $result = $upgrades->apply(fn ($m) => $this->line('  '.$m));
            $this->info('Upgraded '.$result['from'].' → '.$result['to']);
            if ($result['applied'] !== []) {
                $this->info('Applied: '.implode(', ', $result['applied']));
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            $this->warn('Restore with: php artisan wento:upgrade-restore {backup-id}');
            $this->warn('Or open /wento-recovery.php with your recovery token.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
