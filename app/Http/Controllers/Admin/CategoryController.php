<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Services\CategoryService;
use App\Domain\Catalog\Support\CategoryCsvSchema;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Localization\Models\Language;
use App\Domain\Security\RasterImageUpload;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CategoryController extends Controller
{
    public function __construct(
        protected CategoryService $categories,
    ) {}

    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: 'all';
        $name = trim($request->string('name')->toString());
        $slug = trim($request->string('slug')->toString());
        $q = trim($request->string('q')->toString());
        $parentId = $request->string('parent_id')->toString();
        $idMin = $request->input('id_min');
        $idMax = $request->input('id_max');
        $productsMin = $request->input('products_min');
        $productsMax = $request->input('products_max');
        $positionMin = $request->input('position_min');
        $positionMax = $request->input('position_max');
        $sort = $request->string('sort')->toString() ?: 'id';
        $dir = $request->string('dir')->toString() === 'asc' ? 'asc' : 'desc';

        if ($name === '' && $q !== '') {
            $name = $q;
        }

        $base = Category::query();
        $counts = [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('is_enabled', true)->count(),
            'inactive' => (clone $base)->where('is_enabled', false)->count(),
        ];

        return Inertia::render('Catalog/Categories/Index', [
            'tree' => $this->categories->adminTree(),
            'counts' => $counts,
            'parentOptions' => $this->categories->parentOptions(),
            'filters' => [
                'status' => $status,
                'name' => $name,
                'slug' => $slug,
                'q' => $q,
                'parent_id' => $parentId,
                'id_min' => $idMin !== null && $idMin !== '' ? (string) $idMin : '',
                'id_max' => $idMax !== null && $idMax !== '' ? (string) $idMax : '',
                'products_min' => $productsMin !== null && $productsMin !== '' ? (string) $productsMin : '',
                'products_max' => $productsMax !== null && $productsMax !== '' ? (string) $productsMax : '',
                'position_min' => $positionMin !== null && $positionMin !== '' ? (string) $positionMin : '',
                'position_max' => $positionMax !== null && $positionMax !== '' ? (string) $positionMax : '',
                'sort' => $sort,
                'dir' => $dir,
            ],
            'can' => [
                'create' => $this->admin()?->hasPermission('catalog.manage') || $this->admin()?->hasPermission('catalog.categories'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Catalog/Categories/Form', $this->inertiaCreateProps($request));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->categories->save($this->validated($request), null, $this->files($request));

        return redirect()->route('admin.categories.index')->with('success', 'Category created.');
    }

    public function edit(Category $category): Response
    {
        return Inertia::render('Catalog/Categories/Form', $this->inertiaEditProps($category));
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $this->categories->save($this->validated($request, $category), $category, $this->files($request));

        return redirect()->route('admin.categories.index')->with('success', 'Category updated.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        try {
            $this->categories->delete($category);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('admin.categories.index')->with('success', 'Category deleted.');
    }

    public function toggle(Category $category): RedirectResponse
    {
        $category->update(['is_enabled' => ! $category->is_enabled]);

        return back()->with('success', $category->is_enabled ? 'Category enabled.' : 'Category disabled. Existing product assignments were kept.');
    }

    public function bulkStatus(Request $request): RedirectResponse
    {
        $request->merge([
            'ids' => array_values(array_filter(array_map('intval', (array) $request->input('ids', [])))),
            'enabled' => $this->toBool($request->input('enabled')),
        ]);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
            'enabled' => ['required', 'boolean'],
        ]);

        $updated = Category::query()
            ->whereIn('id', $data['ids'])
            ->update(['is_enabled' => (bool) $data['enabled']]);

        if ($updated === 0) {
            return back()->with('error', 'No categories were updated.');
        }

        return back()->with('success', $data['enabled']
            ? ($updated === 1 ? 'Category enabled.' : $updated.' categories enabled.')
            : ($updated === 1 ? 'Category disabled. Existing product assignments were kept.' : $updated.' categories disabled. Existing product assignments were kept.'));
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ]);
        $ids = array_values(array_unique(array_map('intval', $data['ids'])));

        $deleted = 0;
        $blocked = 0;
        foreach (Category::query()->whereIn('id', $ids)->get() as $category) {
            try {
                $this->categories->delete($category);
                $deleted++;
            } catch (ValidationException) {
                $blocked++;
            }
        }

        if ($deleted && $blocked) {
            return back()->with('warning', $deleted.' categor'.($deleted === 1 ? 'y' : 'ies').' deleted. '.$blocked.' could not be deleted (children or product assignments).');
        }
        if ($blocked) {
            return back()->with('error', 'None of the selected categories could be deleted. Move or delete child categories and reassign products first.');
        }
        if ($deleted === 0) {
            return back()->with('error', 'No categories were deleted.');
        }

        return back()->with('success', $deleted === 1 ? 'Category deleted.' : $deleted.' categories deleted.');
    }

    public function bulkDuplicate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ]);
        $ids = array_values(array_unique(array_map('intval', $data['ids'])));

        $copied = 0;
        foreach (Category::query()->whereIn('id', $ids)->get() as $category) {
            $this->categories->duplicate($category);
            $copied++;
        }

        if ($copied === 0) {
            return back()->with('error', 'No categories were duplicated.');
        }

        return back()->with('success', $copied === 1 ? 'Category duplicated.' : $copied.' categories duplicated.');
    }

    public function export(Request $request): StreamedResponse
    {
        $delimiter = (string) $request->input('delimiter', CategoryCsvSchema::DEFAULT_DELIMITER);
        $multi = (string) $request->input('multivalue_separator', CategoryCsvSchema::DEFAULT_MULTI_SEPARATOR);
        $csv = $this->categories->exportCsv(Language::defaultCode(), $request, $delimiter, $multi);

        return response()->streamDownload(fn () => print ($csv), 'categories.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function import(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:8192'],
            'language' => ['nullable', 'string', 'max:16'],
            'delimiter' => ['nullable', 'string', 'max:8'],
            'multivalue_separator' => ['nullable', 'string', 'max:8'],
        ]);

        $locale = $data['language'] ?? Language::defaultCode();
        $delimiter = $data['delimiter'] ?? CategoryCsvSchema::DEFAULT_DELIMITER;
        $multi = $data['multivalue_separator'] ?? CategoryCsvSchema::DEFAULT_MULTI_SEPARATOR;

        $count = $this->categories->importCsv(
            $request->file('file')->getContent(),
            $locale,
            $delimiter,
            $multi,
        );

        return back()->with('success', "Imported {$count} categories.");
    }

    public function importSample(Request $request): StreamedResponse
    {
        $delimiter = (string) $request->input('delimiter', CategoryCsvSchema::DEFAULT_DELIMITER);
        $multi = (string) $request->input('multivalue_separator', CategoryCsvSchema::DEFAULT_MULTI_SEPARATOR);
        $csv = $this->categories->sampleCsv($delimiter, $multi);

        return response()->streamDownload(fn () => print ($csv), 'categories-sample.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function reorder(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:2'],
            'ids.*' => ['integer', 'exists:categories,id'],
        ]);
        $this->categories->reorder($data['ids']);

        return back()->with('success', 'Sort order updated.');
    }

    protected function toBool(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || $value === 'true' || $value === 'on') {
            return true;
        }
        if ($value === 0 || $value === '0' || $value === 'false' || $value === 'off') {
            return false;
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public function inertiaCreateProps(Request $request, ?array $parentOptions = null): array
    {
        $parentId = $request->filled('parent_id') ? (int) $request->input('parent_id') : null;

        return [
            ...$this->formProps(null, $parentOptions),
            'initialParentId' => $parentId,
        ];
    }

    /** @return array<string, mixed> */
    public function inertiaEditProps(Category $category, ?array $parentOptions = null): array
    {
        $category->load(['translations', 'parent.translations']);

        return [
            ...$this->formProps($category, $parentOptions),
            'category' => $this->categories->present($category),
        ];
    }

    /** @return array<string, mixed> */
    public function validateCategoryPayload(Request $request, ?Category $category = null): array
    {
        return $this->validated($request, $category);
    }

    /** @return array<string, UploadedFile|null> */
    public function categoryFiles(Request $request): array
    {
        return $this->files($request);
    }

    /** @return array<string, mixed> */
    protected function formProps(?Category $category = null, ?array $parentOptions = null): array
    {
        $groupIds = Category::normalizeCustomerGroupIds($category?->customer_group_ids);

        return [
            'parentOptions' => $parentOptions ?? $this->categories->parentOptions($category),
            'shopUrl' => rtrim((string) config('app.url'), '/'),
            'customerGroups' => CustomerGroup::catalog(true)
                ->map(fn (CustomerGroup $group) => ['id' => $group->id, 'name' => $group->name])
                ->values()
                ->all(),
            'selectedCustomerGroups' => CustomerGroup::query()
                ->whereIn('id', $groupIds)
                ->get(['id', 'name'])
                ->map(fn (CustomerGroup $group) => ['id' => $group->id, 'name' => $group->name])
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Category $category = null): array
    {
        $locale = Language::defaultCode();
        $maxKb = (int) config('wento.upload.max_image_kb');

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:180',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('category_translations', 'slug')
                    ->where(fn ($query) => $query->where('language_code', $locale))
                    ->ignore($category?->translations()->where('language_code', $locale)->value('id')),
            ],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'description' => ['nullable', 'string'],
            'meta_title' => ['nullable', 'string', 'max:60'],
            'meta_description' => ['nullable', 'string', 'max:160'],
            'meta_keywords' => ['nullable', 'string', 'max:255'],
            'canonical_url' => ['nullable', 'string', 'max:500'],
            'translations' => ['nullable', 'array'],
            'translations.*.language_code' => ['required_with:translations', 'string', Rule::exists('languages', 'code')->where('is_enabled', true)],
            'translations.*.name' => ['nullable', 'string', 'max:255'],
            'translations.*.slug' => ['nullable', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'translations.*.description' => ['nullable', 'string'],
            'translations.*.meta_title' => ['nullable', 'string', 'max:60'],
            'translations.*.meta_description' => ['nullable', 'string', 'max:160'],
            'translations.*.meta_keywords' => ['nullable', 'string', 'max:255'],
            'translations.*.canonical_url' => ['nullable', 'string', 'max:500'],
            'is_enabled' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
            'customer_group_ids' => ['nullable', 'array'],
            'customer_group_ids.*' => ['integer', 'exists:customer_groups,id'],
            'remove_image' => ['nullable', 'boolean'],
            'remove_thumbnail' => ['nullable', 'boolean'],
            'image' => RasterImageUpload::rules($maxKb),
            'thumbnail' => RasterImageUpload::rules($maxKb),
        ]);

        $data['is_enabled'] = (bool) ($data['is_enabled'] ?? true);
        $data['parent_id'] = ($data['parent_id'] ?? '') === '' ? null : (int) $data['parent_id'];
        $data['customer_group_ids'] = Category::normalizeCustomerGroupIds($data['customer_group_ids'] ?? null);
        $data['translations'] = $this->normalizeTranslations($request, $data);
        $default = $data['translations'][$locale] ?? null;
        if ($default) {
            $data['name'] = $default['name'];
            $data['slug'] = $default['slug'];
            $data['description'] = $default['description'];
            $data['meta_title'] = $default['meta_title'];
            $data['meta_description'] = $default['meta_description'];
            $data['meta_keywords'] = $default['meta_keywords'];
            $data['canonical_url'] = $default['canonical_url'];
        }
        $data['language_code'] = $locale;

        return $data;
    }

    /** @return array<string, array<string, mixed>> */
    protected function normalizeTranslations(Request $request, array $data): array
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
            $rows[$language] = [
                'language_code' => $language,
                'name' => trim((string) ($row['name'] ?? '')),
                'slug' => trim((string) ($row['slug'] ?? '')),
                'description' => $row['description'] ?? null,
                'meta_title' => trim((string) ($row['meta_title'] ?? '')),
                'meta_description' => trim((string) ($row['meta_description'] ?? '')),
                'meta_keywords' => trim((string) ($row['meta_keywords'] ?? '')),
                'canonical_url' => trim((string) ($row['canonical_url'] ?? '')),
            ];
        }

        if ($rows === []) {
            $rows[$default] = [
                'language_code' => $default,
                'name' => trim((string) ($data['name'] ?? '')),
                'slug' => trim((string) ($data['slug'] ?? '')),
                'description' => $data['description'] ?? null,
                'meta_title' => trim((string) ($data['meta_title'] ?? '')),
                'meta_description' => trim((string) ($data['meta_description'] ?? '')),
                'meta_keywords' => trim((string) ($data['meta_keywords'] ?? '')),
                'canonical_url' => trim((string) ($data['canonical_url'] ?? '')),
            ];
        }

        if (($rows[$default]['name'] ?? '') === '') {
            $fallbackName = trim((string) ($data['name'] ?? ''));
            if ($fallbackName !== '') {
                $rows[$default]['name'] = $fallbackName;
            }
        }

        if (($rows[$default]['name'] ?? '') === '') {
            throw ValidationException::withMessages([
                "translations.$default.name" => 'Category name is required for the default language.',
            ]);
        }

        foreach ($rows as $language => $row) {
            $hasContent = collect($row)
                ->except(['language_code'])
                ->contains(fn ($value) => is_string($value) ? trim($value) !== '' : $value !== null);

            if ($language !== $default && $hasContent && $row['name'] === '') {
                throw ValidationException::withMessages([
                    "translations.$language.name" => 'Category name is required when saving a translation.',
                ]);
            }
        }

        return $rows;
    }

    /** @return array<string, UploadedFile|null> */
    protected function files(Request $request): array
    {
        return [
            'image' => $request->file('image'),
            'thumbnail' => $request->file('thumbnail'),
        ];
    }

    protected function admin(): ?Admin
    {
        $user = auth('admin')->user();

        return $user instanceof Admin ? $user : null;
    }
}
