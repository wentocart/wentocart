<?php

namespace App\Http\Controllers\Admin\Multivendor;

use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Services\SellerService;
use App\Http\Controllers\Admin\Multivendor\Concerns\PresentsMultivendor;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SellerProfileController extends Controller
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
                $inner->where('profile_name', 'like', '%'.$needle.'%')
                    ->orWhere('email', 'like', '%'.$needle.'%')
                    ->orWhere('first_name', 'like', '%'.$needle.'%')
                    ->orWhere('last_name', 'like', '%'.$needle.'%');
            });
        }

        $this->applySellerColumnFilters($query, $filters);
        $this->applyListIdRange($query, $filters);
        $this->applySellerPeopleSort($query, $filters, ['id', 'profile_name', 'shop_name', 'email', 'status']);

        return Inertia::render('Multivendor/List', [
            'screen' => 'profiles',
            'title' => 'Seller Profile',
            'path' => '/admin/multivendor/seller-profiles',
            'filters' => $filters,
            'exportHref' => '/admin/multivendor/seller-profiles/export',
            'empty' => 'No seller profiles yet.',
            'columns' => [
                ['key' => 'profile_name', 'label' => 'Profile'],
                ['key' => 'seller', 'label' => 'Name'],
                ['key' => 'email', 'label' => 'Email'],
                ['key' => 'shop_name', 'label' => 'Shop'],
                ['key' => 'status', 'label' => 'Status'],
            ],
            'rows' => $query
                ->adminPaginate()
                ->through(fn (Seller $seller) => $this->sellerRow($seller) + [
                    'seller' => $seller->displayName(),
                    'href' => '/admin/multivendor/seller-profiles/'.$seller->id.'/edit',
                    'action_label' => 'Edit',
                    'can_moderate' => in_array($seller->status, [Seller::STATUS_PENDING, Seller::STATUS_BLOCKED], true),
                ]),
        ]);
    }

    public function edit(Seller $seller): Response
    {
        return Inertia::render('Multivendor/Form', [
            'title' => 'Seller Profile',
            'path' => '/admin/multivendor/seller-profiles',
            'method' => 'put',
            'action' => '/admin/multivendor/seller-profiles/'.$seller->id,
            'values' => [
                'first_name' => $seller->first_name,
                'last_name' => $seller->last_name,
                'profile_name' => $seller->profile_name,
                'email' => $seller->email,
                'phone' => $seller->phone,
                'contact_name' => $seller->contact_name,
                'contact_phone' => $seller->contact_phone,
                'contact_email' => $seller->contact_email,
                'tax_id' => $seller->tax_id,
            ],
            'fields' => [
                ['name' => 'first_name', 'label' => 'First name', 'required' => true],
                ['name' => 'last_name', 'label' => 'Last name', 'required' => true],
                ['name' => 'profile_name', 'label' => 'Profile name', 'required' => true],
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
                ['name' => 'phone', 'label' => 'Phone', 'required' => true],
                ['name' => 'contact_name', 'label' => 'Contact name', 'required' => true],
                ['name' => 'contact_phone', 'label' => 'Contact phone', 'required' => true],
                ['name' => 'contact_email', 'label' => 'Contact email', 'type' => 'email'],
                ['name' => 'tax_id', 'label' => 'Tax ID'],
            ],
        ]);
    }

    public function update(Request $request, Seller $seller): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'profile_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['required', 'string', 'max:40'],
            'contact_name' => ['required', 'string', 'max:120'],
            'contact_phone' => ['required', 'string', 'max:40'],
            'contact_email' => ['nullable', 'email', 'max:190'],
            'tax_id' => ['nullable', 'string', 'max:80'],
        ]);
        $this->sellers->updateProfile($seller, $data);

        return redirect('/admin/multivendor/seller-profiles')->with('success', 'Seller profile saved.');
    }
}
