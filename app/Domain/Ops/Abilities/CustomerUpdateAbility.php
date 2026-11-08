<?php

namespace App\Domain\Ops\Abilities;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Services\CustomerService;
use App\Domain\Ops\Ability;
use InvalidArgumentException;

final class CustomerUpdateAbility implements Ability
{
    public function code(): string
    {
        return 'customers.customer.update';
    }

    public function label(): string
    {
        return 'Update customer';
    }

    public function permission(): string
    {
        return 'customers.customers';
    }

    public function plan(array $input): array
    {
        $customer = Customer::query()->find((int) ($input['id'] ?? 0));
        if (! $customer) {
            throw new InvalidArgumentException('Customer not found.');
        }
        $before = $this->snapshot($customer);
        $fields = is_array($input['fields'] ?? null) ? $input['fields'] : $input;
        $after = $before;
        foreach (['name', 'email', 'phone', 'is_enabled', 'newsletter'] as $key) {
            if (array_key_exists($key, $fields)) {
                $after[$key] = $key === 'is_enabled' || $key === 'newsletter'
                    ? (bool) $fields[$key]
                    : trim((string) $fields[$key]);
            }
        }

        return [[
            'entity_type' => 'customer',
            'entity_id' => (int) $customer->id,
            'action' => 'update',
            'label' => $before['name'] ?: $before['email'],
            'before' => $before,
            'after' => $after,
            'reversible' => true,
        ]];
    }

    public function execute(array $item, array $input): array
    {
        $customer = Customer::query()->findOrFail((int) $item['entity_id']);
        $this->apply($customer, $item['after'] ?? []);

        return $this->snapshot($customer->fresh());
    }

    public function rollback(array $item): void
    {
        $customer = Customer::query()->find((int) $item['entity_id']);
        if ($customer) {
            $this->apply($customer, $item['before'] ?? []);
        }
    }

    public function eventName(): ?string
    {
        return 'customer.updated';
    }

    /**
     * @return array<string, mixed>
     */
    protected function snapshot(Customer $customer): array
    {
        return [
            'id' => (int) $customer->id,
            'name' => (string) $customer->name,
            'first_name' => (string) $customer->first_name,
            'last_name' => (string) $customer->last_name,
            'email' => (string) $customer->email,
            'phone' => (string) ($customer->phone ?? ''),
            'is_enabled' => (bool) $customer->is_enabled,
            'newsletter' => (bool) $customer->newsletter,
            'customer_group' => (string) ($customer->customer_group ?? ''),
            'notes' => (string) ($customer->notes ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function apply(Customer $customer, array $state): void
    {
        $name = (string) ($state['name'] ?? $customer->name);
        $parts = preg_split('/\s+/', $name, 2) ?: [];
        app(CustomerService::class)->save([
            'name' => $name,
            'first_name' => (string) ($state['first_name'] ?? $parts[0] ?? $customer->first_name),
            'last_name' => (string) ($state['last_name'] ?? $parts[1] ?? $customer->last_name),
            'email' => (string) ($state['email'] ?? $customer->email),
            'phone' => (string) ($state['phone'] ?? $customer->phone),
            'customer_group' => (string) ($state['customer_group'] ?? $customer->customer_group ?? 'retailer'),
            'newsletter' => (bool) ($state['newsletter'] ?? $customer->newsletter),
            'is_enabled' => (bool) ($state['is_enabled'] ?? $customer->is_enabled),
            'notes' => (string) ($state['notes'] ?? $customer->notes ?? ''),
        ], $customer);
    }
}
