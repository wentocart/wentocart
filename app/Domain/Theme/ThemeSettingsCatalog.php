<?php

namespace App\Domain\Theme;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\UrlRewrite;
use App\Domain\Security\HtmlSanitizer;

/**
 * Defaults, color presets, and normalization for shop theme_settings overlays.
 */
class ThemeSettingsCatalog
{
    public const PRESET_AZURE = 'azure';

    public const PRESET_FOREST = 'forest';

    public const PRESET_PURPLE = 'purple';

    public const PRESET_MARKETPLACE = 'marketplace';

    public const PRESET_DARK = 'dark';

    public const PRESET_CUSTOM = 'custom';

    /** Fixed product count per homepage category shelf (not configurable in admin). */
    public const CATEGORY_SHELF_PRODUCT_LIMIT = 4;

    /** Classic install logo shipped under public/themes (theme.json + merchant pack). */
    public const DEFAULT_LOGO_URL = '/themes/classic/assets/merchant/ui/logo.png';

    /** Bundled shop icon. Used when the merchant has not uploaded a favicon. */
    public const DEFAULT_FAVICON_URL = '/themes/classic/assets/img/favicon.png';

    /** Previous Classic tab icon; treated as the bundled default so installs pick up the W mark. */
    public const LEGACY_DEFAULT_FAVICON_URL = '/themes/classic/assets/img/favicon.ico';

    /** Compact admin-sidebar mark when the rail is collapsed (not stored as logo_url). */
    public const DEFAULT_LOGO_SHORT_URL = '/themes/classic/assets/merchant/ui/logo_short.png';

    /** Previous install default; treated as the bundled default so upgrades pick up the new asset. */
    public const LEGACY_DEFAULT_LOGO_URL = '/themes/classic/assets/merchant/ui/logo.svg';

    /** @return list<string> */
    public static function presetIds(): array
    {
        return [
            self::PRESET_AZURE,
            self::PRESET_FOREST,
            self::PRESET_PURPLE,
            self::PRESET_MARKETPLACE,
            self::PRESET_DARK,
            self::PRESET_CUSTOM,
        ];
    }

    /**
     * @return list<array{id: string, label: string, description: string}>
     */
    public static function presets(): array
    {
        $rows = [
            [
                'id' => self::PRESET_AZURE,
                'label' => 'Classic Azure',
                'description' => 'Blue storefront with a bright accent.',
            ],
            [
                'id' => self::PRESET_FOREST,
                'label' => 'Classic Forest',
                'description' => 'Green storefront for everyday retail.',
            ],
            [
                'id' => self::PRESET_PURPLE,
                'label' => 'Classic Purple',
                'description' => 'Violet storefront.',
            ],
            [
                'id' => self::PRESET_MARKETPLACE,
                'label' => 'Marketplace',
                'description' => 'Light page, dark header, and sale accents.',
            ],
            [
                'id' => self::PRESET_DARK,
                'label' => 'Dark',
                'description' => 'Dark page with light text.',
            ],
            [
                'id' => self::PRESET_CUSTOM,
                'label' => 'Custom',
                'description' => 'Choose each color yourself.',
            ],
        ];

        return array_map(static function (array $row): array {
            if ($row['id'] === self::PRESET_CUSTOM) {
                return $row;
            }
            $colors = self::presetColors($row['id']);
            $row['colors'] = [
                'accent' => $colors['accent'] ?? '',
                'header' => $colors['header'] ?? '',
                'sale' => $colors['sale'] ?? '',
                'accent_soft' => $colors['accent_soft'] ?? '',
            ];

            return $row;
        }, $rows);
    }

    /**
     * @return array<string, mixed>
     */
    /** Bundled hero media under themes/classic/assets/merchant/home/hero/. */
    public const HERO_MEDIA = '/themes/classic/assets/merchant/home/hero';

    public const HERO_PLACEHOLDER = self::HERO_MEDIA.'/placeholder.png';

    /**
     * Rich HTML for bundled hero slides (keyed by asset path under hero/).
     *
     * @return array<string, string>
     */
    public static function demoHeroHtml(): array
    {
        return [
            'layout-1/slide-1.png' => '<p><strong>Stay hydrated</strong></p><h2>Insulated bottles for desk and travel</h2><p>Double-wall bottles that keep drinks cold on the commute and hot at the desk. The same shape in coral, sand, and black.</p><p>Sets from $34</p><p><a href="/lifestyle">Shop bottles</a></p>',
            'layout-1/slide-2.png' => '<p><strong>Desk essentials</strong></p><h2>Smooth-writing pens in three colors</h2><p>A quiet click, a fine line, and a clip that stays put. Keep a coral, sand, or black pen within reach.</p><p>From $12</p><p><a href="/office-tech">Shop pens</a></p>',
            'layout-1/slide-3.png' => '<p><strong>Signature scents</strong></p><h2>Discover premium fragrance today</h2><p>Bleu Élégance is a clean eau de parfum with a soft trail. One bottle for everyday wear.</p><p>From $49</p><p><a href="/catalog">Shop fragrance</a></p>',
            'layout-2/a-1.png' => '<p><strong>Stay hydrated</strong></p><h2>Insulated bottles for desk and travel</h2><p>Double-wall bottles that keep drinks cold on the commute and hot at the desk.</p><p>Sets from $34</p><p><a href="/lifestyle">Shop bottles</a></p>',
            'layout-2/a-2.jpg' => '<p><strong>Desk essentials</strong></p><h2>Smooth-writing pens in three colors</h2><p>A quiet click, a fine line, and a clip that stays put.</p><p>From $12</p><p><a href="/office-tech">Shop pens</a></p>',
            'layout-2/a-3.jpg' => '<p><strong>Signature scents</strong></p><h2>Bleu Élégance eau de parfum</h2><p>A clean fragrance with a soft trail for everyday wear.</p><p>From $49</p><p><a href="/catalog">Shop fragrance</a></p>',
            'layout-2/b-1.jpg' => '<p><strong>Drive safer</strong></p><h2>70mai smart dash cam</h2><p>Clear road footage day and night with a compact windshield mount.</p><p>From $69</p><p><a href="/electronics">Shop dash cams</a></p>',
            'layout-2/b-2.jpg' => '<p><strong>Desk upgrade</strong></p><h2>Adjustable phone & tablet stands</h2><p>Tilt and height that stay put for calls, recipes, and streaming.</p><p>From $24</p><p><a href="/office-tech">Shop stands</a></p>',
            'layout-2/b-3.jpg' => '<p><strong>Quiet focus</strong></p><h2>Minimal quartz desk clocks</h2><p>Soft colors, a clear face, and a stand that looks intentional.</p><p>From $29</p><p><a href="/lifestyle">Shop clocks</a></p>',
            'layout-3/a-1.jpg' => '<p><strong>Pool ready</strong></p><h2>Mirror swim goggles</h2><p>Anti-fog lenses and a snug strap for laps and open water.</p><p>From $28</p><p><a href="/sports-outdoors">Shop swim</a></p>',
            'layout-3/a-2.jpg' => '<p><strong>Travel light</strong></p><h2>Spinner carry-on luggage</h2><p>Hard shell, TSA lock, and wheels that glide through the terminal.</p><p>From $89</p><p><a href="/lifestyle">Shop luggage</a></p>',
            'layout-3/a-3.jpg' => '<p><strong>Work smarter</strong></p><h2>Ergonomic mesh office chair</h2><p>Lumbar support and soft wheels for long focus days at the desk.</p><p>From $149</p><p><a href="/office-tech">Shop office</a></p>',
            'layout-3/b-1.jpg' => '<p><strong>Desk essentials</strong></p><h2>Pens in three colors</h2><p>Coral, sand, and black — keep one within reach.</p><p>From $12</p><p><a href="/office-tech">Shop pens</a></p>',
            'layout-3/b-2.jpg' => '<p><strong>On the road</strong></p><h2>70mai dash cam</h2><p>Compact proof for every drive.</p><p>From $69</p><p><a href="/electronics">Shop now</a></p>',
            'layout-3/b-3.jpg' => '<p><strong>Hands free</strong></p><h2>Adjustable phone stands</h2><p>Two angles for video calls and recipes.</p><p>From $24</p><p><a href="/office-tech">Shop stands</a></p>',
            'layout-3/c-1.jpg' => '<p><strong>Coffee time</strong></p><h2>Speckled ceramic mug</h2><p>A soft pink base for slow morning pours.</p><p>From $18</p><p><a href="/lifestyle">Shop kitchen</a></p>',
            'layout-3/c-2.jpg' => '<p><strong>Create more</strong></p><h2>Pro laptop lineup</h2><p>Power for editing, coding, and everyday work.</p><p>From $999</p><p><a href="/electronics">Shop laptops</a></p>',
            'layout-3/c-3.jpg' => '<p><strong>Everyday style</strong></p><h2>Classic baseball caps</h2><p>Pink, blue, and black — easy finishing touches.</p><p>From $22</p><p><a href="/lifestyle">Shop headwear</a></p>',
            // Legacy keys still referenced by older demos / side defaults.
            'hero-1.png' => '<p><strong>Stay hydrated</strong></p><h2>Insulated bottles for desk and travel</h2><p>Double-wall bottles that keep drinks cold on the commute and hot at the desk. The same shape in coral, sand, and black.</p><p>Sets from $34</p><p><a href="/lifestyle">Shop bottles</a></p>',
            'hero-2.png' => '<p><strong>Desk essentials</strong></p><h2>Smooth-writing pens in three colors</h2><p>A quiet click, a fine line, and a clip that stays put. Keep a coral, sand, or black pen within reach.</p><p>From $12</p><p><a href="/office-tech">Shop pens</a></p>',
            'hero-3.png' => '<p><strong>Signature scents</strong></p><h2>Discover premium fragrance today</h2><p>Bleu Élégance is a clean eau de parfum with a soft trail. One bottle for everyday wear.</p><p>From $49</p><p><a href="/catalog">Shop fragrance</a></p>',
        ];
    }

