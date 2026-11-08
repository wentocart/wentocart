<?php

namespace App\Domain\Catalog\Support;

use App\Domain\Catalog\Models\Category;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Security\CsvSafe;
use Illuminate\Support\Str;

/**
 * Single source of truth for category CSV import, export, and sample download.
 */
final class CategoryCsvSchema
{
    public const DEFAULT_DELIMITER = ';';

    public const DEFAULT_MULTI_SEPARATOR = ',';

    /**
     * @return list<string>
     */
    public static function columns(): array
    {
        return [
            'id',
            'name',
            'slug',
            'parent_slug',
            'description',
            'meta_title',
            'meta_description',
            'meta_keywords',
            'canonical_url',
            'is_enabled',
            'position',
            'customer_groups',
            'image_url',
            'thumbnail_url',
        ];
    }

    /**
     * Realistic sample categories covering roots, children, SEO, and status.
     *
     * @return list<array<string, mixed>>
     */
    public static function sampleCategories(): array
    {
        return [
            [
                'name' => 'Electronics',
                'slug' => 'electronics',
                'parent_slug' => '',
                'description' => 'Phones, computers, and consumer electronics.',
                'meta_title' => 'Electronics',
                'meta_description' => 'Shop electronics and gadgets.',
                'meta_keywords' => 'electronics,gadgets',
                'canonical_url' => '',
                'is_enabled' => '1',
                'position' => '1',
                'customer_groups' => '',
                'image_url' => 'https://example.com/images/categories/electronics.jpg',
                'thumbnail_url' => '',
            ],
            [
                'name' => 'Accessories',
                'slug' => 'accessories',
                'parent_slug' => '',
                'description' => 'Cables, cases, and everyday accessories.',
                'meta_title' => 'Accessories',
                'meta_description' => 'Shop accessories for your devices.',
                'meta_keywords' => 'accessories,cables',
                'canonical_url' => '',
                'is_enabled' => '1',
                'position' => '2',
                'customer_groups' => '',
                'image_url' => 'https://example.com/images/categories/accessories.jpg',
                'thumbnail_url' => '',
            ],
            [
                'name' => 'Fashion',
                'slug' => 'fashion',
                'parent_slug' => '',
                'description' => 'Apparel and seasonal fashion collections.',
                'meta_title' => 'Fashion',
                'meta_description' => 'Discover clothing and style.',
                'meta_keywords' => 'fashion,clothing',
                'canonical_url' => '',
                'is_enabled' => '0',
                'position' => '3',
                'customer_groups' => '',
                'image_url' => '',
                'thumbnail_url' => '',
            ],
            [
                'name' => 'Home & Living',
                'slug' => 'home-living',
                'parent_slug' => '',
                'description' => 'Furniture, decor, and home essentials.',
                'meta_title' => 'Home & Living',
                'meta_description' => 'Furnish and style your home.',
                'meta_keywords' => 'home,living,decor',
                'canonical_url' => '',
                'is_enabled' => '1',
                'position' => '4',
                'customer_groups' => '',
                'image_url' => 'https://example.com/images/categories/home.jpg',
                'thumbnail_url' => 'https://example.com/images/categories/home-thumb.jpg',
            ],
            [
                'name' => 'Computers',
                'slug' => 'computers',
                'parent_slug' => 'electronics',
                'description' => 'Laptops, desktops, and computer parts.',
                'meta_title' => 'Computers',
                'meta_description' => 'Find computers and peripherals.',
                'meta_keywords' => 'computers,laptops',
                'canonical_url' => '',
                'is_enabled' => '1',
                'position' => '1',
                'customer_groups' => '',
                'image_url' => '',
                'thumbnail_url' => '',
            ],
            [
                'name' => 'Phones',
                'slug' => 'phones',
                'parent_slug' => 'electronics',
                'description' => 'Smartphones and mobile accessories.',
                'meta_title' => 'Phones',
                'meta_description' => 'Shop phones and mobile gear.',
                'meta_keywords' => 'phones,mobile',
                'canonical_url' => '',
                'is_enabled' => '1',
                'position' => '2',
                'customer_groups' => '',
                'image_url' => '',
                'thumbnail_url' => '',
            ],
            [
                'name' => 'Laptops',
                'slug' => 'laptops',
                'parent_slug' => 'computers',
                'description' => 'Notebooks for work, school, and gaming.',
                'meta_title' => 'Laptops',
                'meta_description' => 'Compare and buy laptops.',
                'meta_keywords' => 'laptops,notebooks',
                'canonical_url' => '/categories/laptops',
                'is_enabled' => '1',
                'position' => '1',
                'customer_groups' => '',
                'image_url' => 'https://example.com/images/categories/laptops.jpg',
                'thumbnail_url' => '',
            ],
            [
                'name' => 'Cables',
                'slug' => 'cables',
                'parent_slug' => 'accessories',
                'description' => 'USB-C, HDMI, and charging cables.',
                'meta_title' => 'Cables',
                'meta_description' => 'Durable cables for every device.',
                'meta_keywords' => 'cables,usb-c',
                'canonical_url' => '',
                'is_enabled' => '1',
                'position' => '1',
                'customer_groups' => '',
                'image_url' => '',
                'thumbnail_url' => '',
            ],
            [
                'name' => 'Cases & Covers',
                'slug' => 'cases-covers',
                'parent_slug' => 'accessories',
                'description' => 'Protective cases for phones and tablets.',
                'meta_title' => 'Cases & Covers',
                'meta_description' => 'Protect your devices in style.',
                'meta_keywords' => 'cases,covers',
                'canonical_url' => '',
                'is_enabled' => '1',
                'position' => '2',
                'customer_groups' => '',
                'image_url' => '',
                'thumbnail_url' => '',
            ],
            [
                'name' => 'Kitchen',
                'slug' => 'kitchen',
                'parent_slug' => 'home-living',
                'description' => 'Cookware, utensils, and kitchen storage.',
                'meta_title' => 'Kitchen',
                'meta_description' => 'Equip your kitchen with essentials.',
                'meta_keywords' => 'kitchen,cookware',
                'canonical_url' => '',
                'is_enabled' => '1',
                'position' => '1',
                'customer_groups' => '',
                'image_url' => '',
                'thumbnail_url' => '',
            ],
            [
                'name' => 'Outdoor',
                'slug' => 'outdoor',
                'parent_slug' => '',
                'description' => 'Garden and outdoor living products.',
                'meta_title' => 'Outdoor',
                'meta_description' => 'Gear for outdoor spaces.',
                'meta_keywords' => 'outdoor,garden',
                'canonical_url' => '',
                'is_enabled' => '0',
                'position' => '5',
                'customer_groups' => '',
                'image_url' => '',
                'thumbnail_url' => '',
            ],
            [
                'name' => 'VIP Exclusive',
                'slug' => 'vip-exclusive',
                'parent_slug' => '',
                'description' => 'Members-only category visible to VIP customers.',
                'meta_title' => 'VIP Exclusive',
                'meta_description' => 'Exclusive offers for VIP members.',
                'meta_keywords' => 'vip,exclusive',
                'canonical_url' => '',
                'is_enabled' => '1',
                'position' => '6',
                'customer_groups' => ['vip'],
                'image_url' => '',
                'thumbnail_url' => '',
            ],
        ];
    }

