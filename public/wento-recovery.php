<?php

/**
 * Emergency platform restore — works even when Laravel admin/routes are broken.
 * URL: /wento-recovery.php
 *
 * Requires recovery token from a platform backup (storage/app/upgrades/recovery.json).
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$storageUpgrades = $root.'/storage/app/upgrades';
$recoveryFile = $storageUpgrades.'/recovery.json';
$backupsRoot = $storageUpgrades.'/backups';

function wento_recovery_env(string $root): array
{
    $path = $root.'/.env';
    $out = [];
    if (! is_file($path)) {
        return $out;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
            $v = substr($v, 1, -1);
        }
        $out[$k] = $v;
    }

    return $out;
}

function wento_recovery_pdo(array $env): PDO
{
    $driver = $env['DB_CONNECTION'] ?? 'mysql';
    if ($driver === 'sqlite') {
        $db = $env['DB_DATABASE'] ?? ($GLOBALS['root'].'/database/database.sqlite');
        if (! str_starts_with($db, '/')) {
            $db = $GLOBALS['root'].'/'.$db;
        }

        return new PDO('sqlite:'.$db);
    }
    $host = $env['DB_HOST'] ?? '127.0.0.1';
    $port = $env['DB_PORT'] ?? '3306';
    $name = $env['DB_DATABASE'] ?? '';
    $user = $env['DB_USERNAME'] ?? '';
    $pass = $env['DB_PASSWORD'] ?? '';
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    return new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function wento_recovery_list_backups(string $backupsRoot): array
{
    if (! is_dir($backupsRoot)) {
        return [];
    }
    $out = [];
    foreach (scandir($backupsRoot) ?: [] as $id) {
        if ($id === '.' || $id === '..') {
            continue;
        }
        $manifest = $backupsRoot.'/'.$id.'/manifest.json';
        if (! is_file($manifest)) {
            continue;
        }
        $data = json_decode((string) file_get_contents($manifest), true);
        if (is_array($data)) {
            $data['id'] = $data['id'] ?? $id;
            $out[] = $data;
        }
    }
    usort($out, fn ($a, $b) => strcmp((string) ($b['id'] ?? ''), (string) ($a['id'] ?? '')));

    return $out;
}

function wento_recovery_token_ok(string $recoveryFile, string $token): bool
{
    if ($token === '' || ! is_file($recoveryFile)) {
        return false;
    }
    $data = json_decode((string) file_get_contents($recoveryFile), true);
    if (! is_array($data) || empty($data['token_hash'])) {
        return false;
    }

    return hash_equals((string) $data['token_hash'], hash('sha256', $token));
}

$GLOBALS['root'] = $root;
$message = '';
$error = '';
$authed = false;
$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$action = (string) ($_POST['action'] ?? '');
$backupId = (string) ($_POST['backup_id'] ?? '');

if ($token !== '' && wento_recovery_token_ok($recoveryFile, $token)) {
    $authed = true;
}

if ($authed && $action === 'restore' && $backupId !== '') {
    $backupId = basename($backupId);
    $dir = $backupsRoot.'/'.$backupId;
    $manifestPath = $dir.'/manifest.json';
    try {
        if (! is_file($manifestPath)) {
            throw new RuntimeException('Backup not found.');
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true) ?: [];
        $dbFile = $dir.'/'.($manifest['database_file'] ?? 'database.sql');
        $coreFile = $dir.'/'.($manifest['core_file'] ?? 'core.zip');
        $env = wento_recovery_env($root);

        // Restore database
        if (is_file($dbFile)) {
            if (($env['DB_CONNECTION'] ?? 'mysql') === 'sqlite') {
                $target = $env['DB_DATABASE'] ?? ($root.'/database/database.sqlite');
                if (! str_starts_with($target, '/')) {
                    $target = $root.'/'.$target;
                }
                copy($dbFile, $target);
            } else {
                $pdo = wento_recovery_pdo($env);
                $sql = (string) file_get_contents($dbFile);
                $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
                foreach (preg_split('/;\s*\n/', $sql) ?: [] as $stmt) {
                    $stmt = trim($stmt);
                    if ($stmt === '' || str_starts_with($stmt, '--')) {
                        continue;
                    }
                    try {
                        $pdo->exec($stmt);
                    } catch (Throwable) {
                    }
                }
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            }
        }

        // Restore core zip (allowed prefixes only)
        if (is_file($coreFile) && class_exists(ZipArchive::class)) {
            $zip = new ZipArchive;
            if ($zip->open($coreFile) === true) {
                $allowed = ['app/', 'bootstrap/', 'config/', 'database/', 'public/build/', 'public/index.php', 'public/wento-recovery.php', 'resources/', 'routes/', 'extensions/', 'themes/', 'vendor/', 'composer.json', 'composer.lock', 'artisan', 'LICENSE', 'NOTICE'];
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
                    if ($name === '' || str_contains($name, '..')) {
                        continue;
                    }
                    $ok = false;
                    foreach ($allowed as $prefix) {
                        if ($name === rtrim($prefix, '/') || str_starts_with($name, $prefix)) {
                            $ok = true;
                            break;
                        }
                    }
                    if (! $ok) {
                        continue;
                    }
                    $target = $root.'/'.$name;
                    if (str_ends_with($name, '/')) {
                        if (! is_dir($target)) {
                            mkdir($target, 0755, true);
                        }
                        continue;
                    }
                    $parent = dirname($target);
                    if (! is_dir($parent)) {
                        mkdir($parent, 0755, true);
                    }
                    $stream = $zip->getStream($name);
                    if ($stream) {
                        file_put_contents($target, stream_get_contents($stream));
                        fclose($stream);
                    }
                }
                $zip->close();
            }
        }

        $message = 'Restore finished. Open /admin/login and sign in. If pages look cached, wait a moment or clear browser cache.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$backups = $authed ? wento_recovery_list_backups($backupsRoot) : [];
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WentoCart recovery</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f4f4f5; color: #18181b; margin: 0; padding: 2rem; }
        .card { max-width: 40rem; margin: 0 auto; background: #fff; border: 1px solid #e4e4e7; border-radius: 8px; padding: 1.5rem; }
        h1 { font-size: 1.25rem; margin: 0 0 0.5rem; }
        p { color: #52525b; line-height: 1.5; }
        label { display: block; font-size: 0.875rem; margin: 1rem 0 0.35rem; }
        input, select, button { width: 100%; padding: 0.6rem 0.75rem; font-size: 1rem; box-sizing: border-box; }
        button { background: #18181b; color: #fff; border: 0; border-radius: 6px; cursor: pointer; margin-top: 1rem; }
        .ok { background: #ecfdf5; color: #065f46; padding: 0.75rem; border-radius: 6px; }
        .err { background: #fef2f2; color: #991b1b; padding: 0.75rem; border-radius: 6px; }
        .muted { font-size: 0.8rem; color: #71717a; }
    </style>
</head>
<body>
<div class="card">
    <h1>WentoCart emergency recovery</h1>
    <p>Use this page when the admin or storefront is broken after an upgrade. It does not load the full application.</p>
    <?php if ($message !== ''): ?><p class="ok"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>

    <?php if (! $authed): ?>
        <form method="post">
            <label for="token">Recovery token</label>
            <input id="token" name="token" type="password" required autocomplete="off" placeholder="From recovery-credentials.txt">
            <button type="submit">Unlock backups</button>
        </form>
        <p class="muted">Token file: storage/app/upgrades/backups/…/recovery-credentials.txt</p>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="restore">
            <label for="backup_id">Backup to restore</label>
            <select id="backup_id" name="backup_id" required>
                <?php foreach ($backups as $b): ?>
                    <option value="<?= htmlspecialchars((string) $b['id'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars((string) $b['id'], ENT_QUOTES, 'UTF-8') ?>
                        — installed <?= htmlspecialchars((string) ($b['installed_version'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($backups === []): ?>
                <p class="err">No local backups found under storage/app/upgrades/backups.</p>
            <?php else: ?>
                <button type="submit" onclick="return confirm('Restore this backup? Database and core files will be overwritten.');">Restore backup</button>
            <?php endif; ?>
        </form>
        <p class="muted">After restore, open <a href="/admin/login">/admin/login</a>.</p>
    <?php endif; ?>
</div>
</body>
</html>