    public static function heroMedia(string $relative): string
    {
        return self::HERO_MEDIA.'/'.ltrim($relative, '/');
    }

    /**
     * @param  array{eyebrow?: string, title: string, body?: string, content: string, cta_label: string, cta_url: string, image: string}  $row
     * @return array<string, mixed>
     */
    protected static function heroDefaultSlide(array $row): array
    {
        $image = (string) $row['image'];
        $html = self::demoHeroHtml();

        return [
            'eyebrow' => $row['eyebrow'] ?? '',
            'title' => $row['title'],
            'body' => $row['body'] ?? '',
            'content' => $html[$image] ?? ('<h2>'.e($row['title']).'</h2><p><a href="'.e($row['cta_url']).'">'.e($row['cta_label']).'</a></p>'),
            'cta_label' => $row['cta_label'],
            'cta_url' => $row['cta_url'],
            'image_url' => self::heroMedia($image),
        ];
    }

    /**
     * Default column/slide trees for layouts 1–3 (3 / 6 / 9 bundled banners).
     *
     * @return array<int, list<array{width_hint: string, slides: list<array<string, mixed>>}>>
     */
    public static function defaultHeroLayoutColumns(): array
    {
        $slide = static fn (array $row): array => self::heroDefaultSlide($row);

        return [
            1 => [
                [
                    'width_hint' => 'full',
                    'slides' => [
                        $slide(['eyebrow' => 'Stay hydrated', 'title' => 'Insulated bottles for desk and travel', 'body' => 'Double-wall bottles that keep drinks cold on the commute and hot at the desk. The same shape in coral, sand, and black.', 'cta_label' => 'Shop bottles', 'cta_url' => '/lifestyle', 'image' => 'layout-1/slide-1.png']),
                        $slide(['eyebrow' => 'Desk essentials', 'title' => 'Smooth-writing pens in three colors', 'body' => 'A quiet click, a fine line, and a clip that stays put. Keep a coral, sand, or black pen within reach.', 'cta_label' => 'Shop pens', 'cta_url' => '/office-tech', 'image' => 'layout-1/slide-2.png']),
                        $slide(['eyebrow' => 'Signature scents', 'title' => 'Discover premium fragrance today', 'body' => 'Bleu Élégance is a clean eau de parfum with a soft trail. One bottle for everyday wear.', 'cta_label' => 'Shop fragrance', 'cta_url' => '/catalog', 'image' => 'layout-1/slide-3.png']),
                    ],
                ],
            ],
            2 => [
                [
                    'width_hint' => 'main',
                    'slides' => [
                        $slide(['eyebrow' => 'Stay hydrated', 'title' => 'Insulated bottles for desk and travel', 'body' => 'Double-wall bottles for commute and desk.', 'cta_label' => 'Shop bottles', 'cta_url' => '/lifestyle', 'image' => 'layout-2/a-1.png']),
                        $slide(['eyebrow' => 'Desk essentials', 'title' => 'Smooth-writing pens in three colors', 'body' => 'Coral, sand, and black within reach.', 'cta_label' => 'Shop pens', 'cta_url' => '/office-tech', 'image' => 'layout-2/a-2.jpg']),
                        $slide(['eyebrow' => 'Signature scents', 'title' => 'Bleu Élégance eau de parfum', 'body' => 'A clean everyday fragrance.', 'cta_label' => 'Shop fragrance', 'cta_url' => '/catalog', 'image' => 'layout-2/a-3.jpg']),
                    ],
                ],
                [
                    'width_hint' => 'side',
                    'slides' => [
                        $slide(['eyebrow' => 'Drive safer', 'title' => '70mai smart dash cam', 'body' => 'Clear road footage day and night.', 'cta_label' => 'Shop dash cams', 'cta_url' => '/electronics', 'image' => 'layout-2/b-1.jpg']),
                        $slide(['eyebrow' => 'Desk upgrade', 'title' => 'Adjustable phone & tablet stands', 'body' => 'Tilt and height that stay put.', 'cta_label' => 'Shop stands', 'cta_url' => '/office-tech', 'image' => 'layout-2/b-2.jpg']),
                        $slide(['eyebrow' => 'Quiet focus', 'title' => 'Minimal quartz desk clocks', 'body' => 'Soft colors for a calm desk.', 'cta_label' => 'Shop clocks', 'cta_url' => '/lifestyle', 'image' => 'layout-2/b-3.jpg']),
                    ],
                ],
            ],
            3 => [
                [
                    'width_hint' => 'main',
                    'slides' => [
                        $slide(['eyebrow' => 'Pool ready', 'title' => 'Mirror swim goggles', 'body' => 'Anti-fog lenses for laps and open water.', 'cta_label' => 'Shop swim', 'cta_url' => '/sports-outdoors', 'image' => 'layout-3/a-1.jpg']),
                        $slide(['eyebrow' => 'Travel light', 'title' => 'Spinner carry-on luggage', 'body' => 'Hard shell and TSA lock for easy trips.', 'cta_label' => 'Shop luggage', 'cta_url' => '/lifestyle', 'image' => 'layout-3/a-2.jpg']),
                        $slide(['eyebrow' => 'Work smarter', 'title' => 'Ergonomic mesh office chair', 'body' => 'Lumbar support for long focus days.', 'cta_label' => 'Shop office', 'cta_url' => '/office-tech', 'image' => 'layout-3/a-3.jpg']),
                    ],
                ],
                [
                    'width_hint' => 'side',
                    'slides' => [
                        $slide(['eyebrow' => 'Desk essentials', 'title' => 'Pens in three colors', 'body' => 'Coral, sand, and black.', 'cta_label' => 'Shop pens', 'cta_url' => '/office-tech', 'image' => 'layout-3/b-1.jpg']),
                        $slide(['eyebrow' => 'On the road', 'title' => '70mai dash cam', 'body' => 'Compact proof for every drive.', 'cta_label' => 'Shop now', 'cta_url' => '/electronics', 'image' => 'layout-3/b-2.jpg']),
                        $slide(['eyebrow' => 'Hands free', 'title' => 'Adjustable phone stands', 'body' => 'Two angles for calls and recipes.', 'cta_label' => 'Shop stands', 'cta_url' => '/office-tech', 'image' => 'layout-3/b-3.jpg']),
                    ],
                ],
                [
                    'width_hint' => 'side',
                    'slides' => [
                        $slide(['eyebrow' => 'Coffee time', 'title' => 'Speckled ceramic mug', 'body' => 'Soft pink for slow mornings.', 'cta_label' => 'Shop kitchen', 'cta_url' => '/lifestyle', 'image' => 'layout-3/c-1.jpg']),
                        $slide(['eyebrow' => 'Create more', 'title' => 'Pro laptop lineup', 'body' => 'Power for work and creative days.', 'cta_label' => 'Shop laptops', 'cta_url' => '/electronics', 'image' => 'layout-3/c-2.jpg']),
                        $slide(['eyebrow' => 'Everyday style', 'title' => 'Classic baseball caps', 'body' => 'Pink, blue, and black.', 'cta_label' => 'Shop headwear', 'cta_url' => '/lifestyle', 'image' => 'layout-3/c-3.jpg']),
                    ],
                ],
            ],
        ];
    }

