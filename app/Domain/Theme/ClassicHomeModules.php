<?php

namespace App\Domain\Theme;

/**
 * Classic theme homepage modules. Bundled with Classic (not zip Extensions).
 * Placement lives in theme settings; features live on each module Configure screen.
 */
final class ClassicHomeModules
{
    public const HEADER = 'header';

    public const FOOTER = 'footer';

    /**
     * Site chrome (not in the homepage reorder list).
     *
     * @return array<string, array{label: string, hint: string}>
     */
    public static function extras(): array
    {
        return [
            self::HEADER => [
                'label' => 'Header',
                'hint' => 'Which categories appear in the browse menu.',
            ],
            self::FOOTER => [
                'label' => 'Footer',
                'hint' => '',
            ],
        ];
    }

    public static function isConfigurable(string $code): bool
    {
        return isset(self::definitions()[$code]) || isset(self::extras()[$code]);
    }

    /**
     * @return array{label: string, hint: string}|null
     */
    public static function definition(string $code): ?array
    {
        return self::definitions()[$code] ?? self::extras()[$code] ?? null;
    }

    /**
     * Default homepage sequence after the header.
     *
     * @return list<string>
     */
    public static function defaultOrder(): array
    {
        return [
            'hero',
            'trust',
            'promos',
            'featured',
            'banner',
            'new_arrivals',
            'categories',
            'promos_mid',
            'category_shelves',
            'testimonials',
            'bestsellers',
            'promos_bottom',
            'brands',
            'content',
            'newsletter',
        ];
    }

    /**
     * @return array<string, array{label: string, hint: string}>
     */
    public static function definitions(): array
    {
        return [
            'hero' => [
                'label' => 'Hero Banner',
                'hint' => 'Pick layout 1 / 2 / 3 and configure it.',
            ],
            'trust' => [
                'label' => 'Trust row',
                'hint' => 'Icon, title, and short text. Add or remove items.',
            ],
            'promos' => [
                'label' => 'Image banners (1)',
                'hint' => '1 to 4 image banners. Text, image, button, and URL.',
            ],
            'featured' => [
                'label' => 'Featured products',
                'hint' => 'Title, how many products, optional category.',
            ],
            'banner' => [
                'label' => 'Banner',
                'hint' => 'One full-width banner.',
            ],
            'new_arrivals' => [
                'label' => 'New products',
                'hint' => 'Title and how many new products.',
            ],
            'categories' => [
                'label' => 'Shop by category',
                'hint' => 'Which categories appear on the home page.',
            ],
            'promos_mid' => [
                'label' => 'Image banners (2)',
                'hint' => '1 to 4 image banners.',
            ],
            'category_shelves' => [
                'label' => 'Category products',
                'hint' => 'Which category blocks appear on the home page.',
            ],
            'testimonials' => [
                'label' => 'Client reviews',
                'hint' => 'Quotes shown on the home page.',
            ],
            'bestsellers' => [
                'label' => 'Best sellers',
                'hint' => 'Title and how many best-seller products.',
            ],
            'promos_bottom' => [
                'label' => 'Image banners (3)',
                'hint' => '1 to 4 image banners.',
            ],
            'brands' => [
                'label' => 'Popular brands',
                'hint' => 'Which brands appear and how many.',
            ],
            'content' => [
                'label' => 'Content banner',
                'hint' => 'Title and HTML text block.',
            ],
            'newsletter' => [
                'label' => 'Newsletter',
                'hint' => 'Subscribe form. Subscribers are stored in the shop.',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * @param  list<string>  $order
     * @return list<string>
     */
    public static function sanitizeOrder(array $order): array
    {
        $allowed = self::codes();
        $clean = [];
        foreach ($order as $code) {
            $code = is_string($code) ? $code : '';
            if ($code !== '' && in_array($code, $allowed, true) && ! in_array($code, $clean, true)) {
                $clean[] = $code;
            }
        }
        foreach ($allowed as $code) {
            if (! in_array($code, $clean, true)) {
                $clean[] = $code;
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<array{code: string, label: string, hint: string, enabled: bool, configure: string}>
     */
    public static function layoutRows(array $settings): array
    {
        $home = is_array($settings['home'] ?? null) ? $settings['home'] : [];
        $sections = is_array($home['sections'] ?? null) ? $home['sections'] : [];
        $order = self::sanitizeOrder(is_array($home['order'] ?? null) ? $home['order'] : self::defaultOrder());
        $defs = self::definitions();
        $rows = [];
        foreach ($order as $code) {
            $def = $defs[$code];
            $rows[] = [
                'code' => $code,
                'label' => $def['label'],
                'hint' => $def['hint'],
                'enabled' => ($sections[$code] ?? true) !== false,
                'configure' => '/admin/themes/modules/'.$code,
            ];
        }

        return $rows;
    }
}
