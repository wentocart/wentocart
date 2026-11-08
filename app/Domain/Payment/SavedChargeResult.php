<?php

namespace App\Domain\Payment;

use App\Domain\Security\SafeRedirect;

/**
 * Outcome of an off-session (merchant-initiated) charge.
 *
 * `requires_action` is the case a naive implementation gets wrong: the bank wants the
 * customer to authenticate (SCA/3DS). The charge is NOT complete, but it is also not a
 * hard failure — the customer must be sent to `redirectUrl` to finish it.
 */
class SavedChargeResult
{
    public const PAID = 'paid';

    public const REQUIRES_ACTION = 'requires_action';

    public const FAILED = 'failed';

    public function __construct(
        public readonly string $status,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $message = null,
        public readonly array $meta = [],
    ) {}

    public static function paid(array $meta = []): self
    {
        return new self(self::PAID, meta: $meta);
    }

    /**
     * The bank wants the customer to authenticate (SCA/3DS).
     *
     * The link is passed through SafeRedirect, which only permits allowlisted PSP hosts.
     * If it is stripped we must NOT return a `requires_action` result with no link: the
     * caller would treat the charge as "awaiting the customer" forever, never record a
     * failure, and the subscription would stay active while uncharged. In that case we
     * degrade to an explicit failure so it enters dunning.
     */
    public static function requiresAction(string $redirectUrl, array $meta = []): self
    {
        $safe = SafeRedirect::sanitize($redirectUrl);

        if ($safe === null) {
            return self::failed(
                'The bank requires authentication but did not provide a usable link.',
                $meta,
            );
        }

        return new self(
            self::REQUIRES_ACTION,
            redirectUrl: $safe,
            meta: $meta,
        );
    }

    public static function failed(string $message, array $meta = []): self
    {
        return new self(self::FAILED, message: $message, meta: $meta);
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }

    public function needsCustomerAction(): bool
    {
        return $this->status === self::REQUIRES_ACTION;
    }
}
