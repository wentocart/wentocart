<?php

/**
 * Fail if public/build does not include the extension admin navigation UI.
 *
 * Usage (from repo root):
 *   php package/verify-admin-build.php
 *   php package/verify-admin-build.php /path/to/public/build/manifest.json
 */

declare(strict_types=1);

$manifestPath = $argv[1] ?? (dirname(__DIR__).'/public/build/manifest.json');
$manifestPath = (string) $manifestPath;

if (! is_file($manifestPath)) {
    fwrite(STDERR, "Missing Vite manifest: {$manifestPath}\n");
    exit(1);
}

$raw = file_get_contents($manifestPath);
$manifest = is_string($raw) ? json_decode($raw, true) : null;
if (! is_array($manifest)) {
    fwrite(STDERR, "Vite manifest is not valid JSON: {$manifestPath}\n");
    exit(1);
}

$buildRoot = dirname($manifestPath);

/**
 * Resolve a compiled asset path from a manifest entry key or a relative file path.
 */
function wento_build_file(string $buildRoot, array $manifest, string $keyOrFile): ?string
{
    if (isset($manifest[$keyOrFile]) && is_array($manifest[$keyOrFile])) {
        $file = $manifest[$keyOrFile]['file'] ?? null;
        if (! is_string($file) || $file === '') {
            return null;
        }
        $absolute = $buildRoot.'/'.ltrim(str_replace('\\', '/', $file), '/');

        return is_file($absolute) ? $absolute : null;
    }

    $absolute = $buildRoot.'/'.ltrim(str_replace('\\', '/', $keyOrFile), '/');

    return is_file($absolute) ? $absolute : null;
}

/**
 * ExtensionPrimaryNav is a shared chunk (manifest key like _ExtensionPrimaryNav-….js),
 * not a Laravel page entry.
 *
 * @return array{0: ?string, 1: ?string} [absolute path, relative file]
 */
function wento_resolve_primary_nav(string $buildRoot, array $manifest, array $tabsEntry): array
{
    foreach ($tabsEntry['imports'] ?? [] as $import) {
        if (! is_string($import)) {
            continue;
        }
        if (! str_contains($import, 'ExtensionPrimaryNav')) {
            continue;
        }
        $file = is_array($manifest[$import] ?? null) ? ($manifest[$import]['file'] ?? null) : null;
        if (! is_string($file) || $file === '') {
            continue;
        }
        $absolute = $buildRoot.'/'.ltrim(str_replace('\\', '/', $file), '/');
        if (is_file($absolute)) {
            return [$absolute, $file];
        }
    }

    foreach ($manifest as $key => $entry) {
        if (! is_string($key) || ! is_array($entry)) {
            continue;
        }
        if (! str_contains($key, 'ExtensionPrimaryNav')) {
            continue;
        }
        $file = $entry['file'] ?? null;
        if (! is_string($file) || $file === '') {
            continue;
        }
        $absolute = $buildRoot.'/'.ltrim(str_replace('\\', '/', $file), '/');
        if (is_file($absolute)) {
            return [$absolute, $file];
        }
    }

    return [null, null];
}

$hostKey = 'resources/js/admin/Pages/Apps/Host.jsx';
$tabsKey = 'resources/js/admin/Pages/Apps/ExtensionTabbedSettings.jsx';

$errors = [];
$hostPath = wento_build_file($buildRoot, $manifest, $hostKey);
$tabsPath = wento_build_file($buildRoot, $manifest, $tabsKey);

if (! isset($manifest[$hostKey]) || ! is_array($manifest[$hostKey])) {
    $errors[] = "Manifest is missing entry {$hostKey}";
} elseif ($hostPath === null) {
    $errors[] = 'Compiled Host bundle missing on disk: '.(($manifest[$hostKey]['file'] ?? '') ?: '(empty)');
}
if (! isset($manifest[$tabsKey]) || ! is_array($manifest[$tabsKey])) {
    $errors[] = "Manifest is missing entry {$tabsKey}";
} elseif ($tabsPath === null) {
    $errors[] = 'Compiled ExtensionTabbedSettings bundle missing on disk: '.(($manifest[$tabsKey]['file'] ?? '') ?: '(empty)');
}

