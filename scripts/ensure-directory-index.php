<?php

/**
 * Add a deny-listing index.php to every source folder.
 *
 * Skips Laravel config/ (every *.php there is loaded as a config file),
 * real front controllers, vendor/build trees, and runtime upload folders.
 *
 * Usage: php scripts/ensure-directory-index.php [optional-root]
 */
declare(strict_types=1);

$root = isset($argv[1]) ? rtrim($argv[1], '/\\') : dirname(__DIR__);
if (! is_dir($root)) {
    fwrite(STDERR, "Root not found: {$root}\n");
    exit(1);
}

$stub = <<<'PHP'
<?php

header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Last-Modified: '.gmdate('D, d M Y H:i:s').' GMT');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Location: ../');
exit;

PHP;

$skipExact = [
    '.git',
    'vendor',
    'node_modules',
    'public/build',
    'public/hot',
    'public/storage',
    'public/i18n',
    'dist',
    'coverage',
    'storage/framework/cache',
    'storage/framework/sessions',
    'storage/framework/views',
    'storage/logs',
    'storage/debugbar',
    'agent-skills',
    'api-collections',
    'bin',
    'tools',
    'headless',
    'mobile',
    'docker',
    '.github',
];

$skipPrefixes = [
    'storage/app/public/catalog/brands/',
    'storage/app/public/catalog/categories/',
    'storage/app/public/catalog/products/',
];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$added = 0;
$skipped = 0;

foreach ($iterator as $item) {
    if (! $item->isDir()) {
        continue;
    }
    $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
    if ($rel === '' || $rel === false) {
        continue;
    }
    if (shouldSkip($rel, $skipExact, $skipPrefixes)) {
        $skipped++;
        continue;
    }
    $index = $item->getPathname().DIRECTORY_SEPARATOR.'index.php';
    if (is_file($index)) {
        continue;
    }
    file_put_contents($index, $stub);
    $added++;
}

echo "Added {$added} index.php stubs under {$root}\n";

function shouldSkip(string $rel, array $exact, array $prefixes): bool
{
    if ($rel === 'config' || str_starts_with($rel, 'config/')) {
        return true;
    }
    foreach ($exact as $name) {
        if ($rel === $name || str_starts_with($rel, $name.'/')) {
            return true;
        }
    }
    foreach ($prefixes as $prefix) {
        if (str_starts_with($rel, $prefix)) {
            return true;
        }
    }

    return false;
}
