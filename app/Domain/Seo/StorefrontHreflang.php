<?php

namespace App\Domain\Seo;

use App\Domain\Catalog\Services\UrlRewriteService;
use App\Domain\Localization\Models\Language;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\StoreContext;

/**
 * Shared hreflang pairing for SSR and API presenters.
 */
final class StorefrontHreflang
{
    public static function localePathMode(): bool
    {
        try {
            return (string) ShopSetting::get('seo.locale_url_mode', config('wento.locale_url_mode', 'cookie')) === 'path';
        } catch (\Throwable) {
            return (string) config('wento.locale_url_mode', 'cookie') === 'path';
        }
    }

    public static function shopRoot(): string
    {
        try {
            $url = app(StoreContext::class)->store()?->primaryUrl()?->root();
        } catch (\Throwable) {
            $url = null;
        }

        return rtrim(is_string($url) && $url !== '' ? $url : (string) config('app.url'), '/');
    }

    public static function withLocalePrefix(string $path, string $locale): string
    {
        $path = trim($path, '/');
        if (! self::localePathMode()) {
            return $path;
        }
        if ($path === '') {
            return $locale;
        }
        $first = explode('/', $path)[0];
        try {
            $codes = Language::enabledList()->pluck('code')->all();
        } catch (\Throwable) {
            $codes = [$locale];
        }
        if (in_array($first, $codes, true)) {
            return $path;
        }

        return $locale.'/'.$path;
    }

    /**
     * @return list<array{lang: string, url: string}>
     */
    public static function forEntity(string $entityType, int $entityId): array
    {
        try {
            $languages = Language::enabledList();
        } catch (\Throwable) {
            return [];
        }
        if ($languages->count() < 2) {
            return [];
        }

        $root = self::shopRoot();
        $urls = app(UrlRewriteService::class);
        // One batched rewrite lookup for every locale (avoids N×languages pathFor queries).
        $urls->warmPaths($entityType, [$entityId], $languages->pluck('code')->all());
        $out = [];
        $defaultCode = Language::defaultCode();
        $defaultUrl = null;
        foreach ($languages as $language) {
            $path = $urls->pathFor($entityType, $entityId, $language->code);
            if (! $path) {
                continue;
            }
            $href = $root.'/'.self::withLocalePrefix($path, $language->code);
            $out[] = [
                'lang' => $language->code,
                'url' => $href,
            ];
            if ($language->code === $defaultCode || ($defaultUrl === null && $language->is_default)) {
                $defaultUrl = $href;
            }
        }
        if ($defaultUrl) {
            $out[] = ['lang' => 'x-default', 'url' => $defaultUrl];
        }

        return $out;
    }

    /**
     * @return list<array{lang: string, url: string}>
     */
    public static function forHome(): array
    {
        if (! self::localePathMode()) {
            return [];
        }
        try {
            $languages = Language::enabledList();
        } catch (\Throwable) {
            return [];
        }
        if ($languages->count() < 2) {
            return [];
        }
        $root = self::shopRoot();
        $out = [];
        $defaultUrl = $root.'/';
        foreach ($languages as $language) {
            $href = $root.'/'.$language->code;
            $out[] = ['lang' => $language->code, 'url' => $href];
            if ($language->is_default) {
                $defaultUrl = $href;
            }
        }
        $out[] = ['lang' => 'x-default', 'url' => $defaultUrl];

        return $out;
    }
}
