<?php

/**
 * Installer entry. Check PHP, unpack the shop archive, then open the setup wizard.
 */
declare(strict_types=1);

require __DIR__.'/checks.php';

installer_main();

function installer_main(): void
{
    set_time_limit(0);
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $root = __DIR__;
    $zipPath = wrapper_shop_archive();
    $setupReady = wrapper_setup_ready();
    $compat = wrapper_compatibility();
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    $unpacking = $script === '_extract.php';

    if ($setupReady && ! is_file($zipPath)) {
        header('Location: '.wrapper_href('setup/index.php'));
        exit;
    }

    if (isset($_GET['extract'])) {
        header('Content-Type: application/json; charset=utf-8');
        if (! $compat['ready'] || ! is_file($zipPath)) {
            echo json_encode(['ok' => false, 'message' => SETUP_SHOP_ARCHIVE.' is missing. The archive was not unpacked.']);
            exit;
        }
        echo json_encode(extract_batch($root, $zipPath), JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (isset($_GET['unpack']) && $compat['ready'] && is_file($zipPath) && ! $setupReady) {
        $extractPath = $root.DIRECTORY_SEPARATOR.'_extract.php';
        if (! is_writable($root) || @copy(__FILE__, $extractPath) === false) {
            wrapper_render_install_guide($root);
            exit;
        }
        header('Location: '.wrapper_href('_extract.php'));
        exit;
    }

    if (! $unpacking) {
        render_compat($compat);
        exit;
    }

    if (! is_file($zipPath)) {
        render_compat($compat);
        exit;
    }

    render_unpack();
}

/**
 * @param  array<string, mixed>  $compat
 */
function render_compat(array $compat): void
{
    $ready = ! empty($compat['ready']);
    wrapper_render_page([
        'steps' => wrapper_install_steps('System Check'),
        'heading' => 'System compatibility check',
        'paragraphs' => [
            'Only the PHP serving this page is checked. The shop stays packed until you continue.',
        ],
        'aside' => 'Fix any failed checks, then continue. Other PHP installs on this computer are ignored.',
        'bodyView' => 'system-check.php',
        'compat' => $compat,
        'actions' => [
            ['label' => 'Refresh check', 'reload' => true, 'primary' => false],
            ['label' => 'Continue setup', 'href' => wrapper_href('index.php').'?unpack=1', 'primary' => true, 'disabled' => ! $ready],
        ],
    ]);
}

function render_unpack(): void
{
    wrapper_render_page([
        'steps' => wrapper_install_steps('Install Packages'),
        'heading' => 'Unpacking the shop',
        'paragraphs' => [
            'Compatibility passed. Files are copied from the archive, then the installation steps continue.',
        ],
        'aside' => 'Protected folders stay blocked while the shop is unpacked.',
        'showProgress' => true,
        'scriptUrl' => wrapper_href('unpack.js'),
        'actions' => [
            ['label' => 'Continue installation', 'href' => 'setup/index.php?start=1', 'primary' => true, 'disabled' => true, 'id' => 'continue'],
        ],
    ]);
}

/**
 * @return array{ok: bool, message?: string, total?: int, done?: int, percent?: int, finished?: bool, redirect?: string}
 */
function extract_batch(string $root, string $zipPath): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return ['ok' => false, 'message' => 'Could not open '.SETUP_SHOP_ARCHIVE];
    }

    if ($zip->locateName('setup/index.php') === false && $zip->locateName('./setup/index.php') === false) {
        $zip->close();

        return ['ok' => false, 'message' => SETUP_SHOP_ARCHIVE.' is not the shop archive. It must contain setup/index.php. Unzip the customer wentocart.zip first so '.SETUP_SHOP_ARCHIVE.' sits next to this installer.'];
    }

    $total = $zip->numFiles;
    $done = max(0, (int) ($_GET['done'] ?? 0));
    $end = min($total, $done + 250);

    for ($i = $done; $i < $end; $i++) {
        $raw = $zip->getNameIndex($i);
        if (! is_string($raw) || $raw === '') {
            continue;
        }
        $name = str_replace('\\', '/', $raw);
        if ($name === '' || str_contains($name, '..')) {
            continue;
        }
        if (in_array(basename($name), ['_extract.php', 'server.php', 'checks.php'], true)) {
            continue;
        }
        if ($name === '.htaccess' || $name === SETUP_SHOP_ARCHIVE || $name === 'wentocart.zip') {
            continue;
        }
        if (str_ends_with($name, '/')) {
            $dir = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, rtrim($name, '/'));
            if (! is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            continue;
        }

        $target = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $name);
        $dir = dirname($target);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $data = $zip->getFromIndex($i);
        if ($data === false) {
            $zip->close();

            return ['ok' => false, 'message' => 'Could not read '.$name.' from '.SETUP_SHOP_ARCHIVE];
        }
        if (file_put_contents($target, $data) === false) {
            $zip->close();

            return ['ok' => false, 'message' => 'Could not write '.$name];
        }
    }

    $next = $end;
    $finished = $next >= $total;
    if ($finished) {
        $classFile = $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Domain'.DIRECTORY_SEPARATOR.'Installer'.DIRECTORY_SEPARATOR.'ApacheHtaccess.php';
        if (is_file($classFile)) {
            require_once $classFile;
        }
        $htaccess = class_exists(\App\Domain\Installer\ApacheHtaccess::class)
            ? \App\Domain\Installer\ApacheHtaccess::preInstallContents()
            : wrapper_preinstall_htaccess();
        if (file_put_contents($root.DIRECTORY_SEPARATOR.'.htaccess', $htaccess) === false) {
            $zip->close();

            return ['ok' => false, 'message' => 'Could not write .htaccess. The web server cannot update this folder.'];
        }
        $zip->close();
        if (! is_file($root.DIRECTORY_SEPARATOR.'setup'.DIRECTORY_SEPARATOR.'index.php')) {
            return ['ok' => false, 'message' => 'Unpacking finished but setup/index.php was not created. '.SETUP_SHOP_ARCHIVE.' was left in this folder.'];
        }
        @unlink($zipPath);
        $extract = $root.DIRECTORY_SEPARATOR.'_extract.php';
        if (is_file($extract)) {
            register_shutdown_function(static function () use ($extract): void {
                @unlink($extract);
            });
        }
    } else {
        $zip->close();
    }

    return [
        'ok' => true,
        'total' => $total,
        'done' => $next,
        'percent' => $total ? (int) round(100 * $next / $total) : 100,
        'finished' => $finished,
        'redirect' => wrapper_href('setup/index.php?start=1'),
    ];
}
