<?php

/**
 * Remove installer leftovers after a successful install.
 * Called from index.php on /installed so setup/api.php is not deleted while the wizard is still polling.
 */
function wento_tidy_merchant_root(string $root): void
{
    $root = rtrim($root, '\\/');
    $lock = $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'installed.lock';
    $flag = $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'tidy-installer';

    if (! is_file($lock) || ! is_file($flag)) {
        return;
    }

    // Merchant zip only. A git checkout needs AGENTS.md, tests, and setup.
    if (is_dir($root.DIRECTORY_SEPARATOR.'.git')) {
        @unlink($flag);

        return;
    }

    $dirs = ['setup', 'tests', 'tools', 'node_modules', 'database/seeders', 'database/factories', 'database/schema'];
    foreach ($dirs as $dir) {
        wento_fs_remove($root.DIRECTORY_SEPARATOR.$dir);
    }
    $docs = $root.DIRECTORY_SEPARATOR.'docs';
    if (is_dir($docs)) {
        foreach (scandir($docs) ?: [] as $child) {
            if ($child === '.' || $child === '..' || $child === 'extensions') {
                continue;
            }
            wento_fs_remove($docs.DIRECTORY_SEPARATOR.$child);
        }
    }

    $files = [
        'checks.php',
        'install-guide.php',
        'system-check.php',
        'installer.css',
        'unpack.js',
        'helper.php',
        'preinstall.htaccess',
        'Install.html',
        'SETUP.txt',
        'install.sh',
        'install.command',
        'phpunit.xml',
        '.phpunit.result.cache',
        'AGENTS.md',
        'INSTALL.bat',
        'INSTALL.sh',
        'start-installer.bat',
        'start-installer.sh',
        '.env.example',
        '.env.backup',
        'Install.vbs',
        'find-free-port.php',
        'find-php.php',
        'FindPhp.php',
        'php.path.example',
        // Built-in server router. Removing it stops the helper from opening pages.
        'serve-shop.sh',
        '.gitattributes',
        '.gitignore',
    ];
    foreach ($files as $file) {
        wento_fs_remove($root.DIRECTORY_SEPARATOR.$file);
    }

    foreach (glob($root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Domain'.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'AGENTS.md') ?: [] as $agents) {
        wento_fs_remove($agents);
    }
    wento_fs_remove($root.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'js'.DIRECTORY_SEPARATOR.'admin'.DIRECTORY_SEPARATOR.'ui'.DIRECTORY_SEPARATOR.'AGENTS.md');

    foreach (glob($root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'*.log') ?: [] as $log) {
        wento_fs_remove($log);
    }
    wento_fs_remove($root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'install-answers.json');
    wento_fs_remove($root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'inertia-devtools');

    @unlink($flag);
}

function wento_fs_remove(string $path): void
{
    if ($path === '' || ! file_exists($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        @unlink($path);

        return;
    }

    if (! is_dir($path)) {
        return;
    }

    $items = @scandir($path);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        wento_fs_remove($path.DIRECTORY_SEPARATOR.$item);
    }

    @rmdir($path);
}
