<?php

namespace App\Domain\Customer\Services;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerAddress;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Customer\Support\AddressCsvSchema;
use App\Domain\Customer\Support\CustomerCsvSchema;
use App\Domain\Order\Models\Order;
use App\Domain\Plugin\Support\ModuleBuses;
use App\Domain\Search\PrefixQuery;
use App\Domain\Settings\ShopMailer;
use App\Domain\Store\Sharing;
use App\Domain\Store\StoreContext;
use App\Domain\Security\CsvSafe;
use App\Domain\Security\SessionRevoker;
use App\Support\AdminPagination;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerService
{
    public function stats(): array
    {
        $version = $this->cacheVersion();

        $context = app(StoreContext::class);
        $scope = $context->filters()
            ? implode('-', $context->idsFor(Sharing::FAMILY_CUSTOMERS))
            : 'all';

        return Cache::remember("admin.customers.stats.{$version}.{$scope}", 60, function () {
            $now = now();
            $prevStart = $now->copy()->subDays(60);
            $prevEnd = $now->copy()->subDays(30);

            $row = Customer::query()
                ->toBase()
                ->selectRaw(
                    'COUNT(*) as total,
                    SUM(CASE WHEN is_enabled = 1 THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN is_enabled = 0 THEN 1 ELSE 0 END) as inactive,
                    SUM(CASE WHEN customer_group = ? THEN 1 ELSE 0 END) as vip,
                    SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as new_count,
                    SUM(CASE WHEN created_at < ? THEN 1 ELSE 0 END) as total_prev,
                    SUM(CASE WHEN is_enabled = 1 AND created_at < ? THEN 1 ELSE 0 END) as active_prev,
                    SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) as new_prev',
                    ['vip', $prevEnd, $prevEnd, $prevEnd, $prevStart, $prevEnd]
                )
                ->first();

            $total = (int) ($row->total ?? 0);
            $active = (int) ($row->active ?? 0);
            $inactive = (int) ($row->inactive ?? 0);
            $vip = (int) ($row->vip ?? 0);
            $new = (int) ($row->new_count ?? 0);
            $totalPrev = (int) ($row->total_prev ?? 0);
            $activePrev = (int) ($row->active_prev ?? 0);
            $newPrev = (int) ($row->new_prev ?? 0);

            $repeat = $this->repeatCustomerCount();
            $repeatPrev = $this->repeatCustomerCount($prevEnd);
            $repeatRate = $total > 0 ? round(($repeat / $total) * 100, 1) : 0;
            $repeatRatePrev = $totalPrev > 0 ? round(($repeatPrev / $totalPrev) * 100, 1) : 0;

            $aov = (float) Order::query()->where('status', '!=', 'cancelled')->avg('grand_total');
            $aovPrev = (float) Order::query()
                ->where('status', '!=', 'cancelled')
                ->whereBetween('created_at', [$prevStart, $prevEnd])
                ->avg('grand_total');

            return [
                'total' => $total,
                'total_trend' => $this->trend($total, $totalPrev),
                'new' => $new,
                'new_trend' => $this->trend($new, $newPrev),
                'active' => $active,
                'active_trend' => $this->trend($active, $activePrev),
                'repeat_rate' => $repeatRate,
                'repeat_trend' => $this->trend($repeatRate, $repeatRatePrev),
                'aov' => round($aov, 2),
                'aov_trend' => $this->trend($aov, $aovPrev),
                'tab_counts' => [
                    'all' => $total,
                    'active' => $active,
                    'inactive' => $inactive,
                    'new' => $new,
                    'repeat' => $repeat,
                    'vip' => $vip,
                ],
            ];
        });
    }

    public function paginate(Request $request)
    {
        $perPage = AdminPagination::perPage($request, 10);
        $page = max(1, (int) $request->integer('page', 1));
        $base = $this->filteredQuery($request);
        $total = (clone $base)->toBase()->getCountForPagination();

        $ids = $this->applySort(clone $base, $request)
            ->forPage($page, $perPage)
            ->pluck('id');

        $rows = $ids->isEmpty()
            ? collect()
            : $this->hydrateListRows($ids);

        return (new LengthAwarePaginator($rows, $total, $perPage, $page, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]))->withQueryString();
    }

    /** @return list<array{id: int, name: string}> */
    public function suggest(string $q, int $limit = 25): array
    {
        $q = trim($q);
        $query = Customer::query()->select(['id', 'name', 'first_name', 'last_name', 'email']);

        if ($q !== '') {
            $this->applySearch($query, $q);
        } else {
            $query->orderByDesc('id');
        }

        return $query
            ->limit($limit)
            ->get()
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => trim($customer->displayName().' ('.$customer->email.')'),
            ])
            ->values()
            ->all();
    }

    public function filteredQuery(Request $request)
    {
        $prefix = DB::getTablePrefix();
        $ordersTable = $prefix . (new Order)->getTable();
        $customersTable = $prefix . (new Customer)->getTable();
        $addressesTable = $prefix . (new CustomerAddress)->getTable();

        return Customer::query()
            ->when($request->filled('search'), fn ($q) => $this->applySearch($q, (string) $request->search))
            ->when($request->filled('name'), function ($q) use ($request) {
                $term = PrefixQuery::like(trim((string) $request->name));
                $q->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', $term)
                        ->orWhere('first_name', 'like', $term)
                        ->orWhere('last_name', 'like', $term);
                });
            })
            ->when($request->filled('email'), fn ($q) => $q->where('email', 'like', PrefixQuery::like(trim((string) $request->email))))
            ->when($request->filled('phone'), fn ($q) => $q->where('phone', 'like', PrefixQuery::like(trim((string) $request->phone))))
            ->when($request->status === 'active', fn ($q) => $q->where('is_enabled', true))
            ->when($request->status === 'inactive', fn ($q) => $q->where('is_enabled', false))
            ->when($request->status === 'new', fn ($q) => $q->where('created_at', '>=', now()->subDays(30)))
            ->when($request->status === 'repeat', fn ($q) => $this->whereRepeatCustomers($q))
            ->when($request->status === 'vip', fn ($q) => $q->where('customer_group', 'vip'))
            ->when($request->enabled === '1', fn ($q) => $q->where('is_enabled', true))
            ->when($request->enabled === '0', fn ($q) => $q->where('is_enabled', false))
            ->when($request->group, fn ($q, $g) => $q->where('customer_group', $g))
            ->when($this->numericBound($request, 'id_min') !== null, fn ($q) => $q->where("{$customersTable}.id", '>=', (int) $this->numericBound($request, 'id_min')))
            ->when($this->numericBound($request, 'id_max') !== null, fn ($q) => $q->where("{$customersTable}.id", '<=', (int) $this->numericBound($request, 'id_max')))
            ->when($this->ordersMin($request) !== null, function ($q) use ($request, $ordersTable, $customersTable) {
                $q->whereRaw("(SELECT COUNT(*) FROM `{$ordersTable}` WHERE `{$ordersTable}`.`customer_id` = `{$customersTable}`.`id`) >= ?", [$this->ordersMin($request)]);
            })
            ->when($this->numericBound($request, 'orders_max') !== null, function ($q) use ($request, $ordersTable, $customersTable) {
                $q->whereRaw("(SELECT COUNT(*) FROM `{$ordersTable}` WHERE `{$ordersTable}`.`customer_id` = `{$customersTable}`.`id`) <= ?", [(int) $this->numericBound($request, 'orders_max')]);
            })
            ->when($this->spentMin($request) !== null, function ($q) use ($request, $ordersTable, $customersTable) {
                $q->whereRaw("(SELECT COALESCE(SUM(grand_total), 0) FROM `{$ordersTable}` WHERE `{$ordersTable}`.`customer_id` = `{$customersTable}`.`id` AND `{$ordersTable}`.`status` != ?) >= ?", ['cancelled', $this->spentMin($request)]);
            })
            ->when($this->numericBound($request, 'spent_max') !== null, function ($q) use ($request, $ordersTable, $customersTable) {
                $q->whereRaw("(SELECT COALESCE(SUM(grand_total), 0) FROM `{$ordersTable}` WHERE `{$ordersTable}`.`customer_id` = `{$customersTable}`.`id` AND `{$ordersTable}`.`status` != ?) <= ?", ['cancelled', (float) $this->numericBound($request, 'spent_max')]);
            })
            ->when($request->filled('country'), function ($q) use ($request, $addressesTable, $customersTable) {
                $q->whereExists(function ($sub) use ($request, $addressesTable, $customersTable) {
                    $sub->selectRaw('1')
                        ->from($addressesTable)
                        ->whereColumn("{$addressesTable}.customer_id", "{$customersTable}.id")
                        ->where("{$addressesTable}.country_code", $request->country);
                });
            })
            ->when($request->filled('location'), function ($q) use ($request, $addressesTable, $customersTable) {
                $term = '%'.trim((string) $request->location).'%';
                $q->whereExists(function ($sub) use ($term, $addressesTable, $customersTable) {
                    $sub->selectRaw('1')
                        ->from($addressesTable)
                        ->whereColumn("{$addressesTable}.customer_id", "{$customersTable}.id")
                        ->where(function ($inner) use ($term, $addressesTable) {
                            $inner->where("{$addressesTable}.city", 'like', $term)
                                ->orWhere("{$addressesTable}.country_code", 'like', $term)
                                ->orWhere("{$addressesTable}.state", 'like', $term);
                        });
                });
            })
            ->when($request->filled('created'), fn ($q) => $q->whereDate('created_at', $request->created));
    }

    public function bumpListCache(): void
    {
        Cache::forever('admin.customers.cache_v', $this->cacheVersion() + 1);
    }

    public function listRow(Customer $customer): array
    {
        $address = $customer->addresses->firstWhere('is_default_shipping', true)
            ?? $customer->addresses->firstWhere('is_default_billing', true)
            ?? $customer->addresses->first();

        $row = [
            'id' => $customer->id,
            'name' => $customer->displayName(),
            'email' => $customer->email,
            'phone' => $customer->phone,
            'customer_group' => $customer->customer_group ?: 'retailer',
            'orders_count' => (int) $customer->orders_count,
            'total_spent' => (float) ($customer->orders_sum_grand_total ?? 0),
            'location' => $address ? trim(($address->city ? $address->city.', ' : '').($address->country_code ?? '')) : null,
            'is_enabled' => (bool) $customer->is_enabled,
            'created_at' => $customer->created_at?->toIso8601String(),
            'avatar_url' => $customer->avatarUrl(),
            'initials' => $customer->initials(),
            'notes' => $customer->notes,
        ];
        foreach ($customer->getAttributes() as $key => $value) {
            if (! array_key_exists($key, $row) && (is_scalar($value) || $value === null)) {
                $row[$key] = $value;
            }
        }

        return $row;
    }

    public function save(array $data, ?Customer $customer = null, ?UploadedFile $avatar = null): Customer
    {
        $first = trim((string) ($data['first_name'] ?? ''));
        $last = trim((string) ($data['last_name'] ?? ''));
        $name = trim($first.' '.$last) ?: ($data['name'] ?? $customer?->name ?? 'Customer');

        $groupCode = trim((string) ($data['customer_group'] ?? ''));
        if ($groupCode === '') {
            $groupCode = (string) ($customer?->customer_group ?: 'retailer');
        }
        $group = CustomerGroup::query()
            ->where('code', $groupCode)
            ->where('is_enabled', true)
            ->first();
        if (! $group && $customer && $groupCode === (string) $customer->customer_group) {
            $group = CustomerGroup::withoutGlobalScope('shop')
                ->where('code', $groupCode)
                ->where('is_enabled', true)
                ->first();
        }

        $payload = [
            'name' => $name,
            'first_name' => $first ?: null,
            'last_name' => $last ?: null,
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'customer_group' => $group?->code ?? $groupCode,
            'date_of_birth' => ! empty($data['date_of_birth']) ? $data['date_of_birth'] : null,
            'tax_vat' => $data['tax_vat'] ?? null,
            'gender' => $data['gender'] ?? null,
            'company_name' => $data['company_name'] ?? null,
            'business_type' => $data['business_type'] ?? null,
            'company_website' => $data['company_website'] ?? null,
            'gst_tax_id' => $data['gst_tax_id'] ?? null,
            'newsletter' => (bool) ($data['newsletter'] ?? false),
            'notes' => Str::limit((string) ($data['notes'] ?? ''), 500, ''),
            'is_enabled' => (bool) ($data['is_enabled'] ?? true),
        ];
        if ($group) {
            $payload['customer_group_id'] = $group->id;
        }

        if (! empty($data['password'])) {
            $payload['password'] = $data['password'];
            if ($customer) {
                // Password changed: kill remember-me tokens and other sessions.
                $payload['remember_token'] = Str::random(60);
            }
        } elseif (! $customer) {
            $payload['password'] = Hash::make(Str::password(12));
        }

        if ($avatar) {
            $path = $avatar->store('customers', 'public');
            if (! is_string($path) || $path === '') {
                throw new \RuntimeException('Could not store the customer photo.');
            }
            $this->deleteAvatarFile($customer?->avatar_path);
            $payload['avatar_path'] = $path;
        }

        if (! $customer) {
            $payload['store_id'] = app(\App\Domain\Store\StoreContext::class)->customerHomeId()
                ?: app(\App\Domain\Store\StoreContext::class)->requireId();
        }

        wento_action('customer.save', 'before', ['id' => $customer?->id, 'email' => $payload['email']]);

        if ($customer) {
            $customer->forceFill($payload)->save();
            if (! empty($data['password'])) {
                SessionRevoker::revokeOthers('customer', $customer->id);
            }
        } else {
            $customer = Customer::createTrusted($payload);
            app(ShopMailer::class)->customerCreated($customer);
        }

        if (array_key_exists('addresses', $data) && is_array($data['addresses'])) {
            $this->syncAddresses($customer, $data['addresses']);
            $this->applyDefaultAddresses($customer, $data['default_billing'] ?? null, $data['default_shipping'] ?? null);
        }

        $this->bumpListCache();
        wento_action('customer.save', 'after', ['id' => $customer->id]);

        return $customer->fresh(['addresses', 'orders']);
    }

    /**
     * Assign an enabled customer group by code. Used by admin and by extensions
     * via ModuleCustomersWrite (capability customers.write).
     */
    public function assignGroup(Customer $customer, string $groupCode): Customer
    {
        $code = trim($groupCode);
        if ($code === '') {
            throw ValidationException::withMessages(['customer_group' => 'customer_group is required.']);
        }

        $group = CustomerGroup::query()
            ->where('code', $code)
            ->where('is_enabled', true)
            ->first();

        if (! $group) {
            throw ValidationException::withMessages([
                'customer_group' => 'Customer group "'.$code.'" was not found or is disabled.',
            ]);
        }

        wento_action('customer.save', 'before', ['id' => $customer->id, 'email' => $customer->email]);

        $customer->forceFill([
            'customer_group' => $group->code,
            'customer_group_id' => $group->id,
        ])->save();

        $this->bumpListCache();
        wento_action('customer.save', 'after', ['id' => $customer->id, 'customer_group' => $group->code]);

        return $customer->fresh();
    }

    public function addAddress(Customer $customer, array $row): Customer
    {
        $payload = $this->addressPayload($customer, $row);
        $address = $customer->addresses()->create($payload);

        if (! empty($row['is_default_billing'])) {
            $this->applyDefaultAddresses($customer, $address->id, null);
        }
        if (! empty($row['is_default_shipping'])) {
            $this->applyDefaultAddresses($customer, null, $address->id);
        }

        $this->bumpListCache();

        return $customer->fresh(['addresses', 'orders']);
    }

    public function updateAddress(Customer $customer, int $addressId, array $row): Customer
    {
        $address = $customer->addresses()->whereKey($addressId)->firstOrFail();
        $address->update($this->addressPayload($customer, $row));

        if (! empty($row['is_default_billing'])) {
            $this->applyDefaultAddresses($customer, $address->id, null);
        }
        if (! empty($row['is_default_shipping'])) {
            $this->applyDefaultAddresses($customer, null, $address->id);
        }

        $this->bumpListCache();

        return $customer->fresh(['addresses', 'orders']);
    }

    public function removeAddress(Customer $customer, int $addressId): void
    {
        $customer->addresses()->whereKey($addressId)->delete();
        $this->bumpListCache();
    }

    /** @param  list<int>  $ids */
    public function bulkRemoveAddresses(array $ids): int
    {
        $deleted = CustomerAddress::query()->whereIn('id', $ids)->delete();
        if ($deleted) {
            $this->bumpListCache();
        }

        return $deleted;
    }

    public function exportCsv(Request $request): string
    {
        $out = fopen('php://temp', 'r+');
        CsvSafe::put($out, ['id', 'name', 'email', 'phone', 'group', 'status', 'created_at']);
        $this->filteredQuery($request)
            ->orderBy('id')
            ->select(['id', 'name', 'first_name', 'last_name', 'email', 'phone', 'customer_group', 'is_enabled', 'created_at'])
            ->limit(10000)
            ->each(function (Customer $customer) use ($out) {
                CsvSafe::put($out, [
                    $customer->id,
                    $customer->displayName(),
                    $customer->email,
                    $customer->phone,
                    $customer->customer_group,
                    $customer->is_enabled ? 'active' : 'inactive',
                    $customer->created_at?->toDateTimeString(),
                ]);
            });
        rewind($out);

        return stream_get_contents($out) ?: '';
    }

    public function exportAddressesCsv(Request $request): string
    {
        $out = fopen('php://temp', 'r+');
        CsvSafe::put($out, [
            'id', 'customer_id', 'customer_email', 'label', 'first_name', 'last_name', 'phone',
            'line1', 'line2', 'city', 'state', 'postal_code', 'country_code',
            'is_default_billing', 'is_default_shipping',
        ]);

        $this->filteredAddressesQuery($request)
            ->with(['customer:id,email'])
            ->orderBy((new CustomerAddress)->qualifyColumn('id'))
            ->limit(10000)
            ->each(function (CustomerAddress $address) use ($out) {
                CsvSafe::put($out, [
                    $address->id,
                    $address->customer_id,
                    $address->customer?->email,
                    $address->label,
                    $address->first_name,
                    $address->last_name,
                    $address->phone,
                    $address->line1,
                    $address->line2,
                    $address->city,
                    $address->state,
                    $address->postal_code,
                    $address->country_code,
                    $address->is_default_billing ? '1' : '0',
                    $address->is_default_shipping ? '1' : '0',
                ]);
            });
        rewind($out);

        return stream_get_contents($out) ?: '';
    }

    public function importAddressesCsv(string $content): int
    {
        return AddressCsvSchema::import($content);
    }

    public function sampleAddressesCsv(): string
    {
        return AddressCsvSchema::sampleCsv();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<\App\Domain\Customer\Models\CustomerAddress> */
    protected function filteredAddressesQuery(Request $request)
    {
        $customer = trim((string) $request->input('customer', ''));
        $label = trim((string) $request->input('label', ''));
        $address = trim((string) $request->input('address', ''));
        $type = (string) $request->input('type', '') ?: 'all';
        $idMin = trim((string) $request->input('id_min', ''));
        $idMax = trim((string) $request->input('id_max', ''));

        $query = CustomerAddress::query();

        if ($customer !== '') {
            $query->whereHas('customer', function ($builder) use ($customer) {
                $builder->where('name', 'like', "%{$customer}%")
                    ->orWhere('first_name', 'like', "%{$customer}%")
                    ->orWhere('last_name', 'like', "%{$customer}%")
                    ->orWhere('email', 'like', "%{$customer}%");
            });
        }

        $customerId = trim((string) $request->input('customer_id', ''));
        if ($customerId !== '') {
            $query->where('customer_id', (int) $customerId);
        }

        if ($label !== '') {
            $query->where('label', 'like', '%'.$label.'%');
        }

        if ($address !== '') {
            $query->where(function ($inner) use ($address) {
                $inner->where('line1', 'like', "%{$address}%")
                    ->orWhere('line2', 'like', "%{$address}%")
                    ->orWhere('city', 'like', "%{$address}%")
                    ->orWhere('postal_code', 'like', "%{$address}%")
                    ->orWhere('country_code', 'like', "%{$address}%");
            });
        }

        if ($type === 'billing') {
            $query->where('is_default_billing', true);
        } elseif ($type === 'shipping') {
            $query->where('is_default_shipping', true);
        } elseif ($type === 'other') {
            $query->where('is_default_billing', false)->where('is_default_shipping', false);
        }

        if ($idMin !== '') {
            $query->where((new CustomerAddress)->qualifyColumn('id'), '>=', (int) $idMin);
        }
        if ($idMax !== '') {
            $query->where((new CustomerAddress)->qualifyColumn('id'), '<=', (int) $idMax);
        }

        return $query;
    }

    public function importCsv(string $content): int
    {
        $count = CustomerCsvSchema::import($content);
        if ($count) {
            $this->bumpListCache();
        }

        return $count;
    }

    public function sampleCsv(): string
    {
        return CustomerCsvSchema::sampleCsv();
    }

    public function bulk(string $action, array $ids): int
    {
        $query = Customer::query()->whereIn('id', $ids);

        $updated = match ($action) {
            'enable' => $query->update(['is_enabled' => true]),
            'disable' => $query->update(['is_enabled' => false]),
            'delete' => $query->delete(),
            default => 0,
        };

        if ($updated) {
            $this->bumpListCache();
        }

        return $updated;
    }

    /** @param  list<array<string, mixed>>  $addresses */
    protected function syncAddresses(Customer $customer, array $addresses): void
    {
        $keep = [];
        foreach ($addresses as $row) {
            if (empty($row['line1']) || empty($row['city']) || empty($row['country_code'])) {
                continue;
            }
            $payload = $this->addressPayload($customer, $row);
            if (! empty($row['id'])) {
                $address = $customer->addresses()->whereKey($row['id'])->first();
                if ($address) {
                    $address->update($payload);
                    $keep[] = $address->id;

                    continue;
                }
            }
            $keep[] = $customer->addresses()->create($payload)->id;
        }

        if ($keep === [] && $addresses !== []) {
            return;
        }

        $customer->addresses()->whereNotIn('id', $keep)->delete();
    }

    /** @param  array<string, mixed>  $row */
    protected function addressPayload(Customer $customer, array $row): array
    {
        $first = trim((string) ($row['first_name'] ?? $customer->first_name ?? ''));
        if ($first === '') {
            $first = trim((string) $customer->name) ?: 'Customer';
        }

        return [
            'label' => $row['label'] ?? 'Address',
            'first_name' => $first,
            'last_name' => $row['last_name'] ?? $customer->last_name,
            'phone' => $row['phone'] ?? $customer->phone,
            'line1' => $row['line1'],
            'line2' => $row['line2'] ?? null,
            'city' => $row['city'],
            'state' => $row['state'] ?? null,
            'postal_code' => $row['postal_code'] ?? null,
            'country_code' => $row['country_code'],
            'is_default_billing' => filter_var($row['is_default_billing'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'is_default_shipping' => filter_var($row['is_default_shipping'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    protected function applyDefaultAddresses(Customer $customer, mixed $billingId, mixed $shippingId): void
    {
        $billingId = ctype_digit((string) $billingId) ? (int) $billingId : null;
        $shippingId = ctype_digit((string) $shippingId) ? (int) $shippingId : null;

        if ($billingId) {
            $customer->addresses()->update(['is_default_billing' => false]);
            $customer->addresses()->whereKey($billingId)->update(['is_default_billing' => true]);
        }

        if ($shippingId) {
            $customer->addresses()->update(['is_default_shipping' => false]);
            $customer->addresses()->whereKey($shippingId)->update(['is_default_shipping' => true]);
        }
    }

    protected function hydrateListRows($ids)
    {
        $customersTable = (new Customer)->getTable();
        $query = Customer::query()
            ->whereIn("{$customersTable}.id", $ids)
            ->select([
                "{$customersTable}.id", "{$customersTable}.name", "{$customersTable}.first_name", "{$customersTable}.last_name",
                "{$customersTable}.email", "{$customersTable}.phone", "{$customersTable}.customer_group", "{$customersTable}.is_enabled",
                "{$customersTable}.avatar_path", "{$customersTable}.created_at", "{$customersTable}.notes",
            ]);
        ModuleBuses::applyList('customers.customers', $query);
        $customers = $query->get();

        $position = array_flip(collect($ids)->all());
        $customers = $customers->sortBy(fn (Customer $customer) => $position[$customer->id] ?? 0)->values();

        $orderStats = Order::query()
            ->whereIn('customer_id', $ids)
            ->groupBy('customer_id')
            ->selectRaw('customer_id, COUNT(*) as orders_count, COALESCE(SUM(grand_total), 0) as orders_sum_grand_total')
            ->get()
            ->keyBy('customer_id');

        $addresses = CustomerAddress::query()
            ->whereIn('customer_id', $ids)
            ->get(['id', 'customer_id', 'city', 'country_code', 'is_default_shipping', 'is_default_billing'])
            ->groupBy('customer_id');

        return $customers->map(function (Customer $customer) use ($orderStats, $addresses) {
            $stats = $orderStats->get($customer->id);
            $customer->setAttribute('orders_count', (int) ($stats->orders_count ?? 0));
            $customer->setAttribute('orders_sum_grand_total', (float) ($stats->orders_sum_grand_total ?? 0));
            $customer->setRelation('addresses', $addresses->get($customer->id, collect()));

            return $this->listRow($customer);
        })->values();
    }

    protected function filteredCount(Request $request, $query): int
    {
        $version = $this->cacheVersion();

        if (! $this->hasListFilters($request)) {
            return (int) Cache::remember(
                "admin.customers.total.{$version}",
                60,
                fn () => (int) Customer::query()->toBase()->count()
            );
        }

        $key = 'admin.customers.count.'.$version.'.'.md5(json_encode($request->only([
            'search', 'name', 'email', 'phone', 'status', 'enabled', 'group',
            'orders', 'orders_min', 'orders_max', 'spent', 'spent_min', 'spent_max',
            'id_min', 'id_max', 'location', 'country', 'created',
        ])));

        return (int) Cache::remember($key, 30, fn () => (clone $query)->count());
    }

    protected function hasListFilters(Request $request): bool
    {
        return $request->filled('search')
            || $request->filled('name')
            || $request->filled('email')
            || $request->filled('phone')
            || $request->filled('group')
            || $request->filled('orders')
            || $request->filled('orders_min')
            || $request->filled('orders_max')
            || $request->filled('spent')
            || $request->filled('spent_min')
            || $request->filled('spent_max')
            || $request->filled('id_min')
            || $request->filled('id_max')
            || $request->filled('location')
            || $request->filled('country')
            || $request->filled('created')
            || in_array($request->enabled, ['0', '1'], true)
            || in_array($request->status, ['active', 'inactive', 'new', 'repeat', 'vip'], true);
    }

    protected function applySort($query, Request $request)
    {
        [$sort, $dir] = $this->sortFrom($request);
        $prefix = DB::getTablePrefix();
        $ordersTable = $prefix . (new Order)->getTable();
        $customersTable = $prefix . (new Customer)->getTable();
        $addressesTable = $prefix . (new CustomerAddress)->getTable();

        match ($sort) {
            'id' => $query->orderBy('id', $dir),
            'name' => $query->orderBy('first_name', $dir)->orderBy('last_name', $dir)->orderBy('name', $dir),
            'email' => $query->orderBy('email', $dir),
            'phone' => $query->orderBy('phone', $dir),
            'group' => $query->orderBy('customer_group', $dir),
            'status' => $query->orderBy('is_enabled', $dir),
            'created_at' => $query->orderBy('created_at', $dir),
            'orders' => $query->orderByRaw("(SELECT COUNT(*) FROM `{$ordersTable}` WHERE `{$ordersTable}`.`customer_id` = `{$customersTable}`.`id`) {$dir}"),
            'spent' => $query->orderByRaw("(SELECT COALESCE(SUM(grand_total), 0) FROM `{$ordersTable}` WHERE `{$ordersTable}`.`customer_id` = `{$customersTable}`.`id`) {$dir}"),
            'location' => $query->orderByRaw("(SELECT city FROM `{$addressesTable}` WHERE `{$addressesTable}`.`customer_id` = `{$customersTable}`.`id` ORDER BY is_default_shipping DESC, is_default_billing DESC, id ASC LIMIT 1) {$dir}"),
            default => $query->orderBy('created_at', $dir),
        };

        return $query->orderBy('id', $dir);
    }

    /** @return array{0: string, 1: string} */
    protected function sortFrom(Request $request): array
    {
        $allowed = ['id', 'name', 'email', 'phone', 'group', 'orders', 'spent', 'location', 'status', 'created_at'];
        $sort = in_array($request->input('sort'), $allowed, true) ? (string) $request->input('sort') : 'created_at';
        $dir = strtolower((string) $request->input('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        return [$sort, $dir];
    }

    protected function numericBound(Request $request, string $key): int|float|null
    {
        $value = $request->input($key);
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return str_contains($key, 'spent') ? (float) $value : (int) $value;
    }

    protected function ordersMin(Request $request): ?int
    {
        $min = $this->numericBound($request, 'orders_min');
        if ($min !== null) {
            return (int) $min;
        }
        $legacy = $this->numericBound($request, 'orders');

        return $legacy !== null ? (int) $legacy : null;
    }

    protected function spentMin(Request $request): ?float
    {
        $min = $this->numericBound($request, 'spent_min');
        if ($min !== null) {
            return (float) $min;
        }
        $legacy = $this->numericBound($request, 'spent');

        return $legacy !== null ? (float) $legacy : null;
    }

    protected function applySearch($query, string $term)
    {
        $term = trim($term);
        if ($term === '') {
            return $query;
        }

        if (ctype_digit($term)) {
            return $query->where(function ($inner) use ($term) {
                $inner->where('id', (int) $term)
                    ->orWhere('email', 'like', $term.'%')
                    ->orWhere('phone', 'like', $term.'%');
            });
        }

        if (str_contains($term, '@') || str_contains($term, '.')) {
            return $query->where('email', 'like', $term.'%');
        }

        if (DB::getDriverName() === 'mysql' && mb_strlen($term) >= 3) {
            $safe = trim((string) preg_replace('/[+\-><()~*"@]+/', ' ', $term));
            $safe = trim((string) preg_replace('/\s+/', ' ', $safe));
            if ($safe !== '') {
                return $query->whereRaw(
                    'MATCH(name, first_name, last_name, email, phone) AGAINST(? IN BOOLEAN MODE)',
                    ['+'.$safe.'*']
                );
            }
        }

        $like = $term.'%';

        return $query->where(function ($inner) use ($like) {
            $inner->where('email', 'like', $like)
                ->orWhere('name', 'like', $like)
                ->orWhere('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('phone', 'like', $like);
        });
    }

    protected function whereRepeatCustomers($query)
    {
        $ordersTable = (new Order)->getTable();

        return $query->whereIn('id', function ($sub) use ($ordersTable) {
            $sub->select('customer_id')
                ->from($ordersTable)
                ->whereNotNull('customer_id')
                ->groupBy('customer_id')
                ->havingRaw('COUNT(*) >= 2');
        });
    }

    protected function repeatCustomerCount(mixed $createdBefore = null): int
    {
        $ordersTable = (new Order)->getTable();
        $customersTable = (new Customer)->getTable();

        $sub = DB::table($ordersTable)
            ->select("{$ordersTable}.customer_id")
            ->whereNotNull("{$ordersTable}.customer_id")
            ->groupBy("{$ordersTable}.customer_id")
            ->havingRaw('COUNT(*) >= 2');

        if ($createdBefore) {
            $sub->join($customersTable, "{$customersTable}.id", '=', "{$ordersTable}.customer_id")
                ->where("{$customersTable}.created_at", '<', $createdBefore);
        }

        return (int) DB::query()->fromSub($sub, 'repeaters')->count();
    }

    protected function deleteAvatarFile(?string $path): void
    {
        if (! $path || str_contains($path, '/loadtest/')) {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    protected function cacheVersion(): int
    {
        return (int) Cache::get('admin.customers.cache_v', 1);
    }

    protected function trend(float|int $current, float|int $previous): float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
