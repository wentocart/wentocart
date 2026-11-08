<?php

namespace App\Http\Controllers\Admin\Multivendor;

use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerBrand;
use App\Domain\Marketplace\Models\SellerCategory;
use App\Domain\Marketplace\Models\SellerFeature;
use App\Domain\Marketplace\Models\SellerSupplier;
use App\Domain\Marketplace\Services\SellerCatalogService;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\FeatureController;
use App\Http\Controllers\Admin\Multivendor\Concerns\PresentsMultivendor;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SellerTaxonomyController extends Controller
{
    use PresentsMultivendor;

    public function __construct(
        protected SellerCatalogService $catalog,
        protected CategoryController $categoryController,
        protected FeatureController $featureController,
        protected BrandController $brandController,
    ) {}

    public function index(Request $request, string $kind): Response
    {
        $meta = $this->meta($kind);
        $filters = $this->listFilters($request);
        $sellerId = $this->sellerId($request);
        $query = $meta['model']::query()->with('seller:id,shop_name,shop_slug');
        if ($sellerId) {
            $query->where('seller_id', $sellerId);
        }
        if ($filters['search'] !== '') {
            $needle = $filters['search'];
            $query->where(function ($inner) use ($needle, $meta) {
                $inner->where('name', 'like', '%'.$needle.'%');
                if (in_array('slug', $meta['search'], true)) {
                    $inner->orWhere('slug', 'like', '%'.$needle.'%');
                }
                if (in_array('code', $meta['search'], true)) {
                    $inner->orWhere('code', 'like', '%'.$needle.'%');
                }
            });
        }
        if ($filters['status'] === 'enabled') {
            $query->where('is_enabled', true);
        } elseif ($filters['status'] === 'disabled') {
            $query->where('is_enabled', false);
        }
        $columnMap = [];
        foreach ($meta['columns'] as $column) {
            if (! in_array($column['key'], ['shop_name', 'enabled_label'], true)) {
                $columnMap[$column['key']] = $column['key'];
            }
        }
        $this->applyMappedFilters($query, $filters, $columnMap);

        $this->applyListIdRange($query, $filters);
        $this->applyListOrdering($query, $filters, array_merge(['id'], array_keys($columnMap)));

        return Inertia::render('Multivendor/List', [
            'screen' => $kind,
            'title' => $meta['title'],
            'path' => $meta['path'],
            'filters' => $filters,
            'sellers' => $this->sellerOptions(),
            'createHref' => $meta['path'].'/create',
            'createLabel' => 'Add '.$meta['singular'],
            'empty' => 'No seller '.$meta['title'].' yet.',
            'tabs' => [
                ['key' => 'all', 'label' => 'All'],
                ['key' => 'enabled', 'label' => 'Enabled'],
                ['key' => 'disabled', 'label' => 'Disabled'],
            ],
            'counts' => [
                'all' => $meta['model']::query()->when($sellerId, fn ($q) => $q->where('seller_id', $sellerId))->count(),
                'enabled' => $meta['model']::query()->when($sellerId, fn ($q) => $q->where('seller_id', $sellerId))->where('is_enabled', true)->count(),
                'disabled' => $meta['model']::query()->when($sellerId, fn ($q) => $q->where('seller_id', $sellerId))->where('is_enabled', false)->count(),
            ],
            'columns' => $meta['columns'],
            'rows' => $query
                ->adminPaginate()
                ->through(fn (Model $row) => $this->presentRow($kind, $row, $meta)),
        ]);
    }

    public function create(Request $request, string $kind): Response
    {
        if ($kind === 'categories') {
            return $this->categoryForm(null, $request);
        }
        if ($kind === 'features') {
            return $this->featureForm(null, $request);
        }
        if ($kind === 'brands') {
            return $this->brandForm(null, $request);
        }

        return $this->form($kind, null);
    }

    public function edit(Request $request, string $id, string $kind): Response
    {
        $row = $this->find($kind, (int) $id);
        if ($kind === 'categories') {
            return $this->categoryForm($row, $request);
        }
        if ($kind === 'features') {
            return $this->featureForm($row, $request);
        }
        if ($kind === 'brands') {
            return $this->brandForm($row, $request);
        }

        return $this->form($kind, $row);
    }

    public function store(Request $request, string $kind): RedirectResponse
    {
        return $this->save($request, $kind, null);
    }

    public function update(Request $request, string $id, string $kind): RedirectResponse
    {
        return $this->save($request, $kind, $this->find($kind, (int) $id));
    }

    public function destroy(string $id, string $kind): RedirectResponse
    {
        $row = $this->find($kind, (int) $id);
        $seller = $row->seller;
        match ($kind) {
            'categories' => $this->catalog->deleteCategory($seller, $row),
            'features' => $this->catalog->deleteFeature($seller, $row),
            'brands' => $this->catalog->deleteBrand($seller, $row),
            default => $this->catalog->deleteSupplier($seller, $row),
        };

        return back()->with('success', 'Seller '.$this->meta($kind)['singular'].' deleted.');
    }

    protected function save(Request $request, string $kind, ?Model $row): RedirectResponse
    {
        $meta = $this->meta($kind);
        if ($kind === 'categories') {
            return $this->saveCategory($request, $row instanceof SellerCategory ? $row : null);
        }
        if ($kind === 'features') {
            return $this->saveFeature($request, $row instanceof SellerFeature ? $row : null);
        }
        if ($kind === 'brands') {
            return $this->saveBrand($request, $row instanceof SellerBrand ? $row : null);
        }

        $rules = [
            'seller_id' => ['required', 'exists:sellers,id'],
            'name' => ['required', 'string', 'max:160'],
            'is_enabled' => ['sometimes', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
        ];
        $rules['slug'] = ['nullable', 'string', 'max:160'];
        $rules['email'] = ['nullable', 'email', 'max:190'];
        $rules['phone'] = ['nullable', 'string', 'max:40'];
        $rules['city'] = ['nullable', 'string', 'max:80'];
        $rules['country'] = ['nullable', 'string', 'max:80'];

        $data = $request->validate($rules);
        $seller = Seller::query()->findOrFail((int) $data['seller_id']);
        $this->catalog->saveSupplier($seller, $data, $row);

        return redirect($meta['path'])->with('success', 'Seller '.$meta['singular'].' saved.');
    }

    protected function saveCategory(Request $request, ?SellerCategory $row = null): RedirectResponse
    {
        $sellerId = (int) $request->validate([
            'seller_id' => ['required', 'exists:sellers,id'],
        ])['seller_id'];
        $seller = Seller::query()->findOrFail($sellerId);

        $parentId = $request->input('parent_id');
        $payload = $request->duplicate();
        $payload->merge(['parent_id' => null]);
        $data = $this->categoryController->validateCategoryPayload($payload, $row?->category);
        $data['parent_id'] = ($parentId === '' || $parentId === null) ? null : (int) $parentId;
        $data['seller_id'] = $sellerId;

        $files = $this->categoryController->categoryFiles($request);
        $this->catalog->saveCategory($seller, $data, $row, $files);

        return redirect('/admin/multivendor/categories')->with('success', 'Seller category saved.');
    }

    protected function categoryForm(?SellerCategory $row, Request $request): Response
    {
        $row?->loadMissing('seller:id,shop_name,shop_slug');
        $sellerId = $row?->seller_id ?: ($request->integer('seller_id') ?: null);
        $seller = $sellerId ? Seller::query()->find($sellerId) : null;
        $parentOptions = $seller
            ? $this->catalog->sellerCategoryParentOptions($seller, $row)
            : [];

        if ($row) {
            $props = $row->category
                ? $this->categoryController->inertiaEditProps($row->category, $parentOptions)
                : $this->categoryController->inertiaCreateProps($request, $parentOptions);
            $props['category'] = $this->catalog->presentSellerCategoryForm($row->seller, $row);
        } else {
            $props = $this->categoryController->inertiaCreateProps($request, $parentOptions);
        }

        return Inertia::render('Catalog/Categories/Form', [
            ...$props,
            'multivendor' => [
                'listingId' => $row?->id,
                'sellerId' => $sellerId,
                'sellers' => $this->sellerOptions(Seller::STATUS_APPROVED),
                'indexPath' => '/admin/multivendor/categories',
                'createPath' => '/admin/multivendor/categories/create',
                'savePath' => $row
                    ? '/admin/multivendor/categories/'.$row->id
                    : '/admin/multivendor/categories',
                'saveMethod' => $row ? 'put' : 'post',
                'deletePath' => $row ? '/admin/multivendor/categories/'.$row->id : null,
                'shopName' => $row?->seller?->shop_name,
            ],
        ]);
    }

    protected function saveFeature(Request $request, ?SellerFeature $row = null): RedirectResponse
    {
        $sellerId = (int) $request->validate([
            'seller_id' => ['required', 'exists:sellers,id'],
        ])['seller_id'];
        $seller = Seller::query()->findOrFail($sellerId);

        $data = $this->featureController->validateFeaturePayload($request, $row?->feature);
        $data['seller_id'] = $sellerId;
        if (! $row) {
            $data['type'] = $request->input('type', 'select');
        }

        $this->catalog->saveFeature($seller, $data, $row);

        return redirect('/admin/multivendor/features')->with('success', 'Seller feature saved.');
    }

    protected function featureForm(?SellerFeature $row, Request $request): Response
    {
        $row?->loadMissing('seller:id,shop_name,shop_slug');

        if ($row) {
            $props = $row->feature
                ? $this->featureController->inertiaEditProps($row->feature)
                : $this->featureController->inertiaCreateProps();
            $props['feature'] = $this->catalog->presentSellerFeatureForm($row->seller, $row);
        } else {
            $props = $this->featureController->inertiaCreateProps();
        }

        $sellerId = $row?->seller_id ?: ($request->integer('seller_id') ?: null);

        return Inertia::render('Catalog/Features/Form', [
            ...$props,
            'multivendor' => [
                'listingId' => $row?->id,
                'sellerId' => $sellerId,
                'sellers' => $this->sellerOptions(Seller::STATUS_APPROVED),
                'indexPath' => '/admin/multivendor/features',
                'createPath' => '/admin/multivendor/features/create',
                'savePath' => $row
                    ? '/admin/multivendor/features/'.$row->id
                    : '/admin/multivendor/features',
                'saveMethod' => $row ? 'put' : 'post',
                'deletePath' => $row ? '/admin/multivendor/features/'.$row->id : null,
                'shopName' => $row?->seller?->shop_name,
            ],
        ]);
    }

    protected function saveBrand(Request $request, ?SellerBrand $row = null): RedirectResponse
    {
        $sellerId = (int) $request->validate([
            'seller_id' => ['required', 'exists:sellers,id'],
        ])['seller_id'];
        $seller = Seller::query()->findOrFail($sellerId);

        $data = $this->brandController->validateBrandPayload($request, $row?->brand);
        $data['seller_id'] = $sellerId;

        $files = $this->brandController->brandFiles($request);
        $this->catalog->saveBrand($seller, $data, $row, $files);

        return redirect('/admin/multivendor/brands')->with('success', 'Seller brand saved.');
    }

    protected function brandForm(?SellerBrand $row, Request $request): Response
    {
        $row?->loadMissing('seller:id,shop_name,shop_slug');

        if ($row) {
            $props = $row->brand
                ? $this->brandController->inertiaEditProps($row->brand)
                : $this->brandController->inertiaCreateProps();
            $props['brand'] = $this->catalog->presentSellerBrandForm($row->seller, $row);
        } else {
            $props = $this->brandController->inertiaCreateProps();
        }

        $sellerId = $row?->seller_id ?: ($request->integer('seller_id') ?: null);

        return Inertia::render('Catalog/Brands/Form', [
            ...$props,
            'multivendor' => [
                'listingId' => $row?->id,
                'sellerId' => $sellerId,
                'sellers' => $this->sellerOptions(Seller::STATUS_APPROVED),
                'indexPath' => '/admin/multivendor/brands',
                'createPath' => '/admin/multivendor/brands/create',
                'savePath' => $row
                    ? '/admin/multivendor/brands/'.$row->id
                    : '/admin/multivendor/brands',
                'saveMethod' => $row ? 'put' : 'post',
                'deletePath' => $row ? '/admin/multivendor/brands/'.$row->id : null,
                'shopName' => $row?->seller?->shop_name,
            ],
        ]);
    }

    protected function form(string $kind, ?Model $row): Response
    {
        $meta = $this->meta($kind);
        $fields = [
            ['name' => 'seller_id', 'label' => 'Seller', 'type' => 'seller', 'required' => true, 'disabled' => (bool) $row],
            ['name' => 'name', 'label' => 'Name', 'required' => true],
        ];
        $values = [
            'seller_id' => $row?->seller_id,
            'name' => $row?->name,
            'is_enabled' => $row?->is_enabled ?? true,
            'position' => $row?->position ?? 0,
            'description' => $row?->description ?? '',
        ];

        $fields[] = ['name' => 'slug', 'label' => 'Slug'];
        $fields[] = ['name' => 'email', 'label' => 'Email', 'type' => 'email'];
        $fields[] = ['name' => 'phone', 'label' => 'Phone'];
        $fields[] = ['name' => 'city', 'label' => 'City'];
        $fields[] = ['name' => 'country', 'label' => 'Country'];
        $values['slug'] = $row?->slug;
        $values['email'] = $row?->email;
        $values['phone'] = $row?->phone;
        $values['city'] = $row?->city;
        $values['country'] = $row?->country;

        $fields[] = ['name' => 'description', 'label' => 'Description', 'type' => 'textarea'];
        $fields[] = ['name' => 'position', 'label' => 'Position', 'type' => 'number'];
        $fields[] = ['name' => 'is_enabled', 'label' => 'Enabled', 'type' => 'checkbox'];

        return Inertia::render('Multivendor/Form', [
            'title' => ($row ? 'Edit ' : 'Add ').$meta['singular'],
            'path' => $meta['path'],
            'method' => $row ? 'put' : 'post',
            'action' => $row ? $meta['path'].'/'.$row->id : $meta['path'],
            'values' => $values,
            'fields' => $fields,
            'sellers' => $this->sellerOptions(Seller::STATUS_APPROVED),
        ]);
    }

    protected function find(string $kind, int $id): Model
    {
        return $this->meta($kind)['model']::query()->findOrFail($id);
    }

    /** @return array<string, mixed> */
    protected function presentRow(string $kind, Model $row, array $meta): array
    {
        $data = [
            'id' => $row->id,
            'name' => $row->name,
            'shop_name' => $row->seller?->shop_name,
            'enabled_label' => $row->is_enabled ? 'Enabled' : 'Disabled',
            'href' => $meta['path'].'/'.$row->id.'/edit',
            'deleteHref' => $meta['path'].'/'.$row->id,
            'action_label' => 'Edit',
        ];
        if ($kind === 'features') {
            $data['code'] = $row->code;
            $data['type'] = $row->type;
        } elseif ($kind === 'brands') {
            $data['code'] = $row->code ?: '—';
            $data['slug'] = $row->slug;
        } elseif ($kind === 'suppliers') {
            $data['email'] = $row->email ?: '—';
            $data['city'] = $row->city ?: '—';
        } else {
            $data['slug'] = $row->slug;
        }

        return $data;
    }

    /** @return array{title: string, singular: string, path: string, model: class-string, columns: list<array{key: string, label: string}>, search: list<string>} */
    protected function meta(string $kind): array
    {
        return match ($kind) {
            'categories' => [
                'title' => 'Category',
                'singular' => 'category',
                'path' => '/admin/multivendor/categories',
                'model' => SellerCategory::class,
                'search' => ['slug'],
                'columns' => [
                    ['key' => 'name', 'label' => 'Category'],
                    ['key' => 'slug', 'label' => 'Slug'],
                    ['key' => 'shop_name', 'label' => 'Seller'],
                    ['key' => 'enabled_label', 'label' => 'Status'],
                ],
            ],
            'features' => [
                'title' => 'Features',
                'singular' => 'feature',
                'path' => '/admin/multivendor/features',
                'model' => SellerFeature::class,
                'search' => ['code'],
                'columns' => [
                    ['key' => 'name', 'label' => 'Feature'],
                    ['key' => 'code', 'label' => 'Code'],
                    ['key' => 'type', 'label' => 'Type'],
                    ['key' => 'shop_name', 'label' => 'Seller'],
                    ['key' => 'enabled_label', 'label' => 'Status'],
                ],
            ],
            'brands' => [
                'title' => 'Brands',
                'singular' => 'brand',
                'path' => '/admin/multivendor/brands',
                'model' => SellerBrand::class,
                'search' => ['slug', 'code'],
                'columns' => [
                    ['key' => 'name', 'label' => 'Brand'],
                    ['key' => 'code', 'label' => 'Code'],
                    ['key' => 'shop_name', 'label' => 'Seller'],
                    ['key' => 'enabled_label', 'label' => 'Status'],
                ],
            ],
            'suppliers' => [
                'title' => 'Suppliers',
                'singular' => 'supplier',
                'path' => '/admin/multivendor/suppliers',
                'model' => SellerSupplier::class,
                'search' => ['slug'],
                'columns' => [
                    ['key' => 'name', 'label' => 'Supplier'],
                    ['key' => 'email', 'label' => 'Email'],
                    ['key' => 'city', 'label' => 'City'],
                    ['key' => 'shop_name', 'label' => 'Seller'],
                    ['key' => 'enabled_label', 'label' => 'Status'],
                ],
            ],
            default => abort(404),
        };
    }
}
