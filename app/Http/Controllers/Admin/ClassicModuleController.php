<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\UrlRewrite;
use App\Domain\Catalog\Services\CategoryProductQuery;
use App\Domain\Settings\Models\FileChangeLog;
use App\Domain\Store\ShopIdentity;
use App\Domain\Store\StoreBootstrap;
use App\Domain\Store\StoreContext;
use App\Domain\Theme\ClassicHomeModules;
use App\Domain\Theme\ThemeResolver;
use App\Domain\Theme\ThemeSettingsCatalog;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClassicModuleController extends Controller
{
    public function __construct(protected ThemeResolver $resolver) {}

    public function show(string $code): Response
    {
        abort_unless(ClassicHomeModules::isConfigurable($code), 404);

        $store = app(StoreContext::class)->store() ?? StoreBootstrap::ensure();
        $theme = $this->resolver->active($store);
        $settings = $this->resolver->settings($theme, $store);
        $def = ClassicHomeModules::definition($code);
        $locale = app()->getLocale();

        $categoryModels = Category::query()
            ->where('is_enabled', true)
            ->with('translations')
            ->orderBy('position')
            ->get();
        $productCounts = CategoryProductQuery::counts($categoryModels->pluck('id')->all());
        $categoryPaths = UrlRewrite::query()
            ->where('entity_type', 'category')
            ->whereIn('entity_id', $categoryModels->pluck('id'))
            ->where('language_code', $locale)
            ->pluck('request_path', 'entity_id');
        $categories = $categoryModels
            ->map(function (Category $category) use ($locale, $productCounts, $categoryPaths) {
                $translation = $category->translation($locale);
                $path = $categoryPaths[$category->id] ?? $translation?->slug;

                return [
                    'id' => $category->id,
                    'parent_id' => $category->parent_id,
                    'name' => $translation?->name ?: '#'.$category->id,
                    'names' => $category->translations
                        ->mapWithKeys(fn ($row) => [strtolower((string) $row->language_code) => (string) $row->name])
                        ->filter(fn ($name) => trim($name) !== '')
                        ->all(),
                    'url' => '/'.ltrim((string) ($path ?: $category->id), '/'),
                    'products_count' => (int) ($productCounts[$category->id] ?? 0),
                ];
            })
            ->values()
            ->all();

        $brands = Brand::query()
            ->where('is_enabled', true)
            ->with('translations')
            ->orderBy('position')
            ->get()
            ->map(fn (Brand $brand) => [
                'id' => $brand->id,
                'name' => $brand->translation($locale)?->name ?: ($brand->code ?: '#'.$brand->id),
            ])
            ->values()
            ->all();

        return Inertia::render('Design/ClassicModule', [
            'code' => $code,
            'label' => $def['label'] ?? $code,
            'hint' => $def['hint'] ?? '',
            'settings' => $settings,
            'options' => [
                'categories' => $categories,
                'brands' => $brands,
                'shop' => $this->footerShop($store),
            ],
        ]);
    }

    /**
     * Contact lines the storefront footer already prints, so the editor preview matches.
     *
     * @return array{name: string, email: string, phone: string, address: string, business_number: string}
     */
    private function footerShop(mixed $store): array
    {
        $public = ShopIdentity::forStorefront($store);
        $flags = is_array($public['footer'] ?? null) ? $public['footer'] : [];

        return [
            'name' => (string) ($public['name'] ?? ''),
            'email' => ($flags['email'] ?? true) ? (string) ($public['email'] ?? '') : '',
            'phone' => ($flags['phone'] ?? true) ? (string) ($public['phone'] ?? '') : '',
            'address' => ($flags['address'] ?? true) ? (string) ($public['address'] ?? '') : '',
            'business_number' => ! empty($flags['business_number']) ? (string) ($public['business_number'] ?? '') : '',
        ];
    }

    public function update(Request $request, string $code): RedirectResponse
    {
        abort_unless(ClassicHomeModules::isConfigurable($code), 404);

        $store = app(StoreContext::class)->store() ?? StoreBootstrap::ensure();
        $rules = [
            'home' => ['required', 'array'],
        ];
        if ($code === 'trust') {
            $rules = array_merge($rules, [
                'home.trust' => ['nullable', 'array', 'max:8'],
                'home.trust.*.icon' => ['nullable', 'string', 'max:30'],
                'home.trust.*.title' => ['nullable', ThemeSettingsCatalog::localizedTextRule(60)],
                'home.trust.*.text' => ['nullable', ThemeSettingsCatalog::localizedTextRule(120)],
            ]);
        }
        if ($code === 'hero') {
            $text = fn (int $max) => ['nullable', ThemeSettingsCatalog::localizedTextRule($max)];
            $rules = array_merge($rules, [
                'home.hero' => ['required', 'array'],
                'home.hero.layout' => ['nullable', 'integer', 'in:1,2,3'],
                'home.hero.autoplay_ms' => ['nullable', 'integer', 'min:0', 'max:20000'],
                'home.hero.columns' => ['nullable', 'array', 'max:3'],
                'home.hero.columns.*.width_hint' => ['nullable', 'string', 'in:full,main,side'],
                'home.hero.columns.*.slides' => ['nullable', 'array', 'max:8'],
                'home.hero.columns.*.slides.*.title' => $text(120),
                'home.hero.columns.*.slides.*.eyebrow' => $text(80),
                'home.hero.columns.*.slides.*.body' => $text(400),
                'home.hero.columns.*.slides.*.cta_label' => $text(40),
                'home.hero.columns.*.slides.*.content' => ['nullable', ThemeSettingsCatalog::localizedHtmlRule(20000)],
                'home.hero.columns.*.slides.*.image_url' => ['nullable', 'string', 'max:500'],
                'home.hero.columns.*.slides.*.cta_url' => ['nullable', 'string', 'max:200'],
                'home.hero.slides' => ['nullable', 'array', 'max:8'],
                'home.hero.slides.*.title' => $text(120),
                'home.hero.slides.*.eyebrow' => $text(80),
                'home.hero.slides.*.body' => $text(400),
                'home.hero.slides.*.cta_label' => $text(40),
                'home.hero.slides.*.content' => ['nullable', ThemeSettingsCatalog::localizedHtmlRule(20000)],
                'home.hero.slides.*.image_url' => ['nullable', 'string', 'max:500'],
                'home.hero.slides.*.cta_url' => ['nullable', 'string', 'max:200'],
                'home.hero.sides' => ['nullable', 'array', 'max:2'],
                'home.hero.sides.*.kicker' => $text(80),
                'home.hero.sides.*.title' => $text(120),
                'home.hero.sides.*.subtitle' => $text(400),
                'home.hero.sides.*.cta_label' => $text(40),
                'home.hero.sides.*.image_url' => ['nullable', 'string', 'max:500'],
                'home.hero.sides.*.cta_url' => ['nullable', 'string', 'max:200'],
            ]);
        }
        $request->validate($rules);
        $current = is_array($store->theme_settings) ? $store->theme_settings : [];
        $home = is_array($request->input('home')) ? $request->input('home') : [];
        if ($code === 'hero' && isset($home['hero']) && is_array($home['hero'])) {
            $storedHero = is_array($current['home']['hero'] ?? null) ? $current['home']['hero'] : [];
            $home['hero'] = ThemeSettingsCatalog::preserveOmittedSlideContent($home['hero'], $storedHero);
        }
        $data = [
            'home' => $home,
        ];

        $patch = ['home' => $this->homePatch($code, is_array($data['home']) ? $data['home'] : [])];
        $normalized = ThemeSettingsCatalog::normalize(ThemeSettingsCatalog::merge($current, $patch));
        $store->update(['theme_settings' => $normalized]);
        FileChangeLog::record('theme.module', 'stores/'.$store->id.'/theme_settings/'.$code);

        return back()->with('success', 'Module saved. Open Theme settings to change placement.');
    }

    public function uploadMedia(Request $request, string $code): JsonResponse
    {
        abort_unless(ClassicHomeModules::isConfigurable($code), 404);

        $uploaded = $request->file('image');
        if ($uploaded instanceof \Illuminate\Http\UploadedFile && ! $uploaded->isValid()) {
            $tooBig = in_array($uploaded->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            $message = $tooBig
                ? 'The image is larger than the server upload limit ('.ini_get('upload_max_filesize').').'
                : 'The image failed to upload.';

            return response()->json([
                'message' => $message,
                'errors' => ['image' => [$message]],
            ], 422);
        }

        $maxKb = (int) config('wento.upload.max_image_kb', 8192);
        $request->validate([
            'image' => \App\Domain\Security\RasterImageUpload::requiredRules($maxKb),
        ]);

        $store = app(StoreContext::class)->store() ?? StoreBootstrap::ensure();
        $path = \App\Domain\Security\RasterImageUpload::storePublic($request->file('image'), 'theme-media/'.$store->id.'/'.$code, $maxKb);

        FileChangeLog::record('theme.module.media', 'stores/'.$store->id.'/theme_settings/'.$code);

        return response()->json([
            'url' => '/storage/'.$path,
        ]);
    }

    /**
     * @param  array<string, mixed>  $home
     * @return array<string, mixed>
     */
    protected function homePatch(string $code, array $home): array
    {
        $key = match ($code) {
            'categories' => 'shop_categories',
            default => $code,
        };

        if (! array_key_exists($key, $home) && $key !== $code) {
            $home[$key] = $home[$code] ?? [];
        }

        if (! array_key_exists($key, $home)) {
            return [];
        }

        return [$key => $home[$key]];
    }
}
