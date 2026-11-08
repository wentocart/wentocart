<?php

namespace App\Domain\Payment\Contracts;

use App\Domain\Order\Models\Order;
use App\Domain\Payment\PaymentCaptureResult;

interface RefundsPayments
{
    public function refund(Order $order, float $amount, array $payload = []): PaymentCaptureResult;
}
