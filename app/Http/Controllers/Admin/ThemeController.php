<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Settings\Models\FileChangeLog;
use App\Domain\Store\StoreBootstrap;
use App\Domain\Store\StoreContext;
use App\Domain\Theme\ClassicHomeModules;
use App\Domain\Theme\ThemeInstaller;
use App\Domain\Theme\ThemeRegistry;
use App\Domain\Theme\ThemeResolver;
use App\Domain\Theme\ThemeSettingsCatalog;
use App\Domain\Theme\ThemeSettingsSchema;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ThemeController extends Controller
{
    public function __construct(
        protected ThemeRegistry $themes,
        protected ThemeInstaller $installer,
        protected ThemeResolver $resolver,
    ) {}

    public function index(): Response
    {
        $store = app(StoreContext::class)->store() ?? StoreBootstrap::ensure();
        $active = $this->themes->canonical($store->theme);

        $rows = array_map(function ($theme) use ($active) {
            $preview = $this->resolver->previewUrl($theme);

            return [
                'name' => $theme->name,
                'display_name' => $theme->displayName,
                'version' => $theme->version,
                'author' => $theme->author,
                'parent' => $theme->parent,
                'engine' => $theme->engine,
                'description' => $theme->description,
                'bundled' => $theme->bundled,
                'child' => $theme->isChild(),
                'full_override' => $theme->isFullOverride(),
                'preview' => $preview,
                'active' => $theme->name === $active,
                'can_delete' => ! $theme->bundled,
            ];
        }, $this->themes->all());

        return Inertia::render('Design/Themes', [
            'themes' => $rows,
            'active' => $active,
            'shop' => [
                'id' => $store->id,
                'name' => $store->name,
            ],
        ]);
    }

    public function settings(): Response
    {
        $store = app(StoreContext::class)->store() ?? StoreBootstrap::ensure();
        $theme = $this->resolver->active($store);
        $settings = $this->resolver->settings($theme, $store);

        if ($theme->hasSettingsSchema()) {
            return Inertia::render('Design/ThemeSchemaSettings', [
                'settings' => $settings,
                'schema' => $theme->settingsSchema,
                'shop' => [
                    'id' => $store->id,
                    'name' => $store->name,
                    'theme' => $theme->name,
                    'theme_label' => $theme->displayName,
                    'storefront_settings_apply' => $theme->storefrontSettingsApply,
                ],
            ]);
        }

        return Inertia::render('Design/ThemeSettings', [
            'settings' => $settings,
            'presets' => ThemeSettingsCatalog::presets(),
            'modules' => ClassicHomeModules::layoutRows($settings),
            'shop' => [
                'id' => $store->id,
                'name' => $store->name,
                'theme' => $this->themes->canonical($store->theme),
            ],
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $store = app(StoreContext::class)->store() ?? StoreBootstrap::ensure();
        $theme = $this->resolver->active($store);

        if ($theme->hasSettingsSchema()) {
            $data = $request->validate([
                'settings' => ['required', 'array'],
            ]);
            $defaults = $this->resolver->themeDefaults($theme, $store);
            $normalized = ThemeSettingsSchema::normalize($defaults, $theme->settingsSchema, $data['settings']);
            $store->update(['theme_settings' => $normalized]);
            FileChangeLog::record('theme.settings', 'stores/'.$store->id.'/theme_settings');

            $message = $theme->appliesSettingsOnStorefront()
                ? 'Theme settings saved. Open the storefront to see them.'
                : 'Theme settings saved. The live storefront still uses the theme’s built-in static layout until dynamic wiring is enabled.';

            return back()->with('success', $message);
        }

        $data = $request->validate([
            'preset' => ['required', 'string', 'max:40'],
            'logo_text' => ['nullable', ThemeSettingsCatalog::localizedTextRule(80)],
            'logo_url' => ['nullable', 'string', 'max:500'],
            'favicon_url' => ['nullable', 'string', 'max:500'],
            'announcement' => ['nullable', ThemeSettingsCatalog::localizedTextRule(200)],
            'phone' => ['nullable', 'string', 'max:40'],
            'announcement_url' => ['nullable', 'string', 'max:200'],
            'top_bar' => ['nullable', 'array'],
            'top_bar.enabled' => ['nullable', 'boolean'],
            'top_bar.background' => ['nullable', 'string', 'max:20'],
            'top_bar.text_color' => ['nullable', 'string', 'max:20'],
            'top_bar.show_currency' => ['nullable', 'boolean'],
            'top_bar.show_language' => ['nullable', 'boolean'],
            'top_bar.announcement' => ['nullable', ThemeSettingsCatalog::localizedTextRule(200)],
            'top_bar.announcement_url' => ['nullable', 'string', 'max:200'],
            'top_bar.links' => ['nullable', 'array', 'max:8'],
            'top_bar.links.*.label' => ['nullable', ThemeSettingsCatalog::localizedTextRule(60)],
            'top_bar.links.*.url' => ['nullable', 'string', 'max:200'],
            'top_bar.links.*.open_in_new_tab' => ['nullable', 'boolean'],
            'top_bar.contact' => ['nullable', 'array'],
            'top_bar.contact.phone' => ['nullable', 'string', 'max:40'],
            'top_bar.contact.label' => ['nullable', ThemeSettingsCatalog::localizedTextRule(60)],
            'top_bar.contact.url' => ['nullable', 'string', 'max:200'],
            'top_bar.contact.email' => ['nullable', 'string', 'max:120'],
            'colors' => ['nullable', 'array'],
            'colors.accent' => ['nullable', 'string', 'max:20'],
            'colors.accent_soft' => ['nullable', 'string', 'max:20'],
            'colors.ink' => ['nullable', 'string', 'max:20'],
            'colors.muted' => ['nullable', 'string', 'max:20'],
            'colors.surface' => ['nullable', 'string', 'max:20'],
            'colors.card' => ['nullable', 'string', 'max:20'],
            'colors.header' => ['nullable', 'string', 'max:20'],
            'colors.footer' => ['nullable', 'string', 'max:20'],
            'colors.sale' => ['nullable', 'string', 'max:20'],
            'colors.highlight' => ['nullable', 'string', 'max:20'],
            'radius' => ['nullable', 'string', 'max:20'],
            'font' => ['nullable', 'string', 'max:60'],
            'font_size' => ['nullable', 'string', 'max:20'],
            'home' => ['nullable', 'array'],
            'home.order' => ['nullable', 'array'],
            'home.order.*' => ['nullable', 'string', 'max:40'],
            'home.sections' => ['nullable', 'array'],
            'home.header' => ['nullable', 'array'],
            'home.header.nav_source' => ['nullable', 'string', 'in:categories,main_menu'],
            'home.header.show_search' => ['nullable', 'boolean'],
            'home.header.show_compare' => ['nullable', 'boolean'],
            'home.header.show_wishlist' => ['nullable', 'boolean'],
            'home.header.show_login' => ['nullable', 'boolean'],
            'home.header.show_cart' => ['nullable', 'boolean'],
            'home.header.cart_style' => ['nullable', 'string', 'in:icon,pill'],
            'home.header.search_placeholder' => ['nullable'],
            'home.header.search_placeholder.*' => ['nullable', 'string', 'max:80'],
            'home.header.category_ids' => ['nullable', 'array'],
            'home.hero' => ['nullable', 'array'],
            'home.hero.layout' => ['nullable', 'integer', 'in:1,2,3'],
            'home.hero.eyebrow' => ['nullable', ThemeSettingsCatalog::localizedTextRule(80)],
            'home.hero.title' => ['nullable', ThemeSettingsCatalog::localizedTextRule(120)],
            'home.hero.body' => ['nullable', ThemeSettingsCatalog::localizedTextRule(400)],
            'home.hero.cta_label' => ['nullable', ThemeSettingsCatalog::localizedTextRule(40)],
            'home.hero.cta_url' => ['nullable', 'string', 'max:200'],
            'home.hero.cta2_label' => ['nullable', 'string', 'max:40'],
            'home.hero.cta2_url' => ['nullable', 'string', 'max:200'],
            'home.hero.image_url' => ['nullable', 'string', 'max:500'],
            'home.hero.autoplay_ms' => ['nullable', 'integer', 'min:0', 'max:20000'],
            'home.hero.slides' => ['nullable', 'array', 'max:8'],
            'home.hero.slides.*.image_url' => ['nullable', 'string', 'max:500'],
            'home.hero.slides.*.title' => ['nullable', ThemeSettingsCatalog::localizedTextRule(120)],
            'home.hero.slides.*.body' => ['nullable', ThemeSettingsCatalog::localizedTextRule(400)],
            'home.hero.slides.*.eyebrow' => ['nullable', ThemeSettingsCatalog::localizedTextRule(80)],
            'home.hero.slides.*.cta_label' => ['nullable', ThemeSettingsCatalog::localizedTextRule(40)],
            'home.hero.slides.*.content' => ['nullable', ThemeSettingsCatalog::localizedHtmlRule(20000)],
            'home.hero.slides.*.cta_url' => ['nullable', 'string', 'max:200'],
            'home.hero.slides.*.cta2_label' => ['nullable', 'string', 'max:40'],
            'home.hero.slides.*.cta2_url' => ['nullable', 'string', 'max:200'],
            'home.hero.sides' => ['nullable', 'array', 'max:2'],
            'home.hero.sides.*.kicker' => ['nullable', ThemeSettingsCatalog::localizedTextRule(80)],
            'home.hero.sides.*.title' => ['nullable', ThemeSettingsCatalog::localizedTextRule(120)],
            'home.hero.sides.*.subtitle' => ['nullable', ThemeSettingsCatalog::localizedTextRule(400)],
            'home.hero.sides.*.cta_label' => ['nullable', ThemeSettingsCatalog::localizedTextRule(40)],
            'home.hero.sides.*.cta_url' => ['nullable', 'string', 'max:200'],
            'home.hero.sides.*.image_url' => ['nullable', 'string', 'max:500'],
            'home.hero.columns' => ['nullable', 'array', 'max:3'],
            'home.hero.columns.*.width_hint' => ['nullable', 'string', 'in:full,main,side'],
            'home.hero.columns.*.slides' => ['nullable', 'array', 'max:8'],
            'home.hero.columns.*.slides.*.title' => ['nullable', ThemeSettingsCatalog::localizedTextRule(120)],
            'home.hero.columns.*.slides.*.eyebrow' => ['nullable', ThemeSettingsCatalog::localizedTextRule(80)],
            'home.hero.columns.*.slides.*.body' => ['nullable', ThemeSettingsCatalog::localizedTextRule(400)],
            'home.hero.columns.*.slides.*.cta_label' => ['nullable', ThemeSettingsCatalog::localizedTextRule(40)],
            'home.hero.columns.*.slides.*.content' => ['nullable', ThemeSettingsCatalog::localizedHtmlRule(20000)],
            'home.hero.columns.*.slides.*.image_url' => ['nullable', 'string', 'max:500'],
            'home.hero.columns.*.slides.*.cta_url' => ['nullable', 'string', 'max:200'],
            // Per-layout column trees (layout 1 / 2 / 3 keep separate banner sets).
            'home.hero.layout_columns' => ['nullable', 'array'],
            'home.hero.layout_columns.*' => ['nullable', 'array', 'max:3'],
            'home.hero.layout_columns.*.*.width_hint' => ['nullable', 'string', 'in:full,main,side'],
            'home.hero.layout_columns.*.*.slides' => ['nullable', 'array', 'max:8'],
            'home.hero.layout_columns.*.*.slides.*.title' => ['nullable', ThemeSettingsCatalog::localizedTextRule(120)],
            'home.hero.layout_columns.*.*.slides.*.eyebrow' => ['nullable', ThemeSettingsCatalog::localizedTextRule(80)],
            'home.hero.layout_columns.*.*.slides.*.body' => ['nullable', ThemeSettingsCatalog::localizedTextRule(400)],
            'home.hero.layout_columns.*.*.slides.*.cta_label' => ['nullable', ThemeSettingsCatalog::localizedTextRule(40)],
            'home.hero.layout_columns.*.*.slides.*.content' => ['nullable', ThemeSettingsCatalog::localizedHtmlRule(20000)],
            'home.hero.layout_columns.*.*.slides.*.image_url' => ['nullable', 'string', 'max:500'],
            'home.hero.layout_columns.*.*.slides.*.cta_url' => ['nullable', 'string', 'max:200'],
            'home.trust' => ['nullable', 'array'],
            'home.promos' => ['nullable', 'array'],
            'home.testimonials' => ['nullable', 'array'],
            'home.newsletter' => ['nullable', 'array'],
            'home.density' => ['nullable', 'array'],
            'home.density.products_per_row' => ['nullable', 'integer', 'min:2', 'max:6'],
            'home.density.categories_per_row' => ['nullable', 'integer', 'min:3', 'max:8'],
            'home.density.recent_per_row' => ['nullable', 'integer', 'min:2', 'max:6'],
            'home.density.products_limit' => ['nullable', 'integer', 'min:2', 'max:24'],
            'home.density.categories_limit' => ['nullable', 'integer', 'min:3', 'max:20'],
            'home.density.recent_limit' => ['nullable', 'integer', 'min:2', 'max:12'],
        ]);

        $current = is_array($store->theme_settings) ? $store->theme_settings : [];
        if (isset($data['home']['hero']) && is_array($data['home']['hero'])) {
            $storedHero = is_array($current['home']['hero'] ?? null) ? $current['home']['hero'] : [];
            $data['home']['hero'] = ThemeSettingsCatalog::preserveOmittedSlideContent($data['home']['hero'], $storedHero);
        }
        $normalized = ThemeSettingsCatalog::normalize(ThemeSettingsCatalog::merge($current, $data));
        $store->update(['theme_settings' => $normalized]);
        FileChangeLog::record('theme.settings', 'stores/'.$store->id.'/theme_settings');

        return back()->with('success', 'Theme settings saved. Open the storefront to see them.');
    }

    public function resetSettings(): RedirectResponse
    {
        $store = app(StoreContext::class)->store() ?? StoreBootstrap::ensure();
        $store->update(['theme_settings' => null]);
        FileChangeLog::record('theme.settings.reset', 'stores/'.$store->id.'/theme_settings');

        return back()->with('success', 'Theme settings reset to theme defaults.');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'zip' => ['required', 'file', 'mimes:zip', 'max:25600'],
        ]);

        $theme = $this->installer->install($request->file('zip'));

        return back()->with('success', $theme->displayName.' is installed. Enable it on this shop when you are ready.');
    }

    public function enable(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'theme' => ['required', 'string', 'max:80'],
        ]);
        $code = $this->themes->canonical($data['theme']);
        if (! $this->themes->exists($code)) {
            return back()->withErrors(['theme' => 'That theme is not installed.']);
        }

        $store = app(StoreContext::class)->store() ?? StoreBootstrap::ensure();
        $store->update(['theme' => $code]);
        FileChangeLog::record('theme.enable', 'themes/'.$code);

        return back()->with('success', 'This shop now uses '.$this->themes->get($code)?->displayName.'.');
    }

    public function preview(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'theme' => ['required', 'string', 'max:80'],
        ]);
        $code = $this->themes->canonical($data['theme']);
        if (! $this->themes->exists($code)) {
            return back()->withErrors(['theme' => 'That theme is not installed.']);
        }

        return redirect('/')->withCookie(cookie('theme_preview', $code, 60));
    }

    public function destroy(string $theme): RedirectResponse
    {
        $code = $this->themes->canonical($theme);
        $store = app(StoreContext::class)->store() ?? StoreBootstrap::ensure();
        $this->installer->uninstall($code);
        if ($this->themes->canonical($store->theme) === $code) {
            $store->update(['theme' => ThemeRegistry::CLASSIC]);
        }

        return back()->with('success', 'Theme removed. The shop is back on Classic if that was the active theme.');
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        return $this->uploadThemeImage($request, 'logo');
    }

    public function uploadFavicon(Request $request): JsonResponse
    {
        return $this->uploadThemeImage($request, 'favicon');
    }

    protected function uploadThemeImage(Request $request, string $folder): JsonResponse
    {
        $file = $request->file('image');
        if ($file instanceof \Illuminate\Http\UploadedFile && ! $file->isValid()) {
            $message = $file->getErrorMessage() ?: 'The image failed to upload.';

            return response()->json([
                'message' => $message,
                'errors' => ['image' => [$message]],
            ], 422);
        }

        $maxKb = (int) config('wento.upload.max_image_kb', 2048);
        $request->validate([
            'image' => \App\Domain\Security\RasterImageUpload::requiredRules($maxKb),
        ]);

        $store = app(StoreContext::class)->store() ?? StoreBootstrap::ensure();
        $path = \App\Domain\Security\RasterImageUpload::storePublic($request->file('image'), 'theme-media/'.$store->id.'/'.$folder, $maxKb);

        FileChangeLog::record('theme.'.$folder, 'stores/'.$store->id.'/theme_settings/'.$folder);

        return response()->json([
            'url' => '/storage/'.$path,
        ]);
    }
}
