<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Services\UrlRewriteService;
use App\Domain\Cms\CmsPageTemplates;
use App\Domain\Cms\Models\CmsCategory;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Cms\Models\CmsPageTranslation;
use App\Domain\Cms\Support\CmsPageCsvSchema;
use App\Domain\Localization\Models\Language;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CmsPageController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: 'all';
        $q = trim($request->string('q')->toString());
        $title = trim($request->string('title')->toString());
        $slug = trim($request->string('slug')->toString());
        $category = trim($request->string('category')->toString());
        $visible = trim($request->string('visible')->toString());
        $idMin = $request->input('id_min');
        $idMax = $request->input('id_max');
        $sort = $request->string('sort')->toString() ?: 'updated_at';
        $dir = $request->string('dir')->toString() === 'asc' ? 'asc' : 'desc';
        if (! in_array($sort, ['id', 'title', 'slug', 'category', 'status', 'visible', 'updated_at'], true)) {
            $sort = 'updated_at';
        }
        $locale = Language::defaultCode();
        $current = Language::currentCode();
        $searchLocales = array_values(array_unique([$current, $locale]));

        $base = CmsPage::query();

        $counts = [
            'all' => (clone $base)->count(),
            'published' => (clone $base)->where('status', CmsPage::STATUS_PUBLISHED)->count(),
            'draft' => (clone $base)->where('status', CmsPage::STATUS_DRAFT)->count(),
            'archived' => (clone $base)->where('status', CmsPage::STATUS_ARCHIVED)->count(),
        ];

        $pages = CmsPage::query()
            ->with(['translations', 'category'])
            ->when(in_array($status, array_keys(CmsPage::STATUSES), true), fn ($query) => $query->where('status', $status))
            ->when($q !== '', function ($query) use ($q, $searchLocales) {
                $query->whereHas('translations', function ($t) use ($q, $searchLocales) {
                    $t->whereIn('language_code', $searchLocales)
                        ->where(function ($inner) use ($q) {
                            $inner->where('title', 'like', "%{$q}%")
                                ->orWhere('slug', 'like', "%{$q}%")
                                ->orWhere('meta_title', 'like', "%{$q}%");
                        });
                });
            })
            ->when($title !== '', function ($query) use ($title, $searchLocales) {
                $query->whereHas('translations', fn ($t) => $t->whereIn('language_code', $searchLocales)->where('title', 'like', "%{$title}%"));
            })
            ->when($slug !== '', function ($query) use ($slug, $searchLocales) {
                $query->whereHas('translations', fn ($t) => $t->whereIn('language_code', $searchLocales)->where('slug', 'like', "%{$slug}%"));
            })
            ->when($category !== '' && $category !== 'all', function ($query) use ($category) {
                if ($category === 'none') {
                    $query->whereNull('cms_category_id');
                } else {
                    $query->where('cms_category_id', (int) $category);
                }
            })
            ->when($visible === 'visible', fn ($query) => $query->where('is_visible', true))
            ->when($visible === 'hidden', fn ($query) => $query->where('is_visible', false))
            ->when($idMin !== null && $idMin !== '', fn ($query) => $query->where('id', '>=', (int) $idMin))
            ->when($idMax !== null && $idMax !== '', fn ($query) => $query->where('id', '<=', (int) $idMax))
            ->when($sort === 'title', fn ($query) => $query->orderBy(
                CmsPageTranslation::query()->select('title')
                    ->whereColumn('cms_page_id', 'cms_pages.id')
                    ->whereIn('language_code', $searchLocales)
                    ->orderByRaw('language_code = ? desc', [$current])
                    ->limit(1),
                $dir
            ))
            ->when($sort === 'slug', fn ($query) => $query->orderBy(
                CmsPageTranslation::query()->select('slug')
                    ->whereColumn('cms_page_id', 'cms_pages.id')
                    ->whereIn('language_code', $searchLocales)
                    ->orderByRaw('language_code = ? desc', [$current])
                    ->limit(1),
                $dir
            ))
            ->when($sort === 'category', fn ($query) => $query->orderBy('cms_category_id', $dir))
            ->when($sort === 'status', fn ($query) => $query->orderBy('status', $dir))
            ->when($sort === 'visible', fn ($query) => $query->orderBy('is_visible', $dir))
            ->when($sort === 'id', fn ($query) => $query->orderBy('id', $dir))
            ->when($sort === 'updated_at', fn ($query) => $query->orderBy('updated_at', $dir))
            ->when($sort !== 'id', fn ($query) => $query->orderBy('id', $dir))
            ->adminPaginate()
            ->through(function (CmsPage $page) {
                $t = $page->translation();

                return [
                    'id' => $page->id,
                    'title' => $t?->title ?? 'Untitled',
                    'slug' => $t?->slug ?? '',
                    'status' => $page->status,
                    'status_label' => CmsPage::STATUSES[$page->status] ?? $page->status,
                    'is_visible' => $page->is_visible,
                    'is_enabled' => $page->is_enabled,
                    'category' => $page->category?->name,
                    'category_slug' => $page->category?->slug,
                    'updated_at' => optional($page->updated_at)->format('d M Y, g:i A'),
                    'actions' => [
                        'edit' => true,
                        'delete' => true,
                    ],
                ];
            });

        return Inertia::render('Cms/Index', [
            'pages' => $pages,
            'counts' => $counts,
            'categories' => CmsCategory::query()->orderBy('position')->orderBy('name')->get(['id', 'name']),
            'categoryCatalog' => CmsCategory::query()
                ->withCount('pages')
                ->orderBy('position')
                ->orderBy('name')
                ->get()
                ->map(fn (CmsCategory $cat) => [
                    'id' => $cat->id,
                    'name' => $cat->name,
                    'slug' => $cat->slug,
                    'description' => $cat->description,
                    'is_enabled' => $cat->is_enabled,
                    'pages_count' => $cat->pages_count,
                ]),
            'filters' => [
                'status' => $status,
                'q' => $q,
                'title' => $title,
                'slug' => $slug,
                'category' => $category ?: 'all',
                'visible' => $visible ?: 'all',
                'id_min' => $idMin !== null && $idMin !== '' ? (string) $idMin : '',
                'id_max' => $idMax !== null && $idMax !== '' ? (string) $idMax : '',
                'sort' => $sort,
                'dir' => $dir,
            ],
        ]);
    }

    public function export()
    {
        return $this->csvDownload('cms-pages.csv', CmsPageCsvSchema::exportCsv());
    }

    public function importSample()
    {
        return $this->csvDownload('cms-pages-sample.csv', CmsPageCsvSchema::sampleCsv());
    }

    public function import(Request $request): RedirectResponse
    {
        return $this->csvImport($request, CmsPageCsvSchema::import(...), 'CMS pages');
    }

    public function create(): Response
    {
        return Inertia::render('Cms/Form', $this->formProps());
    }

    public function store(Request $request, UrlRewriteService $urls): RedirectResponse
    {
        $data = $this->validated($request);

        $page = DB::transaction(function () use ($data, $urls) {
            $page = CmsPage::query()->create($this->pageAttributes($data));
            $this->syncTranslations($page, $data['translations'], $urls);

            return $page;
        });

        return redirect()->route('admin.cms.edit', $page)->with('success', 'Page created.');
    }

    public function edit(CmsPage $cmsPage): Response
    {
        $cmsPage->load(['translations', 'category']);

        return Inertia::render('Cms/Form', $this->formProps($cmsPage));
    }

    public function update(Request $request, CmsPage $cmsPage, UrlRewriteService $urls): RedirectResponse
    {
        $data = $this->validated($request, $cmsPage);

        DB::transaction(function () use ($data, $cmsPage, $urls) {
            $cmsPage->update($this->pageAttributes($data));
            $this->syncTranslations($cmsPage, $data['translations'], $urls);
        });

        return back()->with('success', 'Page saved.');
    }

    public function destroy(CmsPage $cmsPage): RedirectResponse
    {
        $cmsPage->delete();

        return redirect()->route('admin.cms.index')->with('success', 'Page deleted.');
    }

    public function toggle(CmsPage $cmsPage): RedirectResponse
    {
        $cmsPage->update(['is_enabled' => ! $cmsPage->is_enabled]);

        return back()->with('success', $cmsPage->is_enabled ? 'Page enabled.' : 'Page disabled.');
    }

    public function toggleVisibility(CmsPage $cmsPage): RedirectResponse
    {
        $cmsPage->update(['is_visible' => ! $cmsPage->is_visible]);

        return back()->with('success', $cmsPage->is_visible ? 'Page set to visible.' : 'Page set to hidden.');
    }

    public function bulkDelete(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:cms_pages,id'],
        ]);

        CmsPage::query()
            ->whereIn('id', $data['ids'])
            ->delete();

        return back()->with('success', 'Selected pages deleted.');
    }

    private function formProps(?CmsPage $page = null): array
    {
        $locale = Language::defaultCode();
        $t = $page?->translation($locale);
        $translations = [];
        foreach (Language::enabledList() as $language) {
            $row = $page?->translations?->firstWhere('language_code', $language->code);
            $translations[$language->code] = [
                'language_code' => $language->code,
                'title' => $row?->title ?? '',
                'slug' => $row?->slug ?? '',
                'short_description' => $row?->short_description ?? '',
                'body' => $row?->body ?? '',
                'meta_title' => $row?->meta_title ?? '',
                'meta_description' => $row?->meta_description ?? '',
                'meta_keywords' => $row?->meta_keywords ?? '',
            ];
        }

        return [
            'page' => $page ? [
                'id' => $page->id,
                'title' => $t?->title ?? '',
                'slug' => $t?->slug ?? '',
                'short_description' => $t?->short_description ?? '',
                'body' => $t?->body ?? '',
                'meta_title' => $t?->meta_title ?? '',
                'meta_description' => $t?->meta_description ?? '',
                'meta_keywords' => $t?->meta_keywords ?? '',
                'translations' => $translations,
                'status' => $page->status,
                'is_visible' => $page->is_visible,
                'is_enabled' => $page->is_enabled,
                'template' => CmsPageTemplates::normalize($page->template),
                'cms_category_id' => $page->cms_category_id,
                'canonical_url' => $page->canonical_url ?? '',
                'robots' => $page->robots ?: 'index,follow',
                'preview_path' => $t?->slug ? '/'.$t->slug : '',
            ] : [
                'translations' => $translations,
            ],
            'options' => [
                'statuses' => collect(CmsPage::STATUSES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
                'robots' => collect(CmsPage::ROBOTS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
                'templates' => CmsPageTemplates::options(),
                'template_bodies' => collect(CmsPageTemplates::codes())->mapWithKeys(fn ($code) => [$code => CmsPageTemplates::body($code)]),
                'categories' => CmsCategory::query()->where('is_enabled', true)->orderBy('position')->get(['id', 'name']),
            ],
        ];
    }

    private function validated(Request $request, ?CmsPage $page = null): array
    {
        // Legacy seed used "default"; map unknown codes before Rule::in.
        $request->merge([
            'template' => CmsPageTemplates::normalize($request->input('template')),
        ]);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'short_description' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'meta_keywords' => ['nullable', 'string', 'max:255'],
            'translations' => ['nullable', 'array'],
            'translations.*.language_code' => ['required_with:translations', 'string', Rule::exists('languages', 'code')->where('is_enabled', true)],
            'translations.*.title' => ['nullable', 'string', 'max:255'],
            'translations.*.slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'translations.*.short_description' => ['nullable', 'string', 'max:255'],
            'translations.*.body' => ['nullable', 'string'],
            'translations.*.meta_title' => ['nullable', 'string', 'max:255'],
            'translations.*.meta_description' => ['nullable', 'string', 'max:320'],
            'translations.*.meta_keywords' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(array_keys(CmsPage::STATUSES))],
            'is_visible' => ['sometimes', 'boolean'],
            'is_enabled' => ['sometimes', 'boolean'],
            'template' => ['required', Rule::in(CmsPageTemplates::codes())],
            'cms_category_id' => ['nullable', 'integer', 'exists:cms_categories,id'],
            'canonical_url' => ['nullable', 'string', 'max:500'],
            'robots' => ['required', Rule::in(array_keys(CmsPage::ROBOTS))],
        ]);

        $data['translations'] = $this->normalizeTranslations($data, $page);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array<string, mixed>>
     */
    private function normalizeTranslations(array $data, ?CmsPage $page = null): array
    {
        $default = Language::defaultCode();
        $rows = [];

        foreach ((array) ($data['translations'] ?? []) as $key => $row) {
            if (! is_array($row)) {
                continue;
            }
            $language = (string) ($row['language_code'] ?? $key);
            if ($language === '') {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            $slug = trim((string) ($row['slug'] ?? ''));
            $rows[$language] = [
                'language_code' => $language,
                'title' => $title,
                'slug' => $slug,
                'short_description' => $row['short_description'] ?? null,
                'body' => $row['body'] ?? '',
                'meta_title' => trim((string) ($row['meta_title'] ?? '')),
                'meta_description' => $row['meta_description'] ?? null,
                'meta_keywords' => is_array($row['meta_keywords'] ?? null)
                    ? implode(', ', array_filter(array_map('trim', $row['meta_keywords'])))
                    : trim((string) ($row['meta_keywords'] ?? '')),
            ];
        }

        if ($rows === []) {
            $title = trim((string) ($data['title'] ?? ''));
            $slug = trim((string) ($data['slug'] ?? ''));
            $rows[$default] = [
                'language_code' => $default,
                'title' => $title,
                'slug' => $slug,
                'short_description' => $data['short_description'] ?? null,
                'body' => $data['body'] ?? '',
                'meta_title' => trim((string) ($data['meta_title'] ?? '')),
                'meta_description' => $data['meta_description'] ?? null,
                'meta_keywords' => trim((string) ($data['meta_keywords'] ?? '')),
            ];
        }

        if (($rows[$default]['title'] ?? '') === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                "translations.$default.title" => 'Page title is required for the default language.',
            ]);
        }

        if (($rows[$default]['slug'] ?? '') === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                "translations.$default.slug" => 'URL slug is required for the default language.',
            ]);
        }

        foreach ($rows as $language => $row) {
            $hasContent = collect($row)
                ->except(['language_code'])
                ->contains(fn ($value) => is_string($value) ? trim($value) !== '' : $value !== null && $value !== '');

            if ($language !== $default && $hasContent && $row['title'] === '') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "translations.$language.title" => 'Page title is required when saving a translation.',
                ]);
            }

            if ($row['title'] === '') {
                continue;
            }

            if ($row['slug'] === '') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "translations.$language.slug" => 'URL slug is required when saving a translation.',
                ]);
            }
            $slug = $row['slug'];
            $ignoreId = $page?->translations()->where('language_code', $language)->value('id');
            $exists = DB::table('cms_page_translations')
                ->where('language_code', $language)
                ->where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists();
            if ($exists) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "translations.$language.slug" => 'That URL slug is already used for this language.',
                ]);
            }
            $rows[$language]['slug'] = $slug;
            if (($rows[$language]['meta_title'] ?? '') === '') {
                $rows[$language]['meta_title'] = $row['title'];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, array<string, mixed>>  $translations
     */
    private function syncTranslations(CmsPage $page, array $translations, UrlRewriteService $urls): void
    {
        $keep = [];
        foreach ($translations as $language => $row) {
            if (($row['title'] ?? '') === '') {
                continue;
            }
            $keep[] = $language;
            $page->translations()->updateOrCreate(
                ['language_code' => $language],
                [
                    'title' => $row['title'],
                    'slug' => $row['slug'],
                    'short_description' => $row['short_description'] ?? null,
                    'body' => $row['body'] ?? '',
                    'meta_title' => $row['meta_title'] ?: $row['title'],
                    'meta_description' => $row['meta_description'] ?? null,
                    'meta_keywords' => $row['meta_keywords'] ?? null,
                ]
            );
            $urls->sync('cms', $page->id, $language, $row['slug']);
        }

        if ($keep !== []) {
            $page->translations()->whereNotIn('language_code', $keep)->delete();
        }
    }

    private function pageAttributes(array $data): array
    {
        return [
            'status' => $data['status'],
            'is_visible' => (bool) ($data['is_visible'] ?? true),
            'is_enabled' => (bool) ($data['is_enabled'] ?? true),
            'template' => $data['template'],
            'cms_category_id' => $data['cms_category_id'] ?? null,
            'canonical_url' => $data['canonical_url'] ?: null,
            'robots' => $data['robots'],
        ];
    }
}
