<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Settings\DocumentTemplateCatalog;
use App\Domain\Settings\Models\DocumentTemplate;
use App\Domain\Settings\Models\FileChangeLog;
use App\Domain\Settings\TemplateContentLocales;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DocumentTemplateController extends Controller
{
    public function index(Request $request): Response
    {
        DocumentTemplateCatalog::seed();

        $status = $request->string('status')->toString() ?: 'all';
        $name = trim($request->string('name')->toString());
        $code = trim($request->string('code')->toString());
        $type = $request->string('type')->toString() ?: 'all';
        $subject = trim($request->string('subject')->toString());
        $idMin = $request->input('id_min');
        $idMax = $request->input('id_max');
        $sort = $request->string('sort')->toString() ?: 'position';
        $dir = $request->string('dir')->toString() === 'desc' ? 'desc' : 'asc';
        $sortColumns = [
            'id' => 'id',
            'name' => 'name',
            'code' => 'code',
            'type' => 'type',
            'subject' => 'email_subject',
            'status' => 'is_enabled',
            'position' => 'position',
        ];
        if (! isset($sortColumns[$sort])) {
            $sort = 'position';
        }

        $base = DocumentTemplate::query();
        $counts = [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('is_enabled', true)->count(),
            'inactive' => (clone $base)->where('is_enabled', false)->count(),
        ];

        $templates = DocumentTemplate::query()
            ->when($status === 'active', fn ($query) => $query->where('is_enabled', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_enabled', false))
            ->when($name !== '', fn ($query) => $query->where('name', 'like', "%{$name}%"))
            ->when($code !== '', fn ($query) => $query->where('code', 'like', "%{$code}%"))
            ->when($type !== 'all', fn ($query) => $query->where('type', $type))
            ->when($subject !== '', fn ($query) => $query->where('email_subject', 'like', "%{$subject}%"))
            ->when($idMin !== null && $idMin !== '', fn ($query) => $query->where('id', '>=', (int) $idMin))
            ->when($idMax !== null && $idMax !== '', fn ($query) => $query->where('id', '<=', (int) $idMax))
            ->orderBy($sortColumns[$sort], $dir)
            ->when($sort !== 'id', fn ($query) => $query->orderBy('id', $dir))
            ->adminPaginate()
            ->through(fn (DocumentTemplate $template) => $template->toAdminArray());

        return Inertia::render('Settings/Documents', [
            'templates' => $templates,
            'counts' => $counts,
            'filters' => [
                'status' => $status,
                'name' => $name,
                'code' => $code,
                'type' => $type,
                'subject' => $subject,
                'id_min' => $idMin !== null && $idMin !== '' ? (string) $idMin : '',
                'id_max' => $idMax !== null && $idMax !== '' ? (string) $idMax : '',
                'sort' => $sort,
                'dir' => $dir,
            ],
            'types' => collect(DocumentTemplate::typeLabels())
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
        ]);
    }

    public function create(): Response
    {
        DocumentTemplateCatalog::seed();
        TemplateContentLocales::ensure();
        $default = TemplateContentLocales::defaultCode();
        $subject = '{{shop_name}} document #{{order_number}}';
        $body = DocumentTemplateCatalog::customBody();

        return Inertia::render('Settings/DocumentForm', [
            'template' => [
                'id' => null,
                'code' => '',
                'name' => '',
                'type' => DocumentTemplate::TYPE_CUSTOM,
                'email_subject' => $subject,
                'body' => $body,
                'design_json' => null,
                'translations' => [$default => ['email_subject' => $subject, 'body' => $body, 'design_json' => '']],
                'is_system' => false,
                'is_custom' => true,
                'is_enabled' => true,
            ],
            'languages' => TemplateContentLocales::languages(),
            'default_language' => $default,
            'types' => $this->types(),
            'variables' => DocumentTemplateCatalog::variablesFor(DocumentTemplate::TYPE_CUSTOM),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        TemplateContentLocales::ensure();
        $data = $this->validated($request, true);

        $template = DocumentTemplate::query()->create([
            'code' => $this->uniqueCode($data['name']),
            'name' => $data['name'],
            'type' => $data['type'],
            'email_subject' => $data['email_subject'],
            'body' => $data['body'],
            'design_json' => $data['design_json'] ?? null,
            'is_system' => false,
            'is_custom' => true,
            'is_enabled' => $data['is_enabled'] ?? true,
            'is_in_use' => false,
            'position' => (int) (DocumentTemplate::query()->max('position') ?? 0) + 10,
        ]);

        if (! empty($data['translations']) && is_array($data['translations'])) {
            TemplateContentLocales::syncDocument($template, $data['translations']);
        }

        if (in_array($template->type, DocumentTemplateCatalog::attachableTypes(), true)) {
            $hasCurrent = DocumentTemplate::query()
                ->where('type', $template->type)
                ->where('is_in_use', true)
                ->whereKeyNot($template->id)
                ->exists();
            if (! $hasCurrent && $template->is_enabled) {
                DocumentTemplateCatalog::markInUse($template);
            }
        }

        FileChangeLog::record('created', 'documents/templates/'.$template->code);

        return redirect()->route('admin.documents.edit', $template)->with('success', 'Document template created.');
    }

    public function edit(DocumentTemplate $documentTemplate): Response
    {
        TemplateContentLocales::ensure();

        return Inertia::render('Settings/DocumentForm', [
            'template' => $documentTemplate->toAdminArray(),
            'languages' => TemplateContentLocales::languages(),
            'default_language' => TemplateContentLocales::defaultCode(),
            'types' => $this->types(),
            'variables' => DocumentTemplateCatalog::variablesFor($documentTemplate->type),
        ]);
    }

    public function update(Request $request, DocumentTemplate $documentTemplate): RedirectResponse
    {
        TemplateContentLocales::ensure();
        $data = $this->validated($request, false);
        $enabled = array_key_exists('is_enabled', $data) ? (bool) $data['is_enabled'] : (bool) $documentTemplate->is_enabled;
        $type = $documentTemplate->is_system ? $documentTemplate->type : ($data['type'] ?? $documentTemplate->type);

        if ($documentTemplate->is_in_use && ! $enabled) {
            return wento_admin_toast(back()->with('error', 'This template is in use. Set another '.$documentTemplate->typeLabel().' template as in use before turning it off.'));
        }

        if ($documentTemplate->is_in_use && $type !== $documentTemplate->type) {
            return wento_admin_toast(back()->with('error', 'This template is in use. Set another '.$documentTemplate->typeLabel().' template as in use before changing its type.'));
        }

        $documentTemplate->update([
            'name' => $data['name'],
            'type' => $type,
            'email_subject' => $data['email_subject'],
            'body' => $data['body'],
            'design_json' => array_key_exists('design_json', $data) ? $data['design_json'] : $documentTemplate->design_json,
            'is_enabled' => $enabled,
        ]);

        if (! empty($data['translations']) && is_array($data['translations'])) {
            TemplateContentLocales::syncDocument($documentTemplate->fresh(), $data['translations']);
        }

        FileChangeLog::record('updated', 'documents/templates/'.$documentTemplate->code);

        return back()->with('success', 'Document template saved.');
    }

    public function duplicate(DocumentTemplate $documentTemplate): RedirectResponse
    {
        $copy = $documentTemplate->replicate();
        $copy->code = $this->uniqueCode($documentTemplate->name.' copy');
        $copy->name = $documentTemplate->name.' copy';
        $copy->is_system = false;
        $copy->is_custom = true;
        $copy->is_in_use = false;
        $copy->position = (int) (DocumentTemplate::query()->max('position') ?? 0) + 10;
        $copy->save();

        return redirect()->route('admin.documents.edit', $copy)->with('success', 'Template duplicated.');
    }

    public function useTemplate(DocumentTemplate $documentTemplate): RedirectResponse
    {
        if (! in_array($documentTemplate->type, DocumentTemplateCatalog::attachableTypes(), true)) {
            return wento_admin_toast(back()->with('error', 'Custom documents are not attached to an order. Choose Invoice, Credit slip, or Delivery slip.'));
        }

        DocumentTemplateCatalog::markInUse($documentTemplate);
        FileChangeLog::record('updated', 'documents/templates/'.$documentTemplate->code);

        return wento_admin_toast(back()->with('success', $documentTemplate->name.' is now in use.'));
    }

    public function toggle(Request $request, DocumentTemplate $documentTemplate): RedirectResponse
    {
        $enabled = $request->exists('is_enabled')
            ? $request->boolean('is_enabled')
            : ! $documentTemplate->is_enabled;

        if (! $enabled && $documentTemplate->is_in_use) {
            return wento_admin_toast(back()->with('error', 'This template is in use. Set another '.$documentTemplate->typeLabel().' template as in use before turning it off.'));
        }

        $documentTemplate->update(['is_enabled' => $enabled]);
        FileChangeLog::record('updated', 'documents/templates/'.$documentTemplate->code);

        return wento_admin_toast(back()->with('success', $enabled ? 'Template enabled.' : 'Template disabled.'));
    }

    public function bulkStatus(Request $request): RedirectResponse
    {
        $request->merge([
            'ids' => array_values(array_filter(array_map('intval', (array) $request->input('ids', [])))),
            'enabled' => filter_var($request->input('enabled'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        ]);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'enabled' => ['required', 'boolean'],
        ]);

        $query = DocumentTemplate::query()->whereIn('id', $data['ids']);
        $protected = 0;
        if (! $data['enabled']) {
            $protected = (clone $query)->where('is_in_use', true)->count();
            $query->where('is_in_use', false);
        }

        $updated = $query->update(['is_enabled' => (bool) $data['enabled']]);

        if ($updated === 0) {
            return wento_admin_toast(back()->with('error', $protected > 0
                ? 'Templates that are in use stay on. Set another template as in use first.'
                : 'No templates were updated.'));
        }

        if ($protected > 0) {
            return wento_admin_toast(back()->with('success', $updated === 1
                ? 'Template disabled. The template in use was left on.'
                : $updated.' templates disabled. Templates in use were left on.'));
        }

        return wento_admin_toast(back()->with('success', $data['enabled']
            ? ($updated === 1 ? 'Template enabled.' : $updated.' templates enabled.')
            : ($updated === 1 ? 'Template disabled.' : $updated.' templates disabled.')));
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $selected = DocumentTemplate::query()->whereIn('id', $data['ids']);
        $deleted = (clone $selected)
            ->where('is_system', false)
            ->where('is_in_use', false)
            ->delete();

        if ($deleted === 0) {
            $message = (clone $selected)->where('is_in_use', true)->exists()
                ? 'A template that is in use cannot be deleted. Set another template of that type as in use first.'
                : 'Built-in templates cannot be deleted. Duplicate them or turn them off.';

            return wento_admin_toast(back()->with('error', $message));
        }

        return wento_admin_toast(back()->with('success', $deleted === 1 ? 'Template deleted.' : $deleted.' templates deleted.'));
    }

    public function bulkDuplicate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $copied = 0;
        foreach (DocumentTemplate::query()->whereIn('id', $data['ids'])->get() as $template) {
            $copy = $template->replicate();
            $copy->code = $this->uniqueCode($template->name.' copy');
            $copy->name = $template->name.' copy';
            $copy->is_system = false;
            $copy->is_custom = true;
            $copy->is_in_use = false;
            $copy->position = (int) (DocumentTemplate::query()->max('position') ?? 0) + 10;
            $copy->save();
            $copied++;
        }

        if ($copied === 0) {
            return wento_admin_toast(back()->with('error', 'No templates were duplicated.'));
        }

        return wento_admin_toast(back()->with('success', $copied === 1 ? 'Template duplicated.' : $copied.' templates duplicated.'));
    }

    public function destroy(DocumentTemplate $documentTemplate): RedirectResponse
    {
        if ($documentTemplate->is_in_use) {
            return wento_admin_toast(back()->with('error', 'This template is in use. Set another '.$documentTemplate->typeLabel().' template as in use before deleting it.'));
        }

        if ($documentTemplate->is_system) {
            return wento_admin_toast(back()->with('error', 'Built-in templates cannot be deleted. Duplicate them or turn them off.'));
        }

        $documentTemplate->delete();

        return wento_admin_toast(redirect()->route('admin.documents.index')->with('success', 'Template deleted.'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => [$creating ? 'required' : 'nullable', Rule::in(array_keys(DocumentTemplate::typeLabels()))],
            'email_subject' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string'],
            'design_json' => ['nullable', 'string'],
            'translations' => ['nullable', 'array'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    protected function types(): array
    {
        return collect(DocumentTemplate::typeLabels())
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }

    protected function uniqueCode(string $name): string
    {
        $base = Str::slug($name) ?: 'document';
        $code = $base;
        $i = 2;
        while (DocumentTemplate::query()->where('code', $code)->exists()) {
            $code = $base.'-'.$i;
            $i++;
        }

        return $code;
    }
}
