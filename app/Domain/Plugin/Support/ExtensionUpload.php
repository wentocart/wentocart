<?php

namespace App\Domain\Plugin\Support;

use App\Domain\Plugin\PageDocument;
use App\Domain\Security\RasterImageUpload;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Core-owned public-disk uploads for hosted extension fields.
 * Extensions declare type image|file; they never write the filesystem themselves.
 */
final class ExtensionUpload
{
    public static function isField(array $field): bool
    {
        return in_array((string) ($field['type'] ?? ''), ['image', 'file', 'upload'], true);
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $incoming
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    public static function mergeIncoming(string $vendor, string $name, array $fields, array $incoming, Request $request, array $current = []): array
    {
        foreach ($fields as $field) {
            if (! self::isField($field)) {
                continue;
            }
            $key = (string) ($field['name'] ?? '');
            if ($key === '') {
                continue;
            }
            $kind = ((string) ($field['type'] ?? '')) === 'image' ? 'image' : 'file';
            if ($request->hasFile('values.'.$key)) {
                self::deleteOwned($vendor, $name, $current[$key] ?? null);
                $incoming[$key] = self::store($vendor, $name, $key, $request->file('values.'.$key), $kind);
                continue;
            }
            $posted = $incoming[$key] ?? null;
            if (is_array($posted) && ($posted['clear'] ?? false) === true) {
                self::deleteOwned($vendor, $name, $current[$key] ?? $posted);
                $incoming[$key] = [];
                continue;
            }
            if (self::ownedRecord($vendor, $name, $posted) !== null) {
                $incoming[$key] = self::ownedRecord($vendor, $name, $posted);
                continue;
            }
            unset($incoming[$key]);
        }

        return $incoming;
    }

    /**
     * @return array{path: string, url: string, name: string, mime: string, size: int}
     */
    public static function store(string $vendor, string $name, string $field, UploadedFile $file, string $kind): array
    {
        $field = preg_replace('/[^a-z0-9_]/', '', strtolower($field)) ?? 'file';
        $dir = self::directory($vendor, $name);
        if ($kind === 'image') {
            $path = RasterImageUpload::storePublic($file, $dir, 5120);
        } else {
            self::assertDocument($file);
            $stored = $file->store($dir, 'public');
            if (! is_string($stored) || $stored === '') {
                throw ValidationException::withMessages([$field => 'Could not store the file.']);
            }
            $path = $stored;
        }

        $record = [
            'path' => $path,
            'url' => asset('storage/'.$path),
            'name' => PageDocument::plain($file->getClientOriginalName()),
            'mime' => PageDocument::plain((string) $file->getMimeType()),
            'size' => (int) $file->getSize(),
        ];

        return $record;
    }

    /**
     * @return array{path: string, url: string, name: string, mime?: string, size?: int}|array{}
     */
    public static function sanitizeRecord(mixed $value): array
    {
        $owned = self::ownedRecordFromPath($value);
        if ($owned === null) {
            return [];
        }

        return $owned;
    }

    /**
     * @return array{path: string, url: string, name: string, mime?: string, size?: int}|null
     */
    public static function ownedRecord(string $vendor, string $name, mixed $value): ?array
    {
        $record = self::ownedRecordFromPath($value);
        if ($record === null) {
            return null;
        }
        $prefix = self::directory($vendor, $name).'/';
        if (! str_starts_with($record['path'], $prefix)) {
            return null;
        }

        return $record;
    }

    public static function present(mixed $value, string $vendor, string $name): array
    {
        return self::ownedRecord($vendor, $name, $value) ?? [];
    }

    public static function deleteOwned(string $vendor, string $name, mixed $value): void
    {
        $record = self::ownedRecord($vendor, $name, $value);
        if ($record === null) {
            return;
        }
        Storage::disk('public')->delete($record['path']);
    }

    public static function directory(string $vendor, string $name): string
    {
        $vendor = preg_replace('/[^a-z0-9-]/', '', strtolower($vendor)) ?: 'app';
        $name = preg_replace('/[^a-z0-9-]/', '', strtolower($name)) ?: 'ext';

        return 'extensions/'.$vendor.'/'.$name;
    }

    /** @return list<string> */
    public static function documentExtensions(): array
    {
        return ['pdf', 'csv', 'txt', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'jpg', 'jpeg', 'png', 'webp', 'gif'];
    }

    public static function acceptFor(string $kind): string
    {
        if ($kind === 'image') {
            return 'image/jpeg,image/png,image/webp,image/gif,.jpg,.jpeg,.png,.webp,.gif';
        }

        return implode(',', array_map(fn ($ext) => '.'.$ext, self::documentExtensions()));
    }

    protected static function assertDocument(UploadedFile $file): void
    {
        $maxKb = 10240;
        if ($file->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages(['file' => 'File is too large.']);
        }
        $ext = strtolower((string) ($file->getClientOriginalExtension() ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION)));
        if (! in_array($ext, self::documentExtensions(), true)) {
            throw ValidationException::withMessages(['file' => 'File type is not allowed.']);
        }
        if (in_array($ext, ['php', 'phtml', 'phar', 'html', 'htm', 'js', 'svg', 'svgz', 'xml'], true)) {
            throw ValidationException::withMessages(['file' => 'File type is not allowed.']);
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
        $blocked = ['text/html', 'text/javascript', 'application/javascript', 'application/x-php', 'image/svg+xml'];
        if ($mime !== '' && (in_array($mime, $blocked, true) || str_contains($mime, 'php') || str_contains($mime, 'svg'))) {
            throw ValidationException::withMessages(['file' => 'File type is not allowed.']);
        }
    }

    /**
     * @return array{path: string, url: string, name: string, mime?: string, size?: int}|null
     */
    protected static function ownedRecordFromPath(mixed $value): ?array
    {
        if (is_string($value) && $value !== '') {
            $value = ['path' => $value];
        }
        if (! is_array($value)) {
            return null;
        }
        $path = str_replace('\\', '/', (string) ($value['path'] ?? ''));
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, '..') || ! preg_match('#^extensions/[a-z0-9-]+/[a-z0-9-]+/[a-zA-Z0-9._-]+$#', $path)) {
            return null;
        }

        return [
            'path' => $path,
            'url' => asset('storage/'.$path),
            'name' => PageDocument::plain((string) ($value['name'] ?? basename($path))),
            'mime' => PageDocument::plain((string) ($value['mime'] ?? '')),
            'size' => (int) ($value['size'] ?? 0),
        ];
    }
}
