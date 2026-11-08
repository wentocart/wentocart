<?php

namespace App\Http\Controllers\Admin\Multivendor;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Localization\Models\Language;
use App\Domain\Marketplace\Models\MarketplaceCommissionRule;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerCommission;
use App\Domain\Marketplace\Models\SellerEarning;
use App\Domain\Marketplace\Models\SellerLedgerEntry;
use App\Domain\Marketplace\Services\SellerService;
use App\Domain\Marketplace\Events\MarketplaceEvent;
use App\Domain\Marketplace\Services\MarketplaceLifecycleBroker;
use App\Http\Controllers\Admin\Multivendor\Concerns\PresentsMultivendor;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SellerFinanceController extends Controller
{
    use PresentsMultivendor;

    public function __construct(protected SellerService $sellers) {}

    public function earnings(Request $request): Response
    {
        $filters = $this->listFilters($request);
        $sellerId = $this->sellerId($request);
        $query = SellerEarning::query()->with(['seller:id,shop_name,shop_slug', 'sellerOrder:id,number,currency_code']);
        if ($sellerId) {
            $query->where('seller_id', $sellerId);
        }
        if (in_array($filters['status'], ['pending', 'available', 'paid', 'refunded'], true)) {
            $query->where('status', $filters['status']);
        }
        if ($filters['order_number'] !== '') {
            $needle = $filters['order_number'];
            $query->whereHas('sellerOrder', fn ($inner) => $inner->where('number', 'like', '%'.$needle.'%'));
        }
        $this->applyMappedFilters($query, $filters, [
            'commission_source' => 'commission_source',
            'commission_type' => 'commission_type',
            'gross_amount_range' => 'gross_amount',
            'commission_rate_range' => 'commission_rate',
            'commission_amount_range' => 'commission_amount',
            'net_amount_range' => 'net_amount',
        ]);

        $this->applyListIdRange($query, $filters);
        $this->applyFinanceSort($query, $filters, ['id', 'status', 'created_at', 'gross_amount', 'commission_rate', 'commission_amount', 'net_amount', 'commission_source', 'commission_type']);

        return Inertia::render('Multivendor/List', [
            'screen' => 'earnings',
            'title' => 'Seller Earnings',
            'path' => '/admin/multivendor/earnings',
            'filters' => $filters,
            'sellers' => $this->sellerOptions(),
            'exportHref' => '/admin/multivendor/earnings/export',
            'empty' => 'No seller earnings yet. A row is created when a seller order is paid.',
            'tabs' => [
                ['key' => 'all', 'label' => 'All'],
                ['key' => 'pending', 'label' => 'Pending'],
                ['key' => 'available', 'label' => 'Available'],
                ['key' => 'paid', 'label' => 'Paid'],
                ['key' => 'refunded', 'label' => 'Refunded'],
            ],
            'columns' => [
                ['key' => 'shop_name', 'label' => 'Seller'],
                ['key' => 'order_number', 'label' => 'Order'],
                ['key' => 'gross_amount', 'label' => 'Gross'],
                ['key' => 'commission_source', 'label' => 'Source'],
                ['key' => 'commission_type', 'label' => 'Type'],
                ['key' => 'commission_rate', 'label' => 'Rate %'],
                ['key' => 'commission_amount', 'label' => 'Commission'],
                ['key' => 'net_amount', 'label' => 'Net'],
                ['key' => 'status', 'label' => 'Status'],
            ],
            'rows' => $query
                ->adminPaginate()
                ->through(fn (SellerEarning $row) => [
                    'id' => $row->id,
                    'shop_name' => $row->seller?->shop_name,
                    'order_number' => $row->sellerOrder?->number ?: '—',
                    'gross_amount' => (float) $row->gross_amount,
                    'commission_source' => $row->commission_source ?: '—',
                    'commission_type' => $row->commission_type ?: '—',
                    'commission_rate' => $row->commission_rate !== null ? (float) $row->commission_rate : null,
                    'commission_amount' => (float) $row->commission_amount,
                    'net_amount' => (float) $row->net_amount,
                    'status' => $row->status,
                    'currency_code' => $row->sellerOrder?->currency_code,
                    'href' => $row->order_id ? '/admin/orders/'.$row->order_id.'/edit' : null,
                    'action_label' => $row->order_id ? 'View order' : null,
                ]),
        ]);
    }

    public function ledger(Request $request): Response
    {
        $filters = $this->listFilters($request);
        $sellerId = $this->sellerId($request);
        $query = SellerLedgerEntry::query()->with(['seller:id,shop_name,shop_slug', 'sellerOrder:id,number']);
        if ($sellerId) {
            $query->where('seller_id', $sellerId);
        }
        if ($filters['search'] !== '') {
            $needle = $filters['search'];
            $query->where(function ($inner) use ($needle) {
                $inner->where('reference', 'like', '%'.$needle.'%')
                    ->orWhere('description', 'like', '%'.$needle.'%')
                    ->orWhere('entry_type', 'like', '%'.$needle.'%');
            });
        }
        if ($filters['order_number'] !== '') {
            $needle = $filters['order_number'];
            $query->whereHas('sellerOrder', fn ($inner) => $inner->where('number', 'like', '%'.$needle.'%'));
        }
        $this->applyMappedFilters($query, $filters, [
            'entry_type' => 'entry_type',
            'direction' => 'direction',
            'reference' => 'reference',
            'amount_range' => 'amount',
            'created_at' => 'created_at',
        ]);

        $this->applyListIdRange($query, $filters);
        $this->applyFinanceSort($query, $filters, ['id', 'created_at', 'entry_type', 'direction', 'amount', 'reference']);

        return Inertia::render('Multivendor/List', [
            'screen' => 'ledger',
            'title' => 'Seller Ledger',
            'path' => '/admin/multivendor/ledger',
            'filters' => $filters,
            'sellers' => $this->sellerOptions(),
            'empty' => 'No ledger entries yet.',
            'columns' => [
                ['key' => 'shop_name', 'label' => 'Seller'],
                ['key' => 'order_number', 'label' => 'Order'],
                ['key' => 'entry_type', 'label' => 'Type'],
                ['key' => 'direction', 'label' => 'Direction'],
                ['key' => 'amount', 'label' => 'Amount'],
                ['key' => 'reference', 'label' => 'Reference'],
                ['key' => 'created_at', 'label' => 'When'],
            ],
            'hint' => 'A row is written when an earning is released. Under Marketplace settings, set Earning release to “When parent order is paid”, then pay a seller order. Credits are net earnings. Debits are refunds and paid payouts.',
            'rows' => $query
                ->adminPaginate()
                ->through(fn (SellerLedgerEntry $row) => [
                    'id' => $row->id,
                    'shop_name' => $row->seller?->shop_name,
                    'order_number' => $row->sellerOrder?->number
                        ?: ($row->seller_withdrawal_id ? 'Payout #'.$row->seller_withdrawal_id : '—'),
                    'entry_type' => $row->entry_type,
                    'direction' => $row->direction,
                    'amount' => (float) $row->amount,
                    'reference' => $row->reference,
                    'created_at' => $row->created_at?->toDateTimeString(),
                    'href' => $row->order_id ? '/admin/orders/'.$row->order_id.'/edit' : null,
                    'action_label' => $row->order_id ? 'View order' : null,
                ]),
        ]);
    }

    public function updateEarning(Request $request, SellerEarning $sellerEarning): RedirectResponse
    {
        $data = $request->validate([
            // paid/refunded require payout or order refund flows so the ledger stays consistent.
            'status' => ['required', 'in:pending,available'],
        ]);

        if (in_array($sellerEarning->status, [
            SellerEarning::STATUS_PAID,
            SellerEarning::STATUS_REFUNDED,
        ], true)) {
            return back()->withErrors([
                'status' => 'Paid or refunded earnings cannot be changed here. Use payouts or order refunds.',
            ]);
        }

        $before = (string) $sellerEarning->status;
        $sellerEarning->update(['status' => $data['status']]);
        app(MarketplaceLifecycleBroker::class)->dispatch(MarketplaceEvent::earningAdjusted(
            (int) $sellerEarning->id,
            (int) $sellerEarning->seller_id,
            auth('admin')->id() ? (int) auth('admin')->id() : null,
            $before,
            (string) $data['status'],
        ));

        return back()->with('success', 'Seller earning hold status saved.');
    }

    public function commissions(Request $request): Response
    {
        $filters = $this->listFilters($request);
        $sellerId = $this->sellerId($request);
        $query = SellerCommission::query()->with('seller:id,shop_name,shop_slug,commission_rate');
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
            'rate_range' => 'rate',
        ]);

        $this->applyListIdRange($query, $filters);
        $this->applyFinanceSort($query, $filters, ['id', 'name', 'rate']);

        return Inertia::render('Multivendor/List', [
            'screen' => 'commissions',
            'title' => 'Seller Commission',
            'path' => '/admin/multivendor/commissions',
            'filters' => $filters,
            'sellers' => $this->sellerOptions(),
            'createHref' => '/admin/multivendor/commissions/create',
            'createLabel' => 'Set commission',
            'empty' => 'No seller commissions yet.',
            'columns' => [
                ['key' => 'shop_name', 'label' => 'Seller'],
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'rate', 'label' => 'Rate %'],
                ['key' => 'live_rate', 'label' => 'Live rate %'],
                ['key' => 'default_label', 'label' => 'Default'],
                ['key' => 'enabled_label', 'label' => 'Status'],
            ],
            'rows' => $query
                ->adminPaginate()
                ->through(fn (SellerCommission $row) => [
                    'id' => $row->id,
                    'shop_name' => $row->seller?->shop_name,
                    'name' => $row->name,
                    'rate' => (float) $row->rate,
                    'live_rate' => (float) ($row->seller?->commission_rate ?? 0),
                    'default_label' => $row->is_default ? 'Default' : '—',
                    'enabled_label' => $row->is_enabled ? 'Enabled' : 'Disabled',
                    'href' => '/admin/multivendor/commissions/'.$row->id.'/edit',
                    'deleteHref' => '/admin/multivendor/commissions/'.$row->id,
                    'action_label' => 'Edit',
                ]),
        ]);
    }

    public function createCommission(): Response
    {
        return $this->commissionForm(null);
    }

    public function editCommission(SellerCommission $sellerCommission): Response
    {
        return $this->commissionForm($sellerCommission);
    }

    public function storeCommission(Request $request): RedirectResponse
    {
        return $this->saveCommission($request);
    }

    public function updateCommission(Request $request, SellerCommission $sellerCommission): RedirectResponse
    {
        return $this->saveCommission($request, $sellerCommission);
    }

    public function destroyCommission(SellerCommission $sellerCommission): RedirectResponse
    {
        $sellerCommission->delete();

        return back()->with('success', 'Seller commission deleted.');
    }

    protected function saveCommission(Request $request, ?SellerCommission $row = null): RedirectResponse
    {
        $data = $request->validate([
            'seller_id' => ['required', 'exists:sellers,id'],
            'name' => ['required', 'string', 'max:120'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_default' => ['sometimes', 'boolean'],
        ]);
        $seller = Seller::query()->findOrFail((int) $data['seller_id']);

        // Always sync the live checkout rate — list rows must not diverge from what orders charge.
        $this->sellers->setCommission(
            $seller,
            (float) $data['rate'],
            $data['notes'] ?? null,
            $data['name'],
            $row,
            auth('admin')->id() ? (int) auth('admin')->id() : null,
            (bool) ($data['is_default'] ?? false),
        );

        return redirect('/admin/multivendor/commissions')->with('success', 'Seller commission saved. Live rate updated for the next order.');
    }

    protected function commissionForm(?SellerCommission $row): Response
    {
        return Inertia::render('Multivendor/Form', [
            'title' => $row ? 'Edit commission' : 'Set commission',
            'path' => '/admin/multivendor/commissions',
            'method' => $row ? 'put' : 'post',
            'action' => $row ? '/admin/multivendor/commissions/'.$row->id : '/admin/multivendor/commissions',
            'values' => [
                'seller_id' => $row?->seller_id,
                'name' => $row?->name ?? 'Store commission',
                'rate' => $row?->rate ?? 10,
                'is_default' => $row?->is_default ?? true,
                'notes' => $row?->notes,
            ],
            'fields' => [
                ['name' => 'seller_id', 'label' => 'Seller', 'type' => 'seller', 'required' => true, 'disabled' => (bool) $row],
                ['name' => 'name', 'label' => 'Name', 'required' => true],
                ['name' => 'rate', 'label' => 'Commission rate (%)', 'type' => 'number', 'required' => true],
                ['name' => 'is_default', 'label' => 'Default rate for this seller', 'type' => 'checkbox'],
                ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
            ],
            'sellers' => $this->sellerOptions(Seller::STATUS_APPROVED),
            'hint' => 'Saving always updates the live rate charged on the next order.',
        ]);
    }

    public function rules(Request $request): Response
    {
        $filters = $this->listFilters($request);
        $query = MarketplaceCommissionRule::query()
            ->with(['seller:id,shop_name', 'product:id,sku', 'category']);
        if ($filters['search'] !== '') {
            $needle = $filters['search'];
            $query->where(function ($inner) use ($needle) {
                $inner->where('name', 'like', '%'.$needle.'%')
                    ->orWhere('scope', 'like', '%'.$needle.'%')
                    ->orWhere('type', 'like', '%'.$needle.'%');
            });
        }
        if (in_array($filters['scope'] ?? '', ['product', 'seller', 'category', 'global'], true)) {
            $query->where('scope', $filters['scope']);
        } elseif (in_array($filters['status'], ['product', 'seller', 'category', 'global'], true)) {
            $query->where('scope', $filters['status']);
        }
        if ($filters['status'] === 'enabled') {
            $query->where('is_enabled', true);
        } elseif ($filters['status'] === 'disabled') {
            $query->where('is_enabled', false);
        }
        $this->applyMappedFilters($query, $filters, [
            'name' => 'name',
            'type' => 'type',
            'value_range' => 'value',
        ]);

        $locale = Language::defaultCode();

        $this->applyListIdRange($query, $filters);
        $this->applyListOrdering($query, $filters, ['id', 'name', 'scope', 'type', 'value_label', 'enabled_label'], [
            'value_label' => 'value',
            'enabled_label' => 'is_enabled',
        ]);

        return Inertia::render('Multivendor/List', [
            'screen' => 'commission-rules',
            'title' => 'Commission Rules',
            'path' => '/admin/multivendor/commission-rules',
            'filters' => $filters,
            'createHref' => '/admin/multivendor/commission-rules/create',
            'createLabel' => 'Add rule',
            'empty' => 'No marketplace commission rules yet.',
            'tabs' => [
                ['key' => 'all', 'label' => 'All'],
                ['key' => 'product', 'label' => 'Product'],
                ['key' => 'seller', 'label' => 'Seller'],
                ['key' => 'category', 'label' => 'Category'],
                ['key' => 'global', 'label' => 'Global'],
            ],
            'columns' => [
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'scope', 'label' => 'Scope'],
                ['key' => 'type', 'label' => 'Type'],
                ['key' => 'value_label', 'label' => 'Value'],
                ['key' => 'target', 'label' => 'Target'],
                ['key' => 'enabled_label', 'label' => 'Status'],
            ],
            'rows' => $query
                ->adminPaginate()
                ->through(function (MarketplaceCommissionRule $row) use ($locale) {
                    $target = match ($row->scope) {
                        MarketplaceCommissionRule::SCOPE_PRODUCT => $row->product
                            ? ('Product #'.$row->product_id.($row->product->sku ? ' ('.$row->product->sku.')' : ''))
                            : ('Product #'.$row->product_id),
                        MarketplaceCommissionRule::SCOPE_SELLER => $row->seller?->shop_name ?: ('Seller #'.$row->seller_id),
                        MarketplaceCommissionRule::SCOPE_CATEGORY => $row->category?->translation($locale)?->name
                            ?: ('Category #'.$row->category_id),
                        default => 'Marketplace default',
                    };

                    return [
                        'id' => $row->id,
                        'name' => $row->name,
                        'scope' => $row->scope,
                        'type' => $row->type,
                        'value_label' => $row->type === MarketplaceCommissionRule::TYPE_FIXED
                            ? number_format((float) $row->value, 2)
                            : number_format((float) $row->value, 2).'%',
                        'target' => $target,
                        'enabled_label' => $row->is_enabled ? 'Enabled' : 'Disabled',
                        'href' => '/admin/multivendor/commission-rules/'.$row->id.'/edit',
                        'deleteHref' => '/admin/multivendor/commission-rules/'.$row->id,
                        'action_label' => 'Edit',
                    ];
                }),
        ]);
    }

    public function createRule(): Response
    {
        return $this->ruleForm(null);
    }

    public function editRule(MarketplaceCommissionRule $commissionRule): Response
    {
        return $this->ruleForm($commissionRule);
    }

    public function storeRule(Request $request): RedirectResponse
    {
        return $this->saveRule($request);
    }

    public function updateRule(Request $request, MarketplaceCommissionRule $commissionRule): RedirectResponse
    {
        return $this->saveRule($request, $commissionRule);
    }

    public function destroyRule(MarketplaceCommissionRule $commissionRule): RedirectResponse
    {
        $commissionRule->delete();

        return redirect('/admin/multivendor/commission-rules')->with('success', 'Commission rule deleted.');
    }

    protected function saveRule(Request $request, ?MarketplaceCommissionRule $row = null): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scope' => ['required', Rule::in([
                MarketplaceCommissionRule::SCOPE_PRODUCT,
                MarketplaceCommissionRule::SCOPE_SELLER,
                MarketplaceCommissionRule::SCOPE_CATEGORY,
                MarketplaceCommissionRule::SCOPE_GLOBAL,
            ])],
            'type' => ['required', Rule::in([
                MarketplaceCommissionRule::TYPE_PERCENT,
                MarketplaceCommissionRule::TYPE_FIXED,
            ])],
            'value' => ['required', 'numeric', 'min:0'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'seller_id' => ['nullable', 'integer', 'exists:sellers,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'is_enabled' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        if ($data['type'] === MarketplaceCommissionRule::TYPE_PERCENT && (float) $data['value'] > 100) {
            return back()->withErrors(['value' => 'Percent commission cannot exceed 100.'])->withInput();
        }

        $payload = [
            'name' => $data['name'],
            'scope' => $data['scope'],
            'type' => $data['type'],
            'value' => round((float) $data['value'], 2),
            'product_id' => null,
            'seller_id' => null,
            'category_id' => null,
            'is_enabled' => (bool) ($data['is_enabled'] ?? true),
            'notes' => $data['notes'] ?? null,
        ];

        if ($data['scope'] === MarketplaceCommissionRule::SCOPE_PRODUCT) {
            if (empty($data['product_id'])) {
                return back()->withErrors(['product_id' => 'Product is required for product-scope rules.'])->withInput();
            }
            $payload['product_id'] = (int) $data['product_id'];
        } elseif ($data['scope'] === MarketplaceCommissionRule::SCOPE_SELLER) {
            if (empty($data['seller_id'])) {
                return back()->withErrors(['seller_id' => 'Seller is required for seller-scope rules.'])->withInput();
            }
            $payload['seller_id'] = (int) $data['seller_id'];
        } elseif ($data['scope'] === MarketplaceCommissionRule::SCOPE_CATEGORY) {
            if (empty($data['category_id'])) {
                return back()->withErrors(['category_id' => 'Category is required for category-scope rules.'])->withInput();
            }
            $payload['category_id'] = (int) $data['category_id'];
        }

        if ($row) {
            $row->fill($payload)->save();
        } else {
            MarketplaceCommissionRule::query()->create($payload);
        }

        return redirect('/admin/multivendor/commission-rules')->with('success', 'Commission rule saved.');
    }

    protected function ruleForm(?MarketplaceCommissionRule $row): Response
    {
        $locale = Language::defaultCode();
        $categories = Category::query()
            ->with('translations')
            ->orderBy('position')
            ->limit(500)
            ->get()
            ->map(fn (Category $category) => [
                'value' => (string) $category->id,
                'label' => $category->translation($locale)?->name ?? 'Category #'.$category->id,
            ])
            ->all();

        $products = Product::query()
            ->orderByDesc('id')
            ->limit(500)
            ->get(['id', 'sku'])
            ->map(fn (Product $product) => [
                'value' => (string) $product->id,
                'label' => 'Product #'.$product->id.($product->sku ? ' ('.$product->sku.')' : ''),
            ])
            ->all();

        return Inertia::render('Multivendor/Form', [
            'title' => $row ? 'Edit commission rule' : 'Add commission rule',
            'path' => '/admin/multivendor/commission-rules',
            'method' => $row ? 'put' : 'post',
            'action' => $row ? '/admin/multivendor/commission-rules/'.$row->id : '/admin/multivendor/commission-rules',
            'values' => [
                'name' => $row?->name ?? '',
                'scope' => $row?->scope ?? MarketplaceCommissionRule::SCOPE_GLOBAL,
                'type' => $row?->type ?? MarketplaceCommissionRule::TYPE_PERCENT,
                'value' => $row?->value ?? 10,
                'product_id' => $row?->product_id ? (string) $row->product_id : '',
                'seller_id' => $row?->seller_id,
                'category_id' => $row?->category_id ? (string) $row->category_id : '',
                'is_enabled' => $row?->is_enabled ?? true,
                'notes' => $row?->notes,
            ],
            'fields' => [
                ['name' => 'name', 'label' => 'Name', 'required' => true],
                [
                    'name' => 'scope',
                    'label' => 'Scope',
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        ['value' => 'product', 'label' => 'Product'],
                        ['value' => 'seller', 'label' => 'Seller'],
                        ['value' => 'category', 'label' => 'Category'],
                        ['value' => 'global', 'label' => 'Global'],
                    ],
                    'hint' => 'Precedence: Product → Seller → Category → Global.',
                ],
                [
                    'name' => 'type',
                    'label' => 'Type',
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        ['value' => 'percent', 'label' => 'Percent'],
                        ['value' => 'fixed', 'label' => 'Fixed amount'],
                    ],
                ],
                ['name' => 'value', 'label' => 'Value', 'type' => 'number', 'required' => true, 'hint' => 'Percent 0–100, or fixed currency amount.'],
                ['name' => 'product_id', 'label' => 'Product', 'type' => 'select', 'options' => $products, 'hint' => 'Required when scope is Product.'],
                ['name' => 'seller_id', 'label' => 'Seller', 'type' => 'seller', 'hint' => 'Required when scope is Seller.'],
                ['name' => 'category_id', 'label' => 'Category', 'type' => 'select', 'options' => $categories, 'hint' => 'Required when scope is Category.'],
                ['name' => 'is_enabled', 'label' => 'Enabled', 'type' => 'checkbox'],
                ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
            ],
            'sellers' => $this->sellerOptions(Seller::STATUS_APPROVED),
            'hint' => 'Rules are snapshotted onto seller orders at checkout. Changing a rule does not rewrite historical orders.',
        ]);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  list<string>  $allowed
     */
    protected function applyFinanceSort($query, array $filters, array $allowed): void
    {
        $sort = $filters['sort'] ?? 'id';
        $dir = ($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $table = $query->getModel()->getTable();

        if ($sort === 'live_rate') {
            $sellers = (new Seller)->getTable();
            $query->orderBy(
                Seller::query()->select('commission_rate')->whereColumn($sellers.'.id', $table.'.seller_id')->limit(1),
                $dir
            )->orderByDesc($table.'.id');

            return;
        }

        if ($sort === 'default_label' && \Illuminate\Support\Facades\Schema::hasColumn($table, 'is_default')) {
            $query->orderBy('is_default', $dir)->orderByDesc($table.'.id');

            return;
        }

        if ($sort === 'shop_name') {
            $sellers = (new Seller)->getTable();
            $query->orderBy(
                Seller::query()->select('shop_name')->whereColumn($sellers.'.id', $table.'.seller_id')->limit(1),
                $dir
            )->orderByDesc($table.'.id');

            return;
        }

        if ($sort === 'order_number' && \Illuminate\Support\Facades\Schema::hasColumn($table, 'seller_order_id')) {
            $orders = (new \App\Domain\Marketplace\Models\SellerOrder)->getTable();
            $query->orderBy(
                \App\Domain\Marketplace\Models\SellerOrder::query()->select('number')->whereColumn($orders.'.id', $table.'.seller_order_id')->limit(1),
                $dir
            )->orderByDesc($table.'.id');

            return;
        }

        $this->applyListOrdering($query, $filters, $allowed);
    }
}
