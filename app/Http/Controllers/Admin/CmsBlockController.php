<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Cms\Models\CmsBlock;
use App\Domain\Cms\Support\CmsBlockCsvSchema;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CmsBlockController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: 'all';
        $location = $request->string('location')->toString() ?: 'all';
        $q = trim($request->string('q')->toString());
        $title = trim($request->string('title')->toString());
        $code = trim($request->string('code')->toString());
        $idMin = $request->input('id_min');
        $idMax = $request->input('id_max');
        $positionMin = $request->input('position_min');
        $positionMax = $request->input('position_max');
        $sort = $request->string('sort')->toString() ?: 'position';
        $dir = $request->string('dir')->toString() === 'desc' ? 'desc' : 'asc';
        if (! in_array($sort, ['id', 'title', 'code', 'location', 'position', 'is_enabled'], true)) {
            $sort = 'position';
        }

        $base = CmsBlock::query();

        $counts = [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('is_enabled', true)->count(),
            'inactive' => (clone $base)->where('is_enabled', false)->count(),
        ];

        $blocks = CmsBlock::query()
            ->when($status === 'active', fn ($query) => $query->where('is_enabled', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_enabled', false))
            ->when($location !== '' && $location !== 'all', fn ($query) => $query->where('location', $location))
            ->when($q !== '', fn ($query) => $query->where(function ($inner) use ($q) {
                $inner->where('title', 'like', "%{$q}%")
                    ->orWhere('code', 'like', "%{$q}%")
                    ->orWhere('content', 'like', "%{$q}%");
            }))
            ->when($title !== '', fn ($query) => $query->where('title', 'like', "%{$title}%"))
            ->when($code !== '', fn ($query) => $query->where('code', 'like', "%{$code}%"))
            ->when($idMin !== null && $idMin !== '', fn ($query) => $query->where('id', '>=', (int) $idMin))
            ->when($idMax !== null && $idMax !== '', fn ($query) => $query->where('id', '<=', (int) $idMax))
            ->when($positionMin !== null && $positionMin !== '', fn ($query) => $query->where('position', '>=', (int) $positionMin))
            ->when($positionMax !== null && $positionMax !== '', fn ($query) => $query->where('position', '<=', (int) $positionMax))
            ->orderBy($sort, $dir)
            ->when($sort !== 'id', fn ($query) => $query->orderBy('id', $dir))
            ->adminPaginate()
            ->through(fn (CmsBlock $block) => [
                'id' => $block->id,
                'title' => $block->title,
                'code' => $block->code,
                'location' => $block->location,
                'location_label' => CmsBlock::LOCATIONS[$block->location] ?? $block->location,
                'is_enabled' => $block->is_enabled,
                'position' => $block->position,
                'actions' => [
                    'edit' => true,
                    'delete' => true,
                ],
            ]);

        return Inertia::render('Cms/Blocks/Index', [
            'blocks' => $blocks,
            'counts' => $counts,
            'locations' => CmsBlock::LOCATIONS,
            'filters' => [
                'status' => $status,
                'location' => $location,
                'q' => $q,
                'title' => $title,
                'code' => $code,
                'id_min' => $idMin !== null && $idMin !== '' ? (string) $idMin : '',
                'id_max' => $idMax !== null && $idMax !== '' ? (string) $idMax : '',
                'position_min' => $positionMin !== null && $positionMin !== '' ? (string) $positionMin : '',
                'position_max' => $positionMax !== null && $positionMax !== '' ? (string) $positionMax : '',
                'sort' => $sort,
                'dir' => $dir,
            ],
        ]);
    }

    public function export()
    {
        return $this->csvDownload('cms-blocks.csv', CmsBlockCsvSchema::exportCsv());
    }

    public function importSample()
    {
        return $this->csvDownload('cms-blocks-sample.csv', CmsBlockCsvSchema::sampleCsv());
    }

    public function import(Request $request): RedirectResponse
    {
        return $this->csvImport($request, CmsBlockCsvSchema::import(...), 'CMS blocks');
    }

    public function create(): Response
    {
        return Inertia::render('Cms/Blocks/Form', $this->formProps());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $block = CmsBlock::query()->create($this->payload($data));
        $this->syncTranslations($block, $data['translations'] ?? []);

        return redirect()->route('admin.blocks.index')->with('success', 'Block created.');
    }

    public function edit(CmsBlock $block): Response
    {
        $block->load('translations');

        return Inertia::render('Cms/Blocks/Form', [
            ...$this->formProps(),
            'block' => [
                'id' => $block->id,
                'title' => $block->title,
                'code' => $block->code,
                'location' => $block->location,
                'content' => $block->content ?? '',
                'is_enabled' => $block->is_enabled,
                'position' => $block->position,
                'translations' => $block->translations
                    ->mapWithKeys(fn ($row) => [$row->language_code => [
                        'language_code' => $row->language_code,
                        'title' => $row->title,
                        'content' => $row->content,
                    ]])
                    ->all(),
            ],
        ]);
    }

    public function update(Request $request, CmsBlock $block): RedirectResponse
    {
        $data = $this->validated($request, $block);
        $block->update($this->payload($data, $block->id));
        $this->syncTranslations($block, $data['translations'] ?? []);

        return redirect()->route('admin.blocks.index')->with('success', 'Block updated.');
    }

    public function destroy(CmsBlock $block): RedirectResponse
    {
        $block->delete();

        return redirect()->route('admin.blocks.index')->with('success', 'Block deleted.');
    }

    public function toggle(CmsBlock $block): RedirectResponse
    {
        $block->update(['is_enabled' => ! $block->is_enabled]);

        return back()->with('success', $block->is_enabled ? 'Block enabled.' : 'Block disabled.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:cms_blocks,id'],
        ]);

        CmsBlock::query()->whereIn('id', $data['ids'])->delete();

        return back()->with('success', 'Selected blocks deleted.');
    }

    /** @return array<string, mixed> */
    protected function formProps(): array
    {
        return [
            'locations' => collect(CmsBlock::LOCATIONS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?CmsBlock $block = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'code' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('cms_blocks', 'code')->ignore($block?->id),
            ],
            'location' => ['required', 'string', 'max:64'],
            'content' => ['nullable', 'string'],
            'is_enabled' => ['required', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
            'translations' => ['nullable', 'array'],
            'translations.*.language_code' => ['required_with:translations', 'string', 'max:8'],
            'translations.*.title' => ['nullable', 'string', 'max:190'],
            'translations.*.content' => ['nullable', 'string'],
        ]);

        $data['is_enabled'] = (bool) $data['is_enabled'];
        $data['content'] = ($data['content'] ?? '') !== '' ? $data['content'] : null;
        $data['position'] = (int) ($data['position'] ?? 0);
        $data['code'] = ($data['code'] ?? '') !== '' ? Str::slug($data['code'], '_') : '';

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    protected function payload(array $data, ?int $ignoreId = null): array
    {
        return [
            'title' => $data['title'],
            'code' => $this->uniqueCode($data['code'] ?: $data['title'], $ignoreId),
            'location' => $data['location'],
            'content' => $data['content'],
            'is_enabled' => $data['is_enabled'],
            'position' => $data['position'],
        ];
    }

    protected function uniqueCode(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source, '_') ?: 'block';
        $code = $base;
        $i = 2;

        while (CmsBlock::query()
            ->where('code', $code)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists()) {
            $code = "{$base}_{$i}";
            $i++;
        }

        return $code;
    }

    /**
     * @param  array<string, array<string, mixed>>|list<array<string, mixed>>  $rows
     */
    protected function syncTranslations(CmsBlock $block, array $rows): void
    {
        $default = \App\Domain\Localization\Models\Language::defaultCode();
        foreach ($rows as $key => $row) {
            if (! is_array($row)) {
                continue;
            }
            $language = (string) ($row['language_code'] ?? $key);
            if ($language === '') {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            $content = $row['content'] ?? null;
            $content = is_string($content) && $content !== '' ? $content : null;
            if ($title === '' && $content === null) {
                $block->translations()->where('language_code', $language)->delete();

                continue;
            }
            $block->translations()->updateOrCreate(
                ['language_code' => $language],
                ['title' => $title !== '' ? $title : null, 'content' => $content],
            );
            if ($language === $default) {
                $block->update([
                    'title' => $title !== '' ? $title : $block->title,
                    'content' => $content ?? $block->content,
                ]);
            }
        }
    }
}
