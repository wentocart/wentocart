<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Api\WentoApiIdentity;
use App\Domain\Store\Models\Store;
use App\Domain\Store\StoreContext;
use App\Domain\Theme\ClassicHomeModules;
use App\Domain\Theme\ThemeResolver;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Support\Str;

/**
 * Console appearance sections — Classic homepage module codes are the public type vocabulary.
 */
final class GraphqlAdminAppearanceSections
{
    /** @var list<string> */
    private const CAROUSEL_MODULES = ['hero', 'promos', 'promos_mid', 'promos_bottom', 'banner'];

    /** @var list<string> */
    private const PRODUCT_SHELF_MODULES = ['featured', 'new_arrivals', 'bestsellers'];

    /** @var list<string> */
    private const CATEGORY_MODULES = ['categories', 'category_shelves'];

    /** @var list<string> */
    private const HTML_MODULES = ['content', 'testimonials', 'brands', 'newsletter', 'html'];

    public static function assertAppearance(): Admin
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! $admin->hasPermission('settings.manage')) {
            throw new UserError('Forbidden.');
        }

        return $admin;
    }

    /**
     * Shop REST GET /api/v1/store/sections — published (enabled) sections only, no draft overlay.
     *
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    public static function shopPublished(array $args = []): array
    {
        $type = strtolower(trim((string) ($args['type'] ?? '')));
        $rows = [];
        foreach (self::blocks($args, false) as $row) {
            if ((int) ($row['status'] ?? 0) !== 1) {
                continue;
            }
            if ($type !== '' && strtolower((string) ($row['type'] ?? '')) !== $type) {
                continue;
            }
            $rows[] = [
                'id' => (int) $row['id'],
                'themeCode' => (string) ($row['themeCode'] ?? 'default'),
                'storeId' => (int) ($row['storeId'] ?? 0),
                'type' => (string) ($row['type'] ?? 'content'),
                'name' => (string) ($row['name'] ?? ''),
                'sortOrder' => (int) ($row['sortOrder'] ?? 0),
                'status' => 1,
                'createdAt' => $row['createdAt'] ?? null,
                'updatedAt' => $row['updatedAt'] ?? null,
                'translation' => [
                    'id' => (int) $row['id'],
                    'sectionId' => (int) $row['id'],
                    'locale' => (string) ($row['translation']['locale'] ?? app()->getLocale()),
                    'options' => (string) ($row['translation']['options'] ?? '{}'),
                ],
                'translations' => array_map(static function (array $t) use ($row) {
                    return [
                        'id' => (int) ($t['id'] ?? $row['id']),
                        'sectionId' => (int) $row['id'],
                        'locale' => (string) ($t['locale'] ?? app()->getLocale()),
                        'options' => (string) ($t['options'] ?? '{}'),
                    ];
                }, is_array($row['translations'] ?? null) ? $row['translations'] : []),
            ];
        }

        return $rows;
    }

    /** @return list<string> */
    public static function sectionTypes(): array
    {
        return WentoApiIdentity::APPEARANCE_MODULE_TYPES;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    public static function list(array $args): array
    {
        self::assertAppearance();
        $preview = (bool) ($args['preview'] ?? false);

        return self::blocks($args, $preview);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function one(array $args): array
    {
        self::assertAppearance();
        $id = (int) ($args['id'] ?? 0);
        foreach (self::blocks($args, false) as $row) {
            if ((int) $row['id'] === $id || (string) $row['id'] === (string) ($args['id'] ?? '')) {
                return $row;
            }
        }
        throw new UserError('Appearance section not found.');
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function create(array $args): array
    {
        self::assertAppearance();
        $type = WentoApiIdentity::normalizeAppearanceType((string) ($args['type'] ?? ''));
        if (! WentoApiIdentity::isAppearanceType($type)) {
            throw new UserError('Unsupported section type.');
        }
        if ($type === 'footer') {
            foreach (self::blocks($args, false) as $row) {
                if (($row['type'] ?? '') === 'footer') {
                    throw new UserError('A store can have only one footer section.');
                }
            }
        }
        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '') {
            throw new UserError('Name is required.');
        }

        $store = self::store($args);
        $overlay = is_array($store->theme_settings) ? $store->theme_settings : [];
        $home = is_array($overlay['home'] ?? null) ? $overlay['home'] : [];
        $extras = is_array($home['extra_sections'] ?? null) ? $home['extra_sections'] : [];
        $code = 'extra_'.Str::slug($name, '_');
        if ($code === 'extra_' || isset($extras[$code])) {
            $code = 'extra_'.Str::lower(Str::random(8));
        }
        $extras[$code] = [
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'enabled' => false,
            'options' => [],
        ];
        $home['extra_sections'] = $extras;
        $draft = is_array($overlay['appearance_draft'] ?? null) ? $overlay['appearance_draft'] : [];
        $draft['status'][$code] = 1;
        $overlay['home'] = $home;
        $overlay['appearance_draft'] = $draft;
        $store->update(['theme_settings' => $overlay]);

        $created = self::one(['id' => self::idForCode($code, $args), 'code' => (string) ($args['code'] ?? ''), 'channel' => $args['storeId'] ?? null]);
        $created['message'] = 'Section created.';
        $created['status'] = 0;

        return $created;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function update(array $args): array
    {
        self::assertAppearance();
        $row = self::one($args);
        $code = (string) $row['moduleCode'];
        $store = self::store($args);
        $overlay = is_array($store->theme_settings) ? $store->theme_settings : [];
        $home = is_array($overlay['home'] ?? null) ? $overlay['home'] : [];

        if (str_starts_with($code, 'extra_')) {
            $extras = is_array($home['extra_sections'] ?? null) ? $home['extra_sections'] : [];
            $extra = is_array($extras[$code] ?? null) ? $extras[$code] : [];
            if (isset($args['name']) && trim((string) $args['name']) !== '') {
                $extra['name'] = trim((string) $args['name']);
            }
            if (isset($args['options']) && is_array($args['options'])) {
                $extra['options'] = $args['options'];
            } elseif (isset($args['options']) && is_string($args['options'])) {
                $decoded = json_decode($args['options'], true);
                if (is_array($decoded)) {
                    $extra['options'] = $decoded;
                }
            }
            if (array_key_exists('status', $args) && $args['status'] !== null) {
                $extra['enabled'] = (int) $args['status'] === 1 || $args['status'] === true || $args['status'] === '1';
            }
            $extras[$code] = $extra;
            $home['extra_sections'] = $extras;
        } else {
            $module = is_array($home[$code] ?? null) ? $home[$code] : [];
            if (isset($args['options'])) {
                $options = is_array($args['options']) ? $args['options'] : (json_decode((string) $args['options'], true) ?: []);
                if (isset($options['title'])) {
                    $module['title'] = $options['title'];
                }
                if (isset($options['html'])) {
                    $module['html'] = $options['html'];
                }
                $home[$code] = $module;
            }
            if (array_key_exists('status', $args) && $args['status'] !== null) {
                $sections = is_array($home['sections'] ?? null) ? $home['sections'] : [];
                $sections[$code] = (int) $args['status'] === 1 || $args['status'] === true || $args['status'] === '1';
                $home['sections'] = $sections;
            }
        }
        $overlay['home'] = $home;
        $store->update(['theme_settings' => $overlay]);

        return self::one(['id' => $row['id'], 'code' => (string) ($args['code'] ?? ''), 'channel' => $args['storeId'] ?? null]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, id: string}
     */
    public static function delete(array $args): array
    {
        self::assertAppearance();
        $row = self::one($args);
        $code = (string) $row['moduleCode'];
        if ($code === 'footer') {
            throw new UserError('The footer section cannot be deleted.');
        }
        $store = self::store($args);
        $overlay = is_array($store->theme_settings) ? $store->theme_settings : [];
        $home = is_array($overlay['home'] ?? null) ? $overlay['home'] : [];
        if (str_starts_with($code, 'extra_')) {
            $extras = is_array($home['extra_sections'] ?? null) ? $home['extra_sections'] : [];
            unset($extras[$code]);
            $home['extra_sections'] = $extras;
        } else {
            $sections = is_array($home['sections'] ?? null) ? $home['sections'] : [];
            $sections[$code] = false;
            $home['sections'] = $sections;
        }
        $overlay['home'] = $home;
        $store->update(['theme_settings' => $overlay]);

        return ['ok' => true, 'id' => (string) $row['id']];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function status(array $args): array
    {
        self::assertAppearance();
        $row = self::one($args);
        $code = (string) $row['moduleCode'];
        $on = (int) ($args['status'] ?? $args['value'] ?? 0) === 1
            || ($args['status'] ?? null) === true
            || ($args['status'] ?? null) === '1';
        $store = self::store($args);
        $overlay = is_array($store->theme_settings) ? $store->theme_settings : [];
        $draft = is_array($overlay['appearance_draft'] ?? null) ? $overlay['appearance_draft'] : [];
        $draft['status'][$code] = $on ? 1 : 0;
        $overlay['appearance_draft'] = $draft;
        $store->update(['theme_settings' => $overlay]);
        $fresh = self::one(['id' => $row['id'], 'code' => (string) ($args['code'] ?? ''), 'channel' => $args['storeId'] ?? null]);
        $fresh['hasDraft'] = true;
        $fresh['message'] = 'Section status staged.';

        return $fresh;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, order: list<string>, message: string}
     */
    public static function reorder(array $args): array
    {
        self::assertAppearance();
        $ids = array_values(array_filter(array_map('intval', (array) ($args['ids'] ?? $args['order'] ?? []))));
        if ($ids === []) {
            throw new UserError('Provide section ids in draw order.');
        }
        $byId = [];
        foreach (self::blocks($args, false) as $row) {
            $byId[(int) $row['id']] = (string) $row['moduleCode'];
        }
        $order = [];
        foreach ($ids as $id) {
            if (! isset($byId[$id])) {
                continue;
            }
            $code = $byId[$id];
            if ($code === 'footer') {
                continue;
            }
            if (! in_array($code, $order, true) && ! str_starts_with($code, 'extra_')) {
                $order[] = $code;
            }
        }
        $order = ClassicHomeModules::sanitizeOrder($order);
        $store = self::store($args);
        $overlay = is_array($store->theme_settings) ? $store->theme_settings : [];
        $draft = is_array($overlay['appearance_draft'] ?? null) ? $overlay['appearance_draft'] : [];
        $draft['order'] = $order;
        $overlay['appearance_draft'] = $draft;
        $store->update(['theme_settings' => $overlay]);

        return [
            'ok' => true,
            'order' => array_merge($order, ['footer']),
            'message' => 'Section order staged.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, message: string}
     */
    public static function publish(array $args): array
    {
        self::assertAppearance();
        $store = self::store($args);
        $overlay = is_array($store->theme_settings) ? $store->theme_settings : [];
        $draft = is_array($overlay['appearance_draft'] ?? null) ? $overlay['appearance_draft'] : [];
        $home = is_array($overlay['home'] ?? null) ? $overlay['home'] : [];
        if (isset($draft['order']) && is_array($draft['order'])) {
            $home['order'] = ClassicHomeModules::sanitizeOrder($draft['order']);
        }
        if (isset($draft['status']) && is_array($draft['status'])) {
            $sections = is_array($home['sections'] ?? null) ? $home['sections'] : [];
            $extras = is_array($home['extra_sections'] ?? null) ? $home['extra_sections'] : [];
            foreach ($draft['status'] as $code => $value) {
                $code = (string) $code;
                $on = (int) $value === 1;
                if (str_starts_with($code, 'extra_') && isset($extras[$code])) {
                    $extras[$code]['enabled'] = $on;
                } elseif ($code !== 'footer') {
                    $sections[$code] = $on;
                }
            }
            $home['sections'] = $sections;
            $home['extra_sections'] = $extras;
        }
        $overlay['home'] = $home;
        unset($overlay['appearance_draft']);
        $store->update(['theme_settings' => $overlay]);

        return ['ok' => true, 'message' => 'Appearance sections published.'];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, message: string}
     */
    public static function discard(array $args): array
    {
        self::assertAppearance();
        $store = self::store($args);
        $overlay = is_array($store->theme_settings) ? $store->theme_settings : [];
        unset($overlay['appearance_draft']);
        $store->update(['theme_settings' => $overlay]);

        return ['ok' => true, 'message' => 'Staged appearance edits discarded.'];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function fields(array $args): array
    {
        $row = self::one($args);
        $type = (string) ($row['type'] ?? 'content');
        $schema = match (true) {
            in_array($type, self::CAROUSEL_MODULES, true) => [
                ['code' => 'title', 'type' => 'text', 'label' => 'Title'],
                ['code' => 'images', 'type' => 'repeater', 'label' => 'Images'],
            ],
            in_array($type, self::PRODUCT_SHELF_MODULES, true), $type === 'shelf' => [
                ['code' => 'title', 'type' => 'text', 'label' => 'Title'],
                ['code' => 'filters', 'type' => 'object', 'label' => 'Filters'],
            ],
            in_array($type, self::CATEGORY_MODULES, true) => [
                ['code' => 'title', 'type' => 'text', 'label' => 'Title'],
                ['code' => 'filters', 'type' => 'object', 'label' => 'Filters'],
            ],
            $type === 'trust' => [
                ['code' => 'services', 'type' => 'repeater', 'label' => 'Services'],
            ],
            $type === 'footer' => [
                ['code' => 'columns', 'type' => 'repeater', 'label' => 'Columns'],
            ],
            default => [
                ['code' => 'title', 'type' => 'text', 'label' => 'Title'],
                ['code' => 'html', 'type' => 'html', 'label' => 'HTML'],
            ],
        };

        return [
            'id' => $row['id'],

            'type' => $type,
            'fields' => $schema,
            'options' => $row['options'] ?? null,
            'optionsJson' => is_array($row['options'] ?? null)
                ? json_encode($row['options'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : (string) ($row['translation']['options'] ?? '{}'),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function duplicate(array $args): array
    {
        self::assertAppearance();
        $row = self::one($args);
        if (($row['type'] ?? '') === 'footer') {
            throw new UserError('The footer section cannot be duplicated.');
        }

        return self::create([
            'code' => (string) ($args['code'] ?? $row['themeCode'] ?? 'classic'),
            'channel' => $args['storeId'] ?? $row['storeId'] ?? null,
            'name' => 'Copy of '.(string) ($row['name'] ?? 'Section'),
            'type' => (string) $row['type'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function blocks(array $args, bool $applyDraft): array
    {
        $resolver = app(ThemeResolver::class);
        $store = self::store($args);
        $settings = $resolver->settings(null, $store);
        $overlay = is_array($store->theme_settings) ? $store->theme_settings : [];
        $draft = is_array($overlay['appearance_draft'] ?? null) ? $overlay['appearance_draft'] : [];
        $home = is_array($settings['home'] ?? null) ? $settings['home'] : [];
        $sections = is_array($home['sections'] ?? null) ? $home['sections'] : [];
        $order = ClassicHomeModules::sanitizeOrder(
            is_array(($applyDraft ? ($draft['order'] ?? null) : null) ?? ($home['order'] ?? null))
                ? (($applyDraft && isset($draft['order'])) ? $draft['order'] : ($home['order'] ?? ClassicHomeModules::defaultOrder()))
                : ClassicHomeModules::defaultOrder()
        );
        $themeCode = trim((string) ($args['code'] ?? '')) ?: (string) ($resolver->active($store)->name ?? 'classic');
        $storeId = (string) $store->id;
        $locale = app()->getLocale() ?: 'en';
        $defs = ClassicHomeModules::definitions();
        $blocks = [];
        $n = 1;
        foreach ($order as $code) {
            $enabled = ($sections[$code] ?? true) !== false;
            if ($applyDraft && isset($draft['status'][$code])) {
                $enabled = (int) $draft['status'][$code] === 1;
            }
            $moduleSettings = is_array($home[$code] ?? null) ? $home[$code] : [];
            $options = GraphqlHomeModules::optionsForPublic($code, $code, $moduleSettings, $home);
            $hasDraft = isset($draft['status'][$code]) || isset($draft['order']);
            $blocks[] = self::mapRow($n, $themeCode, $code, (string) ($defs[$code]['label'] ?? $code), $enabled, $storeId, $code, $locale, $options, $hasDraft);
            $n++;
        }

        $extras = is_array($home['extra_sections'] ?? null) ? $home['extra_sections'] : [];
        foreach ($extras as $code => $extra) {
            if (! is_array($extra)) {
                continue;
            }
            $enabled = ($extra['enabled'] ?? false) === true;
            if ($applyDraft && isset($draft['status'][$code])) {
                $enabled = (int) $draft['status'][$code] === 1;
            }
            $type = WentoApiIdentity::normalizeAppearanceType((string) ($extra['type'] ?? 'content'));
            $options = is_array($extra['options'] ?? null) ? $extra['options'] : [];
            $blocks[] = self::mapRow(
                $n,
                $themeCode,
                $type,
                (string) ($extra['name'] ?? $code),
                $enabled,
                $storeId,
                (string) $code,
                $locale,
                $options,
                isset($draft['status'][$code])
            );
            $n++;
        }

        $footer = is_array($settings['footer'] ?? null) ? $settings['footer'] : [];
        $blocks[] = self::mapRow($n, $themeCode, 'footer', 'Footer', true, $storeId, 'footer', $locale, ['columns' => $footer], false);

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected static function mapRow(
        int $n,
        string $themeCode,
        string $type,
        string $name,
        bool $enabled,
        string $storeId,
        string $moduleCode,
        string $locale,
        array $options,
        bool $hasDraft,
    ): array {
        return [
            'id' => (string) $n,

            'themeCode' => $themeCode,
            'type' => $type,
            'name' => $name,
            'sortOrder' => $n,
            'status' => $enabled ? 1 : 0,
            'storeId' => $storeId,
            'moduleCode' => $moduleCode,
            'hasDraft' => $hasDraft,
            'message' => null,
            'options' => $options,
            'createdAt' => null,
            'updatedAt' => null,
            'translation' => [
                'id' => (string) $n,

                'locale' => $locale,
                'options' => json_encode($options, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ],
            'translations' => [[
                'id' => (string) $n,

                'locale' => $locale,
                'options' => json_encode($options, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     */
    protected static function store(array $args): Store
    {
        $channel = (int) ($args['storeId'] ?? 0);
        if ($channel > 0) {
            $store = Store::query()->find($channel);
            if ($store) {
                return $store;
            }
        }
        $ctx = app(StoreContext::class)->store();
        if ($ctx) {
            return $ctx;
        }
        \App\Domain\Store\StoreBootstrap::ensure();
        $store = Store::query()->where('is_default', true)->first()
            ?? Store::query()->orderBy('id')->first();
        if (! $store) {
            throw new UserError('No store found.');
        }

        return $store;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    protected static function idForCode(string $code, array $args): int
    {
        foreach (self::blocks($args, false) as $row) {
            if (($row['moduleCode'] ?? '') === $code) {
                return (int) $row['id'];
            }
        }

        return 0;
    }
}
