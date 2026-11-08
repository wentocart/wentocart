<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Api\WentoApiIdentity;
use App\Domain\Localization\Models\Currency;
use App\Domain\Localization\Models\Language;
use App\Domain\Store\Models\Store;
use App\Domain\Store\StoreContext;
use App\Domain\Theme\ClassicHomeModules;
use App\Domain\Theme\ThemeResolver;
use GraphQL\Error\UserError;

/**
 * Store theme / sections surface — Classic homepage module codes are the public types.
 */
final class GraphqlHomeModules
{
    /** @var list<string> */
    private const CAROUSEL_MODULES = ['hero', 'promos', 'promos_mid', 'promos_bottom', 'banner'];

    /** @var list<string> */
    private const PRODUCT_SHELF_MODULES = ['featured', 'new_arrivals', 'bestsellers'];

    /** @var list<string> */
    private const CATEGORY_MODULES = ['categories', 'category_shelves'];

    /** @var list<string> */
    private const HTML_MODULES = ['content', 'testimonials', 'brands', 'newsletter', 'html'];

    /**
     * Shop REST `sections` — published blocks only (status always "1").
     *
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    public static function sectionsConnection(array $args): array
    {
        $args['publishedOnly'] = true;

        return self::connection($args);
    }

    /**
     * Shop REST `section(id:)` — published block only.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function sectionOne(array $args): array
    {
        $row = self::one($args);
        if (($row['status'] ?? '') !== '1') {
            throw new UserError('Section not found.');
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    public static function connection(array $args): array
    {
        $all = self::allBlocks();
        if (! empty($args['publishedOnly'])) {
            $all = array_values(array_filter($all, fn (array $row) => ($row['status'] ?? '') === '1'));
        }
        $type = trim((string) ($args['type'] ?? ''));
        if ($type !== '') {
            $type = WentoApiIdentity::normalizeAppearanceType($type);
            $all = array_values(array_filter($all, fn (array $row) => ($row['type'] ?? '') === $type));
        }

        $first = max(1, min(100, (int) ($args['first'] ?? $args['limit'] ?? 50)));
        $afterId = 0;
        $after = trim((string) ($args['after'] ?? ''));
        if ($after !== '') {
            $decoded = base64_decode($after, true);
            if (is_string($decoded) && preg_match('/^theme:(\d+)$/', $decoded, $m)) {
                $afterId = (int) $m[1];
            }
        }

        $total = count($all);
        $slice = [];
        foreach ($all as $row) {
            if ($afterId > 0 && (int) $row['id'] <= $afterId) {
                continue;
            }
            $slice[] = $row;
            if (count($slice) >= $first + 1) {
                break;
            }
        }
        $hasNext = count($slice) > $first;
        $slice = array_slice($slice, 0, $first);
        $edges = array_map(function (array $row) {
            return [
                'cursor' => base64_encode('theme:'.$row['id']),
                'node' => $row,
            ];
        }, $slice);
        $end = $slice !== [] ? $slice[array_key_last($slice)] : null;

        return [
            'edges' => $edges,
            'pageInfo' => [
                'hasNextPage' => $hasNext,
                'endCursor' => $end ? base64_encode('theme:'.$end['id']) : null,
            ],
            'totalCount' => $total,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function one(array $args): array
    {
        $id = (int) ($args['id'] ?? 0);
        foreach (self::allBlocks() as $row) {
            if ((int) $row['id'] === $id || (string) $row['id'] === (string) ($args['id'] ?? '')) {
                return $row;
            }
        }
        throw new UserError('Home module not found.');
    }

    /**
     * @return array{code: string, name: string, sectionTypes: list<string>}
     */
    public static function theme(): array
    {
        $resolver = app(ThemeResolver::class);
        $manifest = $resolver->active();

        return [
            'code' => (string) $manifest->name,
            'name' => (string) ($manifest->displayName ?: $manifest->name),
            'sectionTypes' => WentoApiIdentity::APPEARANCE_MODULE_TYPES,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function store(array $args): array
    {
        $code = trim((string) ($args['code'] ?? ''));
        $id = (int) ($args['id'] ?? 0);
        $q = Store::query()->where('is_enabled', true)->with('urls');
        if ($id > 0) {
            $q->whereKey($id);
        } elseif ($code !== '') {
            $q->where('code', $code);
        } else {
            $q->orderByDesc('is_default')->orderBy('id');
        }
        $store = $q->first();
        if (! $store) {
            throw new UserError('Store not found.');
        }

        $url = $store->primaryUrl();
        $locale = Language::enabledList()->first(fn (Language $l) => (bool) $l->is_default)
            ?? Language::enabledList()->first();
        $currency = Currency::query()->where('is_enabled', true)->orderByDesc('is_default')->orderBy('code')->first();

        return [
            'id' => (string) $store->id,
            'code' => $store->code,
            'name' => $store->name,
            'is_default' => (bool) $store->is_default,
            'is_enabled' => (bool) $store->is_enabled,
            'hostname' => $url?->domain,
            'currencyCode' => $currency?->code,
            'localeCode' => $locale?->code,
            'rootCategoryId' => null,
            'theme' => (string) ($store->theme ?: 'classic'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function allBlocks(): array
    {
        $resolver = app(ThemeResolver::class);
        $settings = $resolver->settings();
        $home = is_array($settings['home'] ?? null) ? $settings['home'] : [];
        $sections = is_array($home['sections'] ?? null) ? $home['sections'] : [];
        $order = ClassicHomeModules::sanitizeOrder(
            is_array($home['order'] ?? null) ? $home['order'] : ClassicHomeModules::defaultOrder()
        );
        $store = app(StoreContext::class)->store();
        $storeId = $store ? (string) $store->id : '1';
        $themeCode = (string) ($resolver->active()->name ?? 'classic');
        $locale = app()->getLocale() ?: 'en';
        $defs = ClassicHomeModules::definitions();
        $blocks = [];
        $n = 1;
        foreach ($order as $code) {
            $enabled = ($sections[$code] ?? true) !== false;
            $moduleSettings = is_array($home[$code] ?? null) ? $home[$code] : [];
            $options = self::optionsFor($code, $code, $moduleSettings, $home);
            $blocks[] = [
                'id' => (string) $n,

                'themeCode' => $themeCode,
                'type' => $code,
                'name' => (string) ($defs[$code]['label'] ?? $code),
                'sortOrder' => $n,
                'status' => $enabled ? '1' : '0',
                'storeId' => $storeId,
                'moduleCode' => $code,
                'createdAt' => null,
                'updatedAt' => null,
                'translation' => [
                    'id' => (string) $n,

                    'homeModuleId' => (string) $n,
                    'sectionId' => $n,
                    'locale' => $locale,
                    'options' => json_encode($options, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ],
                'translations' => [[
                    'id' => (string) $n,

                    'homeModuleId' => (string) $n,
                    'sectionId' => $n,
                    'locale' => $locale,
                    'options' => json_encode($options, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]],
            ];
            $n++;
        }

        // Footer chrome as a first-class module
        $footer = is_array($settings['footer'] ?? null) ? $settings['footer'] : [];
        $blocks[] = [
            'id' => (string) $n,

            'themeCode' => $themeCode,
            'type' => 'footer',
            'name' => 'Footer',
            'sortOrder' => $n,
            'status' => '1',
            'storeId' => $storeId,
            'moduleCode' => 'footer',
            'createdAt' => null,
            'updatedAt' => null,
            'translation' => [
                'id' => (string) $n,

                'homeModuleId' => (string) $n,
                'sectionId' => $n,
                'locale' => $locale,
                'options' => json_encode(['columns' => $footer], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ],
            'translations' => [[
                'id' => (string) $n,

                'homeModuleId' => (string) $n,
                'sectionId' => $n,
                'locale' => $locale,
                'options' => json_encode(['columns' => $footer], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]],
        ];

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $module
     * @param  array<string, mixed>  $home
     * @return array<string, mixed>
     */
    public static function optionsForPublic(string $code, string $type, array $module, array $home): array
    {
        return self::optionsFor($code, $type, $module, $home);
    }

    /**
     * @param  array<string, mixed>  $module
     * @param  array<string, mixed>  $home
     * @return array<string, mixed>
     */
    protected static function optionsFor(string $code, string $type, array $module, array $home): array
    {
        $type = WentoApiIdentity::normalizeAppearanceType($type);

        return match (true) {
            in_array($type, self::CAROUSEL_MODULES, true), in_array($code, self::CAROUSEL_MODULES, true) => [
                'title' => $module['title'] ?? ($home['hero']['title'] ?? null),
                'images' => self::imagesFrom($module, $home, $code),
            ],
            in_array($type, self::PRODUCT_SHELF_MODULES, true), $type === 'shelf',
            in_array($code, self::PRODUCT_SHELF_MODULES, true) => [
                'title' => $module['title'] ?? null,
                'filters' => [
                    'limit' => $module['limit'] ?? $module['count'] ?? 8,
                    'category_id' => $module['category_id'] ?? null,
                    'sort' => match ($code) {
                        'new_arrivals' => 'newest',
                        'bestsellers' => 'bestsellers',
                        default => 'featured',
                    },
                ],
            ],
            in_array($type, self::CATEGORY_MODULES, true), in_array($code, self::CATEGORY_MODULES, true) => [
                'title' => $module['title'] ?? null,
                'filters' => [
                    'parent_id' => $module['parent_id'] ?? null,
                    'ids' => $module['category_ids'] ?? $module['ids'] ?? [],
                ],
            ],
            $type === 'trust', $code === 'trust' => [
                'services' => $module['items'] ?? $module['services'] ?? [],
            ],
            in_array($type, self::HTML_MODULES, true), in_array($code, self::HTML_MODULES, true) => [
                'html' => $module['html'] ?? $module['body'] ?? $module['content'] ?? '',
                'css' => $module['css'] ?? '',
                'title' => $module['title'] ?? null,
            ],
            $type === 'footer', $code === 'footer' => [
                'columns' => $module['columns'] ?? $module,
            ],
            default => $module,
        };
    }

    /**
     * @param  array<string, mixed>  $module
     * @param  array<string, mixed>  $home
     * @return list<array<string, mixed>>
     */
    protected static function imagesFrom(array $module, array $home, string $code): array
    {
        if ($code === 'hero') {
            $slides = $home['hero']['slides'] ?? $module['slides'] ?? [];
            if (is_array($slides) && $slides !== []) {
                return array_values(array_map(function ($slide) {
                    $s = is_array($slide) ? $slide : [];

                    return [
                        'image' => $s['image'] ?? $s['src'] ?? null,
                        'link' => $s['link'] ?? $s['url'] ?? null,
                        'title' => $s['title'] ?? $s['alt'] ?? null,
                    ];
                }, $slides));
            }
            $hero = is_array($home['hero'] ?? null) ? $home['hero'] : [];

            return [[
                'image' => $hero['image'] ?? $hero['background'] ?? null,
                'link' => $hero['cta_url'] ?? $hero['link'] ?? null,
                'title' => $hero['title'] ?? null,
            ]];
        }
        $banners = $module['banners'] ?? $module['items'] ?? $module['images'] ?? [];
        if (! is_array($banners)) {
            return [];
        }

        return array_values(array_map(function ($row) {
            $s = is_array($row) ? $row : [];

            return [
                'image' => $s['image'] ?? $s['src'] ?? null,
                'link' => $s['link'] ?? $s['url'] ?? null,
                'title' => $s['title'] ?? $s['alt'] ?? null,
            ];
        }, $banners));
    }
}
