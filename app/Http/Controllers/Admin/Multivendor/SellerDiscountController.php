<?php

namespace App\Http\Controllers\Admin\Multivendor;

use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerDiscount;
use App\Domain\Marketplace\Services\SellerService;
use App\Domain\Promo\Models\DiscountRule;
use App\Http\Controllers\Admin\Multivendor\Concerns\PresentsMultivendor;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SellerDiscountController extends Controller
{
    use PresentsMultivendor;

    public function __construct(protected SellerService $sellers) {}

    public function index(Request $request): Response
    {
        $filters = $this->listFilters($request);
        $sellerId = $this->sellerId($request);
        $query = SellerDiscount::query()->with('seller:id,shop_name,shop_slug');
        if ($sellerId) {
            $query->where('seller_id', $sellerId);
        }
        if ($filters['search'] !== '') {
            $needle = $filters['search'];
            $query->where(function ($inner) use ($needle) {
                $inner->where('name', 'like', '%'.$needle.'%')
                    ->orWhere('code', 'like', '%'.$needle.'%');
            });
        }
        if ($filters['status'] === 'enabled') {
            $query->where('is_enabled', true);
        } elseif ($filters['status'] === 'disabled') {
            $query->where('is_enabled', false);
        }
        $this->applyMappedFilters($query, $filters, [
            'name' => 'name',
            'code' => 'code',
            'action' => 'action',
            'value_range' => 'value',
        ]);

        $this->applyListIdRange($query, $filters);
        $this->applyListOrdering($query, $filters, ['id', 'name', 'code', 'action', 'value']);

        return Inertia::render('Multivendor/List', [
            'screen' => 'discounts',
            'title' => 'Discounts',
            'path' => '/admin/multivendor/discounts',
            'filters' => $filters,
            'sellers' => $this->sellerOptions(),
            'createHref' => '/admin/multivendor/discounts/create',
            'createLabel' => 'Add discount',
            'empty' => 'No seller discounts yet.',
            'columns' => [
                ['key' => 'name', 'label' => 'Discount'],
                ['key' => 'code', 'label' => 'Code'],
                ['key' => 'shop_name', 'label' => 'Seller'],
                ['key' => 'action', 'label' => 'Action'],
                ['key' => 'value', 'label' => 'Value'],
                ['key' => 'enabled_label', 'label' => 'Status'],
            ],
            'rows' => $query
                ->adminPaginate()
                ->through(fn (SellerDiscount $row) => [
                    'id' => $row->id,
                    'name' => $row->name,
                    'code' => $row->code ?: '—',
                    'shop_name' => $row->seller?->shop_name,
                    'action' => $row->action,
                    'value' => $row->value,
                    'enabled_label' => $row->is_enabled ? 'Enabled' : 'Disabled',
                    'href' => '/admin/multivendor/discounts/'.$row->id.'/edit',
                    'deleteHref' => '/admin/multivendor/discounts/'.$row->id,
                    'action_label' => 'Edit',
                ]),
        ]);
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function edit(SellerDiscount $sellerDiscount): Response
    {
        return $this->form($sellerDiscount);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request);
    }

    public function update(Request $request, SellerDiscount $sellerDiscount): RedirectResponse
    {
        return $this->save($request, $sellerDiscount);
    }

    public function destroy(SellerDiscount $sellerDiscount): RedirectResponse
    {
        $this->sellers->deleteDiscount($sellerDiscount->seller, $sellerDiscount);

        return back()->with('success', 'Seller discount deleted.');
    }

    protected function save(Request $request, ?SellerDiscount $row = null): RedirectResponse
    {
        $data = $request->validate([
            'seller_id' => ['required', 'exists:sellers,id'],
            'name' => ['required', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:40'],
            'apply_as' => ['required', 'in:catalog,cart,coupon'],
            'action' => ['required', 'in:percent,amount,set_price,buy_x_get_y'],
            'value' => ['required', 'numeric', 'min:0'],
            'min_cart' => ['nullable', 'numeric', 'min:0'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);
        $seller = Seller::query()->findOrFail((int) $data['seller_id']);
        $this->sellers->saveDiscount($seller, $data, $row);

        return redirect('/admin/multivendor/discounts')->with('success', 'Seller discount saved.');
    }

    protected function form(?SellerDiscount $row): Response
    {
        return Inertia::render('Multivendor/Form', [
            'title' => $row ? 'Edit discount' : 'Add discount',
            'path' => '/admin/multivendor/discounts',
            'method' => $row ? 'put' : 'post',
            'action' => $row ? '/admin/multivendor/discounts/'.$row->id : '/admin/multivendor/discounts',
            'values' => [
                'seller_id' => $row?->seller_id,
                'name' => $row?->name,
                'code' => $row?->code,
                'apply_as' => $row?->apply_as ?? 'catalog',
                'action' => $row?->action ?? 'percent',
                'value' => $row?->value ?? 0,
                'min_cart' => $row?->min_cart,
                'max_uses' => $row?->max_uses,
                'starts_at' => $row?->starts_at?->format('Y-m-d\TH:i'),
                'expires_at' => $row?->expires_at?->format('Y-m-d\TH:i'),
                'is_enabled' => $row?->is_enabled ?? true,
            ],
            'fields' => [
                ['name' => 'seller_id', 'label' => 'Seller', 'type' => 'seller', 'required' => true, 'disabled' => (bool) $row],
                ['name' => 'name', 'label' => 'Name', 'required' => true],
                ['name' => 'code', 'label' => 'Coupon code'],
                ['name' => 'apply_as', 'label' => 'Apply as', 'type' => 'select', 'options' => $this->mapOptions(DiscountRule::APPLY_AS)],
                ['name' => 'action', 'label' => 'Action', 'type' => 'select', 'options' => $this->mapOptions(DiscountRule::ACTIONS)],
                ['name' => 'value', 'label' => 'Value', 'type' => 'number', 'required' => true],
                ['name' => 'min_cart', 'label' => 'Minimum cart', 'type' => 'number'],
                ['name' => 'max_uses', 'label' => 'Max uses', 'type' => 'number'],
                ['name' => 'starts_at', 'label' => 'Starts', 'type' => 'datetime'],
                ['name' => 'expires_at', 'label' => 'Expires', 'type' => 'datetime'],
                ['name' => 'is_enabled', 'label' => 'Enabled', 'type' => 'checkbox'],
            ],
            'sellers' => $this->sellerOptions(Seller::STATUS_APPROVED),
        ]);
    }

    /** @param  array<string, string>  $items */
    protected function mapOptions(array $items): array
    {
        return collect($items)->map(fn (string $label, string $value) => [
            'value' => $value,
            'label' => $label,
        ])->values()->all();
    }
}
