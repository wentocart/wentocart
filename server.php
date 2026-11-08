<?php

/**
 * Router for the built-in PHP server. A web server uses the rewrite file instead.
 * Sensitive paths are blocked only after the shop files have been unpacked.
 */
$block = __DIR__.'/app/Domain/Installer/block-sensitive.php';
if (is_file($block)) {
    require_once $block;
}

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if (str_contains($uri, '..')) {
    http_response_code(400);
    exit;
}
if (function_exists('wento_sensitive_request') && wento_sensitive_request($uri)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found.\n";

    return true;
}

$requested = __DIR__.str_replace('/', DIRECTORY_SEPARATOR, $uri);
if ($uri !== '/' && is_file($requested)) {
    $ext = strtolower((string) pathinfo($requested, PATHINFO_EXTENSION));
    if ($ext === 'php') {
        $_SERVER['SCRIPT_FILENAME'] = $requested;
        $_SERVER['SCRIPT_NAME'] = $uri;
        $_SERVER['PHP_SELF'] = $uri;
        require $requested;

        return true;
    }

    return false;
}

$publicFile = __DIR__.'/public'.$uri;
if ($uri !== '/' && is_file($publicFile)) {
    $ext = strtolower((string) pathinfo($publicFile, PATHINFO_EXTENSION));
    $mimes = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'mjs' => 'application/javascript',
        'json' => 'application/json',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'map' => 'application/json',
        'webp' => 'image/webp',
        'txt' => 'text/plain',
    ];
    header('Content-Type: '.($mimes[$ext] ?? 'application/octet-stream'));
    header('Content-Length: '.filesize($publicFile));
    readfile($publicFile);
    exit;
}

require __DIR__.'/index.php';
