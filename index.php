<?php

if (is_file(__DIR__.'/setup/guard.php')) {
    require_once __DIR__.'/setup/guard.php';
    wento_setup_guard_boot(__DIR__);
}

/**
 * Project-folder front controller.
 * /setup must never fall through to Laravel (that caused a reload loop).
 * After install tidy removes setup/ — this file must still boot the shop.
 */
$vendor = is_file(__DIR__.'/vendor/autoload.php');
$lockPath = __DIR__.'/storage/app/installed.lock';
$lock = is_file($lockPath);
$installing = is_file(__DIR__.'/storage/app/installing.lock');
if (! $lock && ! $installing && is_file(__DIR__.'/.env')) {
    $env = (string) file_get_contents(__DIR__.'/.env');
    if (preg_match('/^WENTO_INSTALLED=(true|1|yes)\s*$/mi', $env)) {
        $lock = true;
        $dir = __DIR__.'/storage/app';
        if (is_dir($dir) && is_writable($dir)) {
            @file_put_contents($lockPath, date('c'));
        }
    }
}

$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
// Apache Action CGI (php83.cgi) — dirname is still the shop folder.
if (preg_match('#^(.*?)/[^/]+\.cgi$#i', $scriptName, $cgi)) {
    $scriptName = ($cgi[1] === '' ? '' : $cgi[1]).'/index.php';
}
$base = rtrim(dirname($scriptName), '/');
if ($base === '.' || $base === '\\') {
    $base = '';
}
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = $uri;
if ($base !== '' && ($uri === $base || str_starts_with($uri, $base.'/'))) {
    $path = substr($uri, strlen($base)) ?: '/';
}
if ($path === '' || $path[0] !== '/') {
    $path = '/'.$path;
}
require_once __DIR__.'/app/Domain/Installer/block-sensitive.php';
if (wento_sensitive_request($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found.';
    exit;
}

if (! $lock && is_file(__DIR__.'/setup/lib.php')) {
    require_once __DIR__.'/setup/lib.php';
    $permissionProblems = setup_permission_problems();
    if ($permissionProblems !== []) {
        if (str_contains($path, 'api.php')) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode([
                'ok' => false,
                'permission' => true,
                'message' => 'The web server cannot write in this shop folder. Fix the folder permissions, then reload this page.',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
        setup_render_permission_page($permissionProblems);
        exit;
    }
}
$abs = static function (string $rel) use ($base): string {
    $rel = '/'.ltrim($rel, '/');

    return $base === '' ? $rel : $base.$rel;
};

// Only tidy after the wizard has left /setup. Chrome favicon and other
// front-controller hits used to delete setup/api.php while the installer
// page was still polling, so the UI sat on Please Wait at 100%.
if ($lock && is_file(__DIR__.'/storage/app/tidy-installer') && ($path === '/installed' || str_starts_with($path, '/installed/'))) {
    require __DIR__.'/app/Domain/Installer/tidy-merchant.php';
    wento_tidy_merchant_root(__DIR__);
}

if (str_starts_with($path, '/setup')) {
    $file = __DIR__.str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (is_file($file)) {
        require $file;
        exit;
    }
    if ($lock) {
        header('Location: '.$abs('installed'), true, 302);
        exit;
    }
    require_once __DIR__.'/app/Domain/Installer/install-gate.php';
    wento_render_install_gate($abs('setup/index.php'), 'setup_missing');
    exit;
}

if (! $lock || ! $vendor) {
    if (is_file(__DIR__.'/setup/index.php')) {
        if ($path === '/_extract.php') {
            require_once __DIR__.'/app/Domain/Installer/install-gate.php';
            wento_render_install_gate($abs('setup/index.php?start=1'), 'extract_removed');
            exit;
        }
        header('Location: '.$abs('setup/index.php'), true, 302);
        exit;
    }
    require_once __DIR__.'/app/Domain/Installer/install-gate.php';
    wento_render_install_gate($abs('index.php'), 'setup_missing');
    exit;
}

require __DIR__.'/public/index.php';
