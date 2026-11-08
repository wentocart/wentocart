<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Attachment;
use App\Domain\Catalog\Models\AttachmentTranslation;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Support\AttachmentUploadValidator;
use App\Domain\Localization\Models\Language;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AttachmentService
{
    /** Free spec sheet / manual: public and downloadable by anyone. */
    public const ROLE_DOCUMENT = 'document';

    /** The purchased deliverable: only reachable through an issued order download token. */
    public const ROLE_PAID = 'paid';

    /** @return list<string> */
    public static function roles(): array
    {
        return [self::ROLE_DOCUMENT, self::ROLE_PAID];
    }

    public static function normalizeRole(?string $role): string
    {
        return $role === self::ROLE_PAID ? self::ROLE_PAID : self::ROLE_DOCUMENT;
    }

    /**
     * Virtual / downloadable / service products may use Paid download (downloadable links).
     * Every other type is Document (public).
     */
    public static function productAllowsPaidRole(Product|string|null $product): bool
    {
        $type = is_string($product) ? $product : (string) ($product?->type ?? '');

        return in_array($type, ['virtual', 'downloadable', 'service'], true);
    }

    /**
     * Resolve the role that should be stored for a product attachment.
     * Non-virtual products always get Document, ignoring any requested role.
     */
    public function resolveRoleForProduct(Product $product, ?string $requestedRole = null): string
    {
        if (! self::productAllowsPaidRole($product)) {
            return self::ROLE_DOCUMENT;
        }

        return $requestedRole !== null
            ? self::normalizeRole($requestedRole)
            : self::ROLE_PAID;
    }

    /**
     * The effective role of a product file.
     *
     * Non-virtual products are always Document (public). On Virtual products, a blank
     * role (legacy link) defaults to Paid download; otherwise the stored role wins.
     */
    public function roleOf(?string $role, ?Product $product = null): string
    {
        if ($product !== null && ! self::productAllowsPaidRole($product)) {
            return self::ROLE_DOCUMENT;
        }

        if ($role === self::ROLE_PAID || $role === self::ROLE_DOCUMENT) {
            return $role;
        }

        return self::productAllowsPaidRole($product)
            ? self::ROLE_PAID
            : self::ROLE_DOCUMENT;
    }

    public function present(Attachment $attachment, ?string $locale = null): array
    {
        // Avoid lazy-load violations when callers only load attachments (not translations).
        $translation = $attachment->relationLoaded('translations')
            ? $attachment->translation($locale)
            : null;

        return [
            'id' => $attachment->id,
            'name' => $translation?->name ?? $attachment->name,
            'description' => $translation?->description ?? $attachment->description,
            'translations' => $attachment->relationLoaded('translations')
                ? $attachment->translations->map(fn (AttachmentTranslation $row) => [
                    'language_code' => $row->language_code,
                    'name' => $row->name,
                    'description' => $row->description,
                ])->values()->all()
                : [],
            'original_filename' => $attachment->original_filename,
            'stored_filename' => $attachment->stored_filename,
            'mime_type' => $attachment->mime_type,
            'file_type_label' => self::fileTypeLabel($attachment),
            'file_size' => (int) $attachment->file_size,
            'file_size_label' => self::formatSize((int) $attachment->file_size),
            'download_url' => route('admin.files.download', $attachment),
            'products_count' => (int) ($attachment->products_count ?? $attachment->products()->count()),
            'is_image' => str_starts_with((string) $attachment->mime_type, 'image/'),
            'preview_url' => str_starts_with((string) $attachment->mime_type, 'image/')
                ? route('admin.files.preview', $attachment)
                : null,
            'created_at' => $attachment->created_at?->toIso8601String(),
            'updated_at' => $attachment->updated_at?->toIso8601String(),
        ];
    }

    public function presentForProduct(Attachment $attachment, Product $product): array
    {
        $role = $this->roleOf($product->attachmentLink((int) $attachment->id)?->role, $product);
        $isImage = str_starts_with((string) $attachment->mime_type, 'image/');
        $isPublicDocument = $role === self::ROLE_DOCUMENT;

        return [
            ...$this->present($attachment),
            'role' => $role,
            'is_paid' => $role === self::ROLE_PAID,
            // Storefront/public URL — never expose paid deliverables here.
            'download_url' => $isPublicDocument
                ? route('attachments.download', [
                    'attachment' => $attachment->id,
                    'product' => $product->id,
                ])
                : null,
            // Inline image preview for the product page (same auth as download).
            'preview_url' => ($isPublicDocument && $isImage)
                ? route('attachments.preview', [
                    'attachment' => $attachment->id,
                    'product' => $product->id,
                ])
                : null,
            // Staff-only preview from the product editor (auth:admin).
            'admin_download_url' => route('admin.files.download', $attachment),
        ];
    }

    /**
     * Public product files for the storefront page.
     *
     * Paid deliverables are excluded: they are delivered per order from the
     * customer's account downloads, not from the public product page.
     *
     * @return list<array<string, mixed>>
     */
    public function presentDocumentsOnly(Product $product): array
    {
        return $product->attachments
            ->map(fn (Attachment $attachment) => $this->presentForProduct($attachment, $product))
            ->filter(fn (array $row) => $row['role'] === self::ROLE_DOCUMENT)
            ->values()
            ->all();
    }

    public static function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }

    public static function fileTypeLabel(Attachment $attachment): string
    {
        $extension = strtoupper((string) pathinfo((string) $attachment->original_filename, PATHINFO_EXTENSION));
        if ($extension !== '') {
            return $extension;
        }

        $mime = (string) $attachment->mime_type;

        return match (true) {
            str_starts_with($mime, 'image/') => 'Image',
            $mime === 'application/pdf' => 'PDF',
            str_contains($mime, 'wordprocessingml') => 'DOCX',
            str_contains($mime, 'spreadsheetml') => 'XLSX',
            $mime === 'application/msword' => 'DOC',
            $mime === 'application/vnd.ms-excel' => 'XLS',
            $mime === 'text/plain' => 'Text',
            $mime === 'text/csv' => 'CSV',
            $mime === 'application/zip' => 'ZIP',
            default => $mime !== '' ? $mime : '—',
        };
    }

    public function create(array $data, UploadedFile $file): Attachment
    {
        return DB::transaction(function () use ($data, $file) {
            $stored = $this->storeUploadedFile($file);
            $default = Language::defaultCode();
            $defaultName = trim((string) ($data['name'] ?? ''))
                ?: pathinfo($stored['original_filename'], PATHINFO_FILENAME);

            $attachment = Attachment::query()->create([
                'name' => $defaultName,
                'original_filename' => $stored['original_filename'],
                'stored_filename' => $stored['stored_filename'],
                'storage_path' => $stored['storage_path'],
                'disk' => $stored['disk'],
                'mime_type' => $stored['mime_type'],
                'file_size' => $stored['file_size'],
                'description' => $data['description'] ?? null,
            ]);

            $this->syncTranslations($attachment, $data, $default);

            return $attachment->fresh(['translations']);
        });
    }

    public function update(Attachment $attachment, array $data, ?UploadedFile $file = null): Attachment
    {
        return DB::transaction(function () use ($attachment, $data, $file) {
            $previousPath = $attachment->storage_path;
            $previousDisk = $attachment->disk;

            if ($file) {
                $stored = $this->storeUploadedFile($file);
                $attachment->fill([
                    'original_filename' => $stored['original_filename'],
                    'stored_filename' => $stored['stored_filename'],
                    'storage_path' => $stored['storage_path'],
                    'disk' => $stored['disk'],
                    'mime_type' => $stored['mime_type'],
                    'file_size' => $stored['file_size'],
                ]);
            }

            $attachment->save();

            $this->syncTranslations($attachment, $data, Language::defaultCode());

            if ($file && $previousPath !== '' && ($previousPath !== $attachment->storage_path || $previousDisk !== $attachment->disk)) {
                $this->deletePhysicalFile($previousDisk, $previousPath);
            }

            return $attachment->fresh(['translations']);
        });
    }

    public function delete(Attachment $attachment): void
    {
        DB::transaction(function () use ($attachment) {
            $attachment->products()->detach();
            $path = $attachment->storage_path;
            $disk = $attachment->disk;
            $attachment->delete();
            $this->deletePhysicalFile($disk, $path);
        });
    }

    /**
     * Sync the product's file library.
     *
     * @param  list<int|string>  $attachmentIds
     * @param  array<int|string, string>  $roles  attachment id => role (document|paid)
     */
    public function syncProductAttachments(Product $product, array $attachmentIds, array $roles = []): void
    {
        $ids = collect($attachmentIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $sync = collect($ids)
            ->mapWithKeys(function (int $id) use ($roles, $product) {
                $requested = $roles[$id] ?? $roles[(string) $id] ?? null;

                return [$id => [
                    'role' => $this->resolveRoleForProduct(
                        $product,
                        $requested !== null ? (string) $requested : null,
                    ),
                ]];
            })
            ->all();

        $product->attachments()->sync($sync);
    }

    public function attachToProduct(Product $product, Attachment $attachment, ?string $role = null): void
    {
        $product->attachments()->syncWithoutDetaching([
            $attachment->id => [
                'role' => $this->resolveRoleForProduct($product, $role),
            ],
        ]);
    }

    public function detachFromProduct(Product $product, Attachment $attachment): void
    {
        $product->attachments()->detach($attachment->id);
    }

    public function createAndAttach(Product $product, UploadedFile $file, ?string $name = null, ?string $description = null, ?string $role = null): Attachment
    {
        $attachment = $this->create([
            'name' => $name,
            'description' => $description,
        ], $file);
        $this->attachToProduct($product, $attachment, $role);

        return $attachment;
    }

    public function resolvePhysicalPath(Attachment $attachment): ?string
    {
        $disk = Storage::disk($attachment->disk);
        if (! $attachment->storage_path || ! $disk->exists($attachment->storage_path)) {
            return null;
        }

        return $disk->path($attachment->storage_path);
    }

    protected function syncTranslations(Attachment $attachment, array $data, string $default): void
    {
        $rows = $data['translations'] ?? [];
        if ($rows === []) {
            $language = (string) ($data['language_code'] ?? $default);
            $rows = [$language => [
                'language_code' => $language,
                'name' => $data['name'] ?? '',
                'description' => $data['description'] ?? null,
            ]];
        }

        foreach ($rows as $key => $row) {
            $row = (array) $row;
            $language = (string) ($row['language_code'] ?? $key);
            if ($language === '') {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            $description = ($row['description'] ?? '') ?: null;
            $blank = $name === '' && $description === null;

            if ($language !== $default && $blank) {
                AttachmentTranslation::query()
                    ->where('attachment_id', $attachment->id)
                    ->where('language_code', $language)
                    ->delete();

                continue;
            }

            $attachment->translations()->updateOrCreate(
                ['language_code' => $language],
                [
                    'name' => $name !== '' ? $name : $attachment->name,
                    'description' => $description,
                ]
            );
        }

        $defaultRow = $attachment->translation($default);
        if ($defaultRow) {
            $attachment->update([
                'name' => $defaultRow->name,
                'description' => $defaultRow->description,
            ]);
        }
    }

    /** @return array{original_filename: string, stored_filename: string, storage_path: string, disk: string, mime_type: string|null, file_size: int} */
    protected function storeUploadedFile(UploadedFile $file): array
    {
        AttachmentUploadValidator::validate($file);

        $original = AttachmentUploadValidator::sanitizeOriginalFilename($file->getClientOriginalName());
        $extension = strtolower($file->getClientOriginalExtension() ?: pathinfo($original, PATHINFO_EXTENSION));
        $storedFilename = Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');
        $storagePath = 'catalog/files/'.$storedFilename;
        $disk = 'local';

        Storage::disk($disk)->putFileAs('catalog/files', $file, $storedFilename);
        $this->ensurePrivateStorageTraversable($disk, 'catalog/files');

        return [
            'original_filename' => $original,
            'stored_filename' => $storedFilename,
            'storage_path' => $storagePath,
            'disk' => $disk,
            'mime_type' => AttachmentUploadValidator::detectMime($file),
            'file_size' => (int) $file->getSize(),
        ];
    }

    /**
     * Laravel's local "private" visibility uses 0700 directories, which breaks downloads
     * when PHP-FPM and CLI/Apache workers do not share the same unix user.
     */
    protected function ensurePrivateStorageTraversable(string $disk, string $directory): void
    {
        try {
            $root = Storage::disk($disk)->path($directory);
        } catch (\Throwable) {
            return;
        }

        foreach ([$root, dirname($root)] as $path) {
            if (is_dir($path)) {
                @chmod($path, 0755);
            }
        }
    }

    protected function deletePhysicalFile(string $disk, string $path): void
    {
        if ($path === '') {
            return;
        }

        Storage::disk($disk)->delete($path);
    }
}
