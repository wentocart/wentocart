<?php

namespace App\Domain\Payment;

enum PaymentCheckoutStatus: string
{
    case RequiresPayment = 'requires_payment';
    case RequiresAction = 'requires_action';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case Fulfilled = 'fulfilled';
    case PaidUnfulfilled = 'paid_unfulfilled';
}
