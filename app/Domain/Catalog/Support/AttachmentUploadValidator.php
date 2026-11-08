<?php

namespace App\Domain\Catalog\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AttachmentUploadValidator
{
    /** @return list<string> */
    public static function allowedExtensions(): array
    {
        return array_values(array_unique(array_map(
            static fn (string $ext) => strtolower(ltrim($ext, '.')),
            config('wento.attachments.extensions', [])
        )));
    }

    /** @return list<string> */
    public static function blockedExtensions(): array
    {
        return array_values(array_unique(array_map(
            static fn (string $ext) => strtolower(ltrim($ext, '.')),
            config('wento.attachments.blocked_extensions', [])
        )));
    }

    /** @return list<string> */
    public static function allowedMimeTypes(): array
    {
        return array_values(array_unique(array_merge(
            config('wento.upload.attachments', []),
            config('wento.upload.images', []),
        )));
    }

    public static function maxKb(): int
    {
        return (int) config('wento.upload.max_attachment_kb', 10240);
    }

    public static function maxBytes(): int
    {
        return self::maxKb() * 1024;
    }

    public static function maxLabel(): string
    {
        $kb = self::maxKb();
        if ($kb >= 1024) {
            $mb = $kb / 1024;

            return ($mb === (float) (int) $mb ? (string) (int) $mb : (string) round($mb, 1)).' MB';
        }

        return $kb.' KB';
    }

    public static function tooLargeMessage(): string
    {
        return 'The file is too large. Maximum size is '.self::maxLabel().'.';
    }

    public static function uploadFailureMessage(UploadedFile $file): string
    {
        return match ($file->getError()) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => self::tooLargeMessage(),
            UPLOAD_ERR_PARTIAL => 'The file upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'Please select a file.',
            default => 'The file could not be uploaded. Check that it is under '.self::maxLabel().' and try again.',
        };
    }

    /** @return list<string> */
    public static function officeExtensions(): array
    {
        return ['doc', 'docx', 'xls', 'xlsx'];
    }

    /** Recommended php.ini upload_max_filesize for the configured app limit. */
    public static function recommendedUploadLimit(): string
    {
        $mb = (int) ceil(self::maxKb() / 1024) + 2;

        return $mb.'M';
    }

    /** Recommended php.ini post_max_size (slightly above upload limit for multipart overhead). */
    public static function recommendedPostLimit(): string
    {
        $uploadMb = (int) ceil(self::maxKb() / 1024) + 2;

        return ($uploadMb + 1).'M';
    }

    public static function validate(UploadedFile $file): void
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        if ($extension === '' || in_array($extension, self::blockedExtensions(), true)) {
            throw ValidationException::withMessages(['file' => 'This file type is not allowed.']);
        }

        $allowedExtensions = self::allowedExtensions();
        if ($allowedExtensions !== [] && ! in_array($extension, $allowedExtensions, true)) {
            throw ValidationException::withMessages(['file' => 'This file type is not allowed.']);
        }

        Validator::validate(
            ['file' => $file],
            ['file' => ['required', 'file', 'max:'.self::maxKb()]]
        );

        if ($file->getSize() > self::maxBytes()) {
            throw ValidationException::withMessages(['file' => self::tooLargeMessage()]);
        }

        $detected = self::detectMime($file);
        $clientMime = $file->getMimeType() ?: null;
        $mimeCandidates = array_values(array_unique(array_filter([$detected, $clientMime])));
        $mimeAllowed = false;
        foreach ($mimeCandidates as $mime) {
            if ($mime !== 'application/octet-stream' && in_array($mime, self::allowedMimeTypes(), true)) {
                $mimeAllowed = true;
                break;
            }
        }

        if (! $mimeAllowed && self::extensionTrustsGenericMime($extension, $mimeCandidates)) {
            $mimeAllowed = true;
        }

        if (! $mimeAllowed) {
            throw ValidationException::withMessages(['file' => 'This file type is not allowed.']);
        }
    }

    /** @param  list<string|null>  $mimeCandidates */
    protected static function extensionTrustsGenericMime(string $extension, array $mimeCandidates): bool
    {
        if (! in_array($extension, self::officeExtensions(), true)) {
            return false;
        }

        foreach ($mimeCandidates as $mime) {
            if ($mime !== null && $mime !== 'application/octet-stream') {
                return false;
            }
        }

        return true;
    }

    public static function detectMime(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();
        if (! $path || ! is_file($path)) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return $file->getMimeType() ?: null;
        }

        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return is_string($mime) && $mime !== '' ? $mime : null;
    }

    public static function sanitizeOriginalFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', trim($name)));
        $name = preg_replace('/[^\w.\- ()\[\]]+/u', '_', $name) ?? 'file';

        return $name !== '' ? $name : 'file';
    }
}
