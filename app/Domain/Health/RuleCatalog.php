<?php

namespace App\Domain\Health;

/**
 * Canonical rule definitions for Store Health.
 *
 * @phpstan-type RuleDef array{
 *   code: string,
 *   name: string,
 *   category: string,
 *   severity: string,
 *   weight: int,
 *   description: string,
 *   impact: string,
 *   recommendation: string,
 *   fix: list<string>,
 *   resource_types: list<string>
 * }
 */
final class RuleCatalog
{
    public const CATEGORIES = ['seo', 'content', 'images', 'performance', 'technical', 'cleanup'];

    public const WEIGHTS = [
        'seo' => 25,
        'content' => 15,
        'images' => 15,
        'performance' => 20,
        'technical' => 20,
        'cleanup' => 5,
    ];

    /**
     * @return list<RuleDef>
     */
    public static function all(): array
    {
        return array_values(self::map());
    }

    /**
     * @return array<string, RuleDef>
     */
    public static function map(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        $defs = [
            // SEO (15)
            self::r('seo.missing_title', 'Missing meta titles', 'seo', 'warning', 8, 'Enabled products have no meta title.', 'Search engines invent titles; click-through suffers.', 'Fill unique titles (~50–60 chars).', ['AI_FIX', 'BULK_FIX', 'AI_BULK_FIX'], ['PRODUCT']),
            self::r('seo.title_too_long', 'Meta titles too long', 'seo', 'warning', 4, 'Meta titles exceed 60 characters.', 'Titles get truncated in SERPs.', 'Trim titles to ~60 characters.', ['AUTO_FIX', 'BULK_FIX'], ['PRODUCT']),
            self::r('seo.title_too_short', 'Meta titles too short', 'seo', 'opportunity', 3, 'Meta titles are under 30 characters.', 'Short titles waste SERP space.', 'Expand titles from the product name.', ['AUTO_FIX', 'BULK_FIX', 'AI_FIX'], ['PRODUCT']),
            self::r('seo.duplicate_title', 'Duplicate meta titles', 'seo', 'warning', 7, 'Multiple products share the same meta title.', 'Duplicate titles dilute rankings.', 'Make titles unique (append SKU or attributes).', ['AUTO_FIX', 'AI_FIX', 'BULK_FIX'], ['PRODUCT']),
            self::r('seo.missing_description', 'Missing meta descriptions', 'seo', 'warning', 8, 'Enabled products have no meta description.', 'Engines rewrite snippets poorly.', 'Add unique ~120–160 character descriptions.', ['AI_FIX', 'BULK_FIX', 'AI_BULK_FIX'], ['PRODUCT']),
            self::r('seo.description_too_long', 'Meta descriptions too long', 'seo', 'opportunity', 3, 'Meta descriptions exceed 160 characters.', 'Snippets may be cut off.', 'Trim descriptions to ~160 characters.', ['AUTO_FIX', 'BULK_FIX'], ['PRODUCT']),
            self::r('seo.description_too_short', 'Meta descriptions too short', 'seo', 'opportunity', 3, 'Meta descriptions are under 70 characters.', 'Thin snippets get rewritten.', 'Expand descriptions.', ['AI_FIX', 'BULK_FIX'], ['PRODUCT']),
            self::r('seo.duplicate_description', 'Duplicate meta descriptions', 'seo', 'warning', 5, 'Multiple products share the same meta description.', 'Looks like duplicate content in SERPs.', 'Write unique descriptions.', ['AI_FIX', 'BULK_FIX'], ['PRODUCT']),
            self::r('seo.missing_h1', 'Missing product names (H1)', 'seo', 'critical', 10, 'Enabled products have an empty name.', 'Pages have no primary heading.', 'Set a product name on each product.', ['MANUAL'], ['PRODUCT']),
            self::r('seo.missing_canonical', 'Missing canonical URLs', 'seo', 'warning', 6, 'Enabled products have no canonical URL.', 'Duplicate URL dilution risk.', 'Write the public rewrite path as canonical.', ['AUTO_FIX', 'BULK_FIX'], ['PRODUCT']),
            self::r('seo.live_noindex', 'Enabled products are noindex', 'seo', 'critical', 10, 'Live products are marked noindex.', 'Products will not appear in Google.', 'Set robots to index,follow on live products.', ['MANUAL', 'BULK_FIX'], ['PRODUCT']),
            self::r('seo.sitemap_off', 'XML sitemap is off', 'seo', 'critical', 9, 'XML sitemap setting is disabled.', 'Crawlers may miss catalog pages.', 'Enable the XML sitemap.', ['AUTO_FIX'], ['CONFIG']),
            self::r('seo.sitemap_stale', 'Sitemap / robots mismatch', 'seo', 'warning', 5, 'robots.txt does not point at the sitemap.', 'Crawlers may not discover the map.', 'Add Sitemap: {sitemap} to robots.txt.', ['AUTO_FIX'], ['CONFIG']),
            self::r('seo.missing_product_schema', 'Product schema coverage', 'seo', 'opportunity', 4, 'Storefront emits JSON-LD; keep product data complete.', 'Rich results need complete product data.', 'Ensure name, price, and availability are set.', ['INFORMATION_ONLY'], ['STORE']),
            self::r('seo.cms_missing_title', 'CMS pages missing meta titles', 'seo', 'warning', 5, 'Enabled CMS pages have no meta title.', 'CMS pages rank poorly.', 'Copy the page title into meta title.', ['AUTO_FIX', 'BULK_FIX', 'AI_FIX'], ['CMS_PAGE']),

            // Content (8)
            self::r('content.missing_description', 'Missing product descriptions', 'content', 'warning', 7, 'Enabled products have thin or empty descriptions.', 'Shoppers and search need body copy.', 'Write short + long descriptions.', ['AI_FIX', 'BULK_FIX', 'AI_BULK_FIX'], ['PRODUCT']),
            self::r('content.thin_description', 'Thin product content', 'content', 'opportunity', 5, 'Product descriptions are very short.', 'Thin pages convert and rank poorly.', 'Expand descriptions with real product facts.', ['AI_FIX', 'BULK_FIX'], ['PRODUCT']),
            self::r('content.missing_category_desc', 'Categories missing descriptions', 'content', 'opportunity', 4, 'Categories have empty descriptions.', 'Category pages look thin.', 'Add category intro copy.', ['AI_FIX', 'BULK_FIX'], ['CATEGORY']),
            self::r('content.empty_cms', 'Empty CMS pages', 'content', 'warning', 6, 'Enabled CMS pages have little or no body.', 'Empty pages hurt UX and SEO.', 'Add page content or disable the page.', ['AI_FIX', 'MANUAL'], ['CMS_PAGE']),
            self::r('content.missing_category', 'Products without a category', 'content', 'warning', 5, 'Enabled products are not in any category.', 'Crawl paths and breadcrumbs suffer.', 'Assign at least one category.', ['MANUAL'], ['PRODUCT']),
            self::r('content.missing_brand', 'Products without a brand', 'content', 'opportunity', 3, 'Enabled products have no brand.', 'Brand pages help navigation.', 'Assign a brand on the product form.', ['MANUAL'], ['PRODUCT']),
            self::r('content.missing_keywords', 'Missing meta keywords', 'content', 'opportunity', 2, 'Products have no meta keywords.', 'Useful for internal search and some engines.', 'Fill keywords from the product name.', ['AUTO_FIX', 'AI_FIX', 'BULK_FIX'], ['PRODUCT']),
            self::r('content.missing_focus', 'Missing focus keywords', 'content', 'opportunity', 3, 'Products have no focus keyword.', 'SERP preview and on-page targeting suffer.', 'Set a focus keyword.', ['AUTO_FIX', 'AI_FIX', 'BULK_FIX'], ['PRODUCT']),

            // Images (8)
            self::r('images.missing_alt', 'Images missing ALT text', 'images', 'warning', 7, 'Product images have empty alt text.', 'Accessibility and image search suffer.', 'Generate ALT from the product name.', ['AI_FIX', 'AUTO_FIX', 'BULK_FIX', 'AI_BULK_FIX'], ['IMAGE']),
            self::r('images.missing_cover', 'Products missing cover images', 'images', 'warning', 6, 'Published products have no cover image.', 'Listing cards look empty.', 'Assign the first gallery image as cover.', ['AUTO_FIX', 'BULK_FIX'], ['PRODUCT']),
            self::r('images.oversized', 'Oversized product images', 'images', 'warning', 6, 'Original product images are larger than 1 MB.', 'Page weight and LCP suffer.', 'Optimize or convert to WebP.', ['AUTO_FIX', 'BULK_FIX'], ['IMAGE']),
            self::r('images.old_format', 'Images not using WebP', 'images', 'opportunity', 4, 'WebP output is off or derivatives are missing.', 'Modern formats shrink pages.', 'Enable WebP and generate sized files.', ['AUTO_FIX', 'BULK_FIX'], ['CONFIG', 'IMAGE']),
            self::r('images.missing_derivatives', 'Missing sized/WebP derivatives', 'images', 'warning', 5, 'Product images lack cart/home/medium derivatives.', 'Storefront may serve huge originals.', 'Generate sized and WebP files.', ['AUTO_FIX', 'BULK_FIX'], ['IMAGE']),
            self::r('images.unused', 'Unused media candidates', 'images', 'opportunity', 3, 'Files in storage that may not be linked from catalog.', 'Wastes disk and backups.', 'Review usage, then delete carefully.', ['MANUAL'], ['MEDIA']),
            self::r('images.broken_ref', 'Products with broken cover paths', 'images', 'warning', 5, 'Cover path points to a missing file.', 'Broken images on the storefront.', 'Re-upload or clear the cover.', ['MANUAL'], ['PRODUCT']),
            self::r('images.lazy_hint', 'Image lazy-loading opportunity', 'images', 'opportunity', 2, 'Theme should lazy-load below-the-fold images.', 'Improves initial page weight.', 'Confirm Classic lazy-loading is enabled in theme.', ['INFORMATION_ONLY'], ['STORE']),

            // Performance (10)
            self::r('perf.js_css_build', 'Storefront JS/CSS build', 'performance', 'critical', 10, 'Vite public/build assets are missing.', 'Storefront may be unstyled or broken.', 'Run npm run build and deploy public/build.', ['MANUAL'], ['CONFIG']),
            self::r('perf.google_fonts', 'External Google Fonts', 'performance', 'opportunity', 4, 'Storefront loads fonts from Google.', 'Extra DNS + CSS delay first paint.', 'Self-host fonts in a child theme.', ['MANUAL'], ['STORE']),
            self::r('perf.webp_off', 'WebP output disabled', 'performance', 'warning', 5, 'Image WebP setting is off.', 'Heavier image payloads.', 'Enable WebP under Images settings.', ['AUTO_FIX'], ['CONFIG']),
            self::r('perf.file_cache', 'File cache driver', 'performance', 'opportunity', 3, 'Cache driver is file, not Redis/Memcached.', 'Fine for small shops; Redis helps scale.', 'Switch CACHE_DRIVER in .env when ready.', ['MANUAL'], ['CONFIG']),
            self::r('perf.debug_on', 'Debug mode is on', 'performance', 'critical', 8, 'APP_DEBUG is true.', 'Leaks errors and slows responses on live shops.', 'Set APP_DEBUG=false in .env.', ['MANUAL'], ['CONFIG']),
            self::r('perf.page_weight_images', 'Catalog image weight', 'performance', 'warning', 5, 'Many large original images inflate page weight.', 'Slows mobile LCP.', 'Optimize oversized images.', ['AUTO_FIX', 'BULK_FIX'], ['IMAGE']),
            self::r('perf.theme_css', 'Theme CSS present', 'performance', 'warning', 3, 'Classic theme CSS file is missing.', 'Header styling may be incomplete.', 'Restore themes/classic assets.', ['MANUAL'], ['STORE']),
            self::r('perf.minify_hint', 'Production asset pipeline', 'performance', 'opportunity', 2, 'Admin/storefront should use built Vite assets in production.', 'Dev servers skip minification.', 'Always ship public/build on live hosts.', ['INFORMATION_ONLY'], ['CONFIG']),
            self::r('perf.requests_hint', 'Third-party script load', 'performance', 'opportunity', 3, 'Analytics/pixels add requests when configured.', 'Extra scripts delay interactivity.', 'Load tags only with consent where required.', ['MANUAL'], ['CONFIG']),
            self::r('perf.maintenance', 'Maintenance mode', 'performance', 'warning', 4, 'Shop maintenance mode is on.', 'Customers cannot browse or buy.', 'Turn maintenance off when ready.', ['MANUAL'], ['CONFIG']),

            // Technical (10)
            self::r('tech.https', 'Shop URL HTTPS', 'technical', 'warning', 7, 'APP_URL is not https://.', 'Mixed content and ranking signals suffer.', 'Set APP_URL to https after TLS is on.', ['MANUAL'], ['CONFIG']),
            self::r('tech.favicon', 'Favicon missing', 'technical', 'opportunity', 2, 'No favicon.ico/svg in public.', 'Browsers and SERPs show a blank icon.', 'Add public/favicon.ico.', ['MANUAL'], ['STORE']),
            self::r('tech.viewport', 'Viewport meta', 'technical', 'critical', 6, 'Storefront blade must emit viewport.', 'Mobile layout breaks without it.', 'Keep viewport in storefront.blade.php.', ['INFORMATION_ONLY'], ['STORE']),
            self::r('tech.html_lang', 'HTML lang attribute', 'technical', 'opportunity', 2, 'HTML lang should be set.', 'Helps accessibility and hreflang.', 'Keep lang on the storefront document.', ['INFORMATION_ONLY'], ['STORE']),
            self::r('tech.og_image', 'Open Graph fallback image', 'technical', 'warning', 4, 'No default OG image is set.', 'Social shares look empty.', 'Set OG image or Fix from a product photo.', ['AUTO_FIX'], ['CONFIG']),
            self::r('tech.default_description', 'Default meta description', 'technical', 'opportunity', 3, 'No shop-wide fallback description.', 'Home/generic pages lack snippets.', 'Set a default meta description.', ['AUTO_FIX'], ['CONFIG']),
            self::r('tech.title_pattern', 'Title pattern', 'technical', 'critical', 6, 'SEO title pattern is empty.', 'Pages without titles have no fallback.', 'Set a title pattern like {name} | {shop}.', ['MANUAL'], ['CONFIG']),
            self::r('tech.analytics', 'Analytics ID', 'technical', 'opportunity', 2, 'No GA4 or GTM ID configured.', 'You cannot measure traffic.', 'Paste a real GA4/GTM ID on SEO settings.', ['MANUAL'], ['CONFIG']),
            self::r('tech.mailer', 'Mail configuration', 'technical', 'critical', 7, 'SMTP selected but host is empty.', 'Order emails will fail.', 'Configure SMTP under Email.', ['MANUAL'], ['CONFIG']),
            self::r('tech.payments', 'Payment methods', 'technical', 'critical', 8, 'No payment methods enabled.', 'Checkout cannot complete.', 'Enable at least one payment method.', ['MANUAL'], ['CONFIG']),

            // Cleanup (7)
            self::r('cleanup.unused_images', 'Unused product gallery orphans', 'cleanup', 'opportunity', 4, 'Gallery rows may point at removable files.', 'Frees storage after review.', 'Review unused images, then delete.', ['MANUAL'], ['IMAGE']),
            self::r('cleanup.empty_categories', 'Empty categories', 'cleanup', 'opportunity', 3, 'Categories with no products.', 'Clutters navigation.', 'Disable, merge, or delete empty categories.', ['MANUAL'], ['CATEGORY']),
            self::r('cleanup.old_drafts', 'Old disabled products', 'cleanup', 'opportunity', 3, 'Products disabled for a long time.', 'Catalog noise in admin.', 'Archive or delete after review.', ['MANUAL'], ['PRODUCT']),
            self::r('cleanup.empty_cms', 'Unused CMS pages', 'cleanup', 'opportunity', 3, 'Disabled or empty CMS pages.', 'Admin clutter.', 'Delete or finish drafting.', ['MANUAL'], ['CMS_PAGE']),
            self::r('cleanup.out_of_stock', 'Out-of-stock published products', 'cleanup', 'opportunity', 2, 'Published products with zero stock.', 'May confuse shoppers.', 'Restock or unpublish.', ['MANUAL'], ['PRODUCT']),
            self::r('cleanup.failed_mail', 'Recent failed emails', 'cleanup', 'warning', 4, 'Emails failed in the last 7 days.', 'Customers miss confirmations.', 'Check Email logs and SMTP.', ['MANUAL'], ['CONFIG']),
            self::r('cleanup.broken_apps', 'Unhealthy extensions', 'cleanup', 'critical', 6, 'Installed extensions reported errors.', 'Features may be broken.', 'Open Extensions and repair or disable.', ['MANUAL'], ['CONFIG']),
        ];

        $map = [];
        foreach ($defs as $def) {
            $map[$def['code']] = $def;
        }

        return $map;
    }

    /**
     * @param  list<string>  $fix
     * @param  list<string>  $types
     * @return RuleDef
     */
    protected static function r(
        string $code,
        string $name,
        string $category,
        string $severity,
        int $weight,
        string $description,
        string $impact,
        string $recommendation,
        array $fix,
        array $types,
    ): array {
        return compact('code', 'name', 'category', 'severity', 'weight', 'description', 'impact', 'recommendation') + [
            'fix' => $fix,
            'resource_types' => $types,
        ];
    }

    /**
     * @return RuleDef|null
     */
    public static function get(string $code): ?array
    {
        return self::map()[$code] ?? null;
    }

    public static function scoreLabel(int $score): string
    {
        return match (true) {
            $score >= 90 => 'Excellent',
            $score >= 80 => 'Good',
            $score >= 70 => 'Needs Attention',
            $score >= 50 => 'Poor',
            default => 'Critical',
        };
    }
}
