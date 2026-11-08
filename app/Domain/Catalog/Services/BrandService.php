<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\BrandImage;
use App\Domain\Catalog\Models\BrandTranslation;
use App\Domain\Catalog\Models\UrlRewrite;
use App\Domain\Catalog\Support\BrandCsvSchema;
use App\Domain\Catalog\Support\CatalogCsvImport;
use App\Domain\Catalog\Support\CatalogMediaUrl;
use App\Domain\Catalog\Support\DefaultImage;
use App\Domain\Catalog\Support\PositionReorder;
use App\Domain\Localization\Models\Language;
use App\Domain\Security\CsvSafe;
use App\Domain\Security\RasterImageUpload;
use App\Domain\Security\SafeUrl;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BrandService
{
    public function __construct(
        protected UrlRewriteService $urls,
    ) {}

    public function present(Brand $brand, ?string $locale = null): array
    {
        $translation = $brand->translation($locale);

        return [
            'id' => $brand->id,
            'name' => $translation?->name ?: 'Untitled brand',
            'code' => $brand->code,
            'slug' => $translation?->slug,
            'short_description' => $translation?->short_description,
            'tagline' => $translation?->tagline,
            'description' => $translation?->description,
            'meta_title' => $translation?->meta_title,
            'meta_description' => $translation?->meta_description,
            'meta_keywords' => $translation?->meta_keywords,
            'translations' => $brand->relationLoaded('translations')
                ? $brand->translations->map(fn (BrandTranslation $row) => [
                    'language_code' => $row->language_code,
                    'name' => $row->name,
                    'slug' => $row->slug,
                    'short_description' => $row->short_description,
                    'tagline' => $row->tagline,
                    'description' => $row->description,
                    'meta_title' => $row->meta_title,
                    'meta_description' => $row->meta_description,
                    'meta_keywords' => $row->meta_keywords,
                ])->values()->all()
                : [],
            'website' => $brand->website,
            'country_code' => $brand->country_code,
            'established_year' => $brand->established_year,
            'email' => $brand->email,
            'phone' => $brand->phone,
            'contact' => collect([$brand->email, $brand->phone])->filter()->implode(' · ') ?: '',
            'address' => $brand->address,
            'address_line2' => $brand->address_line2,
            'city' => $brand->city,
            'state' => $brand->state,
            'postcode' => $brand->postcode,
            'country' => $brand->country,
            'facebook_url' => $brand->facebook_url,
            'instagram_url' => $brand->instagram_url,
            'youtube_url' => $brand->youtube_url,
            'twitter_url' => $brand->twitter_url,
            'linkedin_url' => $brand->linkedin_url,
            'tiktok_url' => $brand->tiktok_url,
            'logo_path' => $brand->logo_path,
            'banner_path' => $brand->banner_path,
            'logo_url' => CatalogMediaUrl::url($brand->logo_path, DefaultImage::SM),
            'banner_url' => CatalogMediaUrl::url($brand->banner_path, DefaultImage::LG),
            'is_enabled' => (bool) $brand->is_enabled,
            'products_count' => $brand->products_count ?? $brand->products()->count(),
            'gallery' => $brand->relationLoaded('images')
                ? $brand->images->map(fn (BrandImage $image) => [
                    'id' => $image->id,
                    'path' => $image->path,
                    'url' => CatalogMediaUrl::url($image->path, DefaultImage::MD),
                    'position' => (int) $image->position,
                ])->values()->all()
                : [],
        ];
    }

    public function save(array $data, ?Brand $brand = null, array $files = []): Brand
    {
        return DB::transaction(function () use ($data, $brand, $files) {
            $brand ??= new Brand;
            $default = Language::defaultCode();

            $brand->fill([
                'seller_id' => ($data['seller_id'] ?? '') === '' || ($data['seller_id'] ?? null) === null
                    ? ($brand->seller_id ?? null)
                    : (int) $data['seller_id'],
                'code' => array_key_exists('code', $data)
                    ? $this->nullableString($data['code'] ?? null)
                    : ($brand->exists ? $brand->code : null),
                'website' => $this->nullableString($data['website'] ?? null),
                'country_code' => array_key_exists('country_code', $data)
                    ? $this->nullableString($data['country_code'])
                    : ($brand->exists ? $brand->country_code : null),
                'established_year' => array_key_exists('established_year', $data)
                    ? (($data['established_year'] ?? '') === '' || $data['established_year'] === null
                        ? null
                        : (int) $data['established_year'])
                    : ($brand->exists ? $brand->established_year : null),
                'email' => $this->nullableString($data['email'] ?? null),
                'phone' => $this->nullableString($data['phone'] ?? null),
                'address' => $this->nullableString($data['address'] ?? null),
                'address_line2' => $this->nullableString($data['address_line2'] ?? null),
                'city' => $this->nullableString($data['city'] ?? null),
                'state' => $this->nullableString($data['state'] ?? null),
                'postcode' => $this->nullableString($data['postcode'] ?? null),
                'country' => $this->nullableString($data['country'] ?? null),
                'facebook_url' => $this->nullableString($data['facebook_url'] ?? null),
                'instagram_url' => $this->nullableString($data['instagram_url'] ?? null),
                'youtube_url' => $this->nullableString($data['youtube_url'] ?? null),
                'twitter_url' => $this->nullableString($data['twitter_url'] ?? null),
                'linkedin_url' => $this->nullableString($data['linkedin_url'] ?? null),
                'tiktok_url' => $this->nullableString($data['tiktok_url'] ?? null),
                'is_enabled' => (bool) ($data['is_enabled'] ?? true),
                'position' => PositionReorder::resolve(
                    $data['position'] ?? null,
                    $brand,
                ),
            ]);
            $brand->save();

            $this->syncTranslations($brand, $data, $default);
            $this->syncMedia($brand, $data, $files);

            return $brand->fresh(['translations', 'images']);
        });
    }

    public function duplicate(Brand $brand): Brand
    {
        $brand->loadMissing(['translations', 'images']);

        return DB::transaction(function () use ($brand) {
            $copy = $brand->replicate(['logo_path', 'banner_path']);
            $copy->is_enabled = false;
            $copy->code = null;
            $copy->logo_path = null;
            $copy->banner_path = null;
            $copy->position = PositionReorder::next(Brand::class);
            $copy->save();

            foreach ($brand->translations as $translation) {
                $copy->translations()->create([
                    'language_code' => $translation->language_code,
                    'name' => $translation->name.' (copy)',
                    'slug' => $this->uniqueSlug($translation->slug.'-copy', $translation->name.' copy', $translation->language_code, $copy->id),
                    'short_description' => $translation->short_description,
                    'tagline' => $translation->tagline,
                    'description' => $translation->description,
                    'meta_title' => $translation->meta_title,
                    'meta_description' => $translation->meta_description,
                    'meta_keywords' => $translation->meta_keywords,
                ]);
            }

            $copy->logo_path = $this->copyPublicFile($brand->logo_path, $copy->id);
            $copy->banner_path = $this->copyPublicFile($brand->banner_path, $copy->id);
            $copy->save();

            foreach ($brand->images as $image) {
                $path = $this->copyPublicFile($image->path, $copy->id);
                if ($path) {
                    $copy->images()->create([
                        'path' => $path,
                        'position' => $image->position,
                    ]);
                }
            }

            $locale = $copy->translation()?->language_code ?: Language::defaultCode();
            if ($copy->translation($locale)?->slug) {
                $this->urls->sync('brand', $copy->id, $locale, $copy->translation($locale)->slug, 'brand');
            }

            return $copy->fresh(['translations', 'images']);
        });
    }

    public function delete(Brand $brand): void
    {
        $count = $brand->products()->count();
        if ($count > 0) {
            throw ValidationException::withMessages([
                'brand' => 'This brand is currently assigned to '.$count.' product'.($count === 1 ? '' : 's').'. Please reassign these products before deleting the brand.',
            ]);
        }

        UrlRewrite::query()
            ->where('entity_type', 'brand')
            ->where('entity_id', $brand->id)
            ->delete();

        $brand->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function syncTranslations(Brand $brand, array $data, string $default): void
    {
        $rows = $data['translations'] ?? [];
        if ($rows === []) {
            $language = (string) ($data['language_code'] ?? $default);
            $rows = [$language => [
                'language_code' => $language,
                'name' => $data['name'] ?? '',
                'slug' => $data['slug'] ?? '',
                'short_description' => $data['short_description'] ?? null,
                'tagline' => $data['tagline'] ?? null,
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
            if ($language !== $default && $this->translationRowBlank($row)) {
                UrlRewrite::query()
                    ->where('entity_type', 'brand')
                    ->where('entity_id', $brand->id)
                    ->where('language_code', $language)
                    ->delete();
                BrandTranslation::query()
                    ->where('brand_id', $brand->id)
                    ->where('language_code', $language)
                    ->delete();

                continue;
            }

            $slug = $this->uniqueSlug((string) ($row['slug'] ?? ''), $name, $language, $brand->id);

            $brand->translations()->updateOrCreate(
                ['language_code' => $language],
                [
                    'name' => $name,
                    'slug' => $slug,
                    'short_description' => $this->nullableString($row['short_description'] ?? null),
                    'tagline' => $this->nullableString($row['tagline'] ?? null),
                    'description' => $row['description'] ?? null,
                    'meta_title' => $this->nullableString($row['meta_title'] ?? null),
                    'meta_description' => $this->nullableString($row['meta_description'] ?? null),
                    'meta_keywords' => $this->nullableString($row['meta_keywords'] ?? null),
                ]
            );

            $this->urls->sync('brand', $brand->id, $language, $slug, 'brand', [
                'id' => $brand->id,
                'brand' => $slug,
                'rewrite' => $slug,
            ]);
        }
    }

    protected function translationRowBlank(array $row): bool
    {
        foreach ([
            'name', 'slug', 'short_description', 'tagline', 'description',
            'meta_title', 'meta_description', 'meta_keywords',
        ] as $field) {
            $value = $row[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return false;
            }
            if (! is_string($value) && $value !== null) {
                return false;
            }
        }

        return true;
    }

    protected function syncMedia(Brand $brand, array $data, array $files): void
    {
        if (! empty($data['remove_logo'])) {
            $this->deletePublicFile($brand->logo_path);
            $brand->logo_path = null;
        }
        if (! empty($data['remove_banner'])) {
            $this->deletePublicFile($brand->banner_path);
            $brand->banner_path = null;
        }

        if (($files['logo'] ?? null) instanceof UploadedFile) {
            $this->deletePublicFile($brand->logo_path);
            $brand->logo_path = $this->storeImage($brand, $files['logo']);
        }
        if (($files['banner'] ?? null) instanceof UploadedFile) {
            $this->deletePublicFile($brand->banner_path);
            $brand->banner_path = $this->storeImage($brand, $files['banner']);
        }

        $keep = array_values(array_map('intval', $data['gallery_keep'] ?? []));
        $owned = $brand->images()->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach ($owned as $id) {
            if (! in_array($id, $keep, true)) {
                $image = $brand->images()->whereKey($id)->first();
                if ($image) {
                    $this->deletePublicFile($image->path);
                    $image->delete();
                }
            }
        }

        $position = 1;
        foreach ($keep as $id) {
            if (! in_array($id, $owned, true)) {
                continue;
            }
            $brand->images()->whereKey($id)->update(['position' => $position]);
            $position++;
        }

        foreach (array_values($files['gallery'] ?? []) as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }
            $brand->images()->create([
                'path' => $this->storeImage($brand, $file),
                'position' => $position,
            ]);
            $position++;
        }

        $brand->save();
    }

    protected function storeImage(Brand $brand, UploadedFile $file): string
    {
        return RasterImageUpload::storePublic(
            $file,
            'catalog/brands/'.$brand->id,
            (int) config('wento.upload.max_image_kb', 5120)
        );
    }

    protected function copyPublicFile(?string $path, int $brandId): ?string
    {
        $disk = \App\Domain\Settings\ObjectStorageSettings::disk();
        if (! $path || ! $disk->exists($path)) {
            return null;
        }

        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $dest = 'catalog/brands/'.$brandId.'/'.Str::uuid().($ext ? '.'.$ext : '');
        $disk->copy($path, $dest);

        return $dest;
    }

    protected function deletePublicFile(?string $path): void
    {
        if ($path) {
            \App\Domain\Settings\ObjectStorageSettings::disk()->delete($path);
        }
    }

    protected function uniqueSlug(string $slug, string $name, string $locale, ?int $ignoreId): string
    {
        $slug = Str::slug($slug !== '' ? $slug : $name) ?: 'brand';
        $base = $slug;
        $i = 1;

        while (
            BrandTranslation::query()
                ->where('language_code', $locale)
                ->where('slug', $slug)
                ->when($ignoreId, fn ($query) => $query->where('brand_id', '!=', $ignoreId))
                ->whereHas('brand')
                ->exists()
        ) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    protected function uniqueCode(string $code): string
    {
        $base = Str::upper(Str::slug($code, ''));
        $candidate = $base !== '' ? $base : 'BRAND';
        $i = 1;
        while (Brand::query()->where('code', $candidate)->exists()) {
            $candidate = $base.$i++;
        }

        return $candidate;
    }

    protected function publicUrl(?string $path): ?string
    {
        return $path ? '/storage/'.$path : null;
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === '' || $value === null ? null : (string) $value;
    }

    public function csvColumns(): array
    {
        return BrandCsvSchema::columns();
    }

    public function sampleCsv(
        string $delimiter = BrandCsvSchema::DEFAULT_DELIMITER,
        string $multiSeparator = BrandCsvSchema::DEFAULT_MULTI_SEPARATOR,
    ): string {
        return BrandCsvSchema::sampleCsv($delimiter, $multiSeparator);
    }

    public function importCsv(
        string $contents,
        string $locale = 'en',
        string $delimiter = BrandCsvSchema::DEFAULT_DELIMITER,
        string $multiSeparator = BrandCsvSchema::DEFAULT_MULTI_SEPARATOR,
    ): int {
        $delimiter = $this->normalizeCsvDelimiter($delimiter);
        $multiSeparator = $this->normalizeCsvMultiSeparator($multiSeparator);

        $parsed = CatalogCsvImport::parse($contents, $delimiter);
        CatalogCsvImport::assertHeaders($parsed['header'], BrandCsvSchema::columns(), 'Brand');
        CatalogCsvImport::assertHasDataRows($parsed['rows'], 'Brand');

        $errors = [];
        $prepared = [];
        foreach ($parsed['rows'] as $index => $row) {
            $rowNumber = CatalogCsvImport::rowNumber($index);
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                $errors[] = "Row {$rowNumber}: Brand name is required.";

                continue;
            }
            $prepared[] = BrandCsvSchema::mapRow($row, $locale, $multiSeparator);
        }

        CatalogCsvImport::throwRowErrors($errors);
        if ($prepared === []) {
            throw ValidationException::withMessages([
                'file' => 'Invalid CSV file. No valid Brand rows to import.',
            ]);
        }

        return DB::transaction(function () use ($prepared, $locale) {
            $count = 0;
            foreach ($prepared as $mapped) {
                $existing = $this->findForImport($mapped['code'] ?? null, $mapped['slug'], $locale);
                $brand = $this->save($mapped, $existing);
                $this->importRemoteMedia($brand, $mapped);
                $count++;
            }

            return $count;
        });
    }

    public function exportCsv(
        string $locale = 'en',
        ?Request $request = null,
        string $delimiter = BrandCsvSchema::DEFAULT_DELIMITER,
        string $multiSeparator = BrandCsvSchema::DEFAULT_MULTI_SEPARATOR,
    ): string {
        $delimiter = $this->normalizeCsvDelimiter($delimiter);
        $this->normalizeCsvMultiSeparator($multiSeparator);

        $out = fopen('php://temp', 'r+');
        CsvSafe::put($out, $this->csvColumns(), $delimiter);

        $status = $request?->string('status')->toString() ?: 'all';
        $name = trim((string) ($request?->string('name')->toString() ?: $request?->string('q')->toString() ?: ''));
        $idMin = $request?->input('id_min');
        $idMax = $request?->input('id_max');

        Brand::query()
            ->with('translations')
            ->when($name !== '', function ($query) use ($name) {
                $query->where(function ($inner) use ($name) {
                    $inner->where('code', 'like', "%{$name}%")
                        ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', "%{$name}%"));
                });
            })
            ->when($idMin !== null && $idMin !== '', fn ($q) => $q->where('brands.id', '>=', (int) $idMin))
            ->when($idMax !== null && $idMax !== '', fn ($q) => $q->where('brands.id', '<=', (int) $idMax))
            ->when($status === 'active', fn ($q) => $q->where('brands.is_enabled', true))
            ->when($status === 'inactive', fn ($q) => $q->where('brands.is_enabled', false))
            ->orderBy('brands.id')
            ->chunk(100, function ($brands) use ($out, $locale, $delimiter) {
                foreach ($brands as $brand) {
                    CsvSafe::put($out, BrandCsvSchema::exportValues($brand, $locale), $delimiter);
                }
            });

        rewind($out);

        return stream_get_contents($out) ?: '';
    }

    protected function findForImport(?string $code, string $slug, string $locale): ?Brand
    {
        if ($code) {
            $byCode = Brand::query()->where('code', $code)->first();
            if ($byCode) {
                return $byCode;
            }
        }

        if ($slug === '') {
            return null;
        }

        return Brand::query()
            ->whereHas('translations', fn ($q) => $q->where('language_code', $locale)->where('slug', $slug))
            ->first();
    }

    /** @param  array<string, mixed>  $mapped */
    protected function importRemoteMedia(Brand $brand, array $mapped): void
    {
        $map = [
            'logo_url' => 'logo',
            'banner_url' => 'banner',
        ];
        $pathMap = [
            'logo' => 'logo_path',
            'banner' => 'banner_path',
        ];

        foreach ($map as $mediaKey => $fileKey) {
            $url = $mapped[$mediaKey] ?? null;
            if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }
            try {
                $response = SafeUrl::get($url, 8, false);
                if (! $response->successful() || $response->body() === '' || strlen($response->body()) > 5_000_000) {
                    continue;
                }
                $tmp = tempnam(sys_get_temp_dir(), 'wento-brand-img-');
                if ($tmp === false) {
                    continue;
                }
                file_put_contents($tmp, $response->body());
                $name = basename(parse_url($url, PHP_URL_PATH) ?: ($fileKey.'.jpg')) ?: ($fileKey.'.jpg');
                $file = new UploadedFile($tmp, $name, $response->header('Content-Type') ?: null, null, true);
                $pathKey = $pathMap[$fileKey];
                $this->deletePublicFile($brand->{$pathKey});
                $brand->{$pathKey} = $this->storeImage($brand, $file);
                $brand->save();
                @unlink($tmp);
            } catch (\Throwable) {
                // Skip unreachable image URLs.
            }
        }
    }

    protected function normalizeCsvDelimiter(string $delimiter): string
    {
        $delimiter = $delimiter === '' ? BrandCsvSchema::DEFAULT_DELIMITER : $delimiter;

        return strlen($delimiter) === 1 ? $delimiter : BrandCsvSchema::DEFAULT_DELIMITER;
    }

    protected function normalizeCsvMultiSeparator(string $separator): string
    {
        return $separator === '' ? BrandCsvSchema::DEFAULT_MULTI_SEPARATOR : $separator;
    }
}