$tabsEntry = is_array($manifest[$tabsKey] ?? null) ? $manifest[$tabsKey] : [];
[$navPath, $navRel] = wento_resolve_primary_nav($buildRoot, $manifest, $tabsEntry);
if ($navPath === null) {
    $errors[] = 'Manifest is missing the ExtensionPrimaryNav shared chunk (imported by ExtensionTabbedSettings)';
}

if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors)."\n");
    exit(1);
}

$host = (string) file_get_contents($hostPath);
$tabs = (string) file_get_contents($tabsPath);
$nav = (string) file_get_contents($navPath);

if (! str_contains($host, 'nav:') && ! str_contains($host, 'nav=')) {
    $errors[] = 'Host bundle does not pass schema.nav to ExtensionTabbedSettings';
}
if (! str_contains($tabs, 'panelTabsShell') && ! str_contains($tabs, 'panelTabs')) {
    $errors[] = 'ExtensionTabbedSettings bundle is missing the panel-style tab shell';
}
if (! str_contains($tabs, 'ExtensionPrimaryNav')) {
    $errors[] = 'ExtensionTabbedSettings bundle does not import ExtensionPrimaryNav';
}
if (! str_contains($nav, 'href') || (! str_contains($nav, 'statusTab') && ! str_contains($nav, 'aria-current'))) {
    $errors[] = 'ExtensionPrimaryNav bundle does not render navigable tabs';
}
if (! str_contains($nav, 'Extension pages')) {
    $errors[] = 'ExtensionPrimaryNav bundle is missing its aria-label';
}

$checkoutKey = 'storefront/src/pages/Checkout.jsx';
$entryKey = 'storefront/src/main.jsx';
$checkoutPath = wento_build_file($buildRoot, $manifest, $checkoutKey);
$entryPath = wento_build_file($buildRoot, $manifest, $entryKey);
$checkoutRel = is_array($manifest[$checkoutKey] ?? null) ? ($manifest[$checkoutKey]['file'] ?? '') : '';
$entryRel = is_array($manifest[$entryKey] ?? null) ? ($manifest[$entryKey]['file'] ?? '') : '';

if (! isset($manifest[$checkoutKey]) || ! is_array($manifest[$checkoutKey])) {
    $errors[] = "Manifest is missing entry {$checkoutKey}";
} elseif ($checkoutPath === null) {
    $errors[] = 'Compiled checkout bundle missing on disk: '.($checkoutRel !== '' ? $checkoutRel : '(empty)');
}
if (! isset($manifest[$entryKey]) || ! is_array($manifest[$entryKey])) {
    $errors[] = "Manifest is missing entry {$entryKey}";
} elseif ($entryPath === null) {
    $errors[] = 'Compiled storefront entry missing on disk: '.($entryRel !== '' ? $entryRel : '(empty)');
}

if ($checkoutPath !== null && $entryPath !== null && is_string($checkoutRel) && is_string($entryRel)) {
    $checkoutJs = (string) file_get_contents($checkoutPath);
    $entryJs = (string) file_get_contents($entryPath);
    $checkoutFile = basename(str_replace('\\', '/', $checkoutRel));
    $entryFile = basename(str_replace('\\', '/', $entryRel));
    if (str_contains($checkoutJs, 'return`/plugins/${')) {
        $errors[] = 'Checkout bundle still requests plugin files from the domain root';
    }
    if (preg_match('/return [A-Za-z_$][\w$]*\(`\/plugins\/\$\{/', $checkoutJs) !== 1) {
        $errors[] = 'Checkout bundle does not prefix plugin files with the shop folder';
    }
    if ($checkoutFile === '' || ! str_contains($entryJs, './'.$checkoutFile)) {
        $errors[] = 'Storefront entry does not load the checkout bundle named in the manifest';
    }
    if ($entryFile === '' || ! str_contains($checkoutJs, './'.$entryFile)) {
        $errors[] = 'Checkout bundle does not load the storefront entry named in the manifest';
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors)."\n");
    exit(1);
}

fwrite(STDOUT, "Admin build OK\n");
fwrite(STDOUT, '  Host: '.$manifest[$hostKey]['file']."\n");
fwrite(STDOUT, '  Tabs: '.$manifest[$tabsKey]['file']."\n");
fwrite(STDOUT, '  Nav:  '.$navRel."\n");
fwrite(STDOUT, '  Checkout: '.$checkoutRel."\n");
exit(0);
