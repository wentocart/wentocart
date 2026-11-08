<?php

namespace App\Domain\Installer;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * /storage/* must resolve to storage/app/public. Windows often leaves a real
 * public/storage folder (gitignore + a partial catalog copy) instead of a link.
 */
final class PublicStorageLink
{
    public static function ensure(): void
    {
        $link = public_path('storage');
        $target = storage_path('app/public');
        File::ensureDirectoryExists($target);

        if (self::sameDir($link, $target)) {
            return;
        }

        if (is_dir($link) && ! self::isLink($link)) {
            File::copyDirectory($link, $target);
            File::deleteDirectory($link);
        } elseif (is_link($link) || is_file($link)) {
            File::delete($link);
        }

        try {
            Artisan::call('storage:link', ['--force' => true]);
        } catch (Throwable) {
            // Windows may lack permission for a symlink; junction below.
        }

        if (self::sameDir($link, $target)) {
            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            if (is_dir($link) && ! self::isLink($link)) {
                File::deleteDirectory($link);
            }
            $from = str_replace('/', '\\', $link);
            $to = str_replace('/', '\\', $target);
            exec('cmd /c mklink /J '.escapeshellarg($from).' '.escapeshellarg($to));
        }
    }

    private static function isLink(string $path): bool
    {
        if (is_link($path)) {
            return true;
        }

        if (PHP_OS_FAMILY !== 'Windows' || ! is_dir($path)) {
            return false;
        }

        $read = @readlink($path);

        return is_string($read) && $read !== '';
    }

    private static function sameDir(string $link, string $target): bool
    {
        $resolved = @realpath($link);
        $want = @realpath($target);

        return is_string($resolved) && is_string($want) && strcasecmp($resolved, $want) === 0;
    }
}
