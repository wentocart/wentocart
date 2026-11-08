<?php

namespace App\Domain\Payment\Contracts;

interface HandlesPaymentWebhooks
{
    /**
     * @param  array<string, string|list<string>>  $headers
     * @return array{ok: bool, order_number?: string, payment_status?: string, message?: string}
     */
    public function handleWebhook(string $body, array $headers = []): array;
}
