<?php

namespace App\Domain\Theme;

use App\Domain\Localization\Money;
use App\Domain\Store\Models\Store;
use App\Domain\Store\StoreContext;
use App\Support\WebBase;

class ThemeResolver
{
    public function __construct(protected ThemeRegistry $themes) {}

    public function active(?Store $store = null): ThemeManifest
    {
        $store ??= app(StoreContext::class)->store();
        $code = $this->themes->canonical($store?->theme);
        $preview = request()->cookie('theme_preview');
        if (is_string($preview) && $preview !== '' && auth('admin')->check() && $this->themes->exists($preview)) {
            $code = $this->themes->canonical($preview);
        }

        return $this->themes->getOrClassic($code);
    }

    /**
     * Merge defaults → theme.json chain → shop theme_settings. Shop wins.
     *
     * @return array<string, mixed>
     */
    public function settings(?ThemeManifest $theme = null, ?Store $store = null): array
    {
        $store ??= app(StoreContext::class)->store();
        $theme ??= $this->active($store);
        $merged = $this->baseDefaults($theme);
        $chain = array_reverse($this->chain($theme));
        foreach ($chain as $item) {
            $merged = $this->mergeSettings($merged, $item->settings);
        }

        $overlay = $store?->theme_settings;
        if (is_array($overlay) && $overlay !== []) {
            $merged = $this->mergeSettings($merged, $overlay);
        }

        if (isset($merged['colors']) && is_array($merged['colors'])) {
            $merged['colors'] = ThemeSettingsCatalog::ensureReadableContrast($merged['colors']);
        }
        if (isset($merged['home']['hero']) && is_array($merged['home']['hero'])) {
            $merged['home']['hero'] = ThemeSettingsCatalog::sanitizeHeroForDisplay($merged['home']['hero']);
        }

        $logoUrl = is_string($merged['logo_url'] ?? null) ? trim($merged['logo_url']) : '';
        if (ThemeSettingsCatalog::isBundledDefaultLogo($logoUrl)) {
            $merged['logo_url'] = ThemeSettingsCatalog::DEFAULT_LOGO_URL;
        }
        $faviconUrl = is_string($merged['favicon_url'] ?? null) ? trim($merged['favicon_url']) : '';
        if (ThemeSettingsCatalog::isBundledDefaultFavicon($faviconUrl)) {
            $merged['favicon_url'] = ThemeSettingsCatalog::DEFAULT_FAVICON_URL;
        }

        return ThemeSettingsCatalog::presentFooter($merged);
    }

    /**
     * Public CSS URLs, parent first then child.
     *
     * @return list<string>
     */
    public function cssUrls(?ThemeManifest $theme = null): array
    {
        $theme ??= $this->active();
        $urls = [];
        foreach (array_reverse($this->chain($theme)) as $item) {
            foreach ($item->css as $rel) {
                $urls[] = $this->publicUrl($item->name, $rel);
            }
            if (is_file($item->path.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'overrides.css')) {
                $urls[] = $this->publicUrl($item->name, 'assets/overrides.css');
            }
        }

        return array_values(array_unique(array_filter($urls)));
    }

    /**
     * @return list<string>
     */
    public function jsUrls(?ThemeManifest $theme = null): array
    {
        $theme ??= $this->active();
        $leaf = $this->leaf($theme);
        $urls = [];
        foreach ($leaf->js as $rel) {
            $urls[] = $this->publicUrl($leaf->name, $rel);
        }

        return array_values(array_unique(array_filter($urls)));
    }

    public function engine(?ThemeManifest $theme = null): string
    {
        $theme ??= $this->active();
        $leaf = $this->leaf($theme);
        if ($leaf->engine === 'spa-dist' && ! $leaf->isChild()) {
            return 'spa-dist';
        }

        return 'react-vite';
    }

    public function previewUrl(ThemeManifest $theme): ?string
    {
        $file = $theme->preview ?: 'preview.svg';
        $public = public_path('themes/'.$theme->name.'/'.$file);
        $source = $theme->path.DIRECTORY_SEPARATOR.$file;
        if (is_file($public) || is_file($source)) {
            return WebBase::url('themes/'.$theme->name.'/'.$file);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function bootPayload(?Store $store = null): array
    {
        $theme = $this->active($store);
        $settings = $this->settings($theme, $store);

        return [
            'name' => $theme->name,
            'display_name' => $theme->displayName,
            'parent' => $theme->parent,
            'engine' => $this->engine($theme),
            'version' => $theme->version,
            'settings' => Money::convertDisplayCopy(WebBase::prefixPublicMedia($settings)),
            'settings_schema' => $theme->hasSettingsSchema() ? $theme->settingsSchema : null,
            'css' => $this->cssUrls($theme),
            'js' => $this->jsUrls($theme),
            'extends_classic' => $theme->name === ThemeRegistry::CLASSIC || $theme->parent === ThemeRegistry::CLASSIC,
        ];
    }

    /**
     * Theme-authored settings use their own defaults; Classic keeps the catalog defaults.
     *
     * @return array<string, mixed>
     */
    protected function baseDefaults(ThemeManifest $theme): array
    {
        if ($theme->hasSettingsSchema()) {
            return [];
        }

        $layout = (string) (($theme->settings['home']['layout'] ?? '') ?: '');
        if ($layout !== '' && $layout !== 'classic') {
            return [];
        }

        return ThemeSettingsCatalog::defaults();
    }

    /**
     * Merged theme.json defaults without shop overlay (for admin + normalization).
     *
     * @return array<string, mixed>
     */
    public function themeDefaults(?ThemeManifest $theme = null, ?Store $store = null): array
    {
        $store ??= app(StoreContext::class)->store();
        $theme ??= $this->active($store);
        $merged = $this->baseDefaults($theme);
        foreach (array_reverse($this->chain($theme)) as $item) {
            $merged = $this->mergeSettings($merged, $item->settings);
        }

        if (isset($merged['colors']) && is_array($merged['colors'])) {
            $merged['colors'] = ThemeSettingsCatalog::ensureReadableContrast($merged['colors']);
        }

        return $merged;
    }

    /**
     * @return list<ThemeManifest>
     */
    public function chain(ThemeManifest $theme): array
    {
        $chain = [];
        $guard = 0;
        $current = $theme;
        while ($current && $guard < 4) {
            $chain[] = $current;
            if (! $current->parent) {
                break;
            }
            $parent = $this->themes->get($current->parent);
            if (! $parent || $parent->name === $current->name) {
                break;
            }
            $current = $parent;
            $guard++;
        }

        return $chain;
    }

    protected function leaf(ThemeManifest $theme): ThemeManifest
    {
        return $theme;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overlay
     * @return array<string, mixed>
     */
    protected function mergeSettings(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && ! array_is_list($value)) {
                $base[$key] = $this->mergeSettings($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    protected function publicUrl(string $theme, string $rel): string
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        $url = WebBase::url('themes/'.$theme.'/'.$rel);
        foreach ([public_path('themes/'.$theme.'/'.$rel), base_path('themes/'.$theme.'/'.$rel)] as $file) {
            if (is_file($file)) {
                return $url.'?v='.filemtime($file);
            }
        }

        return $url;
    }
}
