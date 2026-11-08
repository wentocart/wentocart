<?php

namespace App\Domain\Catalog;

/**
 * Offline-first HD demo media under themes/classic/assets/demo.
 *
 * Photos are Unsplash License (https://unsplash.com/license) — free for commercial use.
 * Bundled demo photos only.
 *
 * Run: php artisan wento:demo-media --download
 */
final class DemoMediaPack
{
    public const ROOT = 'themes/classic/assets/demo';

    public const PUBLIC_ROOT = '/themes/classic/assets/demo';

    public static function absoluteRoot(): string
    {
        return base_path(self::ROOT);
    }

    public static function absolute(string $relative): string
    {
        return self::absoluteRoot().'/'.ltrim(str_replace('\\', '/', $relative), '/');
    }

    public static function url(string $relative): string
    {
        return self::PUBLIC_ROOT.'/'.ltrim(str_replace('\\', '/', $relative), '/');
    }

    public static function productFile(string $sku): string
    {
        return self::absolute('products/'.strtoupper($sku).'.jpg');
    }

    public static function productAltFile(string $sku): string
    {
        return self::absolute('products/'.strtoupper($sku).'-2.jpg');
    }

    public static function categoryFile(string $slug): string
    {
        return self::absolute('categories/'.strtolower($slug).'.jpg');
    }

    public static function categoryUrl(string $slug, int $w = 1400, int $h = 700): string
    {
        $slug = strtolower(trim($slug));
        $file = self::categoryFile($slug);
        if (! is_file($file)) {
            $file = self::categoryFile('catalog');
        }
        if (! is_file($file)) {
            return CategoryBanners::remoteUrlFor($slug, $w, $h);
        }

        return self::url('categories/'.basename($file));
    }

    public static function categoryThumbUrl(string $slug): string
    {
        return self::categoryUrl($slug, 400, 400);
    }

