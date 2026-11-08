<?php

namespace App\Policies;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerAddress;

class CustomerAddressPolicy
{
    public function view(Customer $customer, CustomerAddress $address): bool
    {
        return (int) $address->customer_id === (int) $customer->id;
    }

    public function update(Customer $customer, CustomerAddress $address): bool
    {
        return $this->view($customer, $address);
    }

    public function delete(Customer $customer, CustomerAddress $address): bool
    {
        return $this->view($customer, $address);
    }
}
