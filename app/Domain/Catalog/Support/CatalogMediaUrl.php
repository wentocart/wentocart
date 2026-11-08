<?php

namespace App\Domain\Catalog\Support;

use App\Domain\Settings\ObjectStorageSettings;

final class CatalogMediaUrl
{
    public static function url(?string $path, string $size = DefaultImage::MD): string
    {
        if (is_string($path) && $path !== '') {
            if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                return $path;
            }

            if (str_starts_with($path, '/')) {
                if (str_starts_with($path, '/storage/')) {
                    return self::storedUrl(ltrim(substr($path, strlen('/storage/')), '/'), $size);
                }
                $publicPath = public_path(ltrim($path, '/'));
                if (! is_file($publicPath)) {
                    return self::placeholder($size);
                }

                return \App\Support\WebBase::url($path);
            }

            $disk = ObjectStorageSettings::diskName();
            if ($disk !== 'public') {
                return ObjectStorageSettings::disk()->url(ltrim($path, '/'));
            }

            return self::storedUrl(ltrim($path, '/'), $size);
        }

        return \App\Support\WebBase::url(ltrim(DefaultImage::path($size), '/'));
    }

    public static function urlForType(?string $path, string $imageType): string
    {
        return self::url($path, DefaultImage::sizeForType($imageType));
    }

    public static function asset(?string $path, string $size = DefaultImage::MD): string
    {
        $relative = self::url($path, $size);
        if (str_starts_with($relative, 'http://') || str_starts_with($relative, 'https://')) {
            return $relative;
        }

        $appUrl = rtrim((string) config('app.url'), '/');
        $appPath = rtrim((string) (parse_url($appUrl, PHP_URL_PATH) ?: ''), '/');
        $origin = $appPath !== '' ? substr($appUrl, 0, -strlen($appPath)) : $appUrl;
        if (! app()->runningInConsole()) {
            try {
                $origin = rtrim(request()->getSchemeAndHttpHost(), '/');
            } catch (\Throwable) {
            }
        }

        return $origin.$relative;
    }

    private static function storedUrl(string $relative, string $size): string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || str_contains($relative, '..')) {
            return self::placeholder($size);
        }

        if (app()->runningUnitTests()) {
            return \App\Support\WebBase::url('storage/'.$relative);
        }

        $storageFile = storage_path('app/public/'.$relative);
        $publicFile = public_path('storage/'.$relative);
        if (! is_file($storageFile) && ! is_file($publicFile)) {
            return self::placeholder($size);
        }

        if (is_file($publicFile)) {
            return \App\Support\WebBase::url('storage/'.$relative);
        }

        return \App\Support\WebBase::url('media/'.$relative);
    }

    private static function placeholder(string $size): string
    {
        return \App\Support\WebBase::url(ltrim(DefaultImage::path($size), '/'));
    }
}
