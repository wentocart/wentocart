<?php

namespace App\Domain\Payment;

class PaymentVerificationResult
{
    public function __construct(
        public readonly bool $verified,
        public readonly PaymentCheckoutStatus $status,
        public readonly ?string $providerReference = null,
        public readonly array $paymentProof = [],
        public readonly array $meta = [],
        public readonly ?string $message = null,
        public readonly float $amount = 0.0,
        public readonly string $currency = '',
    ) {}

    public static function paid(
        string $providerReference,
        array $paymentProof = [],
        array $meta = [],
        float $amount = 0.0,
        string $currency = '',
    ): self {
        return new self(
            verified: true,
            status: PaymentCheckoutStatus::Paid,
            providerReference: $providerReference,
            paymentProof: $paymentProof,
            meta: $meta,
            amount: $amount,
            currency: $currency,
        );
    }

    public static function failed(string $message, array $meta = []): self
    {
        return new self(
            verified: false,
            status: PaymentCheckoutStatus::Failed,
            meta: $meta,
            message: $message,
        );
    }

    public static function requiresAction(string $message, array $meta = []): self
    {
        return new self(
            verified: false,
            status: PaymentCheckoutStatus::RequiresAction,
            meta: $meta,
            message: $message,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'verified' => $this->verified,
            'status' => $this->status->value,
            'provider_reference' => $this->providerReference,
            'payment_proof' => $this->paymentProof === [] ? null : $this->paymentProof,
            'meta' => $this->meta === [] ? null : $this->meta,
            'message' => $this->message,
            'amount' => $this->amount > 0 ? $this->amount : null,
            'currency' => $this->currency !== '' ? $this->currency : null,
        ], fn ($v) => $v !== null);
    }
}
