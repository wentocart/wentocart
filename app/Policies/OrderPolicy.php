<?php

namespace App\Policies;

use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;

class OrderPolicy
{
    public function view(Customer $customer, Order $order): bool
    {
        return (int) $order->customer_id === (int) $customer->id;
    }

    public function update(Customer $customer, Order $order): bool
    {
        return $this->view($customer, $order);
    }
}
