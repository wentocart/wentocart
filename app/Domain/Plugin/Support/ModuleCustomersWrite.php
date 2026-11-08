<?php

namespace App\Domain\Plugin\Support;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Customer\Services\CustomerService;
use App\Domain\Plugin\Capabilities;
use App\Domain\Plugin\PluginManifest;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Allowlisted customer writes for extensions. Always goes through CustomerService
 * so hooks and validation stay in force. No raw DB access.
 */
class ModuleCustomersWrite
{
    /** @var list<string> */
    protected array $profileKeys = [
        'company', 'tax_id', 'website', 'job_title', 'account_manager',
        'credit_limit', 'dealer_status', 'notes',
    ];

    public function __construct(protected PluginManifest $manifest) {}

    /**
     * Typeahead / dropdown search for customers (id, name, email only).
     * Finds "Ray" / "Price" by name or email even if they are the 10,000th customer.
     *
     * @return list<array{id: int, name: string, email: string, label: string}>
     */
    public function search(string $query = '', int $limit = 25): array
    {
        $page = $this->paginate($query, 1, $limit);

        return $page['data'];
    }

    /**
     * Paginated customer picker (search + page). Limited columns only.
     *
     * @return array{
     *   data: list<array{id: int, name: string, email: string, label: string}>,
     *   total: int,
     *   page: int,
     *   per_page: int,
     *   last_page: int
     * }
     */
    public function paginate(string $query = '', int $page = 1, int $perPage = 20): array
    {
        $this->assertCanRead();
        $perPage = max(1, min(50, $perPage));
        $page = max(1, min(10000, $page));
        $q = trim(strip_tags($query));

        $builder = Customer::query()
            ->when($q !== '', function ($builder) use ($q) {
                $like = '%'.$q.'%';
                $builder->where(function ($inner) use ($like) {
                    $inner->where('name', 'like', $like)
                        ->orWhere('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            })
            ->orderBy('name');

        $total = (clone $builder)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        if ($page > $lastPage) {
            $page = $lastPage;
        }

        $data = $builder
            ->forPage($page, $perPage)
            ->get(['id', 'name', 'email', 'first_name', 'last_name'])
            ->map(fn (Customer $customer) => $this->serializeCustomer($customer))
            ->values()
            ->all();

        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
        ];
    }

    /**
     * Load specific customers by id (e.g. saved rule: Ray + Price).
     *
     * @param  list<int>  $ids
     * @return list<array{id: int, name: string, email: string, label: string}>
     */
    public function findMany(array $ids): array
    {
        $this->assertCanRead();
        $clean = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && count($clean) < 100) {
                $clean[] = $id;
            }
        }
        if ($clean === []) {
            return [];
        }

        return Customer::query()
            ->whereIn('id', $clean)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'first_name', 'last_name'])
            ->map(fn (Customer $customer) => $this->serializeCustomer($customer))
            ->values()
            ->all();
    }

    /**
     * @return array{id: int, name: string, email: string, label: string}|null
     */
    public function find(int $customerId): ?array
    {
        $this->assertCanRead();
        $customer = Customer::query()->find($customerId);
        if (! $customer) {
            return null;
        }

        return $this->serializeCustomer($customer);
    }

    /**
     * @return array{id: int, name: string, email: string, label: string}
     */
    protected function serializeCustomer(Customer $customer): array
    {
        $name = trim((string) ($customer->name ?: trim($customer->first_name.' '.$customer->last_name)));
        $email = (string) $customer->email;

        return [
            'id' => (int) $customer->id,
            'name' => $name !== '' ? $name : $email,
            'email' => $email,
            'label' => $name !== '' ? $name.' ('.$email.')' : $email,
        ];
    }

    /**
     * Move a customer into an enabled group by code (e.g. prime, vip, retailer).
     * Core Marketing discounts and shipping rules that target that group then apply.
     */
    public function assignGroup(int $customerId, string $groupCode): void
    {
        $this->assertCanWrite();
        $customer = Customer::query()->find($customerId);
        if (! $customer) {
            throw ValidationException::withMessages(['id' => 'Customer '.$customerId.' was not found.']);
        }

        app(CustomerService::class)->assignGroup($customer, $groupCode);
    }

    /**
     * Enable or disable a customer (admin approval / BNPL block).
     */
    public function setEnabled(int $customerId, bool $enabled): void
    {
        $this->assertCanWrite();
        $customer = Customer::query()->find($customerId);
        if (! $customer) {
            throw ValidationException::withMessages(['id' => 'Customer '.$customerId.' was not found.']);
        }

        wento_action('customer.save', 'before', ['id' => $customer->id, 'email' => $customer->email]);
        $customer->update(['is_enabled' => $enabled]);
        wento_action('customer.save', 'after', ['id' => $customer->id, 'is_enabled' => $enabled]);
    }

