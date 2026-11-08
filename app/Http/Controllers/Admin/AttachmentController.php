<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\Attachment;
use App\Domain\Catalog\Services\AttachmentService;
use App\Domain\Catalog\Support\AttachmentUploadValidator;
use App\Domain\Localization\Models\Language;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function __construct(
        protected AttachmentService $attachments,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeFile('view');

        $q = trim($request->string('q')->toString());
        $name = trim($request->string('name')->toString());
        $file = trim($request->string('file')->toString());
        $idMin = $request->input('id_min');
        $idMax = $request->input('id_max');
        $productsMin = $request->input('products_min');
        $productsMax = $request->input('products_max');
        $sizeMin = $request->input('size_min');
        $sizeMax = $request->input('size_max');
        $created = trim($request->string('created')->toString());
        $sort = $request->string('sort')->toString() ?: 'id';
        $dir = $request->string('dir')->toString() === 'asc' ? 'asc' : 'desc';
        $allowedSort = ['id', 'name', 'original_filename', 'file_size', 'products_count', 'created_at'];
        if (! in_array($sort, $allowedSort, true)) {
            $sort = 'id';
        }

        $base = Attachment::query();
        $counts = [
            'all' => (clone $base)->count(),
            'linked' => (clone $base)->has('products')->count(),
            'unused' => (clone $base)->doesntHave('products')->count(),
        ];

        $rows = Attachment::query()
            ->with('translations')
            ->withCount('products')
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.$q.'%';
                $query->where(function ($inner) use ($like, $q) {
                    $inner->where('name', 'like', $like)
                        ->orWhere('original_filename', 'like', $like)
                        ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', $like));
                    if (ctype_digit($q)) {
                        $inner->orWhere('id', (int) $q);
                    }
                });
            })
            ->when($name !== '', function ($query) use ($name) {
                $query->where(function ($inner) use ($name) {
                    $inner->where('name', 'like', "%{$name}%")
                        ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', "%{$name}%"));
                });
            })
            ->when($file !== '', fn ($query) => $query->where('original_filename', 'like', '%'.$file.'%'))
            ->when($sizeMin !== null && $sizeMin !== '', fn ($query) => $query->where('file_size', '>=', (int) $sizeMin))
            ->when($sizeMax !== null && $sizeMax !== '', fn ($query) => $query->where('file_size', '<=', (int) $sizeMax))
            ->when($created !== '', fn ($query) => $query->whereDate('created_at', $created))
            ->when($idMin !== null && $idMin !== '', fn ($query) => $query->where('id', '>=', (int) $idMin))
            ->when($idMax !== null && $idMax !== '', fn ($query) => $query->where('id', '<=', (int) $idMax))
            ->when($productsMin !== null && $productsMin !== '', fn ($query) => $query->has('products', '>=', (int) $productsMin))
            ->when($productsMax !== null && $productsMax !== '', fn ($query) => $query->has('products', '<=', (int) $productsMax))
            ->when($sort === 'products_count', fn ($query) => $query->orderBy('products_count', $dir)->orderByDesc('id'), fn ($query) => $query->orderBy($sort, $dir)->orderByDesc('id'))
            ->adminPaginate()
            ->through(fn (Attachment $attachment) => [
                ...$this->attachments->present($attachment),
                'actions' => [
                    'edit' => $this->can('update'),
                    'delete' => $this->can('delete'),
                ],
            ]);

        return Inertia::render('Catalog/Files/Index', [
            'files' => $rows,
            'counts' => $counts,
            'filters' => [
                'q' => $q,
                'name' => $name,
                'file' => $file,
                'id_min' => $idMin !== null && $idMin !== '' ? (string) $idMin : '',
                'id_max' => $idMax !== null && $idMax !== '' ? (string) $idMax : '',
                'products_min' => $productsMin !== null && $productsMin !== '' ? (string) $productsMin : '',
                'products_max' => $productsMax !== null && $productsMax !== '' ? (string) $productsMax : '',
                'size_min' => $sizeMin !== null && $sizeMin !== '' ? (string) $sizeMin : '',
                'size_max' => $sizeMax !== null && $sizeMax !== '' ? (string) $sizeMax : '',
                'created' => $created,
                'sort' => $sort,
                'dir' => $dir,
            ],
            'can' => [
                'create' => $this->can('create'),
                'delete' => $this->can('delete'),
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorizeFile('create');

        return Inertia::render('Catalog/Files/Form', [
            'file' => null,
            'uploadLimits' => $this->uploadLimits(),
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $this->authorizeFile('create');

        $data = $this->validated($request, creating: true);
        $attachment = $this->attachments->create($data, $request->file('file'));

        if ($request->expectsJson()) {
            return response()->json([
                'file' => $this->attachments->present($attachment),
                'message' => 'File uploaded.',
            ], 201);
        }

        return redirect()->route('admin.files.index')->with('success', 'File uploaded.');
    }

    public function preview(Attachment $file): BinaryFileResponse
    {
        $this->authorizeFile('view');
        if (! str_starts_with((string) $file->mime_type, 'image/')) {
            abort(404);
        }
        $path = $this->attachments->resolvePhysicalPath($file);
        if (! $path) {
            abort(404);
        }

        return response()->file($path);
    }

    public function download(Attachment $file): StreamedResponse
    {
        $this->authorizeFile('view');

        $disk = Storage::disk($file->disk);
        if (! $file->storage_path || ! $disk->exists($file->storage_path)) {
            abort(404);
        }

        $safeName = preg_replace('/[^\w.\- ()\[\]]+/u', '_', $file->original_filename) ?: 'download';

        return $disk->download($file->storage_path, $safeName, [
            'Content-Type' => $file->mime_type ?: 'application/octet-stream',
        ]);
    }

    public function edit(Attachment $file): Response
    {
        $this->authorizeFile('update');

        $file->loadCount('products')->load('translations');

        return Inertia::render('Catalog/Files/Form', [
            'file' => $this->attachments->present($file),
            'uploadLimits' => $this->uploadLimits(),
        ]);
    }

    public function update(Request $request, Attachment $file): RedirectResponse
    {
        $this->authorizeFile('update');

        $data = $this->validated($request, creating: false);
        $this->attachments->update($file, $data, $request->file('file'));

        return redirect()->route('admin.files.index')->with('success', 'File updated.');
    }

    public function destroy(Attachment $file): RedirectResponse
    {
        $this->authorizeFile('delete');

        $this->attachments->delete($file);

        return back()->with('success', 'File deleted.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->authorizeFile('delete');

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:attachments,id'],
        ]);

        $deleted = 0;
        foreach (Attachment::query()->whereIn('id', $data['ids'])->get() as $attachment) {
            $this->attachments->delete($attachment);
            $deleted++;
        }

        return back()->with('success', $deleted === 1 ? 'File deleted.' : "{$deleted} files deleted.");
    }

    public function search(Request $request): JsonResponse
    {
        $this->authorizeFile('view');

        $q = trim($request->string('q')->toString());
        $productId = (int) $request->input('product_id', 0);

        $rows = Attachment::query()
            ->with('translations')
            ->withCount('products')
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.$q.'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('name', 'like', $like)
                        ->orWhere('original_filename', 'like', $like)
                        ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', $like));
                });
            })
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->map(fn (Attachment $attachment) => $this->attachments->present($attachment));

        $selected = [];
        if ($productId > 0) {
            $selected = Attachment::query()
                ->whereHas('products', fn ($query) => $query->where('products.id', $productId))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return response()->json([
            'files' => $rows,
            'selected' => $selected,
        ]);
    }

    protected function validated(Request $request, bool $creating): array
    {
        $this->assertUploadedFile($request->file('file'), required: $creating);

        $maxKb = (int) config('wento.upload.max_attachment_kb');
        $messages = [
            'file.required' => 'Please select a file.',
            'file.uploaded' => AttachmentUploadValidator::tooLargeMessage(),
            'file.max' => AttachmentUploadValidator::tooLargeMessage(),
        ];

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'translations' => ['nullable', 'array'],
            'translations.*.language_code' => ['required_with:translations', 'string', Rule::exists('languages', 'code')->where('is_enabled', true)],
            'translations.*.name' => ['nullable', 'string', 'max:255'],
            'translations.*.description' => ['nullable', 'string'],
            'file' => [$creating ? 'required' : 'nullable', 'file', 'max:'.$maxKb],
        ], $messages);

        $data['translations'] = $this->normalizeTranslations($data);
        $default = Language::defaultCode();
        $defaultRow = $data['translations'][$default] ?? [];
        $defaultName = trim((string) ($defaultRow['name'] ?? $data['name'] ?? ''));

        if ($defaultName === '') {
            throw ValidationException::withMessages([
                "translations.{$default}.name" => 'File name is required.',
            ]);
        }

        $data['name'] = $defaultName;
        $data['description'] = ($defaultRow['description'] ?? $data['description'] ?? '') ?: null;
        $data['language_code'] = $default;

        return $data;
    }

    /** @return array<string, array<string, mixed>> */
    protected function normalizeTranslations(array $data): array
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
                'description' => ($row['description'] ?? '') ?: null,
            ];
        }

        if ($rows === [] && trim((string) ($data['name'] ?? '')) !== '') {
            $rows[$default] = [
                'language_code' => $default,
                'name' => trim((string) $data['name']),
                'description' => ($data['description'] ?? '') ?: null,
            ];
        }

        if (! isset($rows[$default])) {
            $rows[$default] = [
                'language_code' => $default,
                'name' => trim((string) ($data['name'] ?? '')),
                'description' => ($data['description'] ?? '') ?: null,
            ];
        }

        return $rows;
    }

    protected function assertUploadedFile(?UploadedFile $file, bool $required): void
    {
        if (! $file) {
            if ($required) {
                throw ValidationException::withMessages(['file' => 'Please select a file.']);
            }

            return;
        }

        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                'file' => AttachmentUploadValidator::uploadFailureMessage($file),
            ]);
        }
    }

    /** @return array{max_kb: int, max_bytes: int, max_label: string} */
    protected function uploadLimits(): array
    {
        return [
            'max_kb' => AttachmentUploadValidator::maxKb(),
            'max_bytes' => AttachmentUploadValidator::maxBytes(),
            'max_label' => AttachmentUploadValidator::maxLabel(),
        ];
    }

    protected function authorizeFile(string $ability): void
    {
        $admin = $this->admin();
        $map = [
            'view' => 'catalog.files.view',
            'create' => 'catalog.files.create',
            'update' => 'catalog.files.update',
            'delete' => 'catalog.files.delete',
        ];
        $slug = $map[$ability] ?? 'catalog.files.view';
        if ($admin && ($admin->hasPermission('catalog.manage') || $admin->hasPermission('catalog.files') || $admin->hasPermission($slug))) {
            return;
        }

        abort(403, 'You do not have permission to perform this action.');
    }

    protected function can(string $ability): bool
    {
        $admin = $this->admin();
        $map = [
            'view' => 'catalog.files.view',
            'create' => 'catalog.files.create',
            'update' => 'catalog.files.update',
            'delete' => 'catalog.files.delete',
        ];
        $slug = $map[$ability] ?? 'catalog.files.view';

        return $admin && ($admin->hasPermission('catalog.manage') || $admin->hasPermission('catalog.files') || $admin->hasPermission($slug));
    }

    protected function admin(): ?Admin
    {
        return request()->user('admin');
    }
}
