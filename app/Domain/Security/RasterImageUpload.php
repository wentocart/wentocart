<?php

namespace App\Domain\Security;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Raster-only image uploads (no SVG) onto the active media disk
 * ({@see \App\Domain\Settings\ObjectStorageSettings::diskName()}).
 */
final class RasterImageUpload
{
    /** @return list<string> */
    public static function allowedMimes(): array
    {
        $configured = config('wento.upload.images', [
            'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        ]);

        return array_values(array_filter(
            array_map('strval', $configured),
            static fn (string $mime) => $mime !== '' && ! str_contains($mime, 'svg')
        ));
    }

    /** @return list<string> */
    public static function rules(int $maxKb = 4096): array
    {
        return ['nullable', 'file', 'max:'.$maxKb, 'mimes:jpg,jpeg,png,webp,gif'];
    }

    /** @return list<string> */
    public static function requiredRules(int $maxKb = 5120): array
    {
        return ['required', 'file', 'max:'.$maxKb, 'mimes:jpg,jpeg,png,webp,gif'];
    }

    public static function assert(UploadedFile $file, int $maxKb = 5120): void
    {
        if ($file->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages(['image' => 'File is too large.']);
        }

        $ext = strtolower((string) ($file->getClientOriginalExtension() ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION)));
        if (in_array($ext, ['svg', 'svgz', 'xml', 'html', 'htm', 'js'], true)) {
            throw ValidationException::withMessages(['image' => 'SVG and script files are not allowed.']);
        }

        $path = $file->getRealPath();
        $detected = null;
        if ($path && is_file($path)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $path) ?: null;
                finfo_close($finfo);
            }
        }
        $mime = strtolower((string) ($detected ?: $file->getMimeType() ?: ''));
        if ($mime === '' || str_contains($mime, 'svg') || ! in_array($mime, self::allowedMimes(), true)) {
            throw ValidationException::withMessages(['image' => 'File type is not allowed.']);
        }
    }

    public static function storePublic(UploadedFile $file, string $directory, int $maxKb = 5120): string
    {
        self::assert($file, $maxKb);
        $disk = \App\Domain\Settings\ObjectStorageSettings::diskName();
        $path = $file->store($directory, $disk);
        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages(['image' => 'Could not store the image.']);
        }

        return $path;
    }
}
