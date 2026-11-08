<?php

/**
 * Pack or verify the merchant install zip using PHP's zip extension.
 * The zip CLI and Python are not required, so Ubuntu images that only have PHP still work.
 *
 *   php package/zip-tree.php pack <source-dir> <zip-file>
 *   php package/zip-tree.php verify <wentocart.zip>
 */
declare(strict_types=1);

if (! extension_loaded('zip')) {
    fwrite(STDERR, "PHP zip extension is missing. On Ubuntu: sudo apt install php8.3-zip\n");
    exit(1);
}

$cmd = $argv[1] ?? '';
if ($cmd === 'pack' && isset($argv[2], $argv[3])) {
    packTree($argv[2], $argv[3]);
    exit(0);
}
if ($cmd === 'verify' && isset($argv[2])) {
    verifyInstallZip($argv[2]);
    exit(0);
}

fwrite(STDERR, "Usage: php package/zip-tree.php pack <source-dir> <zip-file>\n       php package/zip-tree.php verify <wentocart.zip>\n");
exit(1);

function packTree(string $source, string $destination): void
{
    $root = realpath($source);
    if ($root === false || ! is_dir($root)) {
        fwrite(STDERR, "Zip source is not a directory: {$source}\n");
        exit(1);
    }
    $root = rtrim($root, DIRECTORY_SEPARATOR);
    if (is_file($destination)) {
        unlink($destination);
    }
    $parent = dirname($destination);
    if (! is_dir($parent) && ! mkdir($parent, 0775, true) && ! is_dir($parent)) {
        fwrite(STDERR, "Could not create {$parent}\n");
        exit(1);
    }

    $zip = new ZipArchive();
    $opened = $zip->open($destination, ZipArchive::CREATE);
    if ($opened !== true) {
        fwrite(STDERR, "Could not create {$destination} (ZipArchive {$opened})\n");
        exit(1);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $file) {
        $path = $file->getPathname();
        $relative = substr($path, strlen($root) + 1);
        $relative = str_replace('\\', '/', $relative);
        if ($relative === '' || str_contains($relative, '../')) {
            continue;
        }
        $mode = fileperms($path) & 0777;
        if ($file->isDir()) {
            $zip->addEmptyDir($relative);
            $zip->setExternalAttributesName($relative, ZipArchive::OPSYS_UNIX, ($mode ?: 0755) << 16);
            continue;
        }
        if ($file->isLink()) {
            $target = readlink($path);
            if ($target === false || ! $zip->addFromString($relative, $target)) {
                fwrite(STDERR, "Could not store symlink {$relative}\n");
                $zip->close();
                exit(1);
            }
        } elseif (! $zip->addFile($path, $relative)) {
            fwrite(STDERR, "Could not add {$relative}\n");
            $zip->close();
            exit(1);
        }
        $method = str_ends_with(strtolower($relative), '.zip') ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE;
        $zip->setCompressionName($relative, $method, $method === ZipArchive::CM_STORE ? 0 : 1);
        $zip->setExternalAttributesName($relative, ZipArchive::OPSYS_UNIX, ($mode ?: 0644) << 16);
    }

    if (! $zip->close()) {
        fwrite(STDERR, "Could not finish {$destination}\n");
        exit(1);
    }
}

function verifyInstallZip(string $path): void
{
    if (! is_file($path)) {
        fwrite(STDERR, "Zip not found: {$path}\n");
        exit(1);
    }
    $outer = new ZipArchive();
    if ($outer->open($path) !== true) {
        fwrite(STDERR, "Could not open {$path}\n");
        exit(1);
    }
    $outerNames = normalizedNames($outer);
    if (! in_array('shop.zip', $outerNames, true)) {
        fwrite(STDERR, "outer zip missing shop.zip\n");
        exit(1);
    }
    foreach (['index.php', 'install.sh', 'checks.php', 'install-guide.php', 'system-check.php', 'installer.css', 'unpack.js', 'helper.php', 'preinstall.htaccess', 'server.php', '.htaccess'] as $required) {
        if (! in_array($required, $outerNames, true)) {
            fwrite(STDERR, "outer zip missing {$required}\n");
            exit(1);
        }
    }
    $innerIndex = $outer->locateName('shop.zip');
    if ($innerIndex === false) {
        $innerIndex = $outer->locateName('./shop.zip');
    }
    $temp = tempnam(sys_get_temp_dir(), 'wento-inner-');
    if ($temp === false) {
        $outer->close();
        fwrite(STDERR, "Could not create a temp file for shop.zip\n");
        exit(1);
    }
    $stream = $outer->getStreamIndex((int) $innerIndex);
    $out = $stream !== false ? fopen($temp, 'wb') : false;
    $copied = is_resource($stream) && is_resource($out) ? stream_copy_to_stream($stream, $out) : false;
    if (is_resource($stream)) {
        fclose($stream);
    }
    if (is_resource($out)) {
        fclose($out);
    }
    $outer->close();
    if ($copied === false || $copied < 1) {
        unlink($temp);
        fwrite(STDERR, "Could not read shop.zip from the outer archive\n");
        exit(1);
    }

    $inner = new ZipArchive();
    if ($inner->open($temp) !== true) {
        unlink($temp);
        fwrite(STDERR, "Could not open inner shop.zip\n");
        exit(1);
    }
    $entries = normalizedNames($inner);
    $inner->close();
    unlink($temp);

    if (array_filter($entries, static fn (string $entry): bool => str_contains($entry, '\\'))) {
        fwrite(STDERR, "inner zip has backslash paths\n");
        exit(1);
    }
    foreach ([
        'vendor/autoload.php',
        'public/build/manifest.json',
        'artisan',
        'setup/index.php',
        '.env.example',
        'composer.json',
        'database/schema/mysql-schema.sql',
        'extensions/.gitignore',
        'extensions/README.md',
        'storage/logs/.gitignore',
        'themes/classic/theme.json',
    ] as $required) {
        if (! in_array($required, $entries, true)) {
            fwrite(STDERR, "inner zip missing {$required}\n");
            exit(1);
        }
    }
    $bannedPrefixes = ['node_modules/', '.git/', 'tests/', 'docs/', 'vendor/phpunit/', 'vendor/nunomaduro/collision/'];
    $bannedNames = ['.env', '.env.backup', '.env.production', 'auth.json', 'php.path'];
    foreach ($entries as $entry) {
        $base = rtrim($entry, '/');
        if ($entry !== 'extensions/.gitignore' && $entry !== 'extensions/' && $entry !== 'extensions/README.md' && str_starts_with($entry, 'extensions/')) {
            fwrite(STDERR, "inner zip must not ship extensions: {$entry}\n");
            exit(1);
        }
        foreach ($bannedPrefixes as $prefix) {
            if (str_starts_with($entry, $prefix) || in_array($base, $bannedNames, true)) {
                fwrite(STDERR, "inner zip contains excluded path {$entry}\n");
                exit(1);
            }
        }
    }
    echo "OK vendor + public/build + install layout\n";
}

/**
 * @return list<string>
 */
function normalizedNames(ZipArchive $zip): array
{
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (! is_string($name)) {
            continue;
        }
        if (str_starts_with($name, './')) {
            $name = substr($name, 2);
        }
        $names[] = $name;
    }

    return $names;
}
