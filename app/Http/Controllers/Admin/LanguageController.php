<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Localization\Models\Language;
use App\Domain\Localization\Support\LanguageCsvSchema;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LanguageController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: 'all';
        $q = trim($request->string('q')->toString());
        $name = trim($request->string('name')->toString());
        $code = trim($request->string('code')->toString());
        $idMin = $request->input('id_min');
        $idMax = $request->input('id_max');
        $sort = $request->string('sort')->toString() ?: 'position';
        $dir = $request->string('dir')->toString() === 'desc' ? 'desc' : 'asc';
        $sortColumns = [
            'id' => 'id',
            'name' => 'name',
            'code' => 'code',
            'status' => 'is_enabled',
            'position' => 'position',
        ];
        if (! isset($sortColumns[$sort])) {
            $sort = 'position';
        }

        $base = Language::query();
        $counts = [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('is_enabled', true)->count(),
            'inactive' => (clone $base)->where('is_enabled', false)->count(),
        ];

        $languages = Language::query()
            ->when($status === 'active', fn ($query) => $query->where('is_enabled', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_enabled', false))
            ->when($q !== '', fn ($query) => $query->where(function ($inner) use ($q) {
                $inner->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%");
            }))
            ->when($name !== '', fn ($query) => $query->where('name', 'like', "%{$name}%"))
            ->when($code !== '', fn ($query) => $query->where('code', 'like', "%{$code}%"))
            ->when($idMin !== null && $idMin !== '', fn ($query) => $query->where('id', '>=', (int) $idMin))
            ->when($idMax !== null && $idMax !== '', fn ($query) => $query->where('id', '<=', (int) $idMax))
            ->orderBy($sortColumns[$sort], $dir)
            ->when($sort !== 'id', fn ($query) => $query->orderBy('id', $dir))
            ->adminPaginate()
            ->through(fn (Language $language) => [
                'id' => $language->id,
                'code' => $language->code,
                'name' => $language->name,
                'is_enabled' => $language->is_enabled,
                'is_default' => $language->is_default,
            ]);

        return Inertia::render('Localization/Languages/Index', [
            'languages' => $languages,
            'counts' => $counts,
            'filters' => [
                'status' => $status,
                'q' => $q,
                'name' => $name,
                'code' => $code,
                'id_min' => $idMin !== null && $idMin !== '' ? (string) $idMin : '',
                'id_max' => $idMax !== null && $idMax !== '' ? (string) $idMax : '',
                'sort' => $sort,
                'dir' => $dir,
            ],
            'default_code' => Language::defaultCode(),
        ]);
    }

    public function export()
    {
        return $this->csvDownload('languages.csv', LanguageCsvSchema::exportCsv());
    }

    public function importSample()
    {
        return $this->csvDownload('languages-sample.csv', LanguageCsvSchema::sampleCsv());
    }

    public function import(Request $request): RedirectResponse
    {
        return $this->csvImport($request, LanguageCsvSchema::import(...), 'languages');
    }

    public function create(): Response
    {
        return Inertia::render('Localization/Languages/Form', ['language' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $language = Language::query()->create($data + [
            'position' => (int) Language::query()->max('position') + 1,
        ]);
        if ($language->is_default) {
            $this->clearOtherDefaults($language);
        }

        return redirect()->route('admin.localization.languages.index')->with('success', 'Language created.');
    }

    public function edit(Language $language): Response
    {
        return Inertia::render('Localization/Languages/Form', [
            'language' => [
                'id' => $language->id,
                'code' => $language->code,
                'name' => $language->name,
                'is_enabled' => $language->is_enabled,
                'is_default' => $language->is_default,
            ],
        ]);
    }

    public function update(Request $request, Language $language): RedirectResponse
    {
        $data = $this->validated($request, $language);
        $language->update($data);
        if ($language->is_default) {
            $this->clearOtherDefaults($language);
        }

        return back()->with('success', 'Language saved.');
    }

    public function toggle(Language $language): RedirectResponse
    {
        if ($language->is_default && $language->is_enabled) {
            return back()->with('error', 'Cannot deactivate the default language.');
        }
        $language->update(['is_enabled' => ! $language->is_enabled]);

        return back()->with('success', $language->is_enabled ? 'Language enabled.' : 'Language disabled.');
    }

    public function makeDefault(Language $language): RedirectResponse
    {
        $language->update(['is_enabled' => true, 'is_default' => true]);
        $this->clearOtherDefaults($language);

        return back()->with('success', $language->name.' is now the default language.');
    }

    public function switchLocale(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::exists('languages', 'code')->where('is_enabled', true)],
        ]);
        $request->session()->put('admin_locale', $data['locale']);

        return back();
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Language $language = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:8', 'regex:/^[a-z]{2}(?:-[A-Za-z]{2})?$/i', Rule::unique('languages', 'code')->ignore($language?->id)],
            'name' => ['required', 'string', 'max:80'],
            'is_enabled' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $data['code'] = strtolower($data['code']);
        $data['is_enabled'] = (bool) ($data['is_enabled'] ?? true);
        $data['is_default'] = (bool) ($data['is_default'] ?? false);
        if ($data['is_default']) {
            $data['is_enabled'] = true;
        }

        return $data;
    }

    protected function clearOtherDefaults(Language $language): void
    {
        Language::query()->where('id', '!=', $language->id)->update(['is_default' => false]);
    }
}
