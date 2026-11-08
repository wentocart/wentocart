<?php

namespace App\Http\Controllers\Admin\Multivendor;

use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Services\SellerService;
use App\Http\Controllers\Admin\Multivendor\Concerns\PresentsMultivendor;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SellerShopController extends Controller
{
    use PresentsMultivendor;

    public function __construct(protected SellerService $sellers) {}

    public function index(Request $request): Response
    {
        $filters = $this->listFilters($request);
        $query = Seller::query()->with('customer:id,name,email,first_name,last_name');
        if (in_array($filters['status'], Seller::STATUSES, true)) {
            $query->where('status', $filters['status']);
        }
        if ($filters['search'] !== '') {
            $needle = $filters['search'];
            $query->where(function ($inner) use ($needle) {
                $inner->where('shop_name', 'like', '%'.$needle.'%')
                    ->orWhere('shop_slug', 'like', '%'.$needle.'%')
                    ->orWhere('city', 'like', '%'.$needle.'%');
            });
        }

        $this->applySellerColumnFilters($query, $filters);
        $this->applyListIdRange($query, $filters);
        $this->applyListOrdering($query, $filters, ['id', 'shop_name', 'shop_slug', 'city', 'country_code', 'status']);

        return Inertia::render('Multivendor/List', [
            'screen' => 'shops',
            'title' => 'Seller Shop',
            'path' => '/admin/multivendor/seller-shops',
            'filters' => $filters,
            'empty' => 'No seller shops yet.',
            'columns' => [
                ['key' => 'shop_name', 'label' => 'Shop'],
                ['key' => 'shop_slug', 'label' => 'Unique name'],
                ['key' => 'city', 'label' => 'City'],
                ['key' => 'country_code', 'label' => 'Country'],
                ['key' => 'status', 'label' => 'Status'],
            ],
            'rows' => $query
                ->adminPaginate()
                ->through(fn (Seller $seller) => $this->sellerRow($seller) + [
                    'city' => $seller->city,
                    'country_code' => $seller->country_code,
                    'href' => '/admin/multivendor/seller-shops/'.$seller->id.'/edit',
                    'action_label' => 'Edit',
                ]),
        ]);
    }

    public function edit(Seller $seller): Response
    {
        return Inertia::render('Multivendor/Form', [
            'title' => 'Seller Shop',
            'path' => '/admin/multivendor/seller-shops',
            'method' => 'put',
            'action' => '/admin/multivendor/seller-shops/'.$seller->id,
            'values' => [
                'shop_name' => $seller->shop_name,
                'shop_slug' => $seller->shop_slug,
                'address_line1' => $seller->address_line1,
                'address_line2' => $seller->address_line2,
                'city' => $seller->city,
                'state' => $seller->state,
                'postal_code' => $seller->postal_code,
                'country_code' => $seller->country_code,
                'description' => $seller->description,
                'facebook_url' => $seller->facebook_url,
                'twitter_url' => $seller->twitter_url,
                'instagram_url' => $seller->instagram_url,
                'youtube_url' => $seller->youtube_url,
                'store_policy' => $seller->store_policy,
                'shipping_policy' => $seller->shipping_policy,
                'return_policy' => $seller->return_policy,
                'is_active' => $seller->is_active ?? true,
                'vacation_enabled' => $seller->vacation_enabled,
                'vacation_message' => $seller->vacation_message,
            ],
            'fields' => [
                ['name' => 'shop_name', 'label' => 'Shop name', 'required' => true],
                ['name' => 'shop_slug', 'label' => 'Unique shop name', 'required' => true, 'hint' => 'Lowercase letters, numbers, and hyphens.'],
                ['name' => 'address_line1', 'label' => 'Address', 'required' => true],
                ['name' => 'address_line2', 'label' => 'Address line 2'],
                ['name' => 'city', 'label' => 'City', 'required' => true],
                ['name' => 'state', 'label' => 'State'],
                ['name' => 'postal_code', 'label' => 'Postal code', 'required' => true],
                ['name' => 'country_code', 'label' => 'Country code', 'required' => true],
                ['name' => 'description', 'label' => 'Description', 'type' => 'textarea'],
                ['name' => 'facebook_url', 'label' => 'Facebook URL'],
                ['name' => 'twitter_url', 'label' => 'X URL'],
                ['name' => 'instagram_url', 'label' => 'Instagram URL'],
                ['name' => 'youtube_url', 'label' => 'YouTube URL'],
                ['name' => 'store_policy', 'label' => 'Store policy', 'type' => 'textarea'],
                ['name' => 'shipping_policy', 'label' => 'Shipping policy', 'type' => 'textarea'],
                ['name' => 'return_policy', 'label' => 'Return policy', 'type' => 'textarea'],
                ['name' => 'is_active', 'label' => 'Shop is visible', 'type' => 'checkbox'],
                ['name' => 'vacation_enabled', 'label' => 'Vacation mode', 'type' => 'checkbox', 'hint' => 'Products stay listed but cannot be ordered.'],
                ['name' => 'vacation_message', 'label' => 'Vacation message', 'type' => 'textarea'],
            ],
        ]);
    }

    public function update(Request $request, Seller $seller): RedirectResponse
    {
        $data = $request->validate([
            'shop_name' => ['required', 'string', 'max:160'],
            'shop_slug' => [
                'required',
                'string',
                'max:80',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('sellers', 'shop_slug')->ignore($seller->id),
            ],
            'address_line1' => ['required', 'string', 'max:190'],
            'address_line2' => ['nullable', 'string', 'max:190'],
            'city' => ['required', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:80'],
            'postal_code' => ['required', 'string', 'max:20'],
            'country_code' => ['required', 'string', 'max:4'],
            'description' => ['nullable', 'string', 'max:4000'],
            'facebook_url' => ['nullable', 'string', 'max:190'],
            'twitter_url' => ['nullable', 'string', 'max:190'],
            'instagram_url' => ['nullable', 'string', 'max:190'],
            'youtube_url' => ['nullable', 'string', 'max:190'],
            'store_policy' => ['nullable', 'string', 'max:10000'],
            'shipping_policy' => ['nullable', 'string', 'max:10000'],
            'return_policy' => ['nullable', 'string', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
            'vacation_enabled' => ['sometimes', 'boolean'],
            'vacation_message' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->sellers->updateShop($seller, $data);

        return redirect('/admin/multivendor/seller-shops')->with('success', 'Seller shop saved.');
    }
}
