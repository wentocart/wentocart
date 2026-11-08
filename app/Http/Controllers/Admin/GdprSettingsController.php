<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Localization\Models\Language;
use App\Domain\Settings\GdprSettings;
use App\Domain\Settings\ShopSetting;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GdprSettingsController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Settings/Gdpr', GdprSettings::payload());
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'agreement_enabled' => ['required', 'boolean'],
            'agreement_page_id' => ['nullable', 'integer', Rule::exists('cms_pages', 'id')],
            'agreement_pages' => ['nullable', 'array'],
            'agreement_pages.*' => ['nullable', 'integer', Rule::exists('cms_pages', 'id')],
            'cookie_notice' => ['required', 'boolean'],
            'cookie_position' => ['required', Rule::in(GdprSettings::POSITIONS)],
            'cookie_page_id' => ['nullable', 'integer', Rule::exists('cms_pages', 'id')],
            'cookie_pages' => ['nullable', 'array'],
            'cookie_pages.*' => ['nullable', 'integer', Rule::exists('cms_pages', 'id')],
            'copy' => ['required', 'array'],
            'copy.*' => ['array'],
            'copy.*.agreement_title' => ['nullable', 'string', 'max:255'],
            'copy.*.cookie_title' => ['nullable', 'string', 'max:255'],
            'copy.*.strictly_necessary' => ['nullable', 'string', 'max:500'],
            'copy.*.basic' => ['nullable', 'string', 'max:500'],
            'copy.*.experience' => ['nullable', 'string', 'max:500'],
            'copy.*.measurements' => ['nullable', 'string', 'max:500'],
            'copy.*.targeting' => ['nullable', 'string', 'max:500'],
        ]);

        $enabled = (bool) $data['enabled'];
        ShopSetting::put('gdpr.enabled', $enabled);
        ShopSetting::put('gdpr.agreement_enabled', $enabled && (bool) $data['agreement_enabled']);
        $agreementPages = $this->pageMap($data['agreement_pages'] ?? [], $data['agreement_page_id'] ?? null);
        $cookiePages = $this->pageMap($data['cookie_pages'] ?? [], $data['cookie_page_id'] ?? null);
        ShopSetting::put('gdpr.agreement_pages', $agreementPages);
        ShopSetting::put('gdpr.agreement_page_id', $agreementPages[Language::defaultCode()] ?? null);
        ShopSetting::put('gdpr.cookie_notice', $enabled && (bool) $data['cookie_notice']);
        ShopSetting::put('gdpr.cookie_position', $data['cookie_position']);
        ShopSetting::put('gdpr.cookie_pages', $cookiePages);
        ShopSetting::put('gdpr.cookie_page_id', $cookiePages[Language::defaultCode()] ?? null);
        ShopSetting::put('gdpr.copy', GdprSettings::normalizeCopy($data['copy']));

        return back();
    }

    /**
     * @param  array<string, mixed>  $pages
     * @return array<string, int>
     */
    private function pageMap(array $pages, mixed $legacyId): array
    {
        $map = GdprSettings::normalizePageMap($pages);
        $legacyId = (int) $legacyId;
        $default = Language::defaultCode();
        if ($legacyId > 0 && ! isset($map[$default])) {
            $map[$default] = $legacyId;
        }

        return $map;
    }
}