    public static function defaults(): array
    {
        return [
            'preset' => self::PRESET_AZURE,
            'logo_text' => '',
            'logo_url' => self::DEFAULT_LOGO_URL,
            'favicon_url' => self::DEFAULT_FAVICON_URL,
            'announcement' => 'Super sale up to 20% in this holiday',
            'phone' => '(+1) 1234-567-890',
            'announcement_url' => '/new-arrivals',
            'top_bar' => [
                'enabled' => true,
                'background' => '#3361cf',
                'text_color' => '#f8fafc',
                'show_currency' => true,
                'show_language' => true,
                'announcement' => 'FREE SHIPPING FOR ALL ORDERS OF $75',
                'announcement_url' => '/catalog',
                'links' => [
                    ['label' => 'Gift Cards', 'url' => '/account/vouchers', 'open_in_new_tab' => false],
                    ['label' => 'About Us', 'url' => '/about', 'open_in_new_tab' => false],
                    ['label' => 'Help', 'url' => '/help', 'open_in_new_tab' => false],
                ],
                'contact' => [
                    'phone' => '(+1) 1234-567-890',
                    'label' => 'Contact us',
                    'url' => '/contact',
                    'email' => '',
                ],
            ],
            'colors' => self::presetColors(self::PRESET_AZURE),
            'radius' => '14px',
            'font' => 'Plus Jakarta Sans',
            'font_size' => '16px',
            'home' => [
                'layout' => 'classic',
                'order' => ClassicHomeModules::defaultOrder(),
                'sections' => [
                    'hero' => true,
                    'trust' => true,
                    'categories' => true,
                    'promos' => true,
                    'promos_mid' => true,
                    'promos_bottom' => true,
                    'banner' => true,
                    'new_arrivals' => true,
                    'featured' => true,
                    'bestsellers' => true,
                    'on_sale' => true,
                    'deal' => true,
                    'category_shelves' => true,
                    'brands' => true,
                    'testimonials' => true,
                    'seo' => true,
                    'content' => true,
                    'newsletter' => true,
                    'recent' => true,
                ],
                'density' => [
                    'products_per_row' => 5,
                    'categories_per_row' => 6,
                    'recent_per_row' => 4,
                    'products_limit' => 12,
                    'categories_limit' => 20,
                    'recent_limit' => 4,
                ],
                'hero' => (static function (): array {
                    $layoutColumns = self::defaultHeroLayoutColumns();
                    $columns = $layoutColumns[1];
                    $slides = $columns[0]['slides'];
                    $first = $slides[0];

                    return [
                        'layout' => 1,
                        'eyebrow' => $first['eyebrow'],
                        'title' => $first['title'],
                        'body' => $first['body'],
                        'cta_label' => $first['cta_label'],
                        'cta_url' => $first['cta_url'],
                        'cta2_label' => '',
                        'cta2_url' => '/new-arrivals',
                        'image_url' => $first['image_url'],
                        'autoplay_ms' => 5500,
                        'layout_columns' => $layoutColumns,
                        'columns' => $columns,
                        'slides' => $slides,
                        'sides' => [
                            [
                                'kicker' => 'Featured',
                                'title' => "Adjustable\nphone &\ntablet stand",
                                'subtitle' => 'From $24',
                                'cta_label' => 'Shop office gear',
                                'cta_url' => '/office-tech',
                                'image_url' => '/themes/classic/assets/merchant/home/side-1.png',
                            ],
                            [
                                'kicker' => 'Top rated',
                                'title' => "Wireless\nin-ear\nearbuds",
                                'subtitle' => 'From $59',
                                'cta_label' => 'Shop audio',
                                'cta_url' => '/audio',
                                'image_url' => '/themes/classic/assets/merchant/home/side-2.png',
                            ],
                        ],
                    ];
                })(),
                'trust' => [
                    ['icon' => 'truck', 'title' => 'Free Fast Shipping', 'text' => 'Free shipping for orders over'],
                    ['icon' => 'support', 'title' => 'Our Happy Clients', 'text' => 'Online service center worldwide'],
                    ['icon' => 'return', 'title' => 'Best Online Support', 'text' => 'Within 30 days money return'],
                    ['icon' => 'shield', 'title' => 'Secure Payments', 'text' => 'Pay with multiple credit cards'],
                ],
                'promos' => [
                    [
                        'kicker' => 'Laptops',
                        'title' => "Power for\ncreators &\nwork",
                        'subtitle' => 'From $699',
                        'cta' => 'Shop tablets',
                        'url' => '/mobile-tablets',
                        'bg' => '#EEF1F6',
                        'image_url' => '/themes/classic/assets/merchant/banners/6.png',
                    ],
                    [
                        'kicker' => 'Carry',
                        'title' => "Leather loop\nkeychains",
                        'subtitle' => 'From $19',
                        'cta' => 'Shop accessories',
                        'url' => '/gaming-accessories',
                        'bg' => '#EEF1F6',
                        'image_url' => '/themes/classic/assets/merchant/banners/7.png',
                    ],
                    [
                        'kicker' => 'Headwear',
                        'title' => "Classic caps\nin three\ncolors",
                        'subtitle' => 'From $24',
                        'cta' => 'Shop catalog',
                        'url' => '/catalog',
                        'bg' => '#EEF1F6',
                        'image_url' => '/themes/classic/assets/merchant/banners/8.png',
                    ],
                ],
                'promos_mid' => [
                    [
                        'kicker' => 'Style',
                        'title' => "Genuine leather\nbelt",
                        'subtitle' => 'From $34',
                        'cta' => 'Shop catalog',
                        'url' => '/catalog',
                        'image_url' => '/themes/classic/assets/merchant/banners/9.png',
                    ],
                    [
                        'kicker' => 'Lighting',
                        'title' => "Energy-saving\nLED bulbs",
                        'subtitle' => 'From $12',
                        'cta' => 'Shop smart home',
                        'url' => '/smart-home',
                        'image_url' => '/themes/classic/assets/merchant/banners/10.png',
                    ],
                    [
                        'kicker' => 'Travel',
                        'title' => "Lightweight\ncarry-on\nluggage",
                        'subtitle' => 'From $89',
                        'cta' => 'Shop catalog',
                        'url' => '/catalog',
                        'image_url' => '/themes/classic/assets/merchant/banners/11.png',
                    ],
                ],
                'promos_bottom' => [
                    [
                        'kicker' => 'Wi-Fi',
                        'title' => "Fast home\nmesh router",
                        'subtitle' => 'Stay connected',
                        'cta' => 'Shop smart home',
                        'url' => '/smart-home',
                        'image_url' => '/themes/classic/assets/merchant/banners/12.png',
                    ],
                    [
                        'kicker' => 'Office',
                        'title' => "Ergonomic\ndesk chair",
                        'subtitle' => 'Work in comfort',
                        'cta' => 'Shop office',
                        'url' => '/office-tech',
                        'image_url' => '/themes/classic/assets/merchant/banners/13.png',
                    ],
                    [
                        'kicker' => 'Audio',
                        'title' => "Portable\nBluetooth\nspeakers",
                        'subtitle' => 'From $49',
                        'cta' => 'Shop audio',
                        'url' => '/audio',
                        'image_url' => '/themes/classic/assets/merchant/banners/14.png',
                    ],
                ],
                'testimonials' => [
                    [
                        'name' => 'Alex Morgan',
                        'quote' => 'Fast shipping and the quality is better than I expected. Will order again.',
                        'rating' => 5,
                        'role' => 'Verified buyer',
                        'avatar_url' => '',
                    ],
                    [
                        'name' => 'Sam Rivera',
                        'quote' => 'Clean store, easy checkout, and support answered my sizing question in minutes.',
                        'rating' => 5,
                        'role' => 'Verified buyer',
                        'avatar_url' => '',
                    ],
                    [
                        'name' => 'Jordan Lee',
                        'quote' => 'Love the curated picks. Returns were simple when I needed a different color.',
                        'rating' => 4,
                        'role' => 'Verified buyer',
                        'avatar_url' => '',
                    ],
                ],
                'newsletter' => [
                    'title' => 'Join Our Newsletter',
                    'body' => 'Get all latest information on events, sales and offers',
                ],
                'featured' => [
                    'title' => 'Featured Products',
                    'limit' => 12,
                    'category_id' => null,
                ],
                'new_arrivals' => [
                    'title' => 'New Products',
                    'limit' => 12,
                ],
                'bestsellers' => [
                    'title' => 'Bestseller Products',
                    'limit' => 12,
                ],
                'shop_categories' => [
                    'title' => 'Shop by Featured Categories',
                    'limit' => 12,
                    'category_ids' => [],
                ],
                'category_shelves' => [
                    'category_ids' => [],
                    'show_view_all' => true,
                    'banner_image_url' => '/themes/classic/assets/merchant/banners/15.png',
                ],
                'brands' => [
                    'title' => 'Popular Brands',
                    'limit' => 16,
                    'brand_ids' => [],
                ],
                'banner' => [
                    'kicker' => 'Use discount code in checkout!',
                    'title' => 'Super discount for your first purchase',
                    'subtitle' => 'GET30OFF',
                    'cta' => 'Shop Now',
                    'url' => '/catalog',
                    'image_url' => '',
                ],
                'content' => [
                    'title' => 'Smart Electronics Designed Modern Lifestyle',
                    'html' => '<p>Electronics is the study of electricity the flow of electrons and how to use that to build things like computers it uses circuits that are made with parts describe anything using electronic equipment from electronic games and gadgets to electronic banking and journalism the field of electronics is a branch of physics and electrical engineering concerned with manipulating electrons to perform tasks called components and connecting wires to do useful things the science behind electronics comes from the study of physics and gets applied.</p>',
                ],
                'header' => [
                    'category_ids' => [],
                    'nav_source' => 'categories',
                    'show_search' => true,
                    'show_compare' => true,
                    'show_wishlist' => true,
                    'show_login' => true,
                    'show_cart' => true,
                    'cart_style' => 'pill',
                    'search_placeholder' => 'Search for products, brands and more...',
                ],
                'footer' => [
                    'copyright' => '',
                    'columns' => [
                        [
                            'title' => 'Contact Us',
                            'type' => 'contact',
                            'links' => [],
                        ],
                        [
                            'title' => 'Products',
                            'type' => 'links',
                            'links' => [
                                ['label' => 'Prices drop', 'url' => '/deal'],
                                ['label' => 'New products', 'url' => '/new-arrivals'],
                                ['label' => 'Best sellers', 'url' => '/catalog'],
                            ],
                        ],
                        [
                            'title' => 'Our Company',
                            'type' => 'links',
                            'links' => [
                                ['label' => 'Delivery', 'url' => '/shipping'],
                                ['label' => 'Legal Notice', 'url' => '/privacy'],
                                ['label' => 'Terms and conditions of use', 'url' => '/terms'],
                                ['label' => 'About us', 'url' => '/about'],
                                ['label' => 'Secure payment', 'url' => '/help'],
                                ['label' => 'Contact us', 'url' => '/contact'],
                                ['label' => 'Stores', 'url' => '/stores'],
                            ],
                        ],
                        [
                            'title' => 'Trending Category',
                            'type' => 'categories',
                            'links' => [],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function presetColors(string $preset): array
    {
        return match ($preset) {
            self::PRESET_FOREST => [
                'accent' => '#1C523B',
                'accent_soft' => '#E8F0EC',
                'ink' => '#111111',
                'muted' => '#6B7280',
                'surface' => '#F7F8FA',
                'card' => '#FFFFFF',
                'header' => '#1C523B',
                'footer' => '#1C523B',
                'sale' => '#FF4D4D',
                'highlight' => '#F97316',
            ],
            self::PRESET_PURPLE => [
                'accent' => '#6D3AED',
                'accent_soft' => '#EDE7FF',
                'ink' => '#111111',
                'muted' => '#6B7280',
                'surface' => '#F6F7FB',
                'card' => '#FFFFFF',
                'header' => '#5B21B6',
                'footer' => '#1E1145',
                'sale' => '#EF4444',
                'highlight' => '#F97316',
            ],
            self::PRESET_MARKETPLACE => [
                'accent' => '#A855F7',
                'accent_soft' => '#F3E8FF',
                'ink' => '#0F172A',
                'muted' => '#64748B',
                'surface' => '#F8FAFC',
                'card' => '#FFFFFF',
                'header' => '#0F172A',
                'footer' => '#0F172A',
                'sale' => '#EC4899',
                'highlight' => '#F97316',
            ],
            self::PRESET_DARK => [
                'accent' => '#C8F542',
                'accent_soft' => '#1C2710',
                'ink' => '#F4F4F5',
                'muted' => '#A1A1AA',
                'surface' => '#07080D',
                'card' => '#12141C',
                'header' => '#05060A',
                'footer' => '#05060A',
                'sale' => '#FB7185',
                'highlight' => '#F97316',
            ],
            default => [
                'accent' => '#4F46E5',
                'accent_soft' => '#EEF2FF',
                'ink' => '#0F172A',
                'muted' => '#64748B',
                'surface' => '#FAFAFA',
                'card' => '#FFFFFF',
                'header' => '#FFFFFF',
                'footer' => '#0F172A',
                'sale' => '#F43F5E',
                'highlight' => '#F59E0B',
            ],
        };
    }

    /**
     * Apply a named preset onto existing settings (colors + keep custom copy).
     *
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    public static function applyPreset(string $preset, array $current = []): array
    {
        $base = self::defaults();
        $merged = self::merge($base, $current);
        $preset = in_array($preset, self::presetIds(), true)
            ? $preset
            : self::PRESET_AZURE;

        $merged['preset'] = $preset;
        if ($preset !== self::PRESET_CUSTOM) {
            $merged['colors'] = self::presetColors($preset);
        }

        return $merged;
    }

    /**
     * Normalize + sanitize admin payload before save.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        $base = self::defaults();
        $postedAnnouncementOnly = array_key_exists('announcement', $input) && ! array_key_exists('top_bar', $input);
        $merged = self::merge($base, $input);
        if ($postedAnnouncementOnly) {
            $merged['top_bar']['announcement'] = $input['announcement'];
        }

        $preset = (string) ($merged['preset'] ?? self::PRESET_AZURE);
        if (! in_array($preset, self::presetIds(), true)) {
            $preset = self::PRESET_AZURE;
        }
        $merged['preset'] = $preset;

        if ($preset !== self::PRESET_CUSTOM) {
            $merged['colors'] = self::presetColors($preset);
        } else {
            $merged['colors'] = self::sanitizeColors(is_array($merged['colors'] ?? null) ? $merged['colors'] : []);
        }
        $merged['colors'] = self::ensureReadableContrast($merged['colors']);

        $merged['logo_text'] = self::localizedStr($merged['logo_text'] ?? '', 80, '');
        // Empty / legacy install logo must restore the bundled default — urlPath('') maps to /catalog.
        $logoUrl = is_string($merged['logo_url'] ?? null) ? trim($merged['logo_url']) : '';
        $merged['logo_url'] = self::isBundledDefaultLogo($logoUrl)
            ? self::DEFAULT_LOGO_URL
            : self::urlPath($logoUrl);
        $faviconUrl = is_string($merged['favicon_url'] ?? null) ? trim($merged['favicon_url']) : '';
        $faviconPath = self::mediaPath($faviconUrl);
        $merged['favicon_url'] = ($faviconPath === '' || self::isBundledDefaultFavicon($faviconPath))
            ? self::DEFAULT_FAVICON_URL
            : $faviconPath;
        $merged['announcement'] = self::localizedStr($merged['announcement'] ?? '', 200, '');
        $merged['announcement_url'] = self::urlPath($merged['announcement_url'] ?? '/new-arrivals');
        $merged['phone'] = self::str($merged['phone'] ?? '(+1) 1234-567-890', 40);
        $merged['radius'] = self::cssSize($merged['radius'] ?? '12px', '12px');
        $merged['font'] = self::str($merged['font'] ?? 'Plus Jakarta Sans', 60) ?: 'Plus Jakarta Sans';
        $merged['font_size'] = self::cssSize($merged['font_size'] ?? '16px', '16px');

        $home = is_array($merged['home'] ?? null) ? $merged['home'] : [];
        $sections = is_array($home['sections'] ?? null) ? $home['sections'] : [];
        foreach (array_keys($base['home']['sections']) as $key) {
            $sections[$key] = (bool) ($sections[$key] ?? true);
        }
        $home['sections'] = $sections;
        $home['layout'] = 'classic';
        $home['order'] = ClassicHomeModules::sanitizeOrder(
            is_array($home['order'] ?? null) ? $home['order'] : ClassicHomeModules::defaultOrder()
        );

        $density = is_array($home['density'] ?? null) ? $home['density'] : [];
        $home['density'] = [
            'products_per_row' => self::intIn($density['products_per_row'] ?? 5, 2, 6, 5),
            'categories_per_row' => self::intIn($density['categories_per_row'] ?? 6, 3, 8, 6),
            'recent_per_row' => self::intIn($density['recent_per_row'] ?? 4, 2, 6, 4),
            'products_limit' => self::intIn($density['products_limit'] ?? 12, 2, 24, 12),
            'categories_limit' => self::intIn($density['categories_limit'] ?? 20, 3, 20, 20),
            'recent_limit' => self::intIn($density['recent_limit'] ?? 4, 2, 12, 4),
        ];

        $merged['top_bar'] = self::sanitizeTopBar(
            is_array($merged['top_bar'] ?? null) ? $merged['top_bar'] : [],
            $merged
        );
        // Keep legacy announcement fields in sync with top_bar for older storefronts.
        $merged['announcement'] = is_array($merged['top_bar']['announcement'] ?? null)
            ? $merged['top_bar']['announcement']
            : self::localizedStr($merged['announcement'] ?? '', 200, '');
        $merged['announcement_url'] = self::urlPath($merged['top_bar']['announcement_url'] ?? $merged['announcement_url'] ?? '/new-arrivals');
        $merged['phone'] = self::str($merged['top_bar']['contact']['phone'] ?? $merged['phone'] ?? '', 40);

        $hero = is_array($home['hero'] ?? null) ? $home['hero'] : [];
        $home['hero'] = self::sanitizeHero($hero, $base['home']['hero'] ?? []);

        $trust = [];
        foreach (array_slice(is_array($home['trust'] ?? null) ? $home['trust'] : [], 0, 8) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $trust[] = [
                'icon' => self::str($row['icon'] ?? 'truck', 30) ?: 'truck',
                'title' => self::localizedStr($row['title'] ?? '', 60, ''),
                'text' => self::localizedStr($row['text'] ?? '', 120, ''),
            ];
        }
        $home['trust'] = $trust;

        $home['promos'] = self::sanitizePromoRows($home['promos'] ?? null, true);
        $home['promos_mid'] = self::sanitizePromoRows($home['promos_mid'] ?? null, false);
        $home['promos_bottom'] = self::sanitizePromoRows($home['promos_bottom'] ?? null, false);

        $testimonials = [];
        foreach (array_slice(is_array($home['testimonials'] ?? null) ? $home['testimonials'] : [], 0, 8) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $testimonials[] = [
                'name' => self::localizedStr($row['name'] ?? '', 60, ''),
                'quote' => self::localizedStr($row['quote'] ?? '', 400, ''),
                'rating' => max(1, min(5, (int) ($row['rating'] ?? 5))),
                'role' => self::localizedStr($row['role'] ?? 'Verified buyer', 60, 'Verified buyer'),
                'avatar_url' => self::mediaPath($row['avatar_url'] ?? ''),
            ];
        }
        $home['testimonials'] = $testimonials ?: $base['home']['testimonials'];

        $newsletter = is_array($home['newsletter'] ?? null) ? $home['newsletter'] : [];
        $home['newsletter'] = [
            'title' => self::localizedStr($newsletter['title'] ?? '', 120, ''),
            'body' => self::localizedStr($newsletter['body'] ?? '', 240, ''),
        ];

        $featured = is_array($home['featured'] ?? null) ? $home['featured'] : [];
        $home['featured'] = [
            'title' => self::localizedStr($featured['title'] ?? 'Featured Products', 80, 'Featured Products'),
            'limit' => self::intIn($featured['limit'] ?? 12, 2, 24, 12),
            'category_id' => self::nullableId($featured['category_id'] ?? null),
        ];

        $newArrivals = is_array($home['new_arrivals'] ?? null) ? $home['new_arrivals'] : [];
        $home['new_arrivals'] = [
            'title' => self::localizedStr($newArrivals['title'] ?? 'New Products', 80, 'New Products'),
            'limit' => self::intIn($newArrivals['limit'] ?? 12, 2, 24, 12),
        ];

        $bestsellers = is_array($home['bestsellers'] ?? null) ? $home['bestsellers'] : [];
        $home['bestsellers'] = [
            'title' => self::localizedStr($bestsellers['title'] ?? 'Bestseller Products', 80, 'Bestseller Products'),
            'limit' => self::intIn($bestsellers['limit'] ?? 12, 2, 24, 12),
        ];

        $shopCategories = is_array($home['shop_categories'] ?? null) ? $home['shop_categories'] : [];
        $home['shop_categories'] = [
            'title' => self::localizedStr($shopCategories['title'] ?? 'Shop by Featured Categories', 80, 'Shop by Featured Categories'),
            'limit' => self::intIn($shopCategories['limit'] ?? 20, 3, 20, 20),
            'category_ids' => self::intIdList($shopCategories['category_ids'] ?? []),
        ];

        $shelves = is_array($home['category_shelves'] ?? null) ? $home['category_shelves'] : [];
        // Title was removed from admin; keep FO section headings on each category name.
        // Allow clearing the catalog banner: empty/null from ConvertEmptyStringsToNull must not
        // fall back to the default image when the key is present in the payload.
        $home['category_shelves'] = [
            'category_ids' => self::intIdList($shelves['category_ids'] ?? []),
            'show_view_all' => (bool) ($shelves['show_view_all'] ?? true),
            'banner_image_url' => array_key_exists('banner_image_url', $shelves)
                ? self::mediaPath($shelves['banner_image_url'] ?? '')
                : self::mediaPath('/themes/classic/assets/merchant/banners/15.png'),
        ];

        $brands = is_array($home['brands'] ?? null) ? $home['brands'] : [];
        $home['brands'] = [
            'title' => self::localizedStr($brands['title'] ?? 'Popular Brands', 80, 'Popular Brands'),
            'limit' => self::intIn($brands['limit'] ?? 16, 2, 16, 16),
            'brand_ids' => self::intIdList($brands['brand_ids'] ?? []),
        ];

        $banner = is_array($home['banner'] ?? null) ? $home['banner'] : [];
        $home['banner'] = [
            'kicker' => self::localizedStr($banner['kicker'] ?? '', 40, ''),
            'title' => self::localizedStr($banner['title'] ?? '', 80, ''),
            'subtitle' => self::localizedStr($banner['subtitle'] ?? '', 80, ''),
            'cta' => self::localizedStr($banner['cta'] ?? 'Shop Now', 40, 'Shop Now'),
            'url' => self::urlPath($banner['url'] ?? '/catalog'),
            'image_url' => self::mediaPath($banner['image_url'] ?? ''),
        ];

        $content = is_array($home['content'] ?? null) ? $home['content'] : [];
        $home['content'] = [
            'title' => self::localizedStr($content['title'] ?? '', 120, ''),
            'html' => self::slideHtml($content['html'] ?? ''),
        ];

        $header = is_array($home['header'] ?? null) ? $home['header'] : [];
        $navSource = (string) ($header['nav_source'] ?? 'categories');
        if ($navSource === 'mega_menu') {
            $navSource = 'main_menu';
        }
        if (! in_array($navSource, ['categories', 'main_menu'], true)) {
            $navSource = 'categories';
        }
        $cartStyle = (string) ($header['cart_style'] ?? 'pill');
        if (! in_array($cartStyle, ['icon', 'pill'], true)) {
            $cartStyle = 'pill';
        }
        $home['header'] = [
            'category_ids' => self::intIdList($header['category_ids'] ?? []),
            'nav_source' => $navSource,
            'show_search' => (bool) ($header['show_search'] ?? true),
            'show_compare' => (bool) ($header['show_compare'] ?? true),
            'show_wishlist' => (bool) ($header['show_wishlist'] ?? true),
            'show_login' => (bool) ($header['show_login'] ?? true),
            'show_cart' => (bool) ($header['show_cart'] ?? true),
            'cart_style' => $cartStyle,
            'search_placeholder' => self::localizedStr(
                $header['search_placeholder'] ?? null,
                80,
                'Search for products, brands and more...',
            ),
        ];

        $home['footer'] = self::sanitizeFooter(is_array($home['footer'] ?? null) ? $home['footer'] : []);

        $merged['home'] = $home;

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $topBar
     * @param  array<string, mixed>  $merged
     * @return array<string, mixed>
     */
    protected static function sanitizeTopBar(array $topBar, array $merged): array
    {
        $base = self::defaults()['top_bar'];
        $announcement = self::localizedStr($topBar['announcement'] ?? $merged['announcement'] ?? ($base['announcement'] ?? ''), 200, '');
        $announcementUrl = self::urlPath($topBar['announcement_url'] ?? $merged['announcement_url'] ?? ($base['announcement_url'] ?? '/catalog'));
        $links = [];
        foreach (array_slice(is_array($topBar['links'] ?? null) ? $topBar['links'] : ($base['links'] ?? []), 0, 8) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = array_filter(
                self::localizedStr($row['label'] ?? '', 60, ''),
                static fn ($text) => is_string($text) && trim($text) !== '',
            );
            if ($label === []) {
                continue;
            }
            $links[] = [
                'label' => $label,
                'url' => self::urlPath($row['url'] ?? '/'),
                'open_in_new_tab' => (bool) ($row['open_in_new_tab'] ?? false),
            ];
        }
        $contactIn = is_array($topBar['contact'] ?? null) ? $topBar['contact'] : [];
        $phone = self::str($contactIn['phone'] ?? $merged['phone'] ?? ($base['contact']['phone'] ?? ''), 40);

        return [
            'enabled' => (bool) ($topBar['enabled'] ?? true),
            'background' => self::hex($topBar['background'] ?? ($base['background'] ?? '#3361cf'), '#3361cf'),
            'text_color' => self::hex($topBar['text_color'] ?? ($base['text_color'] ?? '#f8fafc'), '#f8fafc'),
            'show_currency' => (bool) ($topBar['show_currency'] ?? true),
            'show_language' => (bool) ($topBar['show_language'] ?? true),
            'announcement' => $announcement,
            'announcement_url' => $announcementUrl,
            'links' => $links,
            'contact' => [
                'phone' => $phone,
                'label' => self::localizedStr($contactIn['label'] ?? ($base['contact']['label'] ?? 'Contact us'), 60, 'Contact us'),
                'url' => self::urlPath($contactIn['url'] ?? ($base['contact']['url'] ?? '/contact')),
                'email' => self::str($contactIn['email'] ?? '', 120),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $hero
     * @param  array<string, mixed>  $baseHero
     * @return array<string, mixed>
     */
    protected static function sanitizeHero(array $hero, array $baseHero): array
    {
        $slides = self::sanitizeHeroSlides($hero['slides'] ?? null);
        $sides = self::sanitizeHeroSides($hero['sides'] ?? null);
        if ($slides === []) {
            $slides = self::sanitizeHeroSlides($baseHero['slides'] ?? null);
        }
        if ($sides === []) {
            $sides = self::sanitizeHeroSides($baseHero['sides'] ?? null);
        }

        $layout = (int) ($hero['layout'] ?? 1);
        if (! in_array($layout, [1, 2, 3], true)) {
            $layout = 1;
        }

        $defaultsByLayout = self::defaultHeroLayoutColumns();
        $layoutColumns = self::sanitizeHeroLayoutColumns(
            $hero['layout_columns'] ?? ($baseHero['layout_columns'] ?? null),
            $defaultsByLayout
        );

        $columns = self::resolveHeroColumnsForLayout(
            self::sanitizeHeroColumns($hero['columns'] ?? null),
            $layout,
            $layoutColumns[$layout] ?? [],
            $defaultsByLayout[$layout] ?? [],
            $slides,
            $sides
        );
        // Keep the active layout's tree in sync so switching layouts never shares images.
        $layoutColumns[$layout] = $columns;

        $slides = self::sanitizeHeroSlides($columns[0]['slides'] ?? $slides);
        if ($slides === []) {
            $slides = self::sanitizeHeroSlides($defaultsByLayout[1][0]['slides'] ?? []);
        }
        $first = $slides[0] ?? [];

        return [
            'layout' => $layout,
            'eyebrow' => self::firstPlain([$hero['eyebrow'] ?? null, $first['eyebrow'] ?? null, $baseHero['eyebrow'] ?? ''], 80),
            'title' => self::firstPlain([$hero['title'] ?? null, $first['title'] ?? null, $baseHero['title'] ?? ''], 120),
            'body' => self::firstPlain([$hero['body'] ?? null, $first['body'] ?? null, $baseHero['body'] ?? ''], 400),
            'cta_label' => self::firstPlain([$hero['cta_label'] ?? null, $first['cta_label'] ?? null, 'Shop Now'], 40),
            'cta_url' => self::urlPath($hero['cta_url'] ?? ($first['cta_url'] ?? '/catalog')),
            'cta2_label' => self::str($hero['cta2_label'] ?? '', 40),
            'cta2_url' => self::urlPath($hero['cta2_url'] ?? '/search?q=new'),
            'image_url' => self::mediaPath($hero['image_url'] ?? ($first['image_url'] ?? '')),
            'autoplay_ms' => self::intIn($hero['autoplay_ms'] ?? 5500, 0, 20000, 5500),
            'layout_columns' => $layoutColumns,
            'columns' => $columns,
            'slides' => $slides,
            'sides' => $sides,
        ];
    }

    /**
     * @param  mixed  $rows
     * @param  array<int, list<array{width_hint: string, slides: list<array<string, mixed>>}>>  $defaults
     * @return array<int, list<array{width_hint: string, slides: list<array<string, mixed>>}>>
     */
    protected static function sanitizeHeroLayoutColumns(mixed $rows, array $defaults): array
    {
        $out = $defaults;
        if (! is_array($rows)) {
            return $out;
        }
        foreach ([1, 2, 3] as $layout) {
            $raw = $rows[$layout] ?? $rows[(string) $layout] ?? null;
            $clean = self::sanitizeHeroColumns($raw);
            if ($clean !== []) {
                $out[$layout] = $clean;
            }
        }

        return $out;
    }

    /**
     * @param  mixed  $rows
     * @return list<array<string, mixed>>
     */
    protected static function sanitizeHeroSlides(mixed $rows): array
    {
        $slides = [];
        foreach (array_slice(is_array($rows) ? $rows : [], 0, 8) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $slides[] = self::heroSlide($row);
        }

        return $slides;
    }

    /**
     * @param  mixed  $rows
     * @return list<array<string, mixed>>
     */
    protected static function sanitizeHeroSides(mixed $rows): array
    {
        $sides = [];
        foreach (array_slice(is_array($rows) ? $rows : [], 0, 2) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $image = self::mediaPath($row['image_url'] ?? '');
            if (self::plainLocalized($row['title'] ?? '', 80) === '' && $image === '') {
                continue;
            }
            $sides[] = [
                'kicker' => self::slideText($row['kicker'] ?? '', 40),
                'title' => self::slideText($row['title'] ?? '', 80),
                'subtitle' => self::slideText($row['subtitle'] ?? '', 80),
                'cta_label' => self::slideText($row['cta_label'] ?? 'Shop Now', 40),
                'cta_url' => self::urlPath($row['cta_url'] ?? '/catalog'),
                'image_url' => $image,
            ];
        }

        return $sides;
    }

    /**
     * @param  mixed  $rows
     * @return list<array{width_hint: string, slides: list<array<string, mixed>>}>
     */
    protected static function sanitizeHeroColumns(mixed $rows): array
    {
        $columns = [];
        foreach (array_slice(is_array($rows) ? $rows : [], 0, 3) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $hint = (string) ($row['width_hint'] ?? 'main');
            if (! in_array($hint, ['full', 'main', 'side'], true)) {
                $hint = 'main';
            }
            $colSlides = self::sanitizeHeroSlides($row['slides'] ?? null);
            // Allow sides-shaped slides in a column (kicker/subtitle → eyebrow/body).
            if ($colSlides === [] && is_array($row['slides'] ?? null)) {
                foreach (array_slice($row['slides'], 0, 8) as $slide) {
                    if (! is_array($slide)) {
                        continue;
                    }
                    $colSlides[] = self::heroSlide($slide);
                }
            }
            if ($colSlides === []) {
                continue;
            }
            $columns[] = [
                'width_hint' => $hint,
                'slides' => $colSlides,
            ];
        }

        return $columns;
    }

    /**
     * @param  list<array<string, mixed>>  $slides
     * @param  list<array<string, mixed>>  $sides
     * @return list<array{width_hint: string, slides: list<array<string, mixed>>}>
     */
    protected static function migrateHeroColumns(int $layout, array $slides, array $sides): array
    {
        $sideAsSlides = [];
        foreach ($sides as $side) {
            $sideAsSlides[] = [
                'eyebrow' => $side['kicker'] ?? '',
                'title' => $side['title'] ?? '',
                'body' => $side['subtitle'] ?? '',
                'cta_label' => $side['cta_label'] ?? 'Shop Now',
                'cta_url' => $side['cta_url'] ?? '/catalog',
                'cta2_label' => '',
                'cta2_url' => '/search?q=new',
                'image_url' => $side['image_url'] ?? '',
            ];
        }

        if ($layout === 1) {
            return [['width_hint' => 'full', 'slides' => $slides ?: $sideAsSlides]];
        }
        if ($layout === 2) {
            $left = $slides ?: array_slice($sideAsSlides, 0, 1);
            $right = $sideAsSlides ?: array_slice($slides, 0, 1);

            return [
                ['width_hint' => 'main', 'slides' => $left],
                ['width_hint' => 'side', 'slides' => $right ?: $left],
            ];
        }

        // Layout 3 has dedicated 3×3 demo assets — never invent single-slide side columns
        // from the shared slides/sides legacy shape (that corrupts fresh installs).
        $preset = self::defaultHeroLayoutColumns()[3] ?? [];
        if ($preset !== []) {
            return $preset;
        }

        $cols = [['width_hint' => 'main', 'slides' => $slides]];
        foreach (array_slice($sideAsSlides ?: [['eyebrow' => '', 'title' => '', 'body' => '', 'cta_label' => 'Shop Now', 'cta_url' => '/catalog', 'cta2_label' => '', 'cta2_url' => '/', 'image_url' => '']], 0, 2) as $slide) {
            $cols[] = ['width_hint' => 'side', 'slides' => [$slide]];
        }

        return array_slice($cols, 0, 3);
    }

    /**
     * Pick active hero columns for a layout without clobbering per-layout presets.
     *
     * Fresh merchant installs used to set layout=3 while leaving layout-1 `columns`
     * in the overlay; blindly syncing that into layout_columns[3] wiped the 9-banner set.
     *
     * @param  list<array{width_hint: string, slides: list<array<string, mixed>>}>  $columns
     * @param  list<array{width_hint: string, slides: list<array<string, mixed>>}>  $preset
     * @param  list<array{width_hint: string, slides: list<array<string, mixed>>}>  $codeDefault
     * @param  list<array<string, mixed>>  $slides
     * @param  list<array<string, mixed>>  $sides
     * @return list<array{width_hint: string, slides: list<array<string, mixed>>}>
     */
    protected static function resolveHeroColumnsForLayout(
        array $columns,
        int $layout,
        array $preset,
        array $codeDefault,
        array $slides,
        array $sides
    ): array {
        $expected = $layout;
        $usablePreset = (count($preset) === $expected && ! self::heroColumnsAreLegacyMigrate($preset, $layout))
            ? $preset
            : [];
        $usableCode = (count($codeDefault) === $expected) ? $codeDefault : [];

        if ($columns === [] || count($columns) !== $expected || self::heroColumnsAreLegacyMigrate($columns, $layout)) {
            if ($usablePreset !== []) {
                return $usablePreset;
            }
            if ($usableCode !== []) {
                return $usableCode;
            }

            return self::migrateHeroColumns($layout, $slides, $sides);
        }

        if ($usablePreset === []) {
            return $columns;
        }

        $merged = [];
        for ($i = 0; $i < $expected; $i++) {
            $merged[] = self::preferRicherHeroColumn(
                $columns[$i] ?? null,
                $usablePreset[$i] ?? null,
                $i === 0 ? ($layout === 1 ? 'full' : 'main') : 'side'
            );
        }

        return $merged;
    }

    /**
     * Detect layout-3 trees built from legacy slides + 1-slide side columns (placeholders).
     *
     * @param  list<array{width_hint: string, slides: list<array<string, mixed>>}>  $columns
     */
    protected static function heroColumnsAreLegacyMigrate(array $columns, int $layout): bool
    {
        if ($layout !== 3) {
            return false;
        }
        if (count($columns) !== 3) {
            return true;
        }

        foreach ([1, 2] as $index) {
            $slides = is_array($columns[$index]['slides'] ?? null) ? $columns[$index]['slides'] : [];
            if (count($slides) < 2) {
                return true;
            }
            foreach ($slides as $slide) {
                if (! is_array($slide)) {
                    return true;
                }
                if (self::heroImageLooksEmpty((string) ($slide['image_url'] ?? ''))) {
                    return true;
                }
            }
        }

        $mainImage = (string) (($columns[0]['slides'][0]['image_url'] ?? ''));
        // Layout-1 demo filenames used as the layout-3 main column = installer bug.
        if ($mainImage !== '' && (str_contains($mainImage, '/layout-1/') || preg_match('#/hero/slide-[123]\.(png|jpe?g|webp)$#i', $mainImage))) {
            return true;
        }

        return false;
    }

    protected static function heroImageLooksEmpty(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return true;
        }

        return str_contains($url, 'placeholder.png');
    }

    /**
     * @param  mixed  $rows
     * @return list<array<string, mixed>>
     */
    protected static function sanitizePromoRows(mixed $rows, bool $withBg): array
    {
        $out = [];
        foreach (array_slice(is_array($rows) ? $rows : [], 0, 4) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $item = [
                'kicker' => self::localizedStr($row['kicker'] ?? '', 40, ''),
                'title' => self::localizedStr($row['title'] ?? '', 80, ''),
                'subtitle' => self::localizedStr($row['subtitle'] ?? '', 120, ''),
                'cta' => self::localizedStr($row['cta'] ?? 'Shop Now', 40, 'Shop Now'),
                'url' => self::urlPath($row['url'] ?? '/catalog'),
                'image_url' => self::mediaPath($row['image_url'] ?? ''),
            ];
            if ($withBg) {
                $item['bg'] = self::hex($row['bg'] ?? '#F3F4F6', '#F3F4F6');
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $footer
     * @return array{copyright: string, columns: list<array{title: string, type: string, links: list<array{label: string, url: string}>}>}
     */
    protected static function sanitizeFooter(array $footer): array
    {
        $columns = [];
        foreach (array_slice(is_array($footer['columns'] ?? null) ? $footer['columns'] : [], 0, 8) as $col) {
            if (! is_array($col)) {
                continue;
            }
            $type = (string) ($col['type'] ?? 'links');
            if (! in_array($type, ['contact', 'links', 'categories'], true)) {
                $type = 'links';
            }
            $links = [];
            foreach (array_slice(is_array($col['links'] ?? null) ? $col['links'] : [], 0, 20) as $link) {
                if (! is_array($link)) {
                    continue;
                }
                $label = array_filter(
                    self::localizedStr($link['label'] ?? '', 80, ''),
                    static fn ($text) => is_string($text) && trim($text) !== '',
                );
                if ($label === []) {
                    continue;
                }
                $links[] = [
                    'label' => $label,
                    'url' => self::href($link['url'] ?? '/'),
                ];
            }
            $columns[] = [
                'title' => self::localizedStr($col['title'] ?? '', 60, 'Links'),
                'type' => $type,
                'links' => $links,
            ];
        }

        return [
            'copyright' => self::localizedStr($footer['copyright'] ?? '', 160, ''),
            'columns' => $columns,
        ];
    }

    /**
     * Drop the sitemap link and fill an empty categories column with the first root categories.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function presentFooter(array $settings): array
    {
        $columns = $settings['home']['footer']['columns'] ?? null;
        if (! is_array($columns)) {
            return $settings;
        }

        $defaults = null;
        foreach ($columns as $index => $column) {
            if (! is_array($column)) {
                continue;
            }
            $links = [];
            foreach (is_array($column['links'] ?? null) ? $column['links'] : [] as $link) {
                if (! is_array($link) || self::isFooterSitemapLink($link)) {
                    continue;
                }
                $links[] = $link;
            }
            if (($column['type'] ?? '') === 'categories' && $links === []) {
                $defaults ??= self::defaultFooterCategoryLinks();
                $links = $defaults;
            }
            $columns[$index]['links'] = $links;
        }

        $settings['home']['footer']['columns'] = $columns;

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $link
     */
    public static function isFooterSitemapLink(array $link): bool
    {
        $url = strtolower(trim((string) ($link['url'] ?? '')));
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : $url;

        return rtrim($path, '/') === '/sitemap.xml' || rtrim($path, '/') === 'sitemap.xml';
    }

    /**
     * First six enabled root categories, with the names already stored per language.
     *
     * @return list<array{label: array<string, string>, url: string}>
     */
    public static function defaultFooterCategoryLinks(int $limit = 6): array
    {
        try {
            $categories = Category::query()
                ->where('is_enabled', true)
                ->whereNull('parent_id')
                ->with('translations')
                ->orderBy('position')
                ->orderBy('id')
                ->limit($limit)
                ->get();
        } catch (\Throwable) {
            return [];
        }

        if ($categories->isEmpty()) {
            return [];
        }

        $locale = strtolower((string) app()->getLocale());
        $paths = [];
        $rewrites = UrlRewrite::query()
            ->where('entity_type', 'category')
            ->whereIn('entity_id', $categories->pluck('id'))
            ->get(['entity_id', 'language_code', 'request_path']);
        foreach ($rewrites as $rewrite) {
            $id = (int) $rewrite->entity_id;
            $code = strtolower((string) $rewrite->language_code);
            if (! isset($paths[$id]) || $code === $locale || ($code === 'en' && ($paths[$id]['code'] ?? '') !== $locale)) {
                $paths[$id] = ['code' => $code, 'path' => (string) $rewrite->request_path];
            }
        }

        $links = [];
        foreach ($categories as $category) {
            $names = [];
            foreach ($category->translations as $row) {
                $code = strtolower(trim((string) $row->language_code));
                $name = trim((string) $row->name);
                if ($code !== '' && $name !== '') {
                    $names[$code] = $name;
                }
            }
            if ($names === []) {
                continue;
            }
            $slug = (string) ($category->translations->firstWhere('language_code', 'en')?->slug
                ?: $category->translations->first()?->slug
                ?: $category->id);
            $path = $paths[(int) $category->id]['path'] ?? $slug;
            $links[] = [
                'label' => $names,
                'url' => '/'.ltrim((string) $path, '/'),
            ];
        }

        return $links;
    }

    /**
     * @param  mixed  $value
     * @return list<int>
     */
    protected static function intIdList(mixed $value, int $max = 32): array
    {
        if (! is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $id) {
            $n = (int) $id;
            if ($n > 0 && ! in_array($n, $ids, true)) {
                $ids[] = $n;
            }
            if (count($ids) >= $max) {
                break;
            }
        }

        return $ids;
    }

    protected static function nullableId(mixed $value): ?int
    {
        $n = (int) $value;

        return $n > 0 ? $n : null;
    }

    protected static function html(mixed $value, int $max): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return '';
        }

        return mb_substr(\App\Domain\Security\HtmlSanitizer::clean($value), 0, $max);
    }

    protected static function href(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return '/';
        }

        return self::urlPath($value);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overlay
     * @return array<string, mixed>
     */
    public static function merge(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && ! array_is_list($value)) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $colors
     * @return array<string, string>
     */
    protected static function sanitizeColors(array $colors): array
    {
        $defaults = self::presetColors(self::PRESET_AZURE);
        $out = [];
        foreach ($defaults as $key => $fallback) {
            $out[$key] = self::hex($colors[$key] ?? $fallback, $fallback);
        }

        return $out;
    }

    /**
     * Prevent unreadable combos (e.g. dark surface + dark ink = black screen).
     *
     * @param  array<string, string>  $colors
     * @return array<string, string>
     */
    public static function ensureReadableContrast(array $colors): array
    {
        $surface = $colors['surface'] ?? '#F7F8FA';
        $ink = $colors['ink'] ?? '#111111';
        if (self::isDarkHex($surface) && self::isDarkHex($ink)) {
            $colors['ink'] = '#F4F4F5';
            $colors['muted'] = '#A1A1AA';
            if (self::isDarkHex($colors['card'] ?? '#12141C') === false) {
                $colors['card'] = '#12141C';
            }
        }
        if (! self::isDarkHex($surface) && ! self::isDarkHex($ink) && self::relativeLuminance($ink) > 0.65) {
            $colors['ink'] = '#111111';
            $colors['muted'] = '#6B7280';
        }

        return $colors;
    }

    public static function appearanceFor(array $colors): string
    {
        return self::isDarkHex($colors['surface'] ?? '#F7F8FA') ? 'dark' : 'light';
    }

    protected static function isDarkHex(string $hex): bool
    {
        return self::relativeLuminance($hex) < 0.42;
    }

    protected static function relativeLuminance(string $hex): float
    {
        $hex = ltrim(strtoupper(self::hex($hex, '#111111')), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $channel = static function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel(substr($hex, 0, 2))
            + 0.7152 * $channel(substr($hex, 2, 2))
            + 0.0722 * $channel(substr($hex, 4, 2));
    }

    protected static function intIn(mixed $value, int $min, int $max, int $fallback): int
    {
        $n = is_numeric($value) ? (int) $value : $fallback;

        return max($min, min($max, $n));
    }

    protected static function hex(mixed $value, string $fallback): string
    {
        $value = is_string($value) ? trim($value) : '';
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value)) {
            return strtoupper(strlen($value) === 4
                ? sprintf('#%s%s%s%s%s%s', $value[1], $value[1], $value[2], $value[2], $value[3], $value[3])
                : $value);
        }

        return strtoupper($fallback);
    }

    protected static function str(mixed $value, int $max): string
    {
        $value = is_string($value) ? trim(strip_tags($value)) : '';

        return mb_substr($value, 0, $max);
    }

    /**
     * Slide copy may be a legacy string or a locale map. Empty values stay as an empty default-language entry.
     *
     * @return array<string, string>
     */
    protected static function slideText(mixed $value, int $max): array
    {
        return self::localizedStr($value, $max, '');
    }

    /**
     * Keep stored rich text when an older form saves a slide without a content key.
     * A present content value, including an empty one, is left for the editor to replace.
     *
     * @param  array<string, mixed>  $incoming
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    public static function preserveOmittedSlideContent(array $incoming, array $stored): array
    {
        if (isset($incoming['slides']) && is_array($incoming['slides']) && is_array($stored['slides'] ?? null)) {
            $incoming['slides'] = self::preserveSlideContentList($incoming['slides'], $stored['slides']);
        }
        if (isset($incoming['columns']) && is_array($incoming['columns']) && is_array($stored['columns'] ?? null)) {
            foreach ($incoming['columns'] as $index => $column) {
                if (! is_array($column) || ! isset($column['slides']) || ! is_array($column['slides'])) {
                    continue;
                }
                $storedSlides = $stored['columns'][$index]['slides'] ?? null;
                if (! is_array($storedSlides)) {
                    continue;
                }
                $incoming['columns'][$index]['slides'] = self::preserveSlideContentList($column['slides'], $storedSlides);
            }
        }
        if (isset($incoming['layout_columns']) && is_array($incoming['layout_columns']) && is_array($stored['layout_columns'] ?? null)) {
            foreach ($incoming['layout_columns'] as $layoutKey => $columns) {
                if (! is_array($columns)) {
                    continue;
                }
                $storedColumns = $stored['layout_columns'][$layoutKey] ?? null;
                if (! is_array($storedColumns)) {
                    continue;
                }
                foreach ($columns as $index => $column) {
                    if (! is_array($column) || ! isset($column['slides']) || ! is_array($column['slides'])) {
                        continue;
                    }
                    $storedSlides = $storedColumns[$index]['slides'] ?? null;
                    if (! is_array($storedSlides)) {
                        continue;
                    }
                    $incoming['layout_columns'][$layoutKey][$index]['slides'] = self::preserveSlideContentList($column['slides'], $storedSlides);
                }
            }
        }

        return $incoming;
    }

    /**
     * @param  list<mixed>  $incoming
     * @param  list<mixed>  $stored
     * @return list<mixed>
     */
    protected static function preserveSlideContentList(array $incoming, array $stored): array
    {
        foreach ($incoming as $index => $slide) {
            if (! is_array($slide) || array_key_exists('content', $slide)) {
                continue;
            }
            if (isset($stored[$index]) && is_array($stored[$index]) && array_key_exists('content', $stored[$index])) {
                $incoming[$index]['content'] = $stored[$index]['content'];
            }
        }

        return $incoming;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected static function heroSlide(array $row): array
    {
        return [
            'eyebrow' => self::slideText($row['eyebrow'] ?? ($row['kicker'] ?? ''), 80),
            'title' => self::slideText($row['title'] ?? '', 120),
            'body' => self::slideText($row['body'] ?? ($row['subtitle'] ?? ''), 400),
            'cta_label' => self::slideText($row['cta_label'] ?? '', 40),
            'cta_url' => self::urlPath($row['cta_url'] ?? '/catalog'),
            'cta2_label' => self::str($row['cta2_label'] ?? '', 40),
            'cta2_url' => self::urlPath($row['cta2_url'] ?? '/search?q=new'),
            'image_url' => self::mediaPath($row['image_url'] ?? ''),
            'content' => self::slideHtml($row['content'] ?? ''),
        ];
    }

    /**
     * Re-sanitize saved slide HTML before it is sent to the storefront.
     *
     * @param  array<string, mixed>  $hero
     * @return array<string, mixed>
     */
    public static function sanitizeHeroForDisplay(array $hero): array
    {
        $cleanSlide = function (mixed $slide): mixed {
            if (! is_array($slide) || ! array_key_exists('content', $slide)) {
                return $slide;
            }
            $slide['content'] = self::slideHtml($slide['content']);

            return $slide;
        };
        if (isset($hero['slides']) && is_array($hero['slides'])) {
            foreach ($hero['slides'] as $index => $slide) {
                $hero['slides'][$index] = $cleanSlide($slide);
            }
        }
        if (isset($hero['layout_columns']) && is_array($hero['layout_columns'])) {
            foreach ($hero['layout_columns'] as $layoutKey => $columns) {
                if (! is_array($columns)) {
                    continue;
                }
                foreach ($columns as $index => $column) {
                    if (! is_array($column) || ! isset($column['slides']) || ! is_array($column['slides'])) {
                        continue;
                    }
                    foreach ($column['slides'] as $slideIndex => $slide) {
                        $hero['layout_columns'][$layoutKey][$index]['slides'][$slideIndex] = $cleanSlide($slide);
                    }
                }
            }
        }
        // Active columns = per-layout tree. Prefer the richer column when overlay/defaults disagree
        // (e.g. left still has uploads while layout_columns only has placeholders).
        $layout = (int) ($hero['layout'] ?? 1);
        if (! in_array($layout, [1, 2, 3], true)) {
            $layout = 1;
        }
        $defaultsByLayout = self::defaultHeroLayoutColumns();
        $layoutColumns = self::sanitizeHeroLayoutColumns($hero['layout_columns'] ?? null, $defaultsByLayout);
        $hero['columns'] = self::resolveHeroColumnsForLayout(
            self::sanitizeHeroColumns($hero['columns'] ?? null),
            $layout,
            $layoutColumns[$layout] ?? [],
            $defaultsByLayout[$layout] ?? [],
            self::sanitizeHeroSlides($hero['slides'] ?? null),
            self::sanitizeHeroSides($hero['sides'] ?? null)
        );
        $layoutColumns[$layout] = $hero['columns'];
        $hero['layout_columns'] = $layoutColumns;
        if (isset($hero['columns']) && is_array($hero['columns'])) {
            foreach ($hero['columns'] as $index => $column) {
                if (! is_array($column) || ! isset($column['slides']) || ! is_array($column['slides'])) {
                    continue;
                }
                foreach ($column['slides'] as $slideIndex => $slide) {
                    $hero['columns'][$index]['slides'][$slideIndex] = $cleanSlide($slide);
                }
            }
            $hero['slides'] = self::sanitizeHeroSlides($hero['columns'][0]['slides'] ?? ($hero['slides'] ?? null));
        }

        return $hero;
    }

    /**
     * @param  array<string, mixed>|null  $a
     * @param  array<string, mixed>|null  $b
     * @return array{width_hint: string, slides: list<array<string, mixed>>}
     */
    protected static function preferRicherHeroColumn(?array $a, ?array $b, string $fallbackHint): array
    {
        $score = static function (?array $col): int {
            if (! is_array($col)) {
                return -1;
            }
            $slides = is_array($col['slides'] ?? null) ? $col['slides'] : [];
            $withImage = 0;
            foreach ($slides as $slide) {
                if (! is_array($slide)) {
                    continue;
                }
                $url = trim((string) ($slide['image_url'] ?? ''));
                if ($url !== '' && ! str_contains($url, 'placeholder.png')) {
                    $withImage++;
                }
            }

            // Prefer more real images, then more slides (so a 3-slide placeholder beats 1 empty slide).
            return ($withImage * 100) + count($slides);
        };
        $winner = $score($a) >= $score($b) ? $a : $b;
        if (! is_array($winner)) {
            $winner = is_array($a) ? $a : (is_array($b) ? $b : ['width_hint' => $fallbackHint, 'slides' => []]);
        }
        $hint = (string) ($winner['width_hint'] ?? $fallbackHint);
        if (! in_array($hint, ['full', 'main', 'side'], true)) {
            $hint = $fallbackHint;
        }

        return [
            'width_hint' => $hint,
            'slides' => is_array($winner['slides'] ?? null) ? array_values($winner['slides']) : [],
        ];
    }

    /**
     * Locale map of sanitized rich text. Scripts and unsafe URLs are removed.
     *
     * @return array<string, string>
     */
    protected static function slideHtml(mixed $value): array
    {
        $defaultCode = 'en';
        try {
            $defaultCode = strtolower((string) \App\Domain\Localization\Models\Language::defaultCode()) ?: 'en';
        } catch (\Throwable) {
            // Keep en when languages are unavailable.
        }

        $map = [];
        $keep = function (string $code, mixed $html) use (&$map): void {
            $code = strtolower(trim($code));
            if ($code === '' || strlen($code) > 12 || ! is_scalar($html)) {
                return;
            }
            $clean = HtmlSanitizer::clean(mb_substr((string) $html, 0, 20000));
            $text = trim(html_entity_decode(strip_tags(str_replace('&nbsp;', ' ', $clean))));
            if ($text === '' && ! preg_match('/<a\b/i', $clean)) {
                return;
            }
            if ($clean !== '') {
                $map[$code] = $clean;
            }
        };

        if (is_string($value) || is_numeric($value)) {
            $keep($defaultCode, $value);
        } elseif (is_array($value)) {
            foreach ($value as $code => $html) {
                $keep((string) $code, $html);
            }
        }

        return $map;
    }

    /** Accept rich text or a {locale: html} map, within the given raw length. */
    public static function localizedHtmlRule(int $max): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($max): void {
            if ($value === null || $value === '' || $value === []) {
                return;
            }
            $tooLong = function (mixed $text) use ($max, $attribute, $fail): bool {
                if (! is_scalar($text) || mb_strlen((string) $text) > $max) {
                    $fail("The {$attribute} may not be greater than {$max} characters.");

                    return true;
                }

                return false;
            };
            if (is_string($value) || is_numeric($value)) {
                $tooLong($value);

                return;
            }
            if (is_array($value) && array_is_list($value)) {
                foreach ($value as $text) {
                    if ($text !== null && $text !== '') {
                        $fail("The {$attribute} must be text or a language map.");

                        return;
                    }
                }

                return;
            }
            if (! is_array($value)) {
                $fail("The {$attribute} must be text or a language map.");

                return;
            }
            foreach ($value as $code => $text) {
                $code = strtolower(trim((string) $code));
                if ($code === '' || strlen($code) > 12) {
                    $fail("The {$attribute} translations must be text of at most {$max} characters.");

                    return;
                }
                if ($text === null || $text === '') {
                    continue;
                }
                if ($tooLong($text)) {
                    return;
                }
            }
        };
    }

    /**
     * First non-empty plain string from legacy strings or locale maps.
     *
     * @param  list<mixed>  $candidates
     */
    protected static function firstPlain(array $candidates, int $max): string
    {
        foreach ($candidates as $candidate) {
            $text = self::plainLocalized($candidate, $max);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    protected static function plainLocalized(mixed $value, int $max): string
    {
        if (is_string($value) || is_numeric($value)) {
            return self::str((string) $value, $max);
        }
        if (! is_array($value)) {
            return '';
        }

        $preferred = [];
        try {
            $code = strtolower((string) \App\Domain\Localization\Models\Language::defaultCode());
            if ($code !== '') {
                $preferred[] = $code;
            }
        } catch (\Throwable) {
            // Languages are unavailable during install.
        }
        $preferred[] = 'en';

        foreach (array_unique($preferred) as $code) {
            if (isset($value[$code]) && is_scalar($value[$code])) {
                $text = self::str((string) $value[$code], $max);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        foreach ($value as $text) {
            if (! is_scalar($text)) {
                continue;
            }
            $clean = self::str((string) $text, $max);
            if ($clean !== '') {
                return $clean;
            }
        }

        return '';
    }

    /** Accept a plain string or a {locale: text} map, within the given length. */
    public static function localizedTextRule(int $max): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($max): void {
            if ($value === null || $value === '') {
                return;
            }
            if (is_string($value) || is_numeric($value)) {
                if (mb_strlen(trim(strip_tags((string) $value))) > $max) {
                    $fail("The {$attribute} may not be greater than {$max} characters.");
                }

                return;
            }
            if (! is_array($value) || array_is_list($value)) {
                $fail("The {$attribute} must be text or a language map.");

                return;
            }
            foreach ($value as $code => $text) {
                $code = strtolower(trim((string) $code));
                if ($code === '' || strlen($code) > 12) {
                    $fail("The {$attribute} translations must be text of at most {$max} characters.");

                    return;
                }
                if ($text === null || $text === '') {
                    continue;
                }
                if (! is_scalar($text) || mb_strlen(trim(strip_tags((string) $text))) > $max) {
                    $fail("The {$attribute} translations must be text of at most {$max} characters.");

                    return;
                }
            }
        };
    }

    /**
     * Locale map for theme text fields. Accepts a legacy plain string or {en: "…", fr: "…"}.
     *
     * @return array<string, string>
     */
    protected static function localizedStr(mixed $value, int $max, string $fallback): array
    {
        $defaultCode = 'en';
        try {
            $defaultCode = strtolower((string) \App\Domain\Localization\Models\Language::defaultCode()) ?: 'en';
        } catch (\Throwable) {
            // Keep en when languages are unavailable (install / early boot).
        }

        $map = [];
        if (is_string($value) || is_numeric($value)) {
            $text = self::str((string) $value, $max);
            if ($text !== '') {
                $map[$defaultCode] = $text;
            }
        } elseif (is_array($value)) {
            foreach ($value as $code => $text) {
                $code = strtolower(trim((string) $code));
                if ($code === '' || ! is_scalar($text)) {
                    continue;
                }
                $clean = self::str((string) $text, $max);
                if ($clean !== '') {
                    $map[$code] = $clean;
                }
            }
        }

        if ($map === []) {
            $map[$defaultCode] = self::str($fallback, $max) ?: $fallback;
        }

        return $map;
    }

    /** Saved upload, or the bundled W mark when the merchant has not set one. */
    public static function resolvedFaviconUrl(mixed $stored): string
    {
        $url = is_string($stored) ? trim($stored) : '';

        return ($url !== '' && ! self::isBundledDefaultFavicon($url)) ? $url : self::DEFAULT_FAVICON_URL;
    }

    /** True when the URL is empty or still points at a shipped Classic favicon. */
    public static function isBundledDefaultFavicon(?string $url): bool
    {
        $url = is_string($url) ? trim($url) : '';

        return $url === ''
            || $url === self::DEFAULT_FAVICON_URL
            || $url === self::LEGACY_DEFAULT_FAVICON_URL;
    }

    /** True when the URL is empty or still points at a Classic install default logo. */
    public static function isBundledDefaultLogo(?string $url): bool
    {
        $url = is_string($url) ? trim($url) : '';

        return $url === ''
            || $url === self::DEFAULT_LOGO_URL
            || $url === self::LEGACY_DEFAULT_LOGO_URL;
    }

    protected static function urlPath(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return '/catalog';
        }
        if (preg_match('#^https?://#i', $value)) {
            return self::httpUrl($value) ?: '/catalog';
        }
        if (! str_starts_with($value, '/')) {
            $value = '/'.$value;
        }

        return mb_substr($value, 0, 200);
    }

    protected static function httpUrl(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return '';
        }
        if (! filter_var($value, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $value)) {
            return '';
        }

        return mb_substr($value, 0, 500);
    }

    protected static function mediaPath(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $value)) {
            return self::httpUrl($value);
        }
        if (str_starts_with($value, '/')) {
            return mb_substr($value, 0, 500);
        }

        return '';
    }

    protected static function cssSize(mixed $value, string $fallback): string
    {
        $value = is_string($value) ? trim($value) : '';
        if (preg_match('/^\d+(\.\d+)?(px|rem)$/', $value)) {
            return $value;
        }

        return $fallback;
    }
}