    /**
     * Merge allowlisted profile keys (CRM, registration extras, credit limit, dealer status).
     *
     * @param  array<string, mixed>  $fields
     */
    public function setProfile(int $customerId, array $fields): void
    {
        $this->assertCanWrite();
        $customer = Customer::query()->find($customerId);
        if (! $customer) {
            throw ValidationException::withMessages(['id' => 'Customer '.$customerId.' was not found.']);
        }

        $clean = [];
        foreach ($this->profileKeys as $key) {
            if (! array_key_exists($key, $fields)) {
                continue;
            }
            $value = $fields[$key];
            if (is_bool($value)) {
                $clean[$key] = $value ? '1' : '0';
            } elseif (is_int($value) || is_float($value)) {
                $clean[$key] = (string) $value;
            } elseif (is_string($value)) {
                $clean[$key] = Str::limit(trim(strip_tags($value)), 500, '');
            }
        }
        // Also accept custom_* keys from registration forms (max 15).
        $custom = 0;
        foreach ($fields as $key => $value) {
            if ($custom >= 15 || ! is_string($key) || ! preg_match('/^custom_[a-z0-9_]{1,40}$/', $key)) {
                continue;
            }
            if (! is_scalar($value)) {
                continue;
            }
            $clean[$key] = Str::limit(trim(strip_tags((string) $value)), 500, '');
            $custom++;
        }

        $profile = array_merge(is_array($customer->profile) ? $customer->profile : [], $clean);
        wento_action('customer.save', 'before', ['id' => $customer->id, 'email' => $customer->email]);
        $customer->update(['profile' => $profile]);
        wento_action('customer.save', 'after', ['id' => $customer->id]);
    }

    /**
     * @return array<string, string>
     */
    public function profileOf(int $customerId): array
    {
        $this->assertCanWrite();
        $customer = Customer::query()->find($customerId);
        if (! $customer || ! is_array($customer->profile)) {
            return [];
        }

        $out = [];
        foreach ($customer->profile as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * Create or update a customer address through CustomerService.
     *
     * @param  array<string, mixed>  $fields
     */
    public function upsertAddress(int $customerId, array $fields, ?int $addressId = null): int
    {
        $this->assertCanWrite();
        $customer = Customer::query()->find($customerId);
        if (! $customer) {
            throw ValidationException::withMessages(['id' => 'Customer '.$customerId.' was not found.']);
        }

        $row = [
            'label' => isset($fields['label']) ? Str::limit(trim(strip_tags((string) $fields['label'])), 80, '') : 'Address',
            'first_name' => isset($fields['first_name']) ? Str::limit(trim(strip_tags((string) $fields['first_name'])), 80, '') : null,
            'last_name' => isset($fields['last_name']) ? Str::limit(trim(strip_tags((string) $fields['last_name'])), 80, '') : null,
            'phone' => isset($fields['phone']) ? Str::limit(trim(strip_tags((string) $fields['phone'])), 40, '') : null,
            'line1' => Str::limit(trim(strip_tags((string) ($fields['line1'] ?? ''))), 180, ''),
            'line2' => isset($fields['line2']) ? Str::limit(trim(strip_tags((string) $fields['line2'])), 180, '') : null,
            'city' => Str::limit(trim(strip_tags((string) ($fields['city'] ?? ''))), 80, ''),
            'state' => isset($fields['state']) ? Str::limit(trim(strip_tags((string) $fields['state'])), 80, '') : (isset($fields['region_code']) ? Str::limit(trim(strip_tags((string) $fields['region_code'])), 80, '') : null),
            'postal_code' => isset($fields['postal_code']) ? Str::limit(trim(strip_tags((string) $fields['postal_code'])), 20, '') : null,
            'country_code' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) ($fields['country_code'] ?? '')) ?? '', 0, 2)),
            'is_default_billing' => (bool) ($fields['is_default_billing'] ?? false),
            'is_default_shipping' => (bool) ($fields['is_default_shipping'] ?? false),
        ];
        if ($row['line1'] === '' || $row['city'] === '' || strlen($row['country_code']) !== 2) {
            throw ValidationException::withMessages([
                'address' => 'line1, city, and country_code (ISO2) are required.',
            ]);
        }

        $service = app(CustomerService::class);
        if ($addressId) {
            $service->updateAddress($customer, $addressId, $row);
            $exists = $customer->addresses()->whereKey($addressId)->exists();
            if (! $exists) {
                throw ValidationException::withMessages(['address_id' => 'Address was not found.']);
            }

            return $addressId;
        }

        $fresh = $service->addAddress($customer, $row);
        $created = $fresh->addresses()->orderByDesc('id')->first();

        return (int) ($created?->id ?? 0);
    }

    /**
     * Current group code for a customer, or null if missing.
     */
    public function groupOf(int $customerId): ?string
    {
        $this->assertCanWrite();
        $customer = Customer::query()->find($customerId);
        if (! $customer) {
            return null;
        }

        $code = trim((string) ($customer->customer_group ?? ''));

        return $code !== '' ? $code : null;
    }

    /**
     * Enabled groups for pickers / plan → group mapping in extension settings.
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    public function listGroups(): array
    {
        $this->assertCanWrite();

        return CustomerGroup::query()
            ->where('is_enabled', true)
            ->orderBy('position')
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(static fn (CustomerGroup $g) => [
                'id' => (int) $g->id,
                'code' => (string) $g->code,
                'name' => (string) $g->name,
            ])
            ->values()
            ->all();
    }

    protected function assertCanWrite(): void
    {
        if (! $this->manifest->can(Capabilities::CUSTOMERS_WRITE)) {
            throw new InvalidArgumentException('This app did not declare customers.write.');
        }
    }

    protected function assertCanRead(): void
    {
        if (! $this->manifest->can(Capabilities::CUSTOMERS_READ) && ! $this->manifest->can(Capabilities::CUSTOMERS_WRITE)) {
            throw new InvalidArgumentException('This app did not declare customers.read or customers.write.');
        }
    }
}
