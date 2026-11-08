<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Localization\Models\Language;
use App\Domain\Localization\PackageI18n;
use App\Domain\Localization\UiI18n;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UiTranslationController extends Controller
{
    public function index(Request $request): Response
    {
        UiI18n::sync();

        $languages = Language::query()->enabled()->orderBy('position')->orderBy('name')->get(['code', 'name', 'is_default']);
        $locale = $request->string('language')->toString() ?: app()->getLocale();
        if (! $languages->contains('code', $locale)) {
            $locale = Language::defaultCode();
        }

        $surface = $request->string('surface')->toString() ?: 'admin';
        if (! in_array($surface, ['admin', 'storefront', 'module', 'theme'], true)) {
            $surface = 'admin';
        }

        $pages = UiI18n::pageSummaries($locale, $surface);
        $page = $request->string('page')->toString();
        if ($page === '' || ! collect($pages)->contains('id', $page)) {
            $page = $pages[0]['id'] ?? '';
        }

        $q = trim($request->string('q')->toString());
        $strings = $page === '' ? [] : UiI18n::pageStrings($locale, $page);
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $strings = array_values(array_filter($strings, function (array $row) use ($needle) {
                return str_contains(mb_strtolower($row['source']), $needle)
                    || str_contains(mb_strtolower($row['text']), $needle)
                    || str_contains(mb_strtolower($row['key']), $needle);
            }));
        }

        $surfaceCounts = [
            'admin' => count(UiI18n::pageSummaries($locale, 'admin')),
            'storefront' => count(UiI18n::pageSummaries($locale, 'storefront')),
            'module' => count(UiI18n::pageSummaries($locale, 'module')),
            'theme' => count(UiI18n::pageSummaries($locale, 'theme')),
        ];

        return Inertia::render('Localization/Translations/Index', [
            'languages' => $languages,
            'locale' => $locale,
            'surface' => $surface,
            'page' => $page,
            'pages' => $pages,
            'strings' => $strings,
            'filters' => ['q' => $q],
            'surface_counts' => $surfaceCounts,
            'packages' => PackageI18n::packages(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'language' => ['required', 'string', Rule::exists('languages', 'code')->where('is_enabled', true)],
            'page' => ['required', 'string', 'max:80'],
            'strings' => ['required', 'array'],
            'strings.*' => ['nullable', 'string'],
        ]);

        UiI18n::savePage($data['language'], $data['page'], $data['strings']);
        $request->session()->put('admin_locale', $data['language']);

        $surface = match (true) {
            str_starts_with($data['page'], 'storefront.') => 'storefront',
            str_starts_with($data['page'], 'module.') => 'module',
            str_starts_with($data['page'], 'theme.') => 'theme',
            default => 'admin',
        };

        return redirect()
            ->route('admin.localization.translations.index', [
                'language' => $data['language'],
                'page' => $data['page'],
                'surface' => $surface,
            ])
            ->with('success', 'Translations saved.');
    }
}
