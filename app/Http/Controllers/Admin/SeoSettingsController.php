<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Seo\SeoFixer;
use App\Domain\Seo\SeoFixJournal;
use App\Domain\Seo\SeoFixLive;
use App\Domain\Seo\SeoPages;
use App\Domain\Seo\ShopAudit;
use App\Domain\Settings\Models\FileChangeLog;
use App\Domain\Settings\ShopSetting;
use App\Domain\Settings\ShopTools;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Inertia\Inertia;
use Inertia\Response;

class SeoSettingsController extends Controller
{
    public function edit(Request $request, ShopAudit $audit, SeoPages $pages): Response
    {
        $tab = (string) $request->query('tab', 'dashboard');
        if (! in_array($tab, ['dashboard', 'issues', 'pages', 'sitemap', 'settings'], true)) {
            $tab = 'dashboard';
        }

        $payload = [
            'tab' => $tab,
            'audit' => $audit->report(),
            'seo' => [
                'title_pattern' => ShopTools::titlePattern(),
                'product_title_pattern' => (string) ShopSetting::get('seo.product_title_pattern', ''),
                'default_description' => (string) ShopSetting::get('seo.default_description', ''),
                'default_robots' => ShopTools::defaultRobots(),
                'og_image' => (string) ShopSetting::get('seo.og_image', ''),
                'robots_txt' => ShopTools::robotsTxt(),
                'locale_url_mode' => (string) ShopSetting::get('seo.locale_url_mode', config('wento.locale_url_mode', 'cookie')),
                'sitemap_enabled' => ShopSetting::bool('seo.sitemap_enabled', true),
                'sitemap_include_products' => ShopSetting::bool('seo.sitemap_include_products', true),
                'sitemap_include_categories' => ShopSetting::bool('seo.sitemap_include_categories', true),
                'sitemap_include_cms' => ShopSetting::bool('seo.sitemap_include_cms', true),
                'sitemap_include_brands' => ShopSetting::bool('seo.sitemap_include_brands', true),
                'use_short_description_fallback' => ShopSetting::bool('seo.use_short_description_fallback', true),
                'ga4_id' => (string) ShopSetting::get('privacy.ga4_id', ''),
                'gtm_id' => (string) ShopSetting::get('privacy.gtm_id', ''),
                'meta_pixel_id' => (string) ShopSetting::get('privacy.meta_pixel_id', ''),
                'cookie_consent' => ShopSetting::bool('privacy.cookie_consent', true),
                'merchant_feed' => ShopSetting::bool('seo.merchant_feed', true),
                'admin_mfa' => ShopSetting::bool('privacy.admin_mfa', false),
                'customer_mfa' => ShopSetting::bool('privacy.customer_mfa', false),
                'age_gate' => ShopSetting::bool('privacy.age_gate', false),
                'age_min' => (int) ShopSetting::get('privacy.age_min', 18),
            ],
            'pages' => null,
        ];

        if ($tab === 'pages') {
            $payload['pages'] = $pages->list(
                (string) $request->query('type', 'product'),
                (string) $request->query('filter', 'all'),
                (string) $request->query('q', ''),
            );
        }

        return Inertia::render('Settings/Seo', $payload);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title_pattern' => ['required', 'string', 'max:180'],
            'product_title_pattern' => ['nullable', 'string', 'max:180'],
            'default_description' => ['nullable', 'string', 'max:500'],
            'default_robots' => ['required', Rule::in(['index,follow', 'noindex,follow', 'index,nofollow', 'noindex,nofollow'])],
            'og_image' => ['nullable', 'string', 'max:255'],
            'robots_txt' => ['nullable', 'string', 'max:5000'],
            'locale_url_mode' => ['required', Rule::in(['cookie', 'path'])],
            'sitemap_enabled' => ['required', 'boolean'],
            'sitemap_include_products' => ['sometimes', 'boolean'],
            'sitemap_include_categories' => ['sometimes', 'boolean'],
            'sitemap_include_cms' => ['sometimes', 'boolean'],
            'sitemap_include_brands' => ['sometimes', 'boolean'],
            'use_short_description_fallback' => ['sometimes', 'boolean'],
            'ga4_id' => ['nullable', 'string', 'max:40', 'regex:/^$|^G-[A-Z0-9]+$/i'],
            'gtm_id' => ['nullable', 'string', 'max:40', 'regex:/^$|^GTM-[A-Z0-9]+$/i'],
            'meta_pixel_id' => ['nullable', 'string', 'max:20', 'regex:/^$|^[0-9]{5,20}$/'],
            'cookie_consent' => ['sometimes', 'boolean'],
            'merchant_feed' => ['sometimes', 'boolean'],
            'admin_mfa' => ['sometimes', 'boolean'],
            'customer_mfa' => ['sometimes', 'boolean'],
            'age_gate' => ['sometimes', 'boolean'],
            'age_min' => ['sometimes', 'integer', 'min:13', 'max:21'],
        ]);

        ShopSetting::put('seo.title_pattern', $data['title_pattern']);
        ShopSetting::put('seo.product_title_pattern', $data['product_title_pattern'] ?? '');
        ShopSetting::put('seo.default_description', $data['default_description'] ?? '');
        ShopSetting::put('seo.default_robots', $data['default_robots']);
        ShopSetting::put('seo.og_image', $data['og_image'] ?? '');
        ShopSetting::put('seo.robots_txt', $data['robots_txt'] ?? '');
        ShopSetting::put('seo.locale_url_mode', $data['locale_url_mode']);
        ShopSetting::put('seo.sitemap_enabled', (bool) $data['sitemap_enabled']);
        if (array_key_exists('sitemap_include_products', $data)) {
            ShopSetting::put('seo.sitemap_include_products', (bool) $data['sitemap_include_products']);
        }
        if (array_key_exists('sitemap_include_categories', $data)) {
            ShopSetting::put('seo.sitemap_include_categories', (bool) $data['sitemap_include_categories']);
        }
        if (array_key_exists('sitemap_include_cms', $data)) {
            ShopSetting::put('seo.sitemap_include_cms', (bool) $data['sitemap_include_cms']);
        }
        if (array_key_exists('sitemap_include_brands', $data)) {
            ShopSetting::put('seo.sitemap_include_brands', (bool) $data['sitemap_include_brands']);
        }
        if (array_key_exists('use_short_description_fallback', $data)) {
            ShopSetting::put('seo.use_short_description_fallback', (bool) $data['use_short_description_fallback']);
        }
        ShopSetting::put('privacy.ga4_id', strtoupper(trim((string) ($data['ga4_id'] ?? ''))));
        ShopSetting::put('privacy.gtm_id', strtoupper(trim((string) ($data['gtm_id'] ?? ''))));
        ShopSetting::put('privacy.meta_pixel_id', trim((string) ($data['meta_pixel_id'] ?? '')));
        if (array_key_exists('cookie_consent', $data)) {
            ShopSetting::put('privacy.cookie_consent', (bool) $data['cookie_consent']);
        }
        if (array_key_exists('merchant_feed', $data)) {
            ShopSetting::put('seo.merchant_feed', (bool) $data['merchant_feed']);
        }
        if (array_key_exists('admin_mfa', $data)) {
            ShopSetting::put('privacy.admin_mfa', (bool) $data['admin_mfa']);
        }
        if (array_key_exists('customer_mfa', $data)) {
            ShopSetting::put('privacy.customer_mfa', (bool) $data['customer_mfa']);
        }
        if (array_key_exists('age_gate', $data)) {
            ShopSetting::put('privacy.age_gate', (bool) $data['age_gate']);
        }
        if (array_key_exists('age_min', $data)) {
            ShopSetting::put('privacy.age_min', (int) $data['age_min']);
        }
        FileChangeLog::record('updated', 'seo/robots.txt');
        ShopAudit::flush();

        return back()->with('success', 'SEO settings saved.');
    }

    public function fix(Request $request, SeoFixer $fixer, SeoFixLive $live): RedirectResponse|JsonResponse
    {
        if ($live->wants($request)) {
            return $live->start($request, 'seo');
        }

        $data = $request->validate([
            'id' => ['required', 'string', 'max:80'],
        ]);

        try {
            $result = $data['id'] === 'all' ? $fixer->applyAll() : $fixer->apply($data['id']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['id' => $e->getMessage()]);
        }

        return back()->with('success', $result['message']);
    }

    public function rollback(Request $request, SeoFixJournal $journal): RedirectResponse
    {
        $data = $request->validate([
            'operation_id' => ['nullable', 'integer'],
            'item_id' => ['nullable', 'integer'],
            'fix' => ['nullable', 'string', 'max:80'],
            'last' => ['nullable', 'boolean'],
        ]);

        try {
            $journal->rollback(
                isset($data['operation_id']) ? (int) $data['operation_id'] : null,
                isset($data['item_id']) ? (int) $data['item_id'] : null,
                $data['fix'] ?? null,
                (bool) ($data['last'] ?? false),
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        ShopAudit::flush();

        return back()->with('success', 'SEO fix changes were rolled back.');
    }

    public function updatePage(Request $request, string $type, int $id, SeoPages $pages): RedirectResponse
    {
        $data = $request->validate([
            'meta_title' => ['nullable', 'string', 'max:180'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'meta_keywords' => ['nullable', 'string', 'max:500'],
            'focus_keyword' => ['nullable', 'string', 'max:120'],
            'robots' => ['nullable', Rule::in(['index,follow', 'noindex,follow', 'index,nofollow', 'noindex,nofollow'])],
            'canonical_url' => ['nullable', 'string', 'max:500'],
        ]);
        $pages->save($type, $id, $data);

        return back()->with('success', 'Page SEO saved.');
    }
}
