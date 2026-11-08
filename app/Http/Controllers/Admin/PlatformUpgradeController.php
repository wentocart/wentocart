<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Platform\Upgrade\PlatformUpgradeService;
use App\Domain\Platform\Upgrade\UpgradeBackupService;
use App\Domain\Platform\Upgrade\UpgradeRestoreService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class PlatformUpgradeController extends Controller
{
    public function status(PlatformUpgradeService $upgrades): \Illuminate\Http\JsonResponse
    {
        return response()->json($upgrades->status());
    }

    public function backup(UpgradeBackupService $backups): RedirectResponse
    {
        try {
            $result = $backups->create();
            session()->flash('upgrade_recovery_token', $result['recovery_token']);

            return back()->with('success', 'Safety backups created ('.$result['id'].'). Download them before upgrading. Recovery URL: /wento-recovery.php');
        } catch (Throwable $e) {
            return back()->with('error', 'Backup failed: '.$e->getMessage());
        }
    }

    public function download(Request $request, UpgradeBackupService $backups, string $backup, string $file): BinaryFileResponse|RedirectResponse
    {
        $dir = $backups->backupDir($backup);
        if ($dir === null) {
            return back()->with('error', 'Backup not found.');
        }
        $allowed = ['database.sql', 'database.sqlite', 'core.zip', 'recovery-credentials.txt', 'manifest.json'];
        $file = basename($file);
        if (! in_array($file, $allowed, true)) {
            abort(404);
        }
        $path = $dir.DIRECTORY_SEPARATOR.$file;
        if (! is_file($path)) {
            // database may be sqlite
            if ($file === 'database.sql' && is_file($dir.DIRECTORY_SEPARATOR.'database.sqlite')) {
                $path = $dir.DIRECTORY_SEPARATOR.'database.sqlite';
                $file = 'database.sqlite';
            } else {
                abort(404);
            }
        }

        return response()->download($path, $file);
    }

    public function apply(Request $request, PlatformUpgradeService $upgrades): RedirectResponse
    {
        $request->validate([
            'confirm_backups' => ['accepted'],
        ]);
        try {
            $result = $upgrades->apply();
            $msg = 'Platform upgraded '.$result['from'].' → '.$result['to'];
            if ($result['applied'] !== []) {
                $msg .= ' ('.implode(', ', $result['applied']).')';
            }

            return back()->with('success', $msg);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage().' Restore via platform-upgrade restore or /wento-recovery.php');
        }
    }

    public function restore(Request $request, UpgradeRestoreService $restore): RedirectResponse
    {
        $data = $request->validate([
            'backup_id' => ['required', 'string', 'max:80'],
        ]);
        try {
            $restore->restore($data['backup_id']);

            return back()->with('success', 'Backup restored. Sign out and sign in again if needed.');
        } catch (Throwable $e) {
            return back()->with('error', 'Restore failed: '.$e->getMessage());
        }
    }

    public function destroyBackup(UpgradeBackupService $backups, string $backup): RedirectResponse
    {
        $dir = $backups->backupDir($backup);
        if ($dir === null) {
            return back()->with('error', 'Backup not found.');
        }
        File::deleteDirectory($dir);

        return back()->with('success', 'Backup deleted.');
    }
}