    public static function sampleCsv(
        string $delimiter = self::DEFAULT_DELIMITER,
        string $multiSeparator = self::DEFAULT_MULTI_SEPARATOR,
    ): string {
        $out = fopen('php://temp', 'r+');
        CsvSafe::put($out, self::columns(), $delimiter);
        foreach (self::sampleCategories() as $category) {
            CsvSafe::put($out, self::categoryToRow($category, $multiSeparator), $delimiter);
        }
        rewind($out);

        return stream_get_contents($out) ?: '';
    }

    /**
     * @param  array<string, mixed>  $category
     * @return list<string>
     */
    public static function categoryToRow(
        array $category,
        string $multiSeparator = self::DEFAULT_MULTI_SEPARATOR,
    ): array {
        $row = [];
        foreach (self::columns() as $key) {
            $value = $category[$key] ?? '';
            if (is_array($value)) {
                $value = implode($multiSeparator, array_map(static fn ($part) => (string) $part, $value));
            }
            $row[] = (string) $value;
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{
     *     name: string,
     *     slug: string,
     *     parent_slug: ?string,
     *     description: ?string,
     *     meta_title: ?string,
     *     meta_description: ?string,
     *     meta_keywords: ?string,
     *     canonical_url: ?string,
     *     is_enabled: bool,
     *     position: int,
     *     customer_group_ids: list<int>,
     *     image_url: ?string,
     *     thumbnail_url: ?string,
     *     language_code: string
     * }
     */
    public static function mapRow(
        array $row,
        string $locale = 'en',
        string $multiSeparator = self::DEFAULT_MULTI_SEPARATOR,
    ): array {
        $get = static fn (string $key): ?string => array_key_exists($key, $row)
            ? trim((string) $row[$key])
            : null;

        $name = (string) ($get('name') ?? '');
        $slug = self::filled($get('slug')) ? (string) $get('slug') : Str::slug($name);

        return [
            'name' => $name,
            'slug' => $slug,
            'parent_slug' => self::filled($get('parent_slug')) ? (string) $get('parent_slug') : null,
            'description' => self::nullable($get('description')),
            'meta_title' => self::nullable($get('meta_title')),
            'meta_description' => self::nullable($get('meta_description')),
            'meta_keywords' => self::nullable($get('meta_keywords')),
            'canonical_url' => self::nullable($get('canonical_url')),
            'is_enabled' => self::boolOr($get('is_enabled'), true),
            'position' => self::filled($get('position')) ? (int) $get('position') : null,
            'customer_group_ids' => self::resolveCustomerGroupIds($get('customer_groups'), $multiSeparator),
            'image_url' => self::nullable($get('image_url')),
            'thumbnail_url' => self::nullable($get('thumbnail_url')),
            'language_code' => $locale,
        ];
    }

    /**
     * @return list<string|int|null>
     */
    public static function exportValues(
        Category $category,
        string $locale = 'en',
        string $multiSeparator = self::DEFAULT_MULTI_SEPARATOR,
    ): array {
        $t = $category->translation($locale);
        $parentSlug = $category->parent?->translation($locale)?->slug;

        $groups = [];
        foreach (Category::normalizeCustomerGroupIds($category->customer_group_ids) as $groupId) {
            $code = CustomerGroup::query()->whereKey($groupId)->value('code');
            if ($code) {
                $groups[] = $code;
            }
        }

        $map = [
            'id' => $category->id,
            'name' => $t?->name,
            'slug' => $t?->slug,
            'parent_slug' => $parentSlug,
            'description' => $t?->description,
            'meta_title' => $t?->meta_title,
            'meta_description' => $t?->meta_description,
            'meta_keywords' => $t?->meta_keywords,
            'canonical_url' => $t?->canonical_url,
            'is_enabled' => self::boolToCsv($category->is_enabled),
            'position' => $category->position,
            'customer_groups' => implode($multiSeparator, $groups),
            'image_url' => $category->image_path,
            'thumbnail_url' => $category->thumbnail_path,
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

    /** @return list<string> */
    protected static function splitMulti(?string $value, string $multiSeparator): array
    {
        if (! self::filled($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $part) => trim($part),
            explode($multiSeparator, $value)
        ), static fn (string $part) => $part !== ''));
    }

    /** @return list<int> */
    protected static function resolveCustomerGroupIds(?string $value, string $multiSeparator): array
    {
        $ids = [];
        foreach (self::splitMulti($value, $multiSeparator) as $token) {
            $id = CustomerGroup::query()
                ->where(function ($q) use ($token) {
                    $q->where('code', $token)->orWhere('name', $token);
                })
                ->value('id');
            if ($id) {
                $ids[] = (int) $id;
            }
        }

        return Category::normalizeCustomerGroupIds($ids);
    }
}
