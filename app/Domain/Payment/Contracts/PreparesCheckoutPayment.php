<?php

namespace App\Domain\Payment\Contracts;

use App\Domain\Checkout\CheckoutSnapshot;
use App\Domain\Payment\Models\PaymentCheckout;
use App\Domain\Payment\PaymentCheckoutResult;

interface PreparesCheckoutPayment
{
    public function prepareCheckoutPayment(
        CheckoutSnapshot $snapshot,
        PaymentCheckout $session,
    ): PaymentCheckoutResult;
}
