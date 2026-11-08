<?php

namespace App\Domain\Payment;

class PaymentCheckoutResult
{
    public function __construct(
        public readonly PaymentCheckoutStatus $status,
        public readonly ?string $sessionToken = null,
        public readonly ?string $message = null,
        public readonly ?string $redirectUrl = null,
        public readonly array $clientAction = [],
        public readonly array $paymentContext = [],
        public readonly array $meta = [],
    ) {}

    public static function requiresAction(
        string $sessionToken,
        array $clientAction = [],
        array $paymentContext = [],
        array $meta = [],
    ): self {
        return new self(
            status: PaymentCheckoutStatus::RequiresAction,
            sessionToken: $sessionToken,
            clientAction: $clientAction,
            paymentContext: $paymentContext,
            meta: $meta,
        );
    }

    public static function redirect(string $sessionToken, string $url, array $meta = []): self
    {
        return new self(
            status: PaymentCheckoutStatus::RequiresAction,
            sessionToken: $sessionToken,
            redirectUrl: $url,
            meta: $meta,
        );
    }

    public static function failed(string $message, array $meta = []): self
    {
        return new self(
            status: PaymentCheckoutStatus::Failed,
            message: $message,
            meta: $meta,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toPaymentResponse(): array
    {
        $meta = $this->meta;
        if ($this->clientAction !== []) {
            $meta = array_merge($meta, $this->clientAction);
        }

        return array_filter([
            'status' => match ($this->status) {
                PaymentCheckoutStatus::Paid, PaymentCheckoutStatus::Fulfilled => 'paid',
                PaymentCheckoutStatus::Failed, PaymentCheckoutStatus::Canceled => 'failed',
                PaymentCheckoutStatus::RequiresAction, PaymentCheckoutStatus::RequiresPayment => 'pending',
                PaymentCheckoutStatus::Processing => 'pending',
                PaymentCheckoutStatus::PaidUnfulfilled => 'pending',
            },
            'redirect_url' => $this->redirectUrl,
            'message' => $this->message,
            'meta' => $meta === [] ? null : $meta,
            'session_token' => $this->sessionToken,
            'payment_context' => $this->paymentContext === [] ? null : $this->paymentContext,
        ], fn ($v) => $v !== null);
    }
}
