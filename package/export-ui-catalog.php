<?php

/**
 * Write resources/i18n/ui-source.json for a production zip.
 * The zip omits React source. Translation sync reads this catalog instead.
 *
 * Usage: php package/export-ui-catalog.php /path/to/source /path/to/ui-source.json
 */
$source = $argv[1] ?? '';
$out = $argv[2] ?? '';
if ($source === '' || $out === '' || ! is_dir($source)) {
    fwrite(STDERR, "Usage: php package/export-ui-catalog.php <source-root> <output.json>\n");
    exit(1);
}

$roots = [
    $source.'/resources/js/admin',
    $source.'/storefront/src',
];
$hits = [];
foreach ($roots as $root) {
    if (! is_dir($root)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }
        $ext = strtolower($file->getExtension());
        if ($ext !== 'js' && $ext !== 'jsx') {
            continue;
        }
        $contents = (string) file_get_contents($file->getPathname());
        $contents = preg_replace('#/\*.*?\*/#s', '', $contents) ?? $contents;
        $contents = preg_replace('#^\s*//.*$#m', '', $contents) ?? $contents;
        if (! preg_match_all("/\\bt\\(\\s*['\"]([a-z0-9._-]+)['\"]\\s*,\\s*['\"]([^'\"]+)['\"]\\s*\\)/", $contents, $matches, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($matches as $match) {
            $key = $match[1];
            $parts = explode('.', $key);
            $hits[] = [
                'surface' => $parts[0] === 'storefront' ? 'storefront' : 'admin',
                'page' => count($parts) >= 2 ? $parts[0].'.'.$parts[1] : $key,
                'key' => $key,
                'source' => $match[2],
            ];
        }
    }
}

if ($hits === []) {
    fwrite(STDERR, "No translation keys found under resources/js/admin or storefront/src.\n");
    exit(1);
}

$dir = dirname($out);
if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
    fwrite(STDERR, "Could not create {$dir}\n");
    exit(1);
}
$json = json_encode($hits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if ($json === false || file_put_contents($out, $json."\n") === false) {
    fwrite(STDERR, "Could not write {$out}\n");
    exit(1);
}
echo 'Wrote '.count($hits).' translation keys to '.$out.PHP_EOL;
