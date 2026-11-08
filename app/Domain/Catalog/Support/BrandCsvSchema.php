<?php

namespace App\Domain\Catalog\Support;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Security\CsvSafe;
use Illuminate\Support\Str;

/**
 * Single source of truth for brand CSV import, export, and sample download.
 */
final class BrandCsvSchema
{
    public const DEFAULT_DELIMITER = ';';

    public const DEFAULT_MULTI_SEPARATOR = ',';

    /** @return list<string> */
    public static function columns(): array
    {
        return [
            'id',
            'name',
            'slug',
            'code',
            'short_description',
            'tagline',
            'description',
            'meta_title',
            'meta_description',
            'meta_keywords',
            'website',
            'country_code',
            'established_year',
            'email',
            'phone',
            'facebook_url',
            'instagram_url',
            'youtube_url',
            'twitter_url',
            'linkedin_url',
            'tiktok_url',
            'is_enabled',
            'logo_url',
            'banner_url',
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function sampleBrands(): array
    {
        return [
            [
                'name' => 'Volt',
                'slug' => 'volt',
                'code' => 'VOLT',
                'short_description' => 'Modern electronics brand.',
                'tagline' => 'Power your day',
                'description' => 'Volt designs reliable consumer electronics for home and office.',
                'meta_title' => 'Volt',
                'meta_description' => 'Shop Volt electronics.',
                'meta_keywords' => 'volt,electronics',
                'website' => 'https://example.com/volt',
                'country_code' => 'US',
                'established_year' => '2012',
                'email' => 'hello@volt.example',
                'phone' => '+1-555-0101',
                'facebook_url' => 'https://facebook.com/volt',
                'instagram_url' => 'https://instagram.com/volt',
                'youtube_url' => '',
                'twitter_url' => 'https://twitter.com/volt',
                'linkedin_url' => '',
                'tiktok_url' => '',
                'is_enabled' => '1',
                'logo_url' => 'https://example.com/images/brands/volt-logo.png',
                'banner_url' => 'https://example.com/images/brands/volt-banner.jpg',
            ],
            [
                'name' => 'Northwind Apparel',
                'slug' => 'northwind-apparel',
                'code' => 'NWA',
                'short_description' => 'Casual clothing and outerwear.',
                'tagline' => 'Dress for the weather',
                'description' => 'Northwind Apparel focuses on durable everyday fashion.',
                'meta_title' => 'Northwind Apparel',
                'meta_description' => 'Shop Northwind clothing.',
                'meta_keywords' => 'apparel,fashion',
                'website' => 'https://example.com/northwind',
                'country_code' => 'CA',
                'established_year' => '1998',
                'email' => 'care@northwind.example',
                'phone' => '+1-555-0102',
                'facebook_url' => '',
                'instagram_url' => 'https://instagram.com/northwind',
                'youtube_url' => '',
                'twitter_url' => '',
                'linkedin_url' => '',
                'tiktok_url' => '',
                'is_enabled' => '1',
                'logo_url' => 'https://example.com/images/brands/northwind-logo.png',
                'banner_url' => '',
            ],
            [
                'name' => 'Lumen Home',
                'slug' => 'lumen-home',
                'code' => 'LUMEN',
                'short_description' => 'Lighting and home fixtures.',
                'tagline' => 'Light done right',
                'description' => 'Lumen Home produces LED lamps and fixtures for modern interiors.',
                'meta_title' => 'Lumen Home',
                'meta_description' => 'Shop Lumen lighting.',
                'meta_keywords' => 'lighting,home',
                'website' => 'https://example.com/lumen',
                'country_code' => 'DE',
                'established_year' => '2005',
                'email' => 'info@lumen.example',
                'phone' => '+49-555-0103',
                'facebook_url' => '',
                'instagram_url' => '',
                'youtube_url' => 'https://youtube.com/@lumen',
                'twitter_url' => '',
                'linkedin_url' => 'https://linkedin.com/company/lumen',
                'tiktok_url' => '',
                'is_enabled' => '1',
                'logo_url' => '',
                'banner_url' => 'https://example.com/images/brands/lumen-banner.jpg',
            ],
            [
                'name' => 'TrailForge',
                'slug' => 'trailforge',
                'code' => 'TRAIL',
                'short_description' => 'Outdoor gear and camping tools.',
                'tagline' => 'Built for the trail',
                'description' => 'TrailForge makes rugged outdoor equipment for hikers and campers.',
                'meta_title' => 'TrailForge',
                'meta_description' => 'Shop TrailForge outdoor gear.',
                'meta_keywords' => 'outdoor,camping',
                'website' => 'https://example.com/trailforge',
                'country_code' => 'US',
                'established_year' => '2010',
                'email' => 'support@trailforge.example',
                'phone' => '+1-555-0104',
                'facebook_url' => 'https://facebook.com/trailforge',
                'instagram_url' => 'https://instagram.com/trailforge',
                'youtube_url' => '',
                'twitter_url' => '',
                'linkedin_url' => '',
                'tiktok_url' => 'https://tiktok.com/@trailforge',
                'is_enabled' => '1',
                'logo_url' => 'https://example.com/images/brands/trailforge-logo.png',
                'banner_url' => '',
            ],
            [
                'name' => 'Archive Audio',
                'slug' => 'archive-audio',
                'code' => 'ARCH',
                'short_description' => 'Legacy hi-fi brand (inactive).',
                'tagline' => 'Classic sound',
                'description' => 'Archive Audio is kept for historical catalog references.',
                'meta_title' => 'Archive Audio',
                'meta_description' => 'Legacy audio brand.',
                'meta_keywords' => 'audio,legacy',
                'website' => '',
                'country_code' => 'JP',
                'established_year' => '1984',
                'email' => '',
                'phone' => '',
                'facebook_url' => '',
                'instagram_url' => '',
                'youtube_url' => '',
                'twitter_url' => '',
                'linkedin_url' => '',
                'tiktok_url' => '',
                'is_enabled' => '0',
                'logo_url' => '',
                'banner_url' => '',
            ],
            [
                'name' => 'PureBrew',
                'slug' => 'purebrew',
                'code' => 'BREW',
                'short_description' => 'Coffee makers and accessories.',
                'tagline' => 'Brew better',
                'description' => 'PureBrew specializes in home coffee equipment.',
                'meta_title' => 'PureBrew',
                'meta_description' => 'Shop PureBrew coffee gear.',
                'meta_keywords' => 'coffee,brew',
                'website' => 'https://example.com/purebrew',
                'country_code' => 'IT',
                'established_year' => '2016',
                'email' => 'hello@purebrew.example',
                'phone' => '+39-555-0106',
                'facebook_url' => '',
                'instagram_url' => 'https://instagram.com/purebrew',
                'youtube_url' => '',
                'twitter_url' => '',
                'linkedin_url' => '',
                'tiktok_url' => '',
                'is_enabled' => '1',
                'logo_url' => 'https://example.com/images/brands/purebrew-logo.png',
                'banner_url' => '',
            ],
            [
                'name' => 'Skyline Soft',
                'slug' => 'skyline-soft',
                'code' => 'SKY',
                'short_description' => 'Software and digital tools brand.',
                'tagline' => 'Tools that scale',
                'description' => 'Skyline Soft publishes productivity software and digital kits.',
                'meta_title' => 'Skyline Soft',
                'meta_description' => 'Digital tools by Skyline Soft.',
                'meta_keywords' => 'software,digital',
                'website' => 'https://example.com/skyline',
                'country_code' => 'GB',
                'established_year' => '2018',
                'email' => 'team@skyline.example',
                'phone' => '+44-555-0107',
                'facebook_url' => '',
                'instagram_url' => '',
                'youtube_url' => '',
                'twitter_url' => 'https://twitter.com/skylinesoft',
                'linkedin_url' => 'https://linkedin.com/company/skylinesoft',
                'tiktok_url' => '',
                'is_enabled' => '1',
                'logo_url' => '',
                'banner_url' => '',
            ],
            [
                'name' => 'Harbor Kids',
                'slug' => 'harbor-kids',
                'code' => 'HARBOR',
                'short_description' => 'Toys and kids essentials.',
                'tagline' => 'Play with purpose',
                'description' => 'Harbor Kids creates safe and creative products for children.',
                'meta_title' => 'Harbor Kids',
                'meta_description' => 'Shop Harbor Kids products.',
                'meta_keywords' => 'kids,toys',
                'website' => 'https://example.com/harborkids',
                'country_code' => 'US',
                'established_year' => '2009',
                'email' => 'hello@harborkids.example',
                'phone' => '+1-555-0108',
                'facebook_url' => 'https://facebook.com/harborkids',
                'instagram_url' => 'https://instagram.com/harborkids',
                'youtube_url' => '',
                'twitter_url' => '',
                'linkedin_url' => '',
                'tiktok_url' => '',
                'is_enabled' => '1',
                'logo_url' => 'https://example.com/images/brands/harbor-logo.png',
                'banner_url' => 'https://example.com/images/brands/harbor-banner.jpg',
            ],
        ];
    }

    public static function sampleCsv(
        string $delimiter = self::DEFAULT_DELIMITER,
        string $multiSeparator = self::DEFAULT_MULTI_SEPARATOR,
    ): string {
        $out = fopen('php://temp', 'r+');
        CsvSafe::put($out, self::columns(), $delimiter);
        foreach (self::sampleBrands() as $brand) {
            CsvSafe::put($out, self::brandToRow($brand, $multiSeparator), $delimiter);
        }
        rewind($out);

        return stream_get_contents($out) ?: '';
    }

    /**
     * @param  array<string, mixed>  $brand
     * @return list<string>
     */
    public static function brandToRow(array $brand, string $multiSeparator = self::DEFAULT_MULTI_SEPARATOR): array
    {
        $row = [];
        foreach (self::columns() as $key) {
            $value = $brand[$key] ?? '';
            if (is_array($value)) {
                $value = implode($multiSeparator, array_map(static fn ($part) => (string) $part, $value));
            }
            $row[] = (string) $value;
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function mapRow(
        array $row,
        string $locale = 'en',
        string $multiSeparator = self::DEFAULT_MULTI_SEPARATOR,
    ): array {
        unset($multiSeparator);
        $get = static fn (string $key): ?string => array_key_exists($key, $row)
            ? trim((string) $row[$key])
            : null;

        $name = (string) ($get('name') ?? '');
        $slug = self::filled($get('slug')) ? (string) $get('slug') : Str::slug($name);

        return [
            'name' => $name,
            'slug' => $slug,
            'code' => self::nullable($get('code')),
            'short_description' => self::nullable($get('short_description')),
            'tagline' => self::nullable($get('tagline')),
            'description' => self::nullable($get('description')),
            'meta_title' => self::nullable($get('meta_title')),
            'meta_description' => self::nullable($get('meta_description')),
            'meta_keywords' => self::nullable($get('meta_keywords')),
            'website' => self::nullable($get('website')),
            'country_code' => self::nullable($get('country_code')),
            'established_year' => self::filled($get('established_year')) ? (int) $get('established_year') : null,
            'email' => self::nullable($get('email')),
            'phone' => self::nullable($get('phone')),
            'facebook_url' => self::nullable($get('facebook_url')),
            'instagram_url' => self::nullable($get('instagram_url')),
            'youtube_url' => self::nullable($get('youtube_url')),
            'twitter_url' => self::nullable($get('twitter_url')),
            'linkedin_url' => self::nullable($get('linkedin_url')),
            'tiktok_url' => self::nullable($get('tiktok_url')),
            'is_enabled' => self::boolOr($get('is_enabled'), true),
            'logo_url' => self::nullable($get('logo_url')),
            'banner_url' => self::nullable($get('banner_url')),
            'language_code' => $locale,
        ];
    }

    /** @return list<string|int|null> */
    public static function exportValues(Brand $brand, string $locale = 'en'): array
    {
        $t = $brand->translation($locale);
        $map = [
            'id' => $brand->id,
            'name' => $t?->name,
            'slug' => $t?->slug,
            'code' => $brand->code,
            'short_description' => $t?->short_description,
            'tagline' => $t?->tagline,
            'description' => $t?->description,
            'meta_title' => $t?->meta_title,
            'meta_description' => $t?->meta_description,
            'meta_keywords' => $t?->meta_keywords,
            'website' => $brand->website,
            'country_code' => $brand->country_code,
            'established_year' => $brand->established_year,
            'email' => $brand->email,
            'phone' => $brand->phone,
            'facebook_url' => $brand->facebook_url,
            'instagram_url' => $brand->instagram_url,
            'youtube_url' => $brand->youtube_url,
            'twitter_url' => $brand->twitter_url,
            'linkedin_url' => $brand->linkedin_url,
            'tiktok_url' => $brand->tiktok_url,
            'is_enabled' => self::boolToCsv($brand->is_enabled),
            'logo_url' => $brand->logo_path,
            'banner_url' => $brand->banner_path,
        ];

        return array_map(fn (string $key) => $map[$key] ?? '', self::columns());
    }

    protected static function filled(?string $value): bool
    {
        return $value !== null && $value !== '';
    }

    protected static function nullable(?string $value): ?string
    {
        return self::filled($value) ? $value : null;
    }

    protected static function intOr(?string $value, int $default): int
    {
        return self::filled($value) ? (int) $value : $default;
    }

    protected static function boolOr(?string $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            '1', 'true', 'yes', 'y', 'on', 'active', 'enabled' => true,
            '0', 'false', 'no', 'n', 'off', 'inactive', 'disabled' => false,
            default => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default,
        };
    }

    protected static function boolToCsv(mixed $value): string
    {
        return $value ? '1' : '0';
    }
}
