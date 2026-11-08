<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Supplier;
use App\Domain\Catalog\Models\SupplierTranslation;
use App\Domain\Catalog\Models\UrlRewrite;
use App\Domain\Catalog\Support\CatalogCsvImport;
use App\Domain\Catalog\Support\PositionReorder;
use App\Domain\Catalog\Support\SupplierCsvSchema;
use App\Domain\Localization\Models\Language;
use App\Domain\Security\CsvSafe;
use App\Domain\Security\SafeUrl;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupplierService
{
    public function __construct(
        protected UrlRewriteService $urls,
    ) {}

    public function save(array $data, ?Supplier $supplier = null, array $files = []): Supplier
    {
        return DB::transaction(function () use ($data, $supplier, $files) {
            $supplier ??= new Supplier;
            $default = Language::defaultCode();

            $supplier->fill([
                'seller_id' => ($data['seller_id'] ?? '') === '' || ($data['seller_id'] ?? null) === null
                    ? ($supplier->seller_id ?? null)
                    : (int) $data['seller_id'],
                'email' => $this->nullableString($data['email'] ?? null),
                'phone' => $this->nullableString($data['phone'] ?? null),
                'website' => $this->nullableString($data['website'] ?? null),
                'facebook_url' => $this->nullableString($data['facebook_url'] ?? null),
                'instagram_url' => $this->nullableString($data['instagram_url'] ?? null),
                'youtube_url' => $this->nullableString($data['youtube_url'] ?? null),
                'twitter_url' => $this->nullableString($data['twitter_url'] ?? null),
                'linkedin_url' => $this->nullableString($data['linkedin_url'] ?? null),
                'tiktok_url' => $this->nullableString($data['tiktok_url'] ?? null),
                'address' => $this->nullableString($data['address'] ?? null),
                'address_line2' => $this->nullableString($data['address_line2'] ?? null),
                'city' => $this->nullableString($data['city'] ?? null),
                'state' => $this->nullableString($data['state'] ?? null),
                'postcode' => $this->nullableString($data['postcode'] ?? null),
                'country' => $this->nullableString($data['country'] ?? null),
                'is_enabled' => (bool) ($data['is_enabled'] ?? true),
                'position' => PositionReorder::resolve(
                    $data['position'] ?? null,
                    $supplier,
                ),
            ]);
            $supplier->save();

            $this->syncTranslations($supplier, $data, $default);
            $this->syncMedia($supplier, $data, $files);

            return $supplier->fresh(['translations']);
        });
    }

    public function duplicate(Supplier $supplier): Supplier
    {
        $supplier->loadMissing('translations');

        return DB::transaction(function () use ($supplier) {
            $copy = $supplier->replicate(['logo_path']);
            $copy->is_enabled = false;
            $copy->logo_path = null;
            $copy->position = PositionReorder::next(Supplier::class);
            $copy->save();

            foreach ($supplier->translations as $translation) {
                $name = $translation->name.' (copy)';
                $slug = $this->uniqueSlug(
                    ($translation->slug ?: Str::slug($translation->name)).'-copy',
                    $name,
                    $translation->language_code,
                    (int) $copy->id,
                );
                $copy->translations()->create([
                    'language_code' => $translation->language_code,
                    'name' => $name,
                    'slug' => $slug,
                    'description' => $translation->description,
                    'meta_title' => $translation->meta_title,
                    'meta_description' => $translation->meta_description,
                    'meta_keywords' => $translation->meta_keywords,
                ]);
                $this->urls->sync('supplier', $copy->id, $translation->language_code, $slug, 'supplier');
            }

            $copy->logo_path = $this->copyPublicFile($supplier->logo_path, (int) $copy->id);
            $copy->save();

            return $copy->fresh(['translations']);
        });
    }

    public function csvColumns(): array
    {
        return SupplierCsvSchema::columns();
    }

    public function sampleCsv(
        string $delimiter = SupplierCsvSchema::DEFAULT_DELIMITER,
        string $multiSeparator = SupplierCsvSchema::DEFAULT_MULTI_SEPARATOR,
    ): string {
        return SupplierCsvSchema::sampleCsv($delimiter, $multiSeparator);
    }

    public function importCsv(
        string $contents,
        string $locale = 'en',
        string $delimiter = SupplierCsvSchema::DEFAULT_DELIMITER,
        string $multiSeparator = SupplierCsvSchema::DEFAULT_MULTI_SEPARATOR,
    ): int {
        $delimiter = $this->normalizeCsvDelimiter($delimiter);
        $multiSeparator = $this->normalizeCsvMultiSeparator($multiSeparator);

        $parsed = CatalogCsvImport::parse($contents, $delimiter);
        CatalogCsvImport::assertHeaders($parsed['header'], SupplierCsvSchema::columns(), 'Supplier');
        CatalogCsvImport::assertHasDataRows($parsed['rows'], 'Supplier');

        $errors = [];
        $prepared = [];
        foreach ($parsed['rows'] as $index => $row) {
            $rowNumber = CatalogCsvImport::rowNumber($index);
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                $errors[] = "Row {$rowNumber}: Supplier name is required.";

                continue;
            }
            $prepared[] = SupplierCsvSchema::mapRow($row, $locale, $multiSeparator);
        }

        CatalogCsvImport::throwRowErrors($errors);
        if ($prepared === []) {
            throw ValidationException::withMessages([
                'file' => 'Invalid CSV file. No valid Supplier rows to import.',
            ]);
        }

        return DB::transaction(function () use ($prepared, $locale) {
            $count = 0;
            foreach ($prepared as $mapped) {
                $existing = $this->findBySlug($mapped['slug'], $locale);
                $payload = [
                    ...$mapped,
                    'translations' => [
                        $locale => [
                            'language_code' => $locale,
                            'name' => $mapped['name'],
                            'slug' => $mapped['slug'],
                            'description' => $mapped['description'],
                            'meta_title' => $mapped['meta_title'] ?? null,
                            'meta_description' => $mapped['meta_description'] ?? null,
                            'meta_keywords' => $mapped['meta_keywords'] ?? null,
                        ],
                    ],
                ];
                $supplier = $this->save($payload, $existing);
                $this->importRemoteMedia($supplier, $mapped);
                $count++;
            }

            return $count;
        });
    }

    public function exportCsv(
        string $locale = 'en',
        ?Request $request = null,
        string $delimiter = SupplierCsvSchema::DEFAULT_DELIMITER,
        string $multiSeparator = SupplierCsvSchema::DEFAULT_MULTI_SEPARATOR,
    ): string {
        $delimiter = $this->normalizeCsvDelimiter($delimiter);
        $this->normalizeCsvMultiSeparator($multiSeparator);

        $out = fopen('php://temp', 'r+');
        CsvSafe::put($out, $this->csvColumns(), $delimiter);

        $status = $request?->string('status')->toString() ?: 'all';
        $name = trim((string) ($request?->string('name')->toString() ?: $request?->string('q')->toString() ?: ''));
        $contact = trim((string) ($request?->string('contact')->toString() ?: ''));
        $idMin = $request?->input('id_min');
        $idMax = $request?->input('id_max');

        Supplier::query()
            ->with('translations')
            ->when($name !== '', function ($query) use ($name) {
                $query->whereHas('translations', fn ($t) => $t->where('name', 'like', "%{$name}%"));
            })
            ->when($contact !== '', function ($query) use ($contact) {
                $query->where(function ($inner) use ($contact) {
                    $inner->where('email', 'like', "%{$contact}%")
                        ->orWhere('phone', 'like', "%{$contact}%");
                });
            })
            ->when($idMin !== null && $idMin !== '', fn ($q) => $q->where('suppliers.id', '>=', (int) $idMin))
            ->when($idMax !== null && $idMax !== '', fn ($q) => $q->where('suppliers.id', '<=', (int) $idMax))
            ->when($status === 'active', fn ($q) => $q->where('suppliers.is_enabled', true))
            ->when($status === 'inactive', fn ($q) => $q->where('suppliers.is_enabled', false))
            ->orderByDesc('suppliers.id')
            ->chunk(100, function ($suppliers) use ($out, $locale, $delimiter) {
                foreach ($suppliers as $supplier) {
                    CsvSafe::put($out, SupplierCsvSchema::exportValues($supplier, $locale), $delimiter);
                }
            });

        rewind($out);

        return stream_get_contents($out) ?: '';
    }

    protected function syncTranslations(Supplier $supplier, array $data, string $default): void
    {
        $rows = $data['translations'] ?? [];
        if ($rows === []) {
            $language = (string) ($data['language_code'] ?? $default);
            $rows = [$language => [
                'language_code' => $language,
                'name' => $data['name'] ?? '',
                'slug' => $data['slug'] ?? '',
                'description' => $data['description'] ?? null,
                'meta_title' => $data['meta_title'] ?? null,
                'meta_description' => $data['meta_description'] ?? null,
                'meta_keywords' => $data['meta_keywords'] ?? null,
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
            $blank = $name === '' && $description === null
                && trim((string) ($row['meta_title'] ?? '')) === ''
                && trim((string) ($row['meta_description'] ?? '')) === ''
                && trim((string) ($row['meta_keywords'] ?? '')) === '';

            if ($language !== $default && $blank) {
                UrlRewrite::query()
                    ->where('entity_type', 'supplier')
                    ->where('entity_id', $supplier->id)
                    ->where('language_code', $language)
                    ->delete();
                SupplierTranslation::query()
                    ->where('supplier_id', $supplier->id)
                    ->where('language_code', $language)
                    ->delete();

                continue;
            }

            $slug = $this->uniqueSlug(
                (string) ($row['slug'] ?? ''),
                $name,
                $language,
                (int) $supplier->id,
            );

            $supplier->translations()->updateOrCreate(
                ['language_code' => $language],
                [
                    'name' => $name,
                    'slug' => $slug,
                    'description' => $description,
                    'meta_title' => $this->nullableString($row['meta_title'] ?? null),
                    'meta_description' => $this->nullableString($row['meta_description'] ?? null),
                    'meta_keywords' => $this->nullableString($row['meta_keywords'] ?? null),
                ]
            );
            $this->urls->sync('supplier', $supplier->id, $language, $slug, 'supplier', [
                'id' => $supplier->id,
                'supplier' => $slug,
                'rewrite' => $slug,
            ]);
        }
    }

    protected function syncMedia(Supplier $supplier, array $data, array $files): void
    {
        if (! empty($data['remove_logo'])) {
            $this->deletePublicFile($supplier->logo_path);
            $supplier->logo_path = null;
        }
        if (($files['logo'] ?? null) instanceof UploadedFile) {
            $this->deletePublicFile($supplier->logo_path);
            $supplier->logo_path = $this->storeLogo($supplier, $files['logo']);
        }
        $supplier->save();
    }

    protected function storeLogo(Supplier $supplier, UploadedFile $file): string
    {
        $dir = 'suppliers/'.$supplier->id;
        $name = 'logo-'.Str::lower(Str::random(8)).'.'.$file->getClientOriginalExtension();

        return $file->storeAs($dir, $name, \App\Domain\Settings\ObjectStorageSettings::diskName());
    }

    protected function copyPublicFile(?string $path, int $supplierId): ?string
    {
        $disk = \App\Domain\Settings\ObjectStorageSettings::disk();
        if (! $path || ! $disk->exists($path)) {
            return null;
        }

        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $dest = 'suppliers/'.$supplierId.'/logo-'.Str::lower(Str::random(8)).($ext ? '.'.$ext : '');
        $disk->copy($path, $dest);

        return $dest;
    }

    protected function uniqueSlug(string $slug, string $name, string $locale, int $ignoreSupplierId): string
    {
        $base = Str::slug($slug !== '' ? $slug : $name) ?: 'supplier';
        $candidate = $base;
        $i = 2;
        while (SupplierTranslation::query()
            ->where('language_code', $locale)
            ->where('slug', $candidate)
            ->where('supplier_id', '!=', $ignoreSupplierId)
            ->exists()) {
            $candidate = "{$base}-{$i}";
            $i++;
        }

        return $candidate;
    }

    protected function findBySlug(string $slug, string $locale): ?Supplier
    {
        if ($slug === '') {
            return null;
        }

        return Supplier::query()
            ->whereHas('translations', fn ($q) => $q->where('language_code', $locale)->where('slug', $slug))
            ->first();
    }

    /** @param  array<string, mixed>  $mapped */
    protected function importRemoteMedia(Supplier $supplier, array $mapped): void
    {
        $url = $mapped['logo_url'] ?? null;
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return;
        }

        try {
            $response = SafeUrl::get($url, 8, false);
            if (! $response->successful() || $response->body() === '' || strlen($response->body()) > 5_000_000) {
                return;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'wento-supplier-img-');
            if ($tmp === false) {
                return;
            }
            file_put_contents($tmp, $response->body());
            $name = basename(parse_url($url, PHP_URL_PATH) ?: 'logo.jpg') ?: 'logo.jpg';
            $file = new UploadedFile($tmp, $name, $response->header('Content-Type') ?: null, null, true);
            $this->deletePublicFile($supplier->logo_path);
            $supplier->logo_path = $this->storeLogo($supplier, $file);
            $supplier->save();
            @unlink($tmp);
        } catch (\Throwable) {
            // Skip unreachable image URLs.
        }
    }

    protected function deletePublicFile(?string $path): void
    {
        $disk = \App\Domain\Settings\ObjectStorageSettings::disk();
        if ($path && $disk->exists($path)) {
            $disk->delete($path);
        }
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === '' || $value === null ? null : (string) $value;
    }

    protected function normalizeCsvDelimiter(string $delimiter): string
    {
        $delimiter = $delimiter === '' ? SupplierCsvSchema::DEFAULT_DELIMITER : $delimiter;

        return strlen($delimiter) === 1 ? $delimiter : SupplierCsvSchema::DEFAULT_DELIMITER;
    }

    protected function normalizeCsvMultiSeparator(string $separator): string
    {
        return $separator === '' ? SupplierCsvSchema::DEFAULT_MULTI_SEPARATOR : $separator;
    }
}
