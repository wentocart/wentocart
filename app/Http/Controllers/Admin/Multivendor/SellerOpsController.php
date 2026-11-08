<?php

namespace App\Http\Controllers\Admin\Multivendor;

use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerPaymentMethod;
use App\Domain\Marketplace\Models\SellerShippingMethod;
use App\Domain\Marketplace\Models\SellerWithdrawal;
use App\Domain\Marketplace\Services\SellerCatalogService;
use App\Domain\Marketplace\Services\SellerService;
use App\Domain\Security\CsvSafe;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Http\Controllers\Admin\Multivendor\Concerns\PresentsMultivendor;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SellerOpsController extends Controller
{
    use PresentsMultivendor;

    public function __construct(
        protected SellerService $sellers,
        protected SellerCatalogService $catalog,
    ) {}

    public function shipping(Request $request): Response
    {
        $filters = $this->listFilters($request);
        $sellerId = $this->sellerId($request);
        $query = SellerShippingMethod::query()->with('seller:id,shop_name,shop_slug');
        if ($sellerId) {
            $query->where('seller_id', $sellerId);
        }
        if ($filters['search'] !== '') {
            $query->where('name', 'like', '%'.$filters['search'].'%');
        }
        if ($filters['status'] === 'enabled') {
            $query->where('is_enabled', true);
        } elseif ($filters['status'] === 'disabled') {
            $query->where('is_enabled', false);
        }
        $this->applyMappedFilters($query, $filters, [
            'name' => 'name',
            'type' => 'type',
            'price_range' => 'price',
        ]);

        $this->applyListIdRange($query, $filters);
        $this->applyListOrdering($query, $filters, ['id', 'name', 'price', 'type']);

        return Inertia::render('Multivendor/List', [
            'screen' => 'shipping',
            'title' => 'Seller Shipping',
            'path' => '/admin/multivendor/shipping',
            'filters' => $filters,
            'sellers' => $this->sellerOptions(),
            'createHref' => '/admin/multivendor/shipping/create',
            'createLabel' => 'Add shipping method',
            'empty' => 'No seller shipping methods yet.',
            'columns' => [
                ['key' => 'name', 'label' => 'Method'],
                ['key' => 'shop_name', 'label' => 'Seller'],
                ['key' => 'type', 'label' => 'Type'],
                ['key' => 'price', 'label' => 'Price'],
                ['key' => 'enabled_label', 'label' => 'Status'],
            ],
            'rows' => $query
                ->adminPaginate()
                ->through(fn (SellerShippingMethod $row) => [
                    'id' => $row->id,
                    'name' => $row->name,
                    'shop_name' => $row->seller?->shop_name,
                    'type' => $row->type,
                    'price' => (float) $row->price,
                    'enabled_label' => $row->is_enabled ? 'Enabled' : 'Disabled',
                    'href' => '/admin/multivendor/shipping/'.$row->id.'/edit',
                    'deleteHref' => '/admin/multivendor/shipping/'.$row->id,
                    'action_label' => 'Edit',
                ]),
        ]);
    }

    public function createShipping(): Response
    {
        return $this->shippingForm(null);
    }

    public function editShipping(SellerShippingMethod $sellerShippingMethod): Response
    {
        return $this->shippingForm($sellerShippingMethod);
    }

    public function storeShipping(Request $request): RedirectResponse
    {
        return $this->saveShipping($request);
    }

    public function updateShipping(Request $request, SellerShippingMethod $sellerShippingMethod): RedirectResponse
    {
        return $this->saveShipping($request, $sellerShippingMethod);
    }

    public function destroyShipping(SellerShippingMethod $sellerShippingMethod): RedirectResponse
    {
        $this->sellers->deleteShipping($sellerShippingMethod->seller, $sellerShippingMethod);

        return back()->with('success', 'Seller shipping method deleted.');
    }

    public function payments(Request $request): Response
    {
        $filters = $this->listFilters($request);
        $sellerId = $this->sellerId($request);
        $query = SellerPaymentMethod::query()->with('seller:id,shop_name,shop_slug');
        if ($sellerId) {
            $query->where('seller_id', $sellerId);
        }
        if ($filters['search'] !== '') {
            $query->where('name', 'like', '%'.$filters['search'].'%');
        }
        if ($filters['status'] === 'enabled') {
            $query->where('is_enabled', true);
        } elseif ($filters['status'] === 'disabled') {
            $query->where('is_enabled', false);
        }
        $this->applyMappedFilters($query, $filters, [
            'name' => 'name',
            'type' => 'type',
            'account_name' => 'account_name',
        ]);

        $this->applyListIdRange($query, $filters);
        $this->applyListOrdering($query, $filters, ['id', 'name', 'type', 'account_name']);

        return Inertia::render('Multivendor/List', [
            'screen' => 'payments',
            'title' => 'Seller Payout Methods',
            'path' => '/admin/multivendor/payments',
            'filters' => $filters,
            'sellers' => $this->sellerOptions(),
            'createHref' => '/admin/multivendor/payments/create',
            'createLabel' => 'Add payout method',
            'empty' => 'No seller payout methods yet.',
            'columns' => [
                ['key' => 'name', 'label' => 'Method'],
                ['key' => 'shop_name', 'label' => 'Seller'],
                ['key' => 'type', 'label' => 'Type'],
                ['key' => 'account_name', 'label' => 'Account'],
                ['key' => 'enabled_label', 'label' => 'Status'],
            ],
            'rows' => $query
                ->adminPaginate()
                ->through(fn (SellerPaymentMethod $row) => [
                    'id' => $row->id,
                    'name' => $row->name,
                    'shop_name' => $row->seller?->shop_name,
                    'type' => $row->type,
                    'account_name' => $row->account_name ?: ($row->paypal_email ?: '—'),
                    'enabled_label' => $row->is_enabled ? 'Enabled' : 'Disabled',
                    'href' => '/admin/multivendor/payments/'.$row->id.'/edit',
                    'deleteHref' => '/admin/multivendor/payments/'.$row->id,
                    'action_label' => 'Edit',
                ]),
        ]);
    }

    public function createPayment(): Response
    {
        return $this->paymentForm(null);
    }

    public function editPayment(SellerPaymentMethod $sellerPaymentMethod): Response
    {
        return $this->paymentForm($sellerPaymentMethod);
    }

    public function storePayment(Request $request): RedirectResponse
    {
        return $this->savePayment($request);
    }

    public function updatePayment(Request $request, SellerPaymentMethod $sellerPaymentMethod): RedirectResponse
    {
        return $this->savePayment($request, $sellerPaymentMethod);
    }

    public function destroyPayment(SellerPaymentMethod $sellerPaymentMethod): RedirectResponse
    {
        $this->sellers->deletePayment($sellerPaymentMethod->seller, $sellerPaymentMethod);

        return back()->with('success', 'Seller payout method deleted.');
    }

    public function payouts(Request $request): Response
    {
        $filters = $this->listFilters($request);
        $sellerId = $this->sellerId($request);
        $query = SellerWithdrawal::query()->with('seller:id,shop_name,shop_slug');
        if ($sellerId) {
            $query->where('seller_id', $sellerId);
        }
        if (in_array($filters['status'], SellerWithdrawal::STATUSES, true)) {
            $query->where('status', $filters['status']);
        }
        $this->applyMappedFilters($query, $filters, [
            'amount_range' => 'amount',
            'created_at' => 'created_at',
        ]);

        $this->applyListIdRange($query, $filters);
        $this->applyListOrdering($query, $filters, ['id', 'amount', 'status', 'created_at']);

        return Inertia::render('Multivendor/List', [
            'screen' => 'payouts',
            'title' => 'Seller Payouts',
            'path' => '/admin/multivendor/payouts',
            'filters' => $filters,
            'sellers' => $this->sellerOptions(),
            'createHref' => '/admin/multivendor/payouts/create',
            'createLabel' => 'Create payout',
            'exportHref' => '/admin/multivendor/payouts/export',
            'empty' => 'No seller payouts yet.',
            'tabs' => [
                ['key' => 'all', 'label' => 'All'],
                ['key' => 'requested', 'label' => 'Requested'],
                ['key' => 'under_review', 'label' => 'Under review'],
                ['key' => 'processing', 'label' => 'Processing'],
                ['key' => 'paid', 'label' => 'Paid'],
                ['key' => 'failed', 'label' => 'Failed'],
                ['key' => 'rejected', 'label' => 'Rejected'],
            ],
            'columns' => [
                ['key' => 'shop_name', 'label' => 'Seller'],
                ['key' => 'amount', 'label' => 'Amount'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'created_at', 'label' => 'Requested'],
            ],
            'rows' => $query
                ->adminPaginate()
                ->through(fn (SellerWithdrawal $row) => [
                    'id' => $row->id,
                    'shop_name' => $row->seller?->shop_name,
                    'amount' => (float) $row->amount,
                    'status' => $row->status,
                    'created_at' => $row->created_at?->toDateTimeString(),
                    'href' => '/admin/multivendor/payouts/'.$row->id,
                    'action_label' => 'Review',
                ]),
        ]);
    }

    public function createPayout(): Response
    {
        return Inertia::render('Multivendor/Form', [
            'title' => 'Create payout',
            'path' => '/admin/multivendor/payouts',
            'method' => 'post',
            'action' => '/admin/multivendor/payouts',
            'values' => [
                'seller_id' => '',
                'amount' => '',
                'note' => '',
            ],
            'fields' => [
                ['name' => 'seller_id', 'label' => 'Seller', 'type' => 'seller', 'required' => true],
                ['name' => 'amount', 'label' => 'Amount', 'type' => 'number', 'required' => true, 'hint' => 'The seller name shows how much is available for payout. Pending earnings are not included.'],
                ['name' => 'note', 'label' => 'Note', 'type' => 'textarea'],
            ],
            'sellers' => $this->payoutSellerOptions(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    protected function payoutSellerOptions(): array
    {
        $payouts = app(\App\Domain\Marketplace\Services\SellerPayoutService::class);

        return array_map(function (array $option) use ($payouts) {
            $seller = Seller::query()->find($option['id']);
            $available = $seller ? $payouts->availablePayoutAmount($seller) : 0.0;
            $option['available_label'] = number_format($available, 2);

            return $option;
        }, $this->sellerOptions(Seller::STATUS_APPROVED));
    }

    public function storePayout(Request $request): RedirectResponse
    {
        $sellerId = (int) $request->input('seller_id');
        $data = $request->validate([
            'seller_id' => ['required', 'exists:sellers,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:1000'],
            'seller_payment_method_id' => [
                'nullable',
                Rule::exists('seller_payment_methods', 'id')->where('seller_id', $sellerId),
            ],
        ]);
        $seller = Seller::query()->findOrFail((int) $data['seller_id']);
        $this->catalog->requestWithdrawal($seller, $data);

        return redirect('/admin/multivendor/payouts')->with('success', 'Seller payout created.');
    }

    public function showPayout(SellerWithdrawal $sellerWithdrawal): Response
    {
        $sellerWithdrawal->load(['seller', 'paymentMethod']);

        return Inertia::render('Multivendor/PayoutShow', [
            'payout' => [
                'id' => $sellerWithdrawal->id,
                'amount' => (float) $sellerWithdrawal->amount,
                'status' => $sellerWithdrawal->status,
                'note' => $sellerWithdrawal->note,
                'shop_name' => $sellerWithdrawal->seller?->shop_name,
                'payment_name' => $sellerWithdrawal->paymentMethod?->name,
                'created_at' => $sellerWithdrawal->created_at?->toDateTimeString(),
                'processed_at' => $sellerWithdrawal->processed_at?->toDateTimeString(),
                'payout_reference' => $sellerWithdrawal->payout_reference,
                'payout_rail' => $sellerWithdrawal->payout_rail,
                'payout_bank_account' => $sellerWithdrawal->seller?->payout_bank_account,
                'payout_bank_ifsc' => $sellerWithdrawal->seller?->payout_bank_ifsc,
                'payout_upi_id' => $sellerWithdrawal->seller?->payout_upi_id,
                'payout_paypal_email' => $sellerWithdrawal->seller?->payout_paypal_email,
                'available' => $sellerWithdrawal->seller
                    ? $this->catalog->availablePayoutAmount($sellerWithdrawal->seller)
                    : 0.0,
            ],
        ]);
    }

    public function processPayout(Request $request, SellerWithdrawal $sellerWithdrawal): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:review,approve,reject,process,pay,fail,retry'],
            'note' => ['nullable', 'string', 'max:1000'],
            'payout_reference' => ['nullable', 'string', 'max:80'],
            'payout_rail' => ['nullable', 'string', 'max:32'],
        ]);
        if ($data['action'] === 'pay') {
            $sellerWithdrawal->payout_reference = $data['payout_reference']
                ?? ('OFFLINE-'.$sellerWithdrawal->id);
            $sellerWithdrawal->payout_rail = $data['payout_rail'] ?? 'offline';
            $sellerWithdrawal->save();
        }
        $this->catalog->processWithdrawal($sellerWithdrawal, $data['action'], $request->user('admin')?->id, $data['note'] ?? null);

        return back()->with('success', 'Seller payout updated.');
    }

    public function exportPayouts(Request $request): StreamedResponse
    {
        $rows = SellerWithdrawal::query()
            ->with('seller')
            ->orderByDesc('id')
            ->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            CsvSafe::put($out, ['id', 'shop', 'amount', 'status', 'rail', 'bank_account', 'ifsc', 'upi', 'paypal', 'reference', 'requested_at']);
            foreach ($rows as $row) {
                CsvSafe::put($out, [
                    $row->id,
                    $row->seller?->shop_name,
                    $row->amount,
                    $row->status,
                    $row->payout_rail,
                    $row->seller?->payout_bank_account,
                    $row->seller?->payout_bank_ifsc,
                    $row->seller?->payout_upi_id,
                    $row->seller?->payout_paypal_email,
                    $row->payout_reference,
                    $row->created_at?->toDateTimeString(),
                ]);
            }
            fclose($out);
        }, 'seller-payouts.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function saveShipping(Request $request, ?SellerShippingMethod $row = null): RedirectResponse
    {
        $data = $request->validate([
            'seller_id' => ['required', 'exists:sellers,id'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
            'type' => ['required', 'in:flat,free,price_based,weight_based,local_pickup'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);
        $seller = Seller::query()->findOrFail((int) $data['seller_id']);
        $this->sellers->saveShipping($seller, $data, $row);

        return redirect('/admin/multivendor/shipping')->with('success', 'Seller shipping method saved.');
    }

    protected function shippingForm(?SellerShippingMethod $row): Response
    {
        return Inertia::render('Multivendor/Form', [
            'title' => $row ? 'Edit shipping method' : 'Add shipping method',
            'path' => '/admin/multivendor/shipping',
            'method' => $row ? 'put' : 'post',
            'action' => $row ? '/admin/multivendor/shipping/'.$row->id : '/admin/multivendor/shipping',
            'values' => [
                'seller_id' => $row?->seller_id,
                'name' => $row?->name,
                'description' => $row?->description,
                'type' => $row?->type ?? 'flat',
                'price' => $row?->price ?? 0,
                'is_enabled' => $row?->is_enabled ?? true,
            ],
            'fields' => [
                ['name' => 'seller_id', 'label' => 'Seller', 'type' => 'seller', 'required' => true, 'disabled' => (bool) $row],
                ['name' => 'name', 'label' => 'Name', 'required' => true],
                ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => collect(ShippingMethod::TYPES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all()],
                ['name' => 'price', 'label' => 'Price', 'type' => 'number'],
                ['name' => 'description', 'label' => 'Description', 'type' => 'textarea'],
                ['name' => 'is_enabled', 'label' => 'Enabled', 'type' => 'checkbox'],
            ],
            'sellers' => $this->sellerOptions(Seller::STATUS_APPROVED),
        ]);
    }

    protected function savePayment(Request $request, ?SellerPaymentMethod $row = null): RedirectResponse
    {
        $data = $request->validate([
            'seller_id' => ['required', 'exists:sellers,id'],
            'type' => ['required', 'in:bank,paypal,other'],
            'name' => ['required', 'string', 'max:160'],
            'account_name' => ['nullable', 'string', 'max:160'],
            'account_number' => ['nullable', 'string', 'max:80'],
            'bank_name' => ['nullable', 'string', 'max:160'],
            'routing_number' => ['nullable', 'string', 'max:80'],
            'paypal_email' => ['nullable', 'email', 'max:190'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_default' => ['sometimes', 'boolean'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);
        $seller = Seller::query()->findOrFail((int) $data['seller_id']);
        $this->sellers->savePayment($seller, $data, $row);

        return redirect('/admin/multivendor/payments')->with('success', 'Seller payout method saved.');
    }

    protected function paymentForm(?SellerPaymentMethod $row): Response
    {
        return Inertia::render('Multivendor/Form', [
            'title' => $row ? 'Edit payout method' : 'Add payout method',
            'path' => '/admin/multivendor/payments',
            'method' => $row ? 'put' : 'post',
            'action' => $row ? '/admin/multivendor/payments/'.$row->id : '/admin/multivendor/payments',
            'values' => [
                'seller_id' => $row?->seller_id,
                'type' => $row?->type ?? 'bank',
                'name' => $row?->name,
                'account_name' => $row?->account_name,
                'account_number' => $row?->account_number,
                'bank_name' => $row?->bank_name,
                'routing_number' => $row?->routing_number,
                'paypal_email' => $row?->paypal_email,
                'notes' => $row?->notes,
                'is_default' => $row?->is_default ?? false,
                'is_enabled' => $row?->is_enabled ?? true,
            ],
            'fields' => [
                ['name' => 'seller_id', 'label' => 'Seller', 'type' => 'seller', 'required' => true, 'disabled' => (bool) $row],
                ['name' => 'name', 'label' => 'Name', 'required' => true],
                ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => collect(SellerPaymentMethod::TYPES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all()],
                ['name' => 'account_name', 'label' => 'Account name'],
                ['name' => 'account_number', 'label' => 'Account number'],
                ['name' => 'bank_name', 'label' => 'Bank name'],
                ['name' => 'routing_number', 'label' => 'Routing number'],
                ['name' => 'paypal_email', 'label' => 'PayPal email', 'type' => 'email'],
                ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
                ['name' => 'is_default', 'label' => 'Default method', 'type' => 'checkbox'],
                ['name' => 'is_enabled', 'label' => 'Enabled', 'type' => 'checkbox'],
            ],
            'sellers' => $this->sellerOptions(Seller::STATUS_APPROVED),
        ]);
    }
}
