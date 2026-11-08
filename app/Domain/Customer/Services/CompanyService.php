<?php

namespace App\Domain\Customer\Services;

use App\Domain\Customer\Models\Company;
use App\Domain\Customer\Models\CompanyUser;
use App\Domain\Customer\Models\Customer;
use Illuminate\Validation\ValidationException;

class CompanyService
{
    public function create(array $data): Company
    {
        $company = Company::query()->create([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'credit_limit' => (float) ($data['credit_limit'] ?? 0),
            'net_days' => max(0, (int) ($data['net_days'] ?? 30)),
            'is_enabled' => (bool) ($data['is_enabled'] ?? true),
        ]);

        foreach ((array) ($data['customer_ids'] ?? []) as $customerId) {
            $this->attach($company, (int) $customerId, 'manager');
        }

        return $company->fresh('users');
    }

    public function attach(Company $company, int $customerId, string $role = 'buyer'): CompanyUser
    {
        $customer = Customer::query()->find($customerId);
        if (! $customer) {
            throw ValidationException::withMessages(['customer_id' => 'Customer not found.']);
        }
        $role = in_array($role, ['buyer', 'manager'], true) ? $role : 'buyer';
        $row = CompanyUser::query()->updateOrCreate(
            ['company_id' => $company->id, 'customer_id' => $customer->id],
            ['role' => $role]
        );
        $customer->forceFill(['company_id' => $company->id])->save();

        return $row;
    }

    /** @return array<string, mixed> */
    public function present(Company $company): array
    {
        $company->loadMissing(['users:id,name,email']);

        return [
            'id' => (int) $company->id,
            'name' => $company->name,
            'email' => $company->email,
            'phone' => $company->phone,
            'credit_limit' => (float) $company->credit_limit,
            'net_days' => (int) $company->net_days,
            'is_enabled' => (bool) $company->is_enabled,
            'users' => $company->users->map(fn (Customer $c) => [
                'id' => (int) $c->id,
                'name' => $c->displayName(),
                'email' => $c->email,
                'role' => $c->pivot->role ?? 'buyer',
            ])->values()->all(),
        ];
    }
}
