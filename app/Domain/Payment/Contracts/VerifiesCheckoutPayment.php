<?php

namespace App\Domain\Payment\Contracts;

use App\Domain\Checkout\CheckoutSnapshot;
use App\Domain\Payment\Models\PaymentCheckout;
use App\Domain\Payment\PaymentVerificationResult;

interface VerifiesCheckoutPayment
{
    public function verifyCheckoutPayment(
        CheckoutSnapshot $snapshot,
        PaymentCheckout $session,
        array $paymentProof,
    ): PaymentVerificationResult;
}
