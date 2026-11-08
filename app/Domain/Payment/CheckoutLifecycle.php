<?php

namespace App\Domain\Payment;

enum CheckoutLifecycle: string
{
    case OrderFirst = 'order_first';
    case PaymentFirst = 'payment_first';
}