    public static function unsplashId(string $seed): ?string
    {
        $map = self::unsplashMap();

        return $map[$seed] ?? $map[strtoupper($seed)] ?? $map[strtolower($seed)] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public static function unsplashMap(): array
    {
        return array_merge(self::productCategoryMap(), self::chromeMap());
    }

    /**
     * Product + category photography.
     *
     * @return array<string, string>
     */
    protected static function productCategoryMap(): array
    {
        return [
            'DEMO-001' => '1483985988355-763728e1935b',
            'EL-HP-01' => '1505740420928-5e560c06d30e',
            'EL-HP-02' => '1618366712010-f4ae9c647dcb',
            'EL-HP-03' => '1590658268037-6bf12165a8df',
            'EL-PH-01' => '1511707171634-5f897ff02aa9',
            'EL-PH-02' => '1601784551446-20c9e07cdbdb',
            'EL-PH-03' => '1580910051074-3eb694886505',
            'EL-PH-04' => '1592899677977-9c10ca588bbd',
            'EL-LP-01' => '1496181133206-80ce9b88a853',
            'EL-LP-02' => '1517336714731-489689fd1ca8',
            'EL-LP-03' => '1527864550417-7fd91fc51a46',
            'EL-LP-04' => '1593640408182-31c70c8268f5',
            'EL-WR-01' => '1523275335684-37898b6baf30',
            'FA-WO-01' => '1483985988355-763728e1935b',
            'FA-WO-02' => '1590874103328-eac38a683ce7',
            'FA-WO-03' => '1469334031218-e382a71b716b',
            'FA-ME-01' => '1490114538077-0a7f8cb49891',
            'FA-ME-02' => '1617137968427-85924c800a22',
            'FA-ME-03' => '1618354691373-d851c5c3a990',
            'FA-SH-01' => '1542291026-7eec264c27ff',
            'FA-SH-02' => '1606107557195-0e29a4b5b4aa',
            'FA-AC-01' => '1584917865442-de89df76afd3',
            'FA-AC-02' => '1553062407-98eeb64c6a62',
            'HM-FU-01' => '1555041469-a586c61ea9bc',
            'HM-FU-02' => '1507473885765-e6ed057f782c',
            'HM-KI-01' => '1556911220-e15b29be8c8f',
            'HM-KI-02' => '1556909114-f6e7ad7d3136',
            'HM-BE-01' => '1522771739844-6a9f6d5f14af',
            'HM-DE-01' => '1493663284031-b7e3aefcae8e',
            'BE-SK-01' => '1556228578-0d85b1a4d571',
            'BE-SK-02' => '1596755094514-f87e34085b2c',
            'BE-MK-01' => '1512496015851-a90fb38ba796',
            'BE-HA-01' => '1560066984-138dadb4c035',
            'BE-FR-01' => '1541643600914-78b084683601',
            'SP-FI-01' => '1517836357463-d25dfeac3438',
            'SP-FI-02' => '1534438327276-14e5300c3a48',
            'SP-OU-01' => '1551632811-561732d1e306',
            'SP-CY-01' => '1571068316344-75bc76f77890',
            'SP-YO-01' => '1544367567-0f2fcb009e0b',
            'GR-PA-01' => '1474979266404-7eaacbcd87c5',
            'GR-BE-01' => '1495474472287-4d71bcdd2085',
            'GR-SN-01' => '1504674900247-0877df9cc836',
            'GR-OR-01' => '1542838132-92c53300491e',
            'TY-ST-01' => '1587654780291-39c9404d746b',
            'TY-AC-01' => '1566576912321-d58ddd7a6088',
            'TY-BG-01' => '1606092195730-5d7b9af1efc5',
            'TY-BA-01' => '1515488042361-ee00e0ddd4e4',
            'AU-IN-01' => '1449965408869-eaa3f722e40d',
            'AU-CA-01' => '1492144534655-ae79c964c9d7',
            'OF-DE-01' => '1497366754035-f200968a6e72',
            'OF-CH-01' => '1586023492125-27b2c045efd7',
            'OF-ST-01' => '1455390582262-044cdead277a',
            'EL-HP-01-2' => '1511379938547-c1f69419868d',
            'EL-HP-02-2' => '1493225457124-a3eb161ffa5f',
            'FA-SH-01-2' => '1460353581641-37baddab0fa2',
            'HM-BE-01-2' => '1631049307264-da0ec9d70304',
            'HM-FU-01-2' => '1616486338812-3dadae4b4ace',
            'BE-SK-01-2' => '1571781926291-c477ebfd024b',
            'SP-YO-01-2' => '1558618666-fcd25c85cd64',
            'cat-catalog' => '1441986300917-64674bd600d8',
            'cat-electronics' => '1519389950473-47ba0277781c',
            'cat-fashion' => '1445205170230-053b83016050',
            'cat-home-living' => '1616486338812-3dadae4b4ace',
            'cat-beauty' => '1596462502278-27bfdc403348',
            'cat-sports' => '1518611012118-696072aa579a',
            'cat-grocery' => '1542838132-92c53300491e',
            'cat-toys' => '1558060370-d644479cb6f7',
            'cat-automotive' => '1503376780353-7e6692767b70',
            'cat-office' => '1497366216548-37526070297c',
            'cat-audio' => '1618366712010-f4ae9c647dcb',
            'cat-phones' => '1511707171634-5f897ff02aa9',
            'cat-laptops' => '1496181133206-80ce9b88a853',
            'cat-wearables' => '1523275335684-37898b6baf30',
            'cat-women' => '1483985988355-763728e1935b',
            'cat-men' => '1490114538077-0a7f8cb49891',
            'cat-shoes' => '1542291026-7eec264c27ff',
            'cat-accessories' => '1553062407-98eeb64c6a62',
            'cat-furniture' => '1555041469-a586c61ea9bc',
            'cat-kitchen' => '1556911220-e15b29be8c8f',
            'cat-bedding' => '1522771739844-6a9f6d5f14af',
            'cat-decor' => '1493663284031-b7e3aefcae8e',
            'cat-skincare' => '1556228578-0d85b1a4d571',
            'cat-makeup' => '1512496015851-a90fb38ba796',
            'cat-hair' => '1560066984-138dadb4c035',
            'cat-fragrance' => '1541643600914-78b084683601',
            'cat-fitness' => '1517836357463-d25dfeac3438',
            'cat-outdoor' => '1551632811-561732d1e306',
            'cat-cycling' => '1571068316344-75bc76f77890',
            'cat-yoga' => '1544367567-0f2fcb009e0b',
            'cat-pantry' => '1474979266404-7eaacbcd87c5',
            'cat-beverages' => '1495474472287-4d71bcdd2085',
            'cat-snacks' => '1504674900247-0877df9cc836',
            'cat-organic' => '1542838132-92c53300491e',
            'cat-stem' => '1587654780291-39c9404d746b',
            'cat-action-figures' => '1566576912321-d58ddd7a6088',
            'cat-board-games' => '1606092195730-5d7b9af1efc5',
            'cat-baby' => '1515488042361-ee00e0ddd4e4',
            'cat-interior' => '1449965408869-eaa3f722e40d',
            'cat-car-care' => '1492144534655-ae79c964c9d7',
            'cat-desks' => '1497366754035-f200968a6e72',
            'cat-chairs' => '1586023492125-27b2c045efd7',
            'cat-stationery' => '1455390582262-044cdead277a',
            'cat-sale' => '1607082348824-0a96f2a4b9da',
            'cat-new-arrivals' => '1483985988355-763728e1935b',
        ];
    }

    /**
     * Home chrome, testimonials, menu, PDP.
     *
     * @return array<string, string>
     */
    protected static function chromeMap(): array
    {
        return [
            // Home banners — studio product photos; composed onto solid pastel left fields
            // Solid-studio product shots; compose extends each photo's own background color left.
            'home/hero-1' => '1505740420928-5e560c06d30e', // headphones on yellow
            'home/hero-2' => '1618366712010-f4ae9c647dcb', // headphones mauve studio
            'home/hero-3' => '1590658268037-6bf12165a8df', // earbuds studio
            'home/side-1' => '1545127398-14699f92334b', // beige headphones
            'home/side-2' => '1523275335684-37898b6baf30', // watch
            'home/promo-1' => '1676315636995-a5d5df17b192', // headphones yellow studio
            'home/promo-2' => '1511379938547-c1f69419868d', // audio gear
            'home/promo-3' => '1608043152269-423dbba4e7e1', // speaker
            'home/promo-mid-1' => '1511707171634-5f897ff02aa9', // phone
            'home/promo-mid-2' => '1601784551446-20c9e07cdbdb', // phone gold
            'home/promo-mid-3' => '1516035069371-29a1b244cc32', // camera
            'home/promo-bottom-1' => '1498049794561-7780e7231661', // laptop studio
            'home/promo-bottom-2' => '1593642632823-8f785ba67e45', // laptop dark studio
            'home/promo-bottom-3' => '1558618666-fcd25c85cd64', // tech gear
            // Shelf category tiles
            'shelves/accessories' => '1553062407-98eeb64c6a62',
            'shelves/home-appliances' => '1556911220-e15b29be8c8f',
            'shelves/smart-devices' => '1523275335684-37898b6baf30',
            // Testimonials
            'testimonials/avatar-1' => '1494790108377-be9c29b29330',
            'testimonials/avatar-2' => '1507003211169-0a1dd7228f2d',
            'testimonials/avatar-3' => '1438761681033-6461ffad8d80',
            'testimonials/avatar-4' => '1472099645785-5658abf4ff4e',
            // Mega menu
            'menu/browse-promo' => '1497366754035-f200968a6e72',
            // Classic PDP headset gallery (anonymous headphones)
            'pdp/gallery/01' => '1505740420928-5e560c06d30e',
            'pdp/gallery/02' => '1618366712010-f4ae9c647dcb',
            'pdp/gallery/03' => '1511379938547-c1f69419868d',
            'pdp/gallery/04' => '1493225457124-a3eb161ffa5f',
            'pdp/gallery/05' => '1590658268037-6bf12165a8df',
            'pdp/gallery/06' => '1571781926291-c477ebfd024b',
            'pdp/cms/banner-01' => '1517336714731-489689fd1ca8',
            'pdp/cms/banner-02' => '1523275335684-37898b6baf30',
            'pdp/cms/banner-03' => '1511707171634-5f897ff02aa9',
            'pdp/cms/banner-04' => '1555041469-a586c61ea9bc',
            'pdp/related/01' => '1511707171634-5f897ff02aa9',
            'pdp/related/02' => '1523275335684-37898b6baf30',
            'pdp/related/03' => '1601784551446-20c9e07cdbdb',
            'pdp/related/04' => '1618366712010-f4ae9c647dcb',
            'pdp/related/05' => '1496181133206-80ce9b88a853',
            'pdp/related/06' => '1590658268037-6bf12165a8df',
            'pdp/related/07' => '1527864550417-7fd91fc51a46',
            'pdp/related/08' => '1516035069371-29a1b244cc32',
        ];
    }

    public static function unsplashDownloadUrl(string $id, int $w, int $h, ?string $key = null): string
    {
        $url = 'https://images.unsplash.com/photo-'.$id.'?auto=format&fit=crop&w='.$w.'&h='.$h.'&q=85';
        // Bias crop toward the right so product stays in the image half used for banners.
        if (is_string($key) && str_starts_with($key, 'home/')) {
            $url .= '&crop=focalpoint&fp-x=0.78&fp-y=0.5';
        }

        return $url;
    }

    /**
     * @return array{0: int, 1: int}
     */
    public static function dimensionsFor(string $key): array
    {
        if (str_starts_with($key, 'home/hero-')) {
            return [1920, 900];
        }
        if (str_starts_with($key, 'home/side-')) {
            return [900, 1000];
        }
        if (str_starts_with($key, 'home/promo')) {
            return [1000, 700];
        }
        if (str_starts_with($key, 'testimonials/')) {
            return [480, 480];
        }
        if (str_starts_with($key, 'menu/')) {
            return [900, 1100];
        }
        if (str_starts_with($key, 'pdp/gallery/') || str_starts_with($key, 'pdp/related/')) {
            return [1200, 1200];
        }
        if (str_starts_with($key, 'pdp/cms/')) {
            return [1400, 700];
        }
        if (str_starts_with($key, 'shelves/')) {
            return [900, 900];
        }
        if (str_starts_with($key, 'cat-')) {
            return [1600, 800];
        }
        if (str_ends_with($key, '-2')) {
            return [1000, 1000];
        }

        return [1000, 1000];
    }

    /**
     * @return list<array{key: string, path: string, url: string}>
     */
    public static function downloadJobs(): array
    {
        $jobs = [];
        foreach (self::unsplashMap() as $key => $id) {
            [$w, $h] = self::dimensionsFor($key);

            if (str_contains($key, '/')) {
                $jobs[] = [
                    'key' => $key,
                    'path' => self::absolute($key.'.jpg'),
                    'url' => self::unsplashDownloadUrl($id, $w, $h, $key),
                ];
                continue;
            }

            if (str_starts_with($key, 'cat-')) {
                $slug = substr($key, 4);
                $jobs[] = [
                    'key' => $key,
                    'path' => self::categoryFile($slug),
                    'url' => self::unsplashDownloadUrl($id, $w, $h, $key),
                ];
                continue;
            }

            if (str_ends_with($key, '-2')) {
                $sku = substr($key, 0, -2);
                $jobs[] = [
                    'key' => $key,
                    'path' => self::productAltFile($sku),
                    'url' => self::unsplashDownloadUrl($id, $w, $h, $key),
                ];
                continue;
            }

            $jobs[] = [
                'key' => $key,
                'path' => self::productFile($key),
                'url' => self::unsplashDownloadUrl($id, $w, $h, $key),
            ];
        }

        return $jobs;
    }

    /**
     * Map legacy public URLs → bundled demo pack URLs.
     *
     * @return array<string, string>
     */
    public static function legacyUrlMap(): array
    {
        $d = self::PUBLIC_ROOT;

        return [
            '/themes/classic/assets/modules/cp_imageslider/images/sample-1.webp' => $d.'/home/hero-1.jpg',
            '/themes/classic/assets/modules/cp_imageslider/images/sample-2.webp' => $d.'/home/hero-2.jpg',
            '/themes/classic/assets/modules/cp_imageslider/images/sample-3.webp' => $d.'/home/hero-3.jpg',
            '/themes/classic/assets/modules/cp_cmsbanner1/img/cms-banner1.webp' => $d.'/home/side-1.jpg',
            '/themes/classic/assets/modules/cp_cmsbanner1/img/cms-banner2.webp' => $d.'/home/side-2.jpg',
            '/themes/classic/assets/modules/cp_cmsbanner2/img/cms-banner1.webp' => $d.'/home/promo-1.jpg',
            '/themes/classic/assets/modules/cp_cmsbanner2/img/cms-banner2.webp' => $d.'/home/promo-2.jpg',
            '/themes/classic/assets/modules/cp_cmsbanner2/img/cms-banner3.webp' => $d.'/home/promo-3.jpg',
            '/themes/classic/assets/modules/cp_cmsbanner3/img/cms-banner1.webp' => $d.'/home/promo-mid-1.jpg',
            '/themes/classic/assets/modules/cp_cmsbanner3/img/cms-banner2.webp' => $d.'/home/promo-mid-2.jpg',
            '/themes/classic/assets/modules/cp_cmsbanner3/img/cms-banner3.webp' => $d.'/home/promo-mid-3.jpg',
            '/themes/classic/assets/modules/cp_cmsbanner5/img/cms-banner1.webp' => $d.'/home/promo-bottom-1.jpg',
            '/themes/classic/assets/modules/cp_cmsbanner5/img/cms-banner2.webp' => $d.'/home/promo-bottom-2.jpg',
            '/themes/classic/assets/modules/cp_cmsbanner5/img/cms-banner3.webp' => $d.'/home/promo-bottom-3.jpg',
            '/themes/classic/assets/modules/cp_testimonial/img/testimonial-1.jpg' => $d.'/testimonials/avatar-1.jpg',
            '/themes/classic/assets/modules/cp_testimonial/img/testimonial-2.jpg' => $d.'/testimonials/avatar-2.jpg',
            '/themes/classic/assets/modules/cp_testimonial/img/testimonial-3.jpg' => $d.'/testimonials/avatar-3.jpg',
            '/themes/classic/assets/modules/cp_testimonial/img/testimonial-4.jpg' => $d.'/testimonials/avatar-4.jpg',
            '/themes/classic/assets/modules/cp_footercms1/img/payment.png' => $d.'/ui/payment-strip.svg',
            '/themes/classic/assets/catalog/menu/browse-categories.jpg' => $d.'/menu/browse-promo.jpg',
            '/themes/classic/assets/img/logo.svg' => $d.'/ui/logo.svg',
            '/themes/classic/assets/catalog/pdp/brand/reebok.jpg' => $d.'/ui/brand-peakform.svg',
            '/themes/classic/assets/catalog/pdp/ui/payment_icons.png' => $d.'/ui/payment-strip.svg',
            '/themes/classic/assets/catalog/pdp/ui/payment_footer.png' => $d.'/ui/payment-strip.svg',
            '/storage/classic-banners/hero-1.webp' => $d.'/home/hero-1.jpg',
            '/storage/classic-banners/hero-2.webp' => $d.'/home/hero-2.jpg',
            '/storage/classic-banners/hero-3.webp' => $d.'/home/hero-3.jpg',
            '/storage/classic-banners/side-1.webp' => $d.'/home/side-1.jpg',
            '/storage/classic-banners/side-2.webp' => $d.'/home/side-2.jpg',
            '/storage/classic-banners/promo-1.webp' => $d.'/home/promo-1.jpg',
            '/storage/classic-banners/promo-2.webp' => $d.'/home/promo-2.jpg',
            '/storage/classic-banners/promo-3.webp' => $d.'/home/promo-3.jpg',
            '/storage/classic-banners/promo-mid-1.webp' => $d.'/home/promo-mid-1.jpg',
            '/storage/classic-banners/promo-mid-2.webp' => $d.'/home/promo-mid-2.jpg',
            '/storage/classic-banners/promo-mid-3.webp' => $d.'/home/promo-mid-3.jpg',
            '/storage/classic-banners/promo-bottom-1.webp' => $d.'/home/promo-bottom-1.jpg',
            '/storage/classic-banners/promo-bottom-2.webp' => $d.'/home/promo-bottom-2.jpg',
            '/storage/classic-banners/promo-bottom-3.webp' => $d.'/home/promo-bottom-3.jpg',
        ];
    }
}
