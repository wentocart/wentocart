<?php

namespace App\Domain\Catalog\Support;

use App\Domain\Catalog\Models\Supplier;
use App\Domain\Localization\Models\Country;
use App\Domain\Security\CsvSafe;
use Illuminate\Support\Str;

/**
 * Single source of truth for supplier CSV import, export, and sample download.
 */
final class SupplierCsvSchema
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
            'description',
            'meta_title',
            'meta_description',
            'meta_keywords',
            'email',
            'phone',
            'website',
            'facebook_url',
            'instagram_url',
            'youtube_url',
            'twitter_url',
            'linkedin_url',
            'tiktok_url',
            'address',
            'address_line2',
            'city',
            'postcode',
            'country',
            'state',
            'is_enabled',
            'logo_url',
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function sampleSuppliers(): array
    {
        return [
            [
                'name' => 'Wento Supply',
                'slug' => 'wento-supply',
                'description' => 'Primary wholesale partner for catalog demos.',
                'meta_title' => 'Wento Supply',
                'meta_description' => 'Wholesale partner for catalog demos.',
                'meta_keywords' => 'wholesale,supply',
                'email' => 'orders@wentosupply.example',
                'phone' => '+1-555-1001',
                'website' => 'https://example.com/wentosupply',
                'facebook_url' => 'https://facebook.com/wentosupply',
                'instagram_url' => '',
                'youtube_url' => '',
                'twitter_url' => 'https://twitter.com/wentosupply',
                'linkedin_url' => '',
                'tiktok_url' => '',
                'address' => '100 Market Street',
                'address_line2' => 'Suite 200',
                'city' => 'Austin',
                'postcode' => '78701',
                'country' => 'US',
                'state' => 'TX',
                'is_enabled' => '1',
                'logo_url' => 'https://example.com/images/suppliers/wento-supply.png',
            ],
            [
                'name' => 'Pacific Components',
                'slug' => 'pacific-components',
                'description' => 'Electronics components and accessories distributor.',
                'meta_title' => 'Pacific Components',
                'meta_description' => 'Electronics components distributor.',
                'meta_keywords' => 'electronics,components',
                'email' => 'sales@pacific.example',
                'phone' => '+1-555-1002',
                'website' => 'https://example.com/pacific',
                'facebook_url' => '',
                'instagram_url' => 'https://instagram.com/pacificcomponents',
                'youtube_url' => '',
                'twitter_url' => '',
                'linkedin_url' => '',
                'tiktok_url' => '',
                'address' => '220 Harbor Blvd',
                'city' => 'San Diego',
                'postcode' => '92101',
                'country' => 'US',
                'state' => 'CA',
                'is_enabled' => '1',
                'logo_url' => '',
            ],
            [
                'name' => 'Nordic Textiles',
                'slug' => 'nordic-textiles',
                'description' => 'Apparel and fabric supplier based in Scandinavia.',
                'meta_title' => 'Nordic Textiles',
                'meta_description' => 'Scandinavian apparel and fabric wholesaler.',
                'meta_keywords' => 'textiles,apparel,wholesale',
                'email' => 'hello@nordictextiles.example',
                'phone' => '+46-555-1003',
                'website' => 'https://example.com/nordictextiles',
                'facebook_url' => 'https://facebook.com/nordictextiles',
                'instagram_url' => 'https://instagram.com/nordictextiles',
                'youtube_url' => '',
                'twitter_url' => '',
                'linkedin_url' => 'https://linkedin.com/company/nordic-textiles',
                'tiktok_url' => '',
                'address' => '12 Strandvagen',
                'address_line2' => '',
                'city' => 'Stockholm',
                'postcode' => '11456',
                'country' => 'SE',
                'state' => '',
                'is_enabled' => '1',
                'logo_url' => 'https://example.com/images/suppliers/nordic.png',
            ],
            [
                'name' => 'Alpine Fresh Foods',
                'slug' => 'alpine-fresh-foods',
                'description' => 'Specialty food distributor (currently inactive).',
                'meta_title' => 'Alpine Fresh Foods',
                'meta_description' => 'Specialty food distributor for retail partners.',
                'meta_keywords' => 'food,distributor,specialty',
                'email' => 'ops@alpinefresh.example',
                'phone' => '+41-555-1004',
                'website' => 'https://example.com/alpinefresh',
                'facebook_url' => '',
                'instagram_url' => 'https://instagram.com/alpinefreshfoods',
                'youtube_url' => '',
                'twitter_url' => '',
                'linkedin_url' => 'https://linkedin.com/company/alpine-fresh-foods',
                'tiktok_url' => '',
                'address' => '8 Bahnhofstrasse',
                'address_line2' => 'Floor 4',
                'city' => 'Zurich',
                'postcode' => '8001',
                'country' => 'CH',
                'state' => '',
                'is_enabled' => '0',
                'logo_url' => '',
            ],
            [
                'name' => 'BrightBox Logistics',
                'slug' => 'brightbox-logistics',
                'description' => 'Packaging materials and fulfillment supplies.',
                'meta_title' => 'BrightBox Logistics',
                'meta_description' => 'Packaging and fulfillment supplies for e-commerce.',
                'meta_keywords' => 'packaging,logistics,fulfillment',
                'email' => 'contact@brightbox.example',
                'phone' => '+1-555-1005',
                'website' => 'https://example.com/brightbox',
                'facebook_url' => 'https://facebook.com/brightboxlogistics',
                'instagram_url' => '',
                'youtube_url' => 'https://youtube.com/@brightboxlogistics',
                'twitter_url' => 'https://twitter.com/brightboxlog',
                'linkedin_url' => 'https://linkedin.com/company/brightbox-logistics',
                'tiktok_url' => '',
                'address' => '450 Industrial Way',
                'address_line2' => 'Building B',
                'city' => 'Chicago',
                'postcode' => '60607',
                'country' => 'US',
                'state' => 'IL',
                'is_enabled' => '1',
                'logo_url' => '',
            ],
            [
                'name' => 'Sakura Home Goods',
                'slug' => 'sakura-home-goods',
                'description' => 'Home and kitchen goods importer.',
                'meta_title' => 'Sakura Home Goods',
                'meta_description' => 'Importer of home and kitchen goods from Japan.',
                'meta_keywords' => 'home,kitchen,import',
                'email' => 'export@sakurahome.example',
                'phone' => '+81-555-1006',
                'website' => 'https://example.com/sakura',
                'facebook_url' => 'https://facebook.com/sakurahomegoods',
                'instagram_url' => 'https://instagram.com/sakurahomegoods',
                'youtube_url' => 'https://youtube.com/@sakurahome',
                'twitter_url' => '',
                'linkedin_url' => '',
                'tiktok_url' => 'https://tiktok.com/@sakurahome',
                'address' => '3-2-1 Shibuya',
                'address_line2' => '',
                'city' => 'Tokyo',
                'postcode' => '150-0002',
                'country' => 'JP',
                'state' => '',
                'is_enabled' => '1',
                'logo_url' => 'https://example.com/images/suppliers/sakura.png',
            ],
            [
                'name' => 'GreenField Organics',
                'slug' => 'greenfield-organics',
                'description' => 'Organic materials and eco-friendly product supplier.',
                'meta_title' => 'GreenField Organics',
                'meta_description' => 'Organic and eco-friendly materials for retail brands.',
                'meta_keywords' => 'organic,eco-friendly,sustainable',
                'email' => 'team@greenfield.example',
                'phone' => '+61-555-1007',
                'website' => 'https://example.com/greenfield',
                'facebook_url' => 'https://facebook.com/greenfieldorganics',
                'instagram_url' => 'https://instagram.com/greenfieldorganics',
                'youtube_url' => '',
                'twitter_url' => 'https://twitter.com/greenfieldorg',
                'linkedin_url' => 'https://linkedin.com/company/greenfield-organics',
                'tiktok_url' => '',
                'address' => '77 Collins Street',
                'address_line2' => 'Level 12',
                'city' => 'Melbourne',
                'postcode' => '3000',
                'country' => 'AU',
                'state' => 'VIC',
                'is_enabled' => '1',
                'logo_url' => '',
            ],
            [
                'name' => 'Metro Parts Co',
                'slug' => 'metro-parts-co',
                'description' => 'Spare parts and aftermarket accessories.',
                'meta_title' => 'Metro Parts Co',
                'meta_description' => 'Aftermarket spare parts and accessories distributor.',
                'meta_keywords' => 'parts,aftermarket,automotive',
                'email' => 'sales@metroparts.example',
                'phone' => '+1-555-1008',
                'website' => 'https://example.com/metroparts',
                'facebook_url' => 'https://facebook.com/metropartsco',
                'instagram_url' => '',
                'youtube_url' => '',
                'twitter_url' => 'https://twitter.com/metropartsco',
                'linkedin_url' => 'https://linkedin.com/company/metro-parts-co',
                'tiktok_url' => '',
                'address' => '901 Depot Road',
                'address_line2' => 'Unit 14',
                'city' => 'Detroit',
                'postcode' => '48201',
                'country' => 'US',
                'state' => 'MI',
                'is_enabled' => '1',
                'logo_url' => 'https://example.com/images/suppliers/metro-parts.png',
            ],
        ];
    }

    public static function sampleCsv(
        string $delimiter = self::DEFAULT_DELIMITER,
        string $multiSeparator = self::DEFAULT_MULTI_SEPARATOR,
    ): string {
        $out = fopen('php://temp', 'r+');
        CsvSafe::put($out, self::columns(), $delimiter);
        foreach (self::sampleSuppliers() as $supplier) {
            CsvSafe::put($out, self::supplierToRow($supplier, $multiSeparator), $delimiter);
        }
        rewind($out);

        return stream_get_contents($out) ?: '';
    }

    /**
     * @param  array<string, mixed>  $supplier
     * @return list<string>
     */
    public static function supplierToRow(array $supplier, string $multiSeparator = self::DEFAULT_MULTI_SEPARATOR): array
    {
        $row = [];
        foreach (self::columns() as $key) {
            $value = $supplier[$key] ?? '';
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
            'slug' => $slug ?: 'supplier',
            'description' => self::nullable($get('description')),
            'meta_title' => self::nullable($get('meta_title')),
            'meta_description' => self::nullable($get('meta_description')),
            'meta_keywords' => self::nullable($get('meta_keywords')),
            'email' => self::nullable($get('email')),
            'phone' => self::nullable($get('phone')),
            'website' => self::nullable($get('website')),
            'facebook_url' => self::nullable($get('facebook_url')),
            'instagram_url' => self::nullable($get('instagram_url')),
            'youtube_url' => self::nullable($get('youtube_url')),
            'twitter_url' => self::nullable($get('twitter_url')),
            'linkedin_url' => self::nullable($get('linkedin_url')),
            'tiktok_url' => self::nullable($get('tiktok_url')),
            'address' => self::nullable($get('address')),
            'address_line2' => self::nullable($get('address_line2')),
            'city' => self::nullable($get('city')),
            'postcode' => self::nullable($get('postcode')),
            'country' => self::nullable($get('country')),
            'state' => self::resolveStateForImport($get('country'), $get('state')),
            'is_enabled' => self::boolOr($get('is_enabled'), true),
            'logo_url' => self::nullable($get('logo_url')),
            'language_code' => $locale,
        ];
    }

    /** @return list<string|int|null> */
    public static function exportValues(Supplier $supplier, string $locale = 'en'): array
    {
        $t = $supplier->translation($locale);
        $map = [
            'id' => $supplier->id,
            'name' => $t?->name,
            'slug' => $t?->slug,
            'description' => $t?->description,
            'meta_title' => $t?->meta_title,
            'meta_description' => $t?->meta_description,
            'meta_keywords' => $t?->meta_keywords,
            'email' => $supplier->email,
            'phone' => $supplier->phone,
            'website' => $supplier->website,
            'facebook_url' => $supplier->facebook_url,
            'instagram_url' => $supplier->instagram_url,
            'youtube_url' => $supplier->youtube_url,
            'twitter_url' => $supplier->twitter_url,
            'linkedin_url' => $supplier->linkedin_url,
            'tiktok_url' => $supplier->tiktok_url,
            'address' => $supplier->address,
            'address_line2' => $supplier->address_line2,
            'city' => $supplier->city,
            'postcode' => $supplier->postcode,
            'country' => $supplier->country,
            'state' => self::stateCodeForExport($supplier->country, $supplier->state),
            'is_enabled' => self::boolToCsv($supplier->is_enabled),
            'logo_url' => $supplier->logo_path,
        ];

        return array_map(fn (string $key) => $map[$key] ?? '', self::columns());
    }

    protected static function resolveStateForImport(?string $countryCode, ?string $state): ?string
    {
        if (! self::filled($state)) {
            return null;
        }

        $state = trim((string) $state);
        if (! self::filled($countryCode)) {
            return $state;
        }

        $country = Country::query()
            ->where('code', strtoupper(trim($countryCode)))
            ->with(['regions' => fn ($query) => $query->where('is_enabled', true)])
            ->first();

        if (! $country || $country->regions->isEmpty()) {
            return $state;
        }

        $code = strtoupper($state);
        foreach ($country->regions as $region) {
            if (strtoupper((string) $region->code) === $code) {
                return (string) $region->code;
            }
            if (strcasecmp((string) $region->name, $state) === 0) {
                return (string) $region->code;
            }
        }

        return $state;
    }

    protected static function stateCodeForExport(?string $countryCode, ?string $stateValue): string
    {
        if (! self::filled($stateValue)) {
            return '';
        }

        $stateValue = trim((string) $stateValue);
        if (! self::filled($countryCode)) {
            return $stateValue;
        }

        $country = Country::query()
            ->where('code', strtoupper(trim($countryCode)))
            ->with(['regions' => fn ($query) => $query->where('is_enabled', true)])
            ->first();

        if (! $country || $country->regions->isEmpty()) {
            return $stateValue;
        }

        foreach ($country->regions as $region) {
            if ((string) $region->code === $stateValue) {
                return (string) $region->code;
            }
            if (strcasecmp((string) $region->name, $stateValue) === 0) {
                return (string) $region->code;
            }
        }

        return $stateValue;
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
