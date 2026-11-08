<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Cms\Models\CmsCategory;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Cms\Support\CmsCategoryCsvSchema;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CmsCategoryController extends Controller
{
    public function export()
    {
        return $this->csvDownload('cms-categories.csv', CmsCategoryCsvSchema::exportCsv());
    }

    public function importSample()
    {
        return $this->csvDownload('cms-categories-sample.csv', CmsCategoryCsvSchema::sampleCsv());
    }

    public function import(Request $request): RedirectResponse
    {
        return $this->csvImport($request, CmsCategoryCsvSchema::import(...), 'CMS categories');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        CmsCategory::query()->create([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'is_enabled' => $request->boolean('is_enabled', true),
            'position' => (int) CmsCategory::query()->max('position') + 1,
        ]);

        return back()->with('success', 'CMS category created.');
    }

    public function update(Request $request, CmsCategory $cmsCategory): RedirectResponse
    {
        $data = $this->validated($request, $cmsCategory);

        $cmsCategory->update([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'is_enabled' => $request->boolean('is_enabled', true),
        ]);

        return back()->with('success', 'CMS category updated.');
    }

    public function destroy(Request $request, CmsCategory $cmsCategory): RedirectResponse
    {
        $pageCount = $cmsCategory->pages()->count();

        if ($pageCount === 0) {
            $cmsCategory->delete();

            return back()->with('success', 'CMS category deleted.');
        }

        $otherCategories = CmsCategory::query()
            ->where('id', '!=', $cmsCategory->id)
            ->orderBy('position')
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($otherCategories->isEmpty()) {
            return back()->with(
                'error',
                "Cannot delete “{$cmsCategory->name}”. It still has {$pageCount} page(s) and there is no other category to move them to. Delete or reassign those pages first."
            );
        }

        $data = $request->validate([
            'move_to_category_id' => [
                'required',
                'integer',
                Rule::exists('cms_categories', 'id')->where(fn ($q) => $q->where('id', '!=', $cmsCategory->id)),
            ],
        ], [
            'move_to_category_id.required' => 'Choose another category to move the pages into before deleting.',
        ]);

        DB::transaction(function () use ($cmsCategory, $data) {
            CmsPage::query()
                ->where('cms_category_id', $cmsCategory->id)
                ->update(['cms_category_id' => $data['move_to_category_id']]);

            $cmsCategory->delete();
        });

        return back()->with('success', 'Pages moved and category deleted.');
    }

    private function validated(Request $request, ?CmsCategory $category = null): array
    {
        if ($request->input('slug') === '') {
            $request->merge(['slug' => null]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);

        $slug = Str::slug((string) ($data['slug'] ?: $data['name']));
        $request->merge(['slug' => $slug]);

        $request->validate([
            'slug' => [
                'required',
                'string',
                'max:64',
                Rule::unique('cms_categories', 'slug')->ignore($category?->id),
            ],
        ]);

        $data['slug'] = $slug;

        return $data;
    }
}
