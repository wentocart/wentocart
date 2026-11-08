<?php

/**
 * True when a web request is asking for a file the shop must not hand out.
 * setup/ stays reachable so the wizard can run. Tidy removes it after install.
 */
function wento_sensitive_request(string $path): bool
{
    $path = str_replace('\\', '/', $path);
    $path = (string) (parse_url($path, PHP_URL_PATH) ?: $path);
    if (str_contains($path, '..')) {
        return true;
    }
    $path = strtolower(ltrim($path, '/'));
    if ($path === '') {
        return false;
    }
    if ($path === '.env' || str_starts_with($path, '.env.') || str_contains($path, '/.env')) {
        return true;
    }
    if ($path === '.git' || str_starts_with($path, '.git/')) {
        return true;
    }
    if (str_ends_with($path, '.sql')) {
        return true;
    }
    if (in_array($path, ['artisan', 'composer.json', 'composer.lock', 'phpunit.xml'], true)) {
        return true;
    }

    $prefixes = [
        'database/',
        'vendor/',
        'node_modules/',
        'app/',
        'bootstrap/',
        'config/',
        'routes/',
        'resources/',
        'scripts/',
        'package/',
        'extensions/',
        'storefront/',
        'docker/',
        'plugin-packs/',
        'storage/logs/',
        'storage/framework/',
        'tests/',
        'docs/',
    ];
    foreach ($prefixes as $prefix) {
        $dir = rtrim($prefix, '/');
        if ($path === $dir || str_starts_with($path, $prefix)) {
            return true;
        }
    }

    return false;
}
