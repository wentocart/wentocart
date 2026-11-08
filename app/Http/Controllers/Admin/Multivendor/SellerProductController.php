<?php

namespace App\Http\Controllers\Admin\Multivendor;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\AttachmentService;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Marketplace\Services\SellerService;
use App\Http\Controllers\Admin\Multivendor\Concerns\PresentsMultivendor;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SellerProductController extends Controller
{
    use PresentsMultivendor;

    public function __construct(
        protected SellerService $sellers,
        protected ProductController $catalogProducts,
        protected ProductService $products,
        protected AttachmentService $attachments,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $this->listFilters($request);
        $sellerId = $this->sellerId($request);
        $query = SellerProduct::query()->with('seller:id,shop_name,shop_slug,status');
        if ($sellerId) {
            $query->where('seller_id', $sellerId);
        }
        if ($filters['name'] !== '') {
            $query->where('name', 'like', '%'.$filters['name'].'%');
        }
        if ($filters['sku'] !== '') {
            $query->where('sku', 'like', '%'.$filters['sku'].'%');
        }
        if ($filters['search'] !== '') {
            $needle = $filters['search'];
            $query->where(function ($inner) use ($needle) {
                $inner->where('name', 'like', '%'.$needle.'%')
                    ->orWhere('sku', 'like', '%'.$needle.'%');
            });
        }
        if ($filters['status'] === 'enabled') {
            $query->where('is_enabled', true);
        } elseif ($filters['status'] === 'disabled') {
            $query->where('is_enabled', false);
        } elseif (in_array($filters['status'], ['pending', 'approved', 'rejected'], true)) {
            $query->where('approval_status', $filters['status']);
        }

        $this->applyMappedFilters($query, $filters, [
            'price_range' => 'price',
            'stock_qty_range' => 'stock_qty',
        ]);
        $this->applyListIdRange($query, $filters);
        $this->applyListOrdering($query, $filters, ['id', 'name', 'sku', 'price', 'stock_qty', 'enabled_label'], ['enabled_label' => 'is_enabled']);

        return Inertia::render('Multivendor/List', [
            'screen' => 'products',
            'title' => 'Products',
            'path' => '/admin/multivendor/products',
            'filters' => $filters,
            'sellers' => $this->sellerOptions(),
            'createHref' => '/admin/multivendor/products/create',
            'createLabel' => 'Add product',
            'exportHref' => '/admin/multivendor/products/export',
            'empty' => 'No seller products yet.',
            'columns' => [
                ['key' => 'name', 'label' => 'Product'],
                ['key' => 'sku', 'label' => 'SKU'],
                ['key' => 'shop_name', 'label' => 'Seller'],
                ['key' => 'price', 'label' => 'Price'],
                ['key' => 'stock_qty', 'label' => 'Stock'],
                ['key' => 'enabled_label', 'label' => 'Moderation'],
            ],
            'rows' => $query
                ->adminPaginate()
                ->through(fn (SellerProduct $row) => [
                    'id' => $row->id,
                    'name' => $row->name,
                    'sku' => $row->sku,
                    'shop_name' => $row->seller?->shop_name,
                    'price' => (float) $row->price,
                    'stock_qty' => $row->stock_qty,
                    'enabled_label' => ! $row->is_enabled
                        ? 'disabled'
                        : ($row->approval_status ?: 'enabled'),
                    'href' => '/admin/multivendor/products/'.$row->id.'/edit',
                    'deleteHref' => '/admin/multivendor/products/'.$row->id,
                    'can_moderate' => $row->approval_status === SellerProduct::STATUS_PENDING,
                    'approveHref' => $row->approval_status === SellerProduct::STATUS_PENDING ? '/admin/multivendor/products/'.$row->id.'/review' : null,
                    'rejectHref' => $row->approval_status === SellerProduct::STATUS_PENDING ? '/admin/multivendor/products/'.$row->id.'/review' : null,
                    'action_label' => 'Edit',
                ]),
        ]);
    }

    public function create(Request $request, InventoryService $inventory): Response
    {
        return $this->form(null, $request, $inventory);
    }

    public function edit(SellerProduct $sellerProduct, Request $request, InventoryService $inventory): Response
    {
        $sellerProduct->loadMissing('seller:id,shop_name');

        return $this->form($sellerProduct, $request, $inventory);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request);
    }

    public function update(Request $request, SellerProduct $sellerProduct): RedirectResponse
    {
        return $this->save($request, $sellerProduct);
    }

    public function destroy(SellerProduct $sellerProduct): RedirectResponse
    {
        $seller = $sellerProduct->seller;
        $core = $sellerProduct->product;
        if ($seller && $core) {
            $result = $this->sellers->deleteProduct($seller, $core);
            $message = $result['action'] === 'archived'
                ? 'Seller product archived because it appears on past orders.'
                : 'Seller product deleted.';
        } else {
            $sellerProduct->delete();
            $message = 'Seller product deleted.';
        }

        return back()->with('success', $message);
    }

    public function review(Request $request, SellerProduct $sellerProduct): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:approve,reject'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        if ($data['action'] === 'reject' && trim((string) ($data['note'] ?? '')) === '') {
            return back()->withErrors(['note' => 'A rejection reason is required.']);
        }
        $this->sellers->reviewProduct(
            $sellerProduct,
            $data['action'],
            $data['note'] ?? null,
            $request->user('admin')?->id
        );

        return back()->with('success', $data['action'] === 'approve' ? 'Seller product approved.' : 'Seller product rejected.');
    }

    public function export(Request $request)
    {
        $sellerId = $this->sellerId($request);
        $rows = SellerProduct::query()
            ->with('seller:id,shop_name')
            ->when($sellerId, fn ($q) => $q->where('seller_id', $sellerId))
            ->orderByDesc('id')
            ->get();

        $csv = "id,shop,sku,name,price,stock,status,approval\n";
        foreach ($rows as $row) {
            $csv .= implode(',', [
                $row->id,
                '"'.str_replace('"', '""', (string) $row->seller?->shop_name).'"',
                $row->sku,
                '"'.str_replace('"', '""', (string) $row->name).'"',
                $row->price,
                $row->stock_qty,
                $row->is_enabled ? 'enabled' : 'disabled',
                $row->approval_status,
            ])."\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename=seller-products.csv',
        ]);
    }

    protected function save(Request $request, ?SellerProduct $row = null): RedirectResponse
    {
        if ($request->filled('category_id') && ! $request->has('category_ids')) {
            $categoryId = (int) $request->input('category_id');
            $request->merge([
                'category_ids' => [$categoryId],
                'default_category_id' => $categoryId,
            ]);
        }

        $sellerId = (int) $request->validate([
            'seller_id' => ['required', 'exists:sellers,id'],
        ])['seller_id'];

        $maxAttachmentKb = (int) config('wento.upload.max_attachment_kb');
        $request->validate([
            'images' => ['nullable', 'array'],
            'images.*' => ['file'],
            'cover_index' => ['nullable', 'integer', 'min:0'],
            'attachment_files' => ['nullable', 'array'],
            'attachment_files.*' => ['file', 'max:'.$maxAttachmentKb],
            'attachment_labels' => ['nullable', 'array'],
            'attachment_labels.*' => ['nullable', 'string', 'max:255'],
            'attachment_descriptions' => ['nullable', 'array'],
            'attachment_descriptions.*' => ['nullable', 'string'],
        ]);

        try {
            $core = $row?->product;
            $data = $this->catalogProducts->validateProductPayload($request, $core);
            $data['seller_id'] = $sellerId;
            $data['force_approved'] = true;

            $seller = Seller::query()->findOrFail($sellerId);
            $saved = $this->sellers->saveProduct($seller, $data, $core);

            if (! $core) {
                $files = array_values($request->file('images', []) ?: []);
                $coverIndex = $request->input('cover_index');
                $coverIndex = ($coverIndex === null || $coverIndex === '') ? null : (int) $coverIndex;

                foreach ($files as $index => $file) {
                    $isCover = $coverIndex === null ? $index === 0 : $index === $coverIndex;
                    $this->products->storeImage($saved, $file, null, $isCover);
                }

                $attachmentFiles = array_values($request->file('attachment_files', []) ?: []);
                $labels = $request->input('attachment_labels', []);
                $descriptions = $request->input('attachment_descriptions', []);

                foreach ($attachmentFiles as $index => $file) {
                    $this->attachments->createAndAttach(
                        $saved,
                        $file,
                        is_string($labels[$index] ?? null) ? $labels[$index] : null,
                        is_string($descriptions[$index] ?? null) ? $descriptions[$index] : null,
                    );
                }
            } else {
                if ($request->has('image_order')) {
                    $this->products->reorderImages($saved, $request->input('image_order', []));
                }

                if ($request->filled('cover_image_id')) {
                    $image = $saved->images()->whereKey($request->integer('cover_image_id'))->first();
                    if ($image) {
                        $saved->update(['cover_path' => $image->path]);
                    }
                }
            }

            $listing = $row ?: SellerProduct::query()
                ->where('product_id', $saved->id)
                ->where('seller_id', $sellerId)
                ->first();

            if ($listing) {
                return redirect()
                    ->route('admin.multivendor.products.edit', $listing)
                    ->with('success', 'Product saved successfully.')
                    ->with('toast', true);
            }

            return back()->with('success', 'Product saved successfully.')->with('toast', true);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Could not save the product.')->with('toast', true);
        }
    }

    protected function form(?SellerProduct $row, Request $request, InventoryService $inventory): Response
    {
        $product = $row?->product;
        $props = $product
            ? $this->catalogProducts->inertiaEditProps($product, $inventory)
            : $this->catalogProducts->inertiaCreateProps($request);

        $sellerId = $row?->seller_id ?: ($request->integer('seller_id') ?: null);

        return Inertia::render('Catalog/Products/Form', [
            ...$props,
            'multivendor' => [
                'listingId' => $row?->id,
                'sellerId' => $sellerId,
                'sellers' => $this->sellerOptions(Seller::STATUS_APPROVED),
                'indexPath' => '/admin/multivendor/products',
                'savePath' => $row
                    ? '/admin/multivendor/products/'.$row->id
                    : '/admin/multivendor/products',
                'saveMethod' => $row ? 'put' : 'post',
                'deletePath' => $row ? '/admin/multivendor/products/'.$row->id : null,
                'reviewPath' => $row && $row->approval_status === SellerProduct::STATUS_PENDING
                    ? '/admin/multivendor/products/'.$row->id.'/review'
                    : null,
                'approvalStatus' => $row?->approval_status,
                'approvalNote' => $row?->approval_note,
                'shopName' => $row?->seller?->shop_name,
            ],
        ]);
    }
}
