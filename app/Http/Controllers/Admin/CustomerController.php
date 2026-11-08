<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerAddress;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Customer\Services\CustomerDeskService;
use App\Domain\Customer\Services\CustomerService;
use App\Domain\Localization\Models\Country;
use App\Domain\Plugin\Support\ModuleBuses;
use App\Domain\Security\CustomerPassword;
use App\Domain\Settings\CustomerSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerController extends Controller
{
    public function index(Request $request, CustomerService $customers): Response
    {
        $page = $customers->paginate($request);
        $throwaway = \App\Domain\Customer\Models\Customer::query();
        $moduleList = ModuleBuses::applyList('customers.customers', $throwaway);
        $page->getCollection()->transform(function ($row) use ($moduleList) {
            if (is_object($row) && method_exists($row, 'setAttribute')) {
                $row->setAttribute('module_values', ModuleBuses::rowValues($row, $moduleList['columns']));
            } elseif (is_array($row) || is_object($row)) {
                $values = [];
                foreach ($moduleList['columns'] as $column) {
                    $key = $column['key'];
                    $raw = is_array($row) ? ($row[$key] ?? $row['notes'] ?? '') : ($row->$key ?? $row->notes ?? '');
                    $values[$key] = (string) $raw;
                }
                if (is_array($row)) {
                    $row['module_values'] = $values;
                } else {
                    $row->module_values = $values;
                }
            }

            return $row;
        });

        return Inertia::render('Customers/Index', [
            'customers' => $page,
            'stats' => Inertia::defer(fn () => $customers->stats()),
            'filters' => [
                'search' => $request->input('search', ''),
                'name' => $request->input('name', ''),
                'email' => $request->input('email', ''),
                'phone' => $request->input('phone', ''),
                'status' => $request->input('status', ''),
                'enabled' => $request->input('enabled', ''),
                'group' => $request->input('group', ''),
                'id_min' => $request->input('id_min', ''),
                'id_max' => $request->input('id_max', ''),
                'orders' => $request->input('orders', ''),
                'orders_min' => $request->input('orders_min', ''),
                'orders_max' => $request->input('orders_max', ''),
                'spent' => $request->input('spent', ''),
                'spent_min' => $request->input('spent_min', ''),
                'spent_max' => $request->input('spent_max', ''),
                'location' => $request->input('location', ''),
                'country' => $request->input('country', ''),
                'created' => $request->input('created', ''),
                'sort' => $request->input('sort', 'created_at'),
                'dir' => strtolower((string) $request->input('dir', 'desc')) === 'asc' ? 'asc' : 'desc',
            ],
            'countries' => fn () => Country::optionsForAddresses(),
            'groups' => CustomerGroup::catalog(),
            'moduleList' => $moduleList,
        ]);
    }

    public function exportAddresses(Request $request, CustomerService $customers): StreamedResponse
    {
        $csv = $customers->exportAddressesCsv($request);

        return response()->streamDownload(fn () => print ($csv), 'addresses.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function importAddresses(Request $request, CustomerService $customers): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:8192']]);
        $count = $customers->importAddressesCsv($request->file('file')->getContent());

        return back()->with('success', "Imported {$count} addresses.");
    }

    public function importAddressesSample(CustomerService $customers)
    {
        return $this->csvDownload('addresses-sample.csv', $customers->sampleAddressesCsv());
    }

    public function addresses(Request $request): Response
    {
        $filters = $this->addressFilters($request);

        $query = CustomerAddress::query()
            ->with(['customer:id,name,first_name,last_name,email']);

        if ($filters['customer'] !== '') {
            $needle = $filters['customer'];
            $query->whereHas('customer', function ($customer) use ($needle) {
                $customer->where('name', 'like', "%{$needle}%")
                    ->orWhere('first_name', 'like', "%{$needle}%")
                    ->orWhere('last_name', 'like', "%{$needle}%")
                    ->orWhere('email', 'like', "%{$needle}%");
            });
        }

        if ($filters['customer_id'] !== '') {
            $query->where('customer_id', (int) $filters['customer_id']);
        }

        if ($filters['label'] !== '') {
            $query->where('label', 'like', '%'.$filters['label'].'%');
        }

        if ($filters['address'] !== '') {
            $needle = $filters['address'];
            $query->where(function ($inner) use ($needle) {
                $inner->where('line1', 'like', "%{$needle}%")
                    ->orWhere('line2', 'like', "%{$needle}%")
                    ->orWhere('city', 'like', "%{$needle}%")
                    ->orWhere('postal_code', 'like', "%{$needle}%")
                    ->orWhere('country_code', 'like', "%{$needle}%");
            });
        }

        if ($filters['type'] === 'billing') {
            $query->where('is_default_billing', true);
        } elseif ($filters['type'] === 'shipping') {
            $query->where('is_default_shipping', true);
        } elseif ($filters['type'] === 'other') {
            $query->where('is_default_billing', false)->where('is_default_shipping', false);
        }

        if ($filters['id_min'] !== '') {
            $query->where((new CustomerAddress)->qualifyColumn('id'), '>=', (int) $filters['id_min']);
        }
        if ($filters['id_max'] !== '') {
            $query->where((new CustomerAddress)->qualifyColumn('id'), '<=', (int) $filters['id_max']);
        }

        $dir = $filters['dir'];
        $sort = $filters['sort'];
        if ($sort === 'customer') {
            $customersTable = (new Customer)->getTable();
            $query
                ->leftJoin($customersTable, (new Customer)->qualifyColumn('id'), '=', (new CustomerAddress)->qualifyColumn('customer_id'))
                ->orderBy((new Customer)->qualifyColumn('name'), $dir)
                ->orderBy((new Customer)->qualifyColumn('email'), $dir)
                ->select((new CustomerAddress)->qualifyColumn('*'));
        } elseif ($sort === 'label') {
            $query->orderBy('label', $dir)->orderBy('id', $dir);
        } elseif ($sort === 'address') {
            $query->orderBy('city', $dir)->orderBy('line1', $dir)->orderBy('id', $dir);
        } elseif ($sort === 'type') {
            $query
                ->orderBy('is_default_shipping', $dir)
                ->orderBy('is_default_billing', $dir)
                ->orderBy('id', $dir);
        } elseif ($sort === 'id') {
            $query->orderBy('id', $dir);
        } else {
            $query->orderBy('created_at', $dir)->orderBy('id', $dir);
        }

        $addresses = $query->adminPaginate();

        $counts = [
            'all' => CustomerAddress::query()->count(),
            'shipping' => CustomerAddress::query()->where('is_default_shipping', true)->count(),
            'billing' => CustomerAddress::query()->where('is_default_billing', true)->count(),
            'other' => CustomerAddress::query()
                ->where('is_default_billing', false)
                ->where('is_default_shipping', false)
                ->count(),
        ];

        $panel = null;
        if ($request->boolean('create')) {
            $panel = ['mode' => 'create'];
            if ($customerId = $request->integer('customer_id')) {
                $forCustomer = Customer::query()
                    ->select(['id', 'name', 'first_name', 'last_name', 'email', 'phone'])
                    ->find($customerId);
                if ($forCustomer) {
                    $label = trim((string) ($forCustomer->name ?: implode(' ', array_filter([
                        $forCustomer->first_name,
                        $forCustomer->last_name,
                    ]))));
                    $panel['customer_id'] = $forCustomer->id;
                    $panel['customer_label'] = $label !== ''
                        ? $label.' ('.$forCustomer->email.')'
                        : (string) $forCustomer->email;
                    $panel['first_name'] = $forCustomer->first_name;
                    $panel['last_name'] = $forCustomer->last_name;
                    $panel['phone'] = $forCustomer->phone;
                    $panel['customer_locked'] = true;
                }
            }
        } elseif ($editId = $request->integer('edit')) {
            $editing = CustomerAddress::query()
                ->with(['customer:id,name,first_name,last_name,email'])
                ->find($editId);
            if ($editing) {
                $panel = [
                    'mode' => 'edit',
                    'address' => $this->addressPanelPayload($editing),
                ];
            }
        }

        return Inertia::render('Customers/Addresses', [
            'addresses' => $addresses,
            'countries' => Country::optionsForAddresses(),
            'filters' => $filters,
            'counts' => $counts,
            'panel' => $panel,
        ]);
    }

    public function searchAddressCustomers(Request $request, CustomerService $customers): JsonResponse
    {
        return response()->json([
            'results' => $customers->suggest($request->string('q')->toString()),
        ]);
    }

    public function storeStandaloneAddress(Request $request, CustomerService $customers): RedirectResponse
    {
        $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
        ]);
        $customer = Customer::query()->findOrFail($request->integer('customer_id'));
        $customers->addAddress($customer, $this->validatedAddress($request));

        return $this->redirectToAddressesIndex($request, 'Address saved.');
    }

    public function updateStandaloneAddress(Request $request, CustomerAddress $customerAddress, CustomerService $customers): RedirectResponse
    {
        $customerAddress->loadMissing('customer');
        $customers->updateAddress($customerAddress->customer, $customerAddress->id, $this->validatedAddress($request));

        return $this->redirectToAddressesIndex($request, 'Address updated.');
    }

    public function destroyStandaloneAddress(Request $request, CustomerAddress $customerAddress, CustomerService $customers): RedirectResponse
    {
        $customerAddress->loadMissing('customer');
        $customers->removeAddress($customerAddress->customer, $customerAddress->id);

        return $this->redirectToAddressesIndex($request, 'Address deleted.');
    }

    public function bulkDestroyAddresses(Request $request, CustomerService $customers): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $deleted = $customers->bulkRemoveAddresses($data['ids']);

        return back()->with('success', $deleted === 1 ? 'Address deleted.' : "{$deleted} addresses deleted.");
    }

    public function create(): Response
    {
        return Inertia::render('Customers/Create', $this->formProps());
    }

    public function store(Request $request, CustomerService $customers): RedirectResponse
    {
        $customer = $customers->save($this->validated($request, true), null, $this->uploadedAvatar($request));

        if ($request->boolean('create_another')) {
            return redirect()->route('admin.customers.create')->with('success', 'Customer created.');
        }

        return redirect()->route('admin.customers.show', $customer)->with('success', 'Customer created.');
    }

    public function show(Customer $customer, CustomerDeskService $desk): Response
    {
        $customer->load(['addresses', 'orders']);

        return Inertia::render('Customers/Show', [
            ...$this->formProps($customer),
            'customer' => $this->formCustomer($customer),
            'desk' => $desk->timeline($customer, 40),
            'moduleSlots' => ModuleBuses::collectSlots('admin.customer.show', ['id' => $customer->id, 'email' => $customer->email]),
        ]);
    }

    public function storeDeskNote(Request $request, Customer $customer, CustomerDeskService $desk): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'max:5000'],
            'customer_notified' => ['sometimes', 'boolean'],
        ]);

        try {
            $desk->addNote(
                $customer,
                (string) $data['note'],
                (bool) ($data['customer_notified'] ?? false),
            );
        } catch (\GraphQL\Error\UserError $e) {
            return back()->withErrors(['note' => $e->getMessage()]);
        }

        return back()->with('success', 'Desk note saved.');
    }

    public function update(Request $request, Customer $customer, CustomerService $customers): RedirectResponse
    {
        $customers->save($this->validated($request, false, $customer), $customer, $this->uploadedAvatar($request));

        return back()->with('success', 'Customer updated.');
    }

    public function storeAddress(Request $request, Customer $customer, CustomerService $customers): RedirectResponse
    {
        $customers->addAddress($customer, $this->validatedAddress($request));

        return back()->with('success', 'Address saved.');
    }

    public function updateAddress(Request $request, Customer $customer, int $address, CustomerService $customers): RedirectResponse
    {
        $data = $this->validatedAddress($request);
        $customers->updateAddress($customer, $address, $data);

        return back()->with('success', 'Address updated.');
    }

    public function destroyAddress(Customer $customer, int $address, CustomerService $customers): RedirectResponse
    {
        $customers->removeAddress($customer, $address);

        return back()->with('success', 'Address deleted.');
    }

    public function destroy(Customer $customer, CustomerService $customers, \App\Domain\Customer\Services\CustomerAnonymizer $anonymizer): RedirectResponse
    {
        wento_action('customer.delete', 'before', ['id' => $customer->id]);
        $id = $customer->id;
        $anonymizer->scrubPersonalRecords($customer);
        $anonymizer->purgeUnlinkedPersonalData($customer);
        $customer->delete();
        $customers->bumpListCache();
        wento_action('customer.delete', 'after', ['id' => $id]);

        return redirect()->route('admin.customers.index')->with('success', 'Customer deleted.');
    }

    public function impersonate(Request $request, Customer $customer): RedirectResponse
    {
        $admin = $request->user('admin');
        if (! $admin) {
            abort(403);
        }
        \Illuminate\Support\Facades\Auth::guard('customer')->login($customer);
        $request->session()->put('impersonator_admin_id', $admin->id);
        $request->session()->regenerate();
        \App\Domain\Auth\Models\AdminImpersonation::query()->create([
            'admin_id' => $admin->id,
            'customer_id' => $customer->id,
            'started_at' => now(),
            'ip' => $request->ip(),
        ]);

        return redirect('/')->with('success', 'You are viewing the store as this customer.');
    }

    public function export(Request $request, CustomerService $customers): StreamedResponse
    {
        $csv = $customers->exportCsv($request);

        return response()->streamDownload(fn () => print ($csv), 'customers.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function import(Request $request, CustomerService $customers): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:8192']]);
        $count = $customers->importCsv($request->file('file')->getContent());

        return back()->with('success', "Imported {$count} customers.");
    }

    public function importSample(CustomerService $customers)
    {
        return $this->csvDownload('customers-sample.csv', $customers->sampleCsv());
    }

    public function bulk(Request $request, CustomerService $customers): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:enable,disable,delete'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);
        $count = $customers->bulk($data['action'], $data['ids']);

        return back()->with('success', "Updated {$count} customers.");
    }

    public function toggle(Customer $customer, CustomerService $customers): RedirectResponse
    {
        $customers->bulk($customer->is_enabled ? 'disable' : 'enable', [$customer->id]);

        return back()->with('success', $customer->fresh()->is_enabled ? 'Customer enabled.' : 'Customer disabled.');
    }

    protected function formProps(?Customer $customer = null): array
    {
        $groups = CustomerGroup::catalog();
        $code = trim((string) ($customer?->customer_group ?? ''));
        if ($code !== '' && ! $groups->contains(fn (CustomerGroup $group) => $group->code === $code)) {
            $current = CustomerGroup::withoutGlobalScope('shop')->where('code', $code)->first();
            if ($current) {
                $groups->prepend($current);
            }
        }

        return [
            'countries' => Country::optionsForAddresses(),
            'groups' => $groups,
            'default_group_code' => CustomerSettings::defaultGroupCode(),
        ];
    }

    protected function formCustomer(Customer $customer): array
    {
        $paid = $customer->orders->where('status', '!=', 'cancelled');

        return [
            ...$customer->only([
                'id', 'name', 'first_name', 'last_name', 'email', 'phone', 'customer_group',
                'date_of_birth', 'tax_vat', 'gender', 'company_name', 'business_type',
                'company_website', 'gst_tax_id', 'newsletter', 'notes', 'is_enabled',
            ]),
            'date_of_birth' => $customer->date_of_birth?->toDateString(),
            'avatar_url' => $customer->avatarUrl(),
            'initials' => $customer->initials(),
            'created_at' => $customer->created_at?->toIso8601String(),
            'orders_count' => $paid->count(),
            'total_spent' => (float) $paid->sum('grand_total'),
            'aov' => $paid->count() ? round($paid->avg('grand_total'), 2) : 0,
            'last_order_at' => optional($paid->sortByDesc('created_at')->first())->created_at?->toIso8601String(),
            'addresses' => $customer->addresses->map(fn ($address) => [
                'id' => $address->id,
                'label' => $address->label,
                'first_name' => $address->first_name,
                'last_name' => $address->last_name,
                'phone' => $address->phone,
                'line1' => $address->line1,
                'line2' => $address->line2,
                'city' => $address->city,
                'state' => $address->state,
                'postal_code' => $address->postal_code,
                'country_code' => $address->country_code,
                'is_default_billing' => (bool) $address->is_default_billing,
                'is_default_shipping' => (bool) $address->is_default_shipping,
            ])->values(),
        ];
    }

    protected function uploadedAvatar(Request $request): ?UploadedFile
    {
        $file = $request->file('avatar');

        return $file instanceof UploadedFile && $file->isValid() ? $file : null;
    }

    protected function validated(Request $request, bool $creating, ?Customer $customer = null): array
    {
        if (is_string($request->input('addresses'))) {
            $decoded = json_decode($request->input('addresses'), true);
            if (is_array($decoded)) {
                $request->merge(['addresses' => $decoded]);
            } else {
                $request->request->remove('addresses');
            }
        }

        if ($request->input('customer_group') === '') {
            $request->merge(['customer_group' => null]);
        }
        if ($request->exists('phone')) {
            $request->merge(['phone' => \App\Domain\Settings\CustomerSettings::normalizePhone($request->input('phone'))]);
        }

        $home = app(\App\Domain\Store\StoreContext::class)->customerHomeId()
            ?: app(\App\Domain\Store\StoreContext::class)->requireId();
        $unique = Rule::unique('customers', 'email')->where(fn ($q) => $q->where('store_id', $home));
        if ($customer) {
            $unique = $unique->ignore($customer->id);
        }

        $rules = [
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', $unique],
            'phone' => \App\Domain\Settings\CustomerSettings::phoneMode() === 'hidden'
                ? ['nullable', 'string', 'max:40', 'regex:/^(\+\d{1,4}\s?)?\d{6,15}$/']
                : \App\Domain\Settings\CustomerSettings::phoneRules(),
            'customer_group' => [
                'bail',
                'required',
                'string',
                'max:64',
                function (string $attribute, mixed $value, \Closure $fail) use ($customer): void {
                    $code = trim((string) $value);
                    $visible = $code !== '' && CustomerGroup::query()
                        ->where('code', $code)
                        ->where('is_enabled', true)
                        ->exists();
                    if (! $visible && $customer && $code === (string) $customer->customer_group) {
                        $visible = CustomerGroup::withoutGlobalScope('shop')
                            ->where('code', $code)
                            ->where('is_enabled', true)
                            ->exists();
                    }
                    if (! $visible) {
                        $fail('Customer group is required.');
                    }
                },
            ],
            'date_of_birth' => ['nullable', 'date'],
            'tax_vat' => ['nullable', 'string', 'max:64'],
            'gender' => ['nullable', 'in:male,female,other'],
            'company_name' => ['nullable', 'string', 'max:180'],
            'business_type' => ['nullable', 'string', 'max:80'],
            'company_website' => ['nullable', 'string', 'max:180'],
            'gst_tax_id' => ['nullable', 'string', 'max:64'],
            'newsletter' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_enabled' => ['nullable', 'boolean'],
            'avatar' => ['nullable', 'file', 'image', 'max:5120'],
            'addresses' => ['nullable', 'array'],
            'addresses.*.id' => ['nullable', 'integer'],
            'addresses.*.label' => ['nullable', 'string', 'max:80'],
            'addresses.*.first_name' => ['nullable', 'string', 'max:120'],
            'addresses.*.last_name' => ['nullable', 'string', 'max:120'],
            'addresses.*.phone' => ['nullable', 'string', 'max:40', 'regex:/^(\+\d{1,4}\s?)?\d{6,15}$/'],
            'addresses.*.line1' => ['required', 'string', 'max:180'],
            'addresses.*.line2' => ['nullable', 'string', 'max:180'],
            'addresses.*.city' => ['required', 'string', 'max:120'],
            'addresses.*.state' => ['nullable', 'string', 'max:120'],
            'addresses.*.postal_code' => ['nullable', 'string', 'max:32'],
            'addresses.*.country_code' => ['required', 'string', 'max:4'],
            'addresses.*.is_default_billing' => ['nullable'],
            'addresses.*.is_default_shipping' => ['nullable'],
            'default_billing' => ['nullable'],
            'default_shipping' => ['nullable'],
        ];

        $rules['password'] = CustomerPassword::rules($creating);

        $data = $request->validate($rules, [
            'customer_group.required' => 'Customer group is required.',
        ] + CustomerPassword::messages());
        $data['is_enabled'] = $request->boolean('is_enabled', true);
        $data['newsletter'] = $request->boolean('newsletter');
        $data['default_billing'] = $request->input('default_billing');
        $data['default_shipping'] = $request->input('default_shipping');

        return $data;
    }

    protected function validatedAddress(Request $request): array
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:80'],
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^(\+\d{1,4}\s?)?\d{6,15}$/'],
            'line1' => ['required', 'string', 'max:180'],
            'line2' => ['nullable', 'string', 'max:180'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'country_code' => ['required', 'string', 'max:4'],
            'is_default_billing' => ['nullable', 'boolean'],
            'is_default_shipping' => ['nullable', 'boolean'],
        ]);
        $data['is_default_billing'] = $request->boolean('is_default_billing');
        $data['is_default_shipping'] = $request->boolean('is_default_shipping');

        return $data;
    }

    /** @return array{customer: string, customer_id: string, label: string, address: string, type: string, id_min: string, id_max: string, sort: string, dir: string} */
    protected function addressFilters(Request $request): array
    {
        $sort = (string) $request->query('sort', 'id');
        if (! in_array($sort, ['id', 'customer', 'label', 'address', 'type', 'created_at'], true)) {
            $sort = 'id';
        }

        return [
            'customer' => trim((string) $request->query('customer', '')),
            'customer_id' => trim((string) $request->query('customer_id', '')),
            'label' => trim((string) $request->query('label', '')),
            'address' => trim((string) $request->query('address', '')),
            'type' => (string) $request->query('type', '') ?: 'all',
            'id_min' => trim((string) $request->query('id_min', '')),
            'id_max' => trim((string) $request->query('id_max', '')),
            'sort' => $sort,
            'dir' => strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc',
        ];
    }

    protected function addressPanelPayload(CustomerAddress $address): array
    {
        $customer = $address->customer;
        $name = trim((string) ($customer?->name ?: implode(' ', array_filter([
            $customer?->first_name,
            $customer?->last_name,
        ]))));

        return [
            'id' => $address->id,
            'customer_id' => $address->customer_id,
            'customer_label' => $name !== '' ? $name.' ('.$customer?->email.')' : (string) $customer?->email,
            'label' => $address->label ?: 'Shipping',
            'first_name' => $address->first_name,
            'last_name' => $address->last_name,
            'phone' => $address->phone,
            'line1' => $address->line1,
            'line2' => $address->line2,
            'city' => $address->city,
            'state' => $address->state,
            'postal_code' => $address->postal_code,
            'country_code' => $address->country_code,
            'is_default_billing' => $address->is_default_billing,
            'is_default_shipping' => $address->is_default_shipping,
        ];
    }

    protected function redirectToAddressesIndex(Request $request, string $message): RedirectResponse
    {
        $query = [];
        if ($request->headers->has('referer')) {
            $referer = parse_url((string) $request->headers->get('referer'));
            parse_str($referer['query'] ?? '', $refererQuery);
            unset($refererQuery['edit'], $refererQuery['create']);
            $query = array_intersect_key($refererQuery, array_flip(['customer', 'customer_id', 'label', 'address', 'type', 'id_min', 'id_max', 'sort', 'dir']));
        }
        $query = array_filter($query, fn ($value) => $value !== '' && $value !== 'all');

        return redirect()->route('admin.addresses.index', $query)->with('success', $message);
    }
}
