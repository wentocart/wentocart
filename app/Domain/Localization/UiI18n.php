<?php

namespace App\Domain\Localization;

use App\Domain\Auth\PageCatalog;
use App\Domain\Dashboard\ChartCatalog;
use App\Domain\Dashboard\Models\Dashboard;
use App\Domain\Localization\Models\UiString;
use App\Domain\Localization\Models\UiStringTranslation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

final class UiI18n
{
    private const SCAN_CACHE_KEY = 'ui_i18n.scan_hits.v1';

    public static function sync(bool $force = false): int
    {
        $desired = self::desiredStrings($force);
        $existing = UiString::query()
            ->get(['id', 'key', 'surface', 'page', 'source'])
            ->keyBy('key');

        $now = now();
        $inserts = [];
        $changed = 0;

        foreach ($desired as $key => $row) {
            $current = $existing->get($key);
            if ($current === null) {
                $inserts[] = [
                    'key' => $key,
                    'surface' => $row['surface'],
                    'page' => $row['page'],
                    'source' => $row['source'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $changed++;

                continue;
            }

            if (
                $current->surface !== $row['surface']
                || $current->page !== $row['page']
                || $current->source !== $row['source']
            ) {
                UiString::query()->where('id', $current->id)->update([
                    'surface' => $row['surface'],
                    'page' => $row['page'],
                    'source' => $row['source'],
                    'updated_at' => $now,
                ]);
                $changed++;
            }

            $existing->forget($key);
        }

        foreach (array_chunk($inserts, 200) as $chunk) {
            UiString::query()->insert($chunk);
        }

        if ($existing->isNotEmpty()) {
            UiString::query()->whereIn('id', $existing->pluck('id')->all())->delete();
            $changed += $existing->count();
        }

        self::forgetMessageCaches();

        return $changed;
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $locale, ?string $surface = null): array
    {
        $locale = trim($locale) !== '' ? $locale : 'en';
        $surfaceKey = $surface ?? 'all';
        $cacheKey = 'ui_i18n.messages.v1.'.self::messagesEpoch().'.'.$surfaceKey.'.'.$locale;

        try {
            /** @var array<string, string> $cached */
            $cached = Cache::store('file')->remember($cacheKey, 3600, fn () => self::messagesFresh($locale, $surface));

            return is_array($cached) ? $cached : [];
        } catch (\Throwable) {
            return self::messagesFresh($locale, $surface);
        }
    }

    /**
     * Public URL for a cacheable script that assigns storefront/admin messages onto window.__WENTO__.
     * Keeps the HTML document small on full page reloads.
     */
    public static function messagesScriptUrl(string $locale, string $surface = 'storefront'): string
    {
        $locale = trim($locale) !== '' ? $locale : 'en';
        $surface = in_array($surface, ['admin', 'storefront'], true) ? $surface : 'storefront';
        $epoch = preg_replace('/[^0-9.]/', '', self::messagesEpoch()) ?: '1';
        $file = $surface.'.'.$locale.'.'.$epoch.'.js';
        $path = public_path('i18n/'.$file);

        if (! is_file($path)) {
            self::writeMessagesScript($locale, $surface, $path);
        }

        return \App\Support\WebBase::url('/i18n/'.$file);
    }

    /**
     * @return array<string, string>
     */
    protected static function messagesFresh(string $locale, ?string $surface = null): array
    {
        $codes = array_values(array_unique([$locale, 'en']));

        return UiString::query()
            ->when($surface, fn ($query) => $query->where('surface', $surface))
            ->with(['translations' => fn ($query) => $query->whereIn('language_code', $codes)])
            ->orderBy('key')
            ->get()
            ->mapWithKeys(fn (UiString $string) => [$string->key => $string->textFor($locale)])
            ->all();
    }

    protected static function messagesEpoch(): string
    {
        try {
            $epoch = Cache::store('file')->get('ui_i18n.messages.epoch');
            if (is_string($epoch) && $epoch !== '') {
                return $epoch;
            }
            Cache::store('file')->forever('ui_i18n.messages.epoch', '1');

            return '1';
        } catch (\Throwable) {
            return '1';
        }
    }

    public static function forgetMessageCaches(): void
    {
        try {
            Cache::store('file')->forever('ui_i18n.messages.epoch', (string) microtime(true));
        } catch (\Throwable) {
            // ignore
        }

        $dir = public_path('i18n');
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*.js') ?: [] as $file) {
            @unlink($file);
        }
    }

    protected static function writeMessagesScript(string $locale, string $surface, string $path): void
    {
        $messages = self::messages($locale, $surface);
        $json = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{}';
        }

        $dir = dirname($path);
        if (! is_dir($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        // Sync classic script: runs before deferred module entry, so bootI18n() sees messages.
        File::put(
            $path,
            'window.__WENTO__=window.__WENTO__||{};'
            .'window.__WENTO__.i18n=Object.assign({},window.__WENTO__.i18n||{},{messages:'.$json.'});'
        );
    }

    /**
     * @return list<array{id: string, name: string, surface: string, total: int, missing: int}>
     */
    public static function pageSummaries(string $locale, string $surface): array
    {
        $query = UiString::query()
            ->with(['translations' => fn ($q) => $q->where('language_code', $locale)])
            ->orderBy('page')
            ->orderBy('key');

        // Modules/themes store chrome under surface=admin with page prefixes.
        if ($surface === 'module') {
            $query->where('surface', 'admin')->where('page', 'like', 'module.%');
        } elseif ($surface === 'theme') {
            $query->where('surface', 'admin')->where('page', 'like', 'theme.%');
        } elseif ($surface === 'admin') {
            $query->where('surface', 'admin')
                ->where('page', 'not like', 'module.%')
                ->where('page', 'not like', 'theme.%');
        } else {
            $query->where('surface', $surface);
        }

        $rows = $query->get()->groupBy('page');

        $names = collect(UiCatalog::pages())->keyBy('id');
        $packageNames = collect(PackageI18n::packages())->keyBy('page');
        $isEnglish = $locale === 'en';

        return $rows->map(function ($strings, $page) use ($names, $packageNames, $locale, $isEnglish, $surface) {
            $missing = $isEnglish ? 0 : $strings->filter(function (UiString $string) use ($locale) {
                $row = $string->translations->firstWhere('language_code', $locale);

                return $row === null || trim((string) $row->text) === '';
            })->count();

            $package = $packageNames->get($page);
            $name = $package
                ? (($package['type'] === 'module' ? 'Module · ' : 'Theme · ').$package['name'])
                : ($names[$page]['name'] ?? UiCatalog::pageName($page));

            return [
                'id' => $page,
                'name' => $name,
                'surface' => $surface === 'module' || $surface === 'theme' ? $surface : ($strings->first()?->surface ?? 'admin'),
                'total' => $strings->count(),
                'missing' => $missing,
            ];
        })->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    /**
     * @return list<array{key: string, source: string, text: string}>
     */
    public static function pageStrings(string $locale, string $page): array
    {
        return UiString::query()
            ->where('page', $page)
            ->with(['translations' => fn ($query) => $query->where('language_code', $locale)])
            ->orderBy('key')
            ->get()
            ->map(function (UiString $string) use ($locale) {
                $saved = $string->translations->firstWhere('language_code', $locale)?->text ?? '';

                return [
                    'key' => $string->key,
                    'source' => $string->source,
                    'text' => $saved !== '' ? $saved : ($locale === 'en' ? $string->source : ''),
                ];
            })
            ->all();
    }

    public static function savePage(string $locale, string $page, array $values): void
    {
        $strings = UiString::query()->where('page', $page)->get()->keyBy('key');

        foreach ($values as $key => $text) {
            $string = $strings->get($key);
            if (! $string) {
                continue;
            }
            $text = is_string($text) ? trim($text) : '';
            if ($text === '') {
                UiStringTranslation::query()
                    ->where('ui_string_id', $string->id)
                    ->where('language_code', $locale)
                    ->delete();

                continue;
            }

            UiStringTranslation::query()->updateOrCreate(
                ['ui_string_id' => $string->id, 'language_code' => $locale],
                ['text' => $text]
            );
        }

        self::forgetMessageCaches();
    }

    /**
     * @return array<string, array{surface: string, page: string, source: string}>
     */
    protected static function desiredStrings(bool $force = false): array
    {
        $desired = [];

        foreach (UiCatalog::pages() as $page) {
            foreach ($page['strings'] as $suffix => $source) {
                $key = $page['id'].'.'.$suffix;
                $desired[$key] = [
                    'surface' => $page['surface'],
                    'page' => $page['id'],
                    'source' => $source,
                ];
            }
        }

        foreach (self::scanFiles($force) as $hit) {
            $desired[$hit['key']] = [
                'surface' => $hit['surface'],
                'page' => $hit['page'],
                'source' => $hit['source'],
            ];
        }

        foreach (self::structuralHits() as $hit) {
            $desired[$hit['key']] = [
                'surface' => $hit['surface'],
                'page' => $hit['page'],
                'source' => $hit['source'],
            ];
        }

        foreach (PackageI18n::catalogHits() as $hit) {
            $desired[$hit['key']] = [
                'surface' => $hit['surface'],
                'page' => $hit['page'],
                'source' => $hit['source'],
            ];
        }

        return $desired;
    }

    /**
     * Labels that the React scanner cannot see (PHP catalogs / dynamic t(key, label)).
     *
     * @return list<array{surface: string, page: string, key: string, source: string}>
     */
    protected static function structuralHits(): array
    {
        $hits = [];
        $add = function (string $key, string $source, string $page = 'admin.pages') use (&$hits): void {
            $key = trim($key);
            $source = trim($source);
            if ($key === '' || $source === '') {
                return;
            }
            $hits[] = [
                'surface' => 'admin',
                'page' => $page,
                'key' => $key,
                'source' => $source,
            ];
        };

        foreach (PageCatalog::pages() as $page) {
            $add($page['label_key'] ?? ('admin.pages.'.$page['slug']), $page['label']);
            $add($page['group_key'] ?? PageCatalog::groupKey($page['group']), $page['group']);
        }
        foreach (PageCatalog::navGroups() as $group) {
            $add($group['heading_key'] ?? PageCatalog::headingKey($group['heading']), $group['heading']);
        }

        foreach (ChartCatalog::chartOptions() as $opt) {
            $add($opt['label_key'], $opt['label'], 'admin.dashboards');
            $add($opt['hint_key'], $opt['hint'], 'admin.dashboards');
        }
        foreach (ChartCatalog::metricOptions() as $opt) {
            $add($opt['label_key'], $opt['label'], 'admin.dashboards');
        }
        foreach (ChartCatalog::rangeOptions() as $opt) {
            $add($opt['label_key'], $opt['label'], 'admin.dashboards');
        }
        foreach (Dashboard::VISIBILITY as $value => $label) {
            $add('admin.dashboards.visibility_'.$value, $label, 'admin.dashboards');
            $add(
                'admin.dashboards.visibility_'.$value.'_hint',
                $value === 'assigned'
                    ? 'Only super admins and the employee profiles (or people) you pick below'
                    : 'Every signed-in admin can open this board',
                'admin.dashboards'
            );
        }

        foreach ([
            'admin.jump.group_products' => 'Products',
            'admin.jump.group_orders' => 'Orders',
            'admin.jump.group_customers' => 'Customers',
            'admin.jump.group_actions' => 'Actions',
            'admin.jump.recents' => 'Recent',
            'admin.jump.action_products' => 'Products',
            'admin.jump.action_compose_order' => 'Compose order',
            'admin.jump.action_apps' => 'Apps / Extensions',
            'admin.jump.action_performance' => 'Performance',
            'admin.jump.action_search_shortcut' => 'Search shortcut',
            'admin.jump.action_my_search_shortcut' => 'My search shortcut',
            'admin.module_nav.mainmenu' => 'Main menu',
            'admin.module_nav.mainmenu_links' => 'Links',
            'admin.common.breadcrumb' => 'Breadcrumb',
            'admin.common.open' => 'Open',
            'admin.list.delimiter_tab' => 'Tab',
            'admin.list.language_english' => 'English',
            'admin.search_shortcut.default_label' => 'Ctrl+F',
            'admin.dashboards.range_last_n_days' => 'Last :n days',
            'admin.dashboards.vs_previous_period' => 'vs previous period',
            'admin.dashboards.blurb_store_overview' => 'General store performance at a glance',
            'admin.dashboards.blurb_catalog' => 'Product, category and stock insights',
            'admin.dashboards.blurb_sales' => 'Sales performance and trends',
            'admin.dashboards.blurb_customers' => 'Customer growth and segmentation',
            'admin.dashboards.blurb_operations' => 'Orders, shipping and fulfillment',
            'admin.dashboards.width_narrow' => 'Narrow',
            'admin.dashboards.width_quarter' => 'Quarter',
            'admin.dashboards.width_third' => 'Third',
            'admin.dashboards.width_half' => 'Half',
            'admin.dashboards.width_wide' => 'Wide',
            'admin.dashboards.width_full' => 'Full',
        ] as $key => $source) {
            $page = str_starts_with($key, 'admin.dashboards.') ? 'admin.dashboards'
                : (str_starts_with($key, 'admin.jump.') ? 'admin.jump'
                    : (str_starts_with($key, 'admin.module_nav.') ? 'admin.module_nav'
                        : (str_starts_with($key, 'admin.list.') ? 'admin.list' : 'admin.common')));
            $add($key, $source, $page);
        }

        return $hits;
    }

    /**
     * @return list<array{surface: string, page: string, key: string, source: string}>
     */
    protected static function scanFiles(bool $force = false): array
    {
        $fingerprint = self::sourceFingerprint();
        // File store only: the hit list is ~1MB+ and blows MySQL max_allowed_packet
        // when CACHE_STORE=database (default during install).
        $cache = Cache::store('file');
        if (! $force) {
            try {
                $cached = $cache->get(self::SCAN_CACHE_KEY);
                if (is_array($cached) && ($cached['fingerprint'] ?? null) === $fingerprint && isset($cached['hits']) && is_array($cached['hits'])) {
                    return $cached['hits'];
                }
            } catch (\Throwable) {
                // ignore stale/unreadable cache
            }
        }

        $hits = self::scanFilesFresh();
        try {
            $cache->forever(self::SCAN_CACHE_KEY, [
                'fingerprint' => $fingerprint,
                'hits' => $hits,
            ]);
        } catch (\Throwable) {
            // Install must not fail if the cache backend rejects a large value.
        }

        return $hits;
    }

    /**
     * @return list<array{surface: string, page: string, key: string, source: string}>
     */
    protected static function scanFilesFresh(): array
    {
        $hits = [];
        $roots = [
            resource_path('js/admin'),
            base_path('storefront/src'),
        ];
        $hasSource = false;
        foreach ($roots as $root) {
            if (is_dir($root)) {
                $hasSource = true;
                break;
            }
        }
        if (! $hasSource) {
            return self::catalogHits();
        }

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            foreach (File::allFiles($root) as $file) {
                if (! in_array($file->getExtension(), ['js', 'jsx'], true)) {
                    continue;
                }
                $contents = $file->getContents();
                $contents = preg_replace('#/\*.*?\*/#s', '', $contents) ?? $contents;
                $contents = preg_replace('#^\s*//.*$#m', '', $contents) ?? $contents;
                if (! preg_match_all("/\\bt\\(\\s*['\"]([a-z0-9._-]+)['\"]\\s*,\\s*['\"]([^'\"]+)['\"]\\s*\\)/", $contents, $matches, PREG_SET_ORDER)) {
                    continue;
                }
                foreach ($matches as $match) {
                    $key = $match[1];
                    $parts = explode('.', $key);
                    $surface = $parts[0] === 'storefront' ? 'storefront' : 'admin';
                    $page = count($parts) >= 2 ? $parts[0].'.'.$parts[1] : $key;
                    $hits[] = [
                        'surface' => $surface,
                        'page' => $page,
                        'key' => $key,
                        'source' => $match[2],
                    ];
                }
            }
        }

        return $hits;
    }

    /**
     * Production zips omit the React source. The catalog is the same key list.
     *
     * @return list<array{surface: string, page: string, key: string, source: string}>
     */
    protected static function catalogHits(): array
    {
        $path = resource_path('i18n/ui-source.json');
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return [];
        }
        $hits = [];
        foreach ($decoded as $row) {
            if (! is_array($row) || ! isset($row['key'], $row['source'])) {
                continue;
            }
            $hits[] = [
                'surface' => (string) ($row['surface'] ?? 'admin'),
                'page' => (string) ($row['page'] ?? $row['key']),
                'key' => (string) $row['key'],
                'source' => (string) $row['source'],
            ];
        }

        return $hits;
    }

    protected static function sourceFingerprint(): string
    {
        $parts = [
            (string) @filemtime((new \ReflectionClass(UiCatalog::class))->getFileName()),
        ];

        $catalog = resource_path('i18n/ui-source.json');
        $parts[] = is_file($catalog) ? (string) filemtime($catalog) : '0';

        foreach ([resource_path('js/admin'), base_path('storefront/src')] as $root) {
            if (! is_dir($root)) {
                $parts[] = '0:0';

                continue;
            }

            $count = 0;
            $maxMtime = 0;
            foreach (File::allFiles($root) as $file) {
                if (! in_array($file->getExtension(), ['js', 'jsx'], true)) {
                    continue;
                }
                $count++;
                $mtime = $file->getMTime();
                if ($mtime > $maxMtime) {
                    $maxMtime = $mtime;
                }
            }
            $parts[] = $count.':'.$maxMtime;
        }

        return hash('sha1', implode('|', $parts));
    }
}
