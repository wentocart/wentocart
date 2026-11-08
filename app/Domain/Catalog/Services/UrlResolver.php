<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Catalog\Models\UrlRedirect;
use App\Domain\Catalog\Models\UrlRewrite;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Localization\Models\Language;
use App\Domain\Settings\ShopTools;

class UrlResolver
{
    /**
     * Bare entity roots that carry no slug — they must land on the listing shell.
     *
     * @var array<string, string>
     */
    protected const BARE_ROOTS = [
        'brand' => 'brands',
        'supplier' => 'brands',
    ];

    /**
     * @return array{rewrite: ?UrlRewrite, redirect_from: ?string, redirect_to: ?string}
     */
    public function resolve(string $path, ?string $language = null): array
    {
        $path = trim($path, '/');
        $language ??= app()->getLocale();

        if (isset(self::BARE_ROOTS[$path])) {
            return [
                'rewrite' => null,
                'redirect_from' => $path,
                'redirect_to' => self::BARE_ROOTS[$path],
            ];
        }

        $hit = UrlRewrite::locate($path, $language);
        if ($hit) {
            return ['rewrite' => $hit, 'redirect_from' => null, 'redirect_to' => null];
        }

        $redirect = UrlRedirect::locate($path, $language);
        if ($redirect) {
            $to = trim($redirect->to_path, '/');
            $final = UrlRewrite::locate($to, $language) ?: $this->locateBySchema($to, $language);

            return [
                'rewrite' => $final,
                'redirect_from' => $path,
                'redirect_to' => $to,
            ];
        }

        $suffix = ShopTools::urlSuffix();
        if ($suffix !== '' && ! str_ends_with($path, $suffix)) {
            $suffixed = UrlRewrite::locate($path.$suffix, $language);
            if ($suffixed) {
                return [
                    'rewrite' => $suffixed,
                    'redirect_from' => $path,
                    'redirect_to' => trim((string) $suffixed->request_path, '/'),
                ];
            }
        }

        $fromSchema = $this->locateBySchema($path, $language);
        if ($fromSchema) {
            return $this->canonicalOrRedirect($fromSchema, $path);
        }

        $fromSlug = $this->locateBySlug($path, $language);
        if (! $fromSlug) {
            return ['rewrite' => null, 'redirect_from' => null, 'redirect_to' => null];
        }

        $stored = UrlRewrite::query()
            ->where('entity_type', $fromSlug->entity_type)
            ->where('entity_id', $fromSlug->entity_id)
            ->where('language_code', $fromSlug->language_code)
            ->first();

        return $this->canonicalOrRedirect($stored ?: $fromSlug, $path);
    }

    /**
     * @return array{rewrite: UrlRewrite, redirect_from: ?string, redirect_to: ?string}
     */
    protected function canonicalOrRedirect(UrlRewrite $rewrite, string $path): array
    {
        $canonical = trim((string) $rewrite->request_path, '/');
        if ($canonical !== '' && $canonical !== $path) {
            return [
                'rewrite' => $rewrite,
                'redirect_from' => $path,
                'redirect_to' => $canonical,
            ];
        }

        return ['rewrite' => $rewrite, 'redirect_from' => null, 'redirect_to' => null];
    }

    public function locate(string $path, ?string $language = null): ?UrlRewrite
    {
        return $this->resolve($path, $language)['rewrite'];
    }

    protected function locateBySchema(string $path, string $language): ?UrlRewrite
    {
        $service = app(UrlRewriteService::class);
        foreach (ShopTools::URL_TYPES as $type) {
            $regex = $service->schemaRegex($type);
            if (! $regex || ! preg_match($regex, $path, $m) || empty($m['id'])) {
                continue;
            }
            $rewrite = UrlRewrite::query()
                ->where('entity_type', $type)
                ->where('entity_id', (int) $m['id'])
                ->where('language_code', $language)
                ->first();
            if ($rewrite) {
                return $rewrite;
            }
        }

        return null;
    }

    protected function locateBySlug(string $path, string $language): ?UrlRewrite
    {
        $suffix = ShopTools::urlSuffix();
        $bare = $suffix !== '' && str_ends_with($path, $suffix) ? substr($path, 0, -strlen($suffix)) : $path;
        $slug = basename($bare);
        if ($slug === '') {
            return null;
        }

        $locale = $language ?: Language::defaultCode();
        $hit = $this->entityBySlug($slug, $locale) ?: $this->entityBySlug($slug, null);
        if (! $hit) {
            return null;
        }

        [$type, $id] = $hit;
        $canonical = app(UrlRewriteService::class)->pathFor($type, $id, $language);
        if (is_string($canonical) && $canonical !== '') {
            return $this->synthetic($type, $id, $language, $canonical);
        }

        return $this->synthetic($type, $id, $language, $path);
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    protected function entityBySlug(string $slug, ?string $locale): ?array
    {
        $models = [
            'product' => Product::class,
            'category' => Category::class,
            'cms' => CmsPage::class,
            'brand' => Brand::class,
            'supplier' => Supplier::class,
        ];
        foreach ($models as $type => $class) {
            $row = $class::query()->whereHas('translations', function ($query) use ($slug, $locale) {
                $query->where('slug', $slug);
                if (is_string($locale) && $locale !== '') {
                    $query->where('language_code', $locale);
                }
            })->first();
            if ($row) {
                return [$type, (int) $row->id];
            }
        }

        return null;
    }

    protected function synthetic(string $type, int $id, string $language, string $path): UrlRewrite
    {
        $row = new UrlRewrite;
        $row->entity_type = $type;
        $row->entity_id = $id;
        $row->language_code = $language;
        $row->request_path = $path;

        return $row;
    }
}
