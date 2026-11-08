<?php

namespace App\Domain\Cms\Support;

use App\Domain\Catalog\Services\UrlRewriteService;
use App\Domain\Cms\CmsPageTemplates;
use App\Domain\Cms\Models\CmsCategory;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Cms\Models\CmsPageTranslation;
use App\Domain\Localization\Models\Language;
use App\Domain\Settings\AdminCsv;
use Illuminate\Support\Str;

final class CmsPageCsvSchema
{
    /** @return list<string> */
    public static function columns(): array
    {
        return [
            'title', 'slug', 'status', 'is_enabled', 'is_visible', 'template', 'category_slug',
            'short_description', 'body', 'meta_title', 'meta_description', 'meta_keywords',
        ];
    }

    /** @return list<string> */
    public static function required(): array
    {
        return ['title'];
    }

    /** @return list<array<string, mixed>> */
    public static function samples(): array
    {
        return [
            [
                'title' => 'Shipping policy',
                'slug' => 'shipping-policy',
                'status' => 'published',
                'is_enabled' => '1',
                'is_visible' => '1',
                'template' => 'legal',
                'category_slug' => '',
                'short_description' => 'How we ship orders.',
                'body' => '<p>Orders usually ship within two business days.</p>',
                'meta_title' => 'Shipping policy',
                'meta_description' => 'Read our shipping policy.',
                'meta_keywords' => 'shipping,delivery',
            ],
            [
                'title' => 'About the shop',
                'slug' => 'about-the-shop',
                'status' => 'draft',
                'is_enabled' => '1',
                'is_visible' => '1',
                'template' => 'about',
                'category_slug' => '',
                'short_description' => 'Our story.',
                'body' => '<p>Edit this page after import.</p>',
                'meta_title' => 'About the shop',
                'meta_description' => 'About our store.',
                'meta_keywords' => 'about',
            ],
        ];
    }

    public static function sampleCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        return AdminCsv::stringify(self::columns(), self::samples(), $delimiter);
    }

    public static function exportCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        $locale = Language::defaultCode();
        $rows = CmsPage::query()
            ->with(['translations', 'category'])
            ->orderBy('id')
            ->limit(10000)
            ->get()
            ->map(function (CmsPage $page) use ($locale) {
                $t = $page->translation($locale);

                return [
                    'title' => $t?->title,
                    'slug' => $t?->slug,
                    'status' => $page->status,
                    'is_enabled' => $page->is_enabled ? '1' : '0',
                    'is_visible' => $page->is_visible ? '1' : '0',
                    'template' => $page->template,
                    'category_slug' => $page->category?->slug,
                    'short_description' => $t?->short_description,
                    'body' => $t?->body,
                    'meta_title' => $t?->meta_title,
                    'meta_description' => $t?->meta_description,
                    'meta_keywords' => $t?->meta_keywords,
                ];
            })
            ->all();

        return AdminCsv::stringify(self::columns(), $rows, $delimiter);
    }

    public static function import(string $contents, ?string $delimiter = null): int
    {
        $parsed = AdminCsv::parse($contents, self::columns(), 'CMS page', $delimiter, self::required());
        $locale = Language::defaultCode();
        $urls = app(UrlRewriteService::class);
        $count = 0;

        foreach ($parsed['rows'] as $row) {
            $title = AdminCsv::get($row, 'title');
            if ($title === '') {
                continue;
            }
            $slug = Str::slug(AdminCsv::get($row, 'slug') ?: $title);
            $status = AdminCsv::get($row, 'status') ?: CmsPage::STATUS_DRAFT;
            if (! isset(CmsPage::STATUSES[$status])) {
                $status = CmsPage::STATUS_DRAFT;
            }
            $template = CmsPageTemplates::normalize(AdminCsv::get($row, 'template'));
            $categoryId = null;
            $categorySlug = AdminCsv::get($row, 'category_slug');
            if ($categorySlug !== '') {
                $categoryId = CmsCategory::query()->where('slug', $categorySlug)->value('id');
            }
            $translation = CmsPageTranslation::query()
                ->where('language_code', $locale)
                ->where('slug', $slug)
                ->first();
            $page = $translation?->page ?: new CmsPage;
            $page->fill([
                'status' => $status,
                'is_enabled' => AdminCsv::bool(AdminCsv::get($row, 'is_enabled'), true),
                'is_visible' => AdminCsv::bool(AdminCsv::get($row, 'is_visible'), true),
                'template' => $template,
                'cms_category_id' => $categoryId,
                'robots' => 'index,follow',
            ]);
            $page->save();
            $page->translations()->updateOrCreate(
                ['language_code' => $locale],
                [
                    'title' => $title,
                    'slug' => $slug,
                    'short_description' => AdminCsv::nullable(AdminCsv::get($row, 'short_description')),
                    'body' => AdminCsv::get($row, 'body'),
                    'meta_title' => AdminCsv::get($row, 'meta_title') ?: $title,
                    'meta_description' => AdminCsv::nullable(AdminCsv::get($row, 'meta_description')),
                    'meta_keywords' => AdminCsv::nullable(AdminCsv::get($row, 'meta_keywords')),
                ]
            );
            $urls->sync('cms', $page->id, $locale, $slug);
            $count++;
        }

        return $count;
    }
}
