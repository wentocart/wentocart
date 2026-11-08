<?php

namespace App\Domain\Cms;

use App\Domain\Catalog\Services\UrlRewriteService;
use App\Domain\Cms\Models\CmsCategory;
use App\Domain\Cms\Models\CmsPage;

/**
 * Minimal published pages so storefront chrome (footer, Help, Shipping) resolves
 * without the merchant demo pack. DemoCatalogSeeder overwrites matching slugs.
 */
final class CoreCmsStubs
{
    /**
     * @return list<array{slug: string, title: string, template: string, category: string, short: string}>
     */
    public static function pages(): array
    {
        return [
            ['slug' => 'about', 'title' => 'About us', 'template' => 'about', 'category' => 'info', 'short' => 'About this store.'],
            ['slug' => 'contact', 'title' => 'Contact us', 'template' => 'contact', 'category' => 'info', 'short' => 'How to reach this store.'],
            ['slug' => 'help', 'title' => 'Help Center', 'template' => 'blank', 'category' => 'info', 'short' => 'Help and support.'],
            ['slug' => 'faq', 'title' => 'FAQ', 'template' => 'faq', 'category' => 'info', 'short' => 'Frequently asked questions.'],
            ['slug' => 'stores', 'title' => 'Stores', 'template' => 'blank', 'category' => 'info', 'short' => 'Store locations.'],
            ['slug' => 'privacy', 'title' => 'Privacy policy', 'template' => 'legal', 'category' => 'legal', 'short' => 'How we handle customer data.'],
            ['slug' => 'terms', 'title' => 'Terms and conditions', 'template' => 'legal', 'category' => 'legal', 'short' => 'Terms of use.'],
            ['slug' => 'shipping', 'title' => 'Shipping policy', 'template' => 'legal', 'category' => 'legal', 'short' => 'Delivery information.'],
            ['slug' => 'returns', 'title' => 'Returns and refunds', 'template' => 'legal', 'category' => 'legal', 'short' => 'Return and refund policy.'],
            ['slug' => 'order-tracking', 'title' => 'Order tracking', 'template' => 'blank', 'category' => 'info', 'short' => 'Track an order.'],
            ['slug' => 'payment-security', 'title' => 'Secure payment', 'template' => 'legal', 'category' => 'legal', 'short' => 'Payment security.'],
            ['slug' => 'warranty', 'title' => 'Warranty', 'template' => 'legal', 'category' => 'legal', 'short' => 'Warranty information.'],
        ];
    }

    public static function seed(bool $overwrite = false): void
    {
        $urls = app(UrlRewriteService::class);
        $categoryIds = CmsCategory::query()->pluck('id', 'slug');
        $body = '<p>Replace this page in Admin → Pages before going live.</p>';

        foreach (self::pages() as $row) {
            $existing = CmsPage::query()
                ->whereHas('translations', fn ($t) => $t->where('slug', $row['slug'])->where('language_code', 'en'))
                ->first();
            $page = $existing ?: CmsPage::query()->create([
                'is_enabled' => true,
                'status' => 'published',
                'is_visible' => true,
                'template' => $row['template'],
                'cms_category_id' => $categoryIds[$row['category']] ?? null,
                'robots' => 'index,follow',
            ]);

            if ($existing && ! $overwrite) {
                continue;
            }

            if ($existing) {
                $page->fill([
                    'template' => $row['template'],
                    'cms_category_id' => $categoryIds[$row['category']] ?? $page->cms_category_id,
                    'is_enabled' => true,
                    'status' => 'published',
                    'is_visible' => true,
                ])->save();
            }

            $page->translations()->updateOrCreate(
                ['language_code' => 'en'],
                [
                    'title' => $row['title'],
                    'slug' => $row['slug'],
                    'short_description' => $row['short'],
                    'body' => $body,
                    'meta_title' => $row['title'],
                    'meta_description' => $row['short'],
                ]
            );
            $urls->sync('cms', $page->id, 'en', $row['slug']);
        }
    }

    public static function restore(): void
    {
        self::seed(true);
    }
}
