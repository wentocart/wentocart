<?php

namespace App\Domain\Platform\Upgrade;

use App\Domain\Settings\Models\ShopSetting;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Throwable;

final class PlatformUpgradeService
{
    public function __construct(
        protected UpgradeBackupService $backups = new UpgradeBackupService,
    ) {}

    /**
     * @return array{code: string, installed: string, pending: list<array{version: string, description: string}>}
     */
    public function status(): array
    {
        PlatformMeta::ensure();
        $code = PlatformMeta::codeVersion();
        $installed = PlatformMeta::installedVersion();
        $pending = array_map(fn (UpgradePatch $p) => [
            'version' => $p->version(),
            'description' => $p->description(),
        ], UpgradePatchRegistry::pending($installed, $code));

        return [
            'code' => $code,
            'installed' => $installed,
            'pending' => $pending,
            'needs_upgrade' => $pending !== [] || version_compare($installed, $code, '<'),
            'logs' => array_map(fn ($row) => (array) $row, PlatformMeta::recentLogs()),
            'backups' => $this->backups->listBackups(),
        ];
    }

    /**
     * @return array{applied: list<string>, from: string, to: string}
     */
    public function apply(?callable $progress = null, bool $dryRun = false): array
    {
        PlatformMeta::ensure();
        $from = PlatformMeta::installedVersion();
        $to = PlatformMeta::codeVersion();
        if (version_compare($to, $from, '<')) {
            throw new RuntimeException('Code version '.$to.' is older than installed '.$from.'. Downgrades are not supported.');
        }

        $pending = UpgradePatchRegistry::pending($from, $to);
        if ($pending === [] && version_compare($from, $to, '=')) {
            return ['applied' => [], 'from' => $from, 'to' => $to];
        }

        if ($dryRun) {
            return [
                'applied' => array_map(fn (UpgradePatch $p) => $p->version(), $pending),
                'from' => $from,
                'to' => $to,
            ];
        }

        $this->maintenance(true);
        $applied = [];
        try {
            foreach ($pending as $patch) {
                $progress && $progress('Applying '.$patch->version().': '.$patch->description());
                try {
                    $patch->up();
                    PlatformMeta::stamp($patch->version());
                    PlatformMeta::log($patch->version(), 'applied', $patch->description());
                    $applied[] = $patch->version();
                } catch (Throwable $e) {
                    PlatformMeta::log($patch->version(), 'failed', $e->getMessage());
                    // Fail closed: leave maintenance on so the storefront stays offline until restore/retry.
                    throw new RuntimeException('Upgrade failed at '.$patch->version().': '.$e->getMessage(), 0, $e);
                }
            }
            if (version_compare(PlatformMeta::installedVersion(), $to, '<')) {
                PlatformMeta::stamp($to);
            }
            try {
                Artisan::call('optimize:clear');
            } catch (Throwable) {
            }
            $this->maintenance(false);
        } catch (Throwable $e) {
            throw $e;
        }

        return ['applied' => $applied, 'from' => $from, 'to' => $to];
    }

    protected function maintenance(bool $on): void
    {
        try {
            ShopSetting::put('advanced.maintenance', $on);
            if ($on) {
                ShopSetting::put(
                    'advanced.maintenance_message',
                    (string) ShopSetting::get('advanced.maintenance_message', '') ?: 'We are upgrading the store. Please try again shortly.'
                );
            }
        } catch (Throwable) {
            // settings table may be mid-migration
        }
    }
}
