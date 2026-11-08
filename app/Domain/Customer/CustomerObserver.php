<?php

namespace App\Domain\Customer;

use App\Domain\Customer\Models\Customer;
use App\Domain\Notify\AdminInbox;
use App\Domain\Ops\CommerceTopics;
use App\Domain\Ops\WebhookPayload;

class CustomerObserver
{
    public function created(Customer $customer): void
    {
        CommerceTopics::notify('customer.created', WebhookPayload::customer($customer));
        AdminInbox::customerRegistered($customer);
    }

    public function updated(Customer $customer): void
    {
        if ($customer->wasChanged(['password', 'remember_token', 'totp_secret', 'sandbox_payment_token']) && count($customer->getChanges()) <= 2) {
            return;
        }
        CommerceTopics::notify('customer.updated', WebhookPayload::customer($customer));
    }

    public function deleted(Customer $customer): void
    {
        CommerceTopics::notify('customer.deleted', [
            'id' => (int) $customer->id,
            'email' => (string) $customer->email,
        ]);
    }
}
