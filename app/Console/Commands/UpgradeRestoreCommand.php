<?php

namespace App\Console\Commands;

use App\Domain\Platform\Upgrade\UpgradeBackupService;
use App\Domain\Platform\Upgrade\UpgradeRestoreService;
use Illuminate\Console\Command;
use Throwable;

class UpgradeRestoreCommand extends Command
{
    protected $signature = 'wento:upgrade-restore
        {backup? : Backup id under storage/app/upgrades/backups}
        {--force : Skip confirm}';

    protected $description = 'Restore a platform upgrade backup (database + core files).';

    public function handle(UpgradeBackupService $backups, UpgradeRestoreService $restore): int
    {
        $id = (string) ($this->argument('backup') ?? '');
        if ($id === '') {
            $list = $backups->listBackups();
            if ($list === []) {
                $this->error('No backups found.');

                return self::FAILURE;
            }
            $this->table(['ID', 'Created', 'Installed'], array_map(
                fn ($b) => [$b['id'] ?? '', $b['created_at'] ?? '', $b['installed_version'] ?? ''],
                $list
            ));
            $id = (string) $this->ask('Backup ID to restore');
        }

        if (! $this->option('force') && ! $this->confirm('Restore backup '.$id.'? This overwrites the database and core files.', false)) {
            return self::SUCCESS;
        }

        try {
            $restore->restore($id, fn ($m) => $this->line('  '.$m));
            $this->info('Restore complete. Sign in at /admin/login');
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
