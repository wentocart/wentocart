<?php

namespace App\Support;

use App\Domain\Installer\Models\ShopUrl;

final class WebBase
{
    /** Install folder (e.g. /shop). Empty at domain root. Does not include a multishop virtual path. */
    public static function installPath(): string
    {
        $base = self::requestBasePath();
        if ($base === '') {
            $base = rtrim((string) (parse_url((string) config('app.url'), PHP_URL_PATH) ?: ''), '/');
        }

        return $base;
    }

    /** Storefront base, including the matched shop virtual path when multishop is on. */
    public static function path(): string
    {
        $base = self::installPath();
        if (! app()->bound('request')) {
            return $base;
        }
        try {
            $matched = request()->attributes->get('wento.shop_url');
            if ($matched instanceof ShopUrl) {
                $virtual = $matched->virtualSegment();
                if ($virtual !== '') {
                    $base = ($base === '' ? '' : $base).'/'.$virtual;
                }
            }
        } catch (\Throwable) {
            // Keep the install path when the request is not available.
        }

        return $base;
    }

    private static function requestBasePath(): string
    {
        if (app()->runningInConsole() || ! app()->bound('request')) {
            return '';
        }
        try {
            $base = rtrim(str_replace('\\', '/', (string) request()->getBasePath()), '/');

            return (string) preg_replace('#/public$#', '', $base);
        } catch (\Throwable) {
            return '';
        }
    }

    public static function url(string $path): string
    {
        $path = '/'.ltrim(str_replace('\\', '/', $path), '/');
        $base = self::isPublicAsset($path) ? self::installPath() : self::path();
        if ($base === '') {
            return $path;
        }
        while (str_starts_with($path, $base.$base)) {
            $path = substr($path, strlen($base));
        }
        if ($path === $base || str_starts_with($path, $base.'/')) {
            return $path;
        }

        return $base.$path;
    }

    /** Built files and uploads live on the install path. A virtual shop path does not have its own /build or /themes. */
    public static function isPublicAsset(string $path): bool
    {
        $path = '/'.ltrim(str_replace('\\', '/', $path), '/');

        return (bool) preg_match('#^/(build|themes|plugins|hot|images|storage|i18n)(/|$)#', $path);
    }

    /** Prefix /themes and /storage paths in theme JSON so the storefront can load them in a folder install. */
    public static function prefixPublicMedia(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::prefixPublicMedia($item);
            }

            return $value;
        }
        if (! is_string($value) || $value === '') {
            return $value;
        }
        if (str_starts_with($value, '/themes/') || str_starts_with($value, '/storage/')) {
            return self::url($value);
        }

        return $value;
    }
}
