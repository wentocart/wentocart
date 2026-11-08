<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Services\UrlRewriteService;
use App\Domain\Settings\Models\FileChangeLog;
use App\Domain\Settings\ShopSetting;
use App\Domain\Settings\ShopTools;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UrlSettingsController extends Controller
{
    public function edit(): Response
    {
        $schemas = [];
        foreach (ShopTools::URL_TYPES as $type) {
            $schemas[$type] = ShopTools::urlSchema($type);
        }

        return Inertia::render('Settings/Urls', [
            'schemas' => $schemas,
            'suffix' => ShopTools::urlSuffix(),
            'defaults' => ShopTools::URL_SCHEMAS,
            'tokens' => [
                ['token' => '{id}', 'hint' => 'Entity ID (product, category, CMS, brand, or supplier)'],
                ['token' => '{rewrite}', 'hint' => 'Language slug / SEO slug'],
                ['token' => '{id_category}', 'hint' => 'Default category ID (products)'],
                ['token' => '{category}', 'hint' => 'Default category slug (products)'],
                ['token' => '{id_parent}', 'hint' => 'Parent category ID (categories)'],
                ['token' => '{parent}', 'hint' => 'Parent category slug (categories)'],
                ['token' => '{brand}', 'hint' => 'Brand slug'],
                ['token' => '{supplier}', 'hint' => 'Supplier slug'],
                ['token' => '{ipa}', 'hint' => 'Combination / variant ID (products). Also {combination}. Empty on the parent product URL; storefront uses ?ipa= for shareable combination links.'],
            ],
        ]);
    }

    public function update(Request $request, UrlRewriteService $urls): RedirectResponse
    {
        $data = $request->validate([
            'schemas' => ['required', 'array'],
            'schemas.product' => ['required', 'string', 'max:180', $this->schemaRule()],
            'schemas.category' => ['required', 'string', 'max:180', $this->schemaRule()],
            'schemas.cms' => ['required', 'string', 'max:180', $this->schemaRule()],
            'schemas.brand' => ['required', 'string', 'max:180', $this->schemaRule()],
            'schemas.supplier' => ['required', 'string', 'max:180', $this->schemaRule()],
            'suffix' => ['nullable', Rule::in(['', '.html'])],
        ]);

        foreach (ShopTools::URL_TYPES as $type) {
            ShopSetting::put('url.schema.'.$type, $data['schemas'][$type]);
        }
        ShopSetting::put('url.suffix', $data['suffix'] ?? '');
        $count = $urls->regenerate(null);
        FileChangeLog::record('updated', 'urls/schemas');

        return back()->with('success', 'URL schemas saved. Updated '.$count.' storefront URLs.');
    }

    public function regenerate(Request $request, UrlRewriteService $urls): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['nullable', 'in:product,category,cms,brand,supplier'],
        ]);

        $count = $urls->regenerate($data['type'] ?? null);
        FileChangeLog::record('regenerated', 'urls/'.($data['type'] ?? 'all'));

        return back()->with('success', 'Regenerated '.$count.' URLs.');
    }

    protected function schemaRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $value = (string) $value;
            if (! str_contains($value, '{id}') && ! str_contains($value, '{rewrite}')) {
                $fail('Schema must include {id} or {rewrite}.');
            }
        };
    }
}
