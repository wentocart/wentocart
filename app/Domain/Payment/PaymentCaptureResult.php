<?php

namespace App\Domain\Payment;

use App\Domain\Security\SafeRedirect;

class PaymentCaptureResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $redirectUrl = null,
        public readonly array $meta = [],
        public readonly ?string $message = null,
    ) {}

    public static function pending(array $meta = []): self
    {
        return new self(status: 'pending', meta: self::sanitizeMeta($meta));
    }

    public static function paid(array $meta = []): self
    {
        return new self(status: 'paid', meta: self::sanitizeMeta($meta));
    }

    public static function redirect(string $url, array $meta = []): self
    {
        return new self(
            status: 'redirect',
            redirectUrl: SafeRedirect::sanitize($url),
            meta: self::sanitizeMeta($meta),
        );
    }

    public static function failed(string $message, array $meta = []): self
    {
        return new self(status: 'failed', meta: self::sanitizeMeta($meta), message: $message);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            status: (string) ($data['status'] ?? 'pending'),
            redirectUrl: isset($data['redirect_url']) ? SafeRedirect::sanitize((string) $data['redirect_url']) : null,
            meta: self::sanitizeMeta(is_array($data['meta'] ?? null) ? $data['meta'] : []),
            message: isset($data['message']) ? (string) $data['message'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'redirect_url' => $this->redirectUrl ? SafeRedirect::sanitize($this->redirectUrl) : null,
            'message' => $this->message,
            'meta' => $this->meta === [] ? null : self::sanitizeMeta($this->meta),
        ], fn ($v) => $v !== null);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    protected static function sanitizeMeta(array $meta): array
    {
        if (isset($meta['redirect_url'])) {
            $meta['redirect_url'] = SafeRedirect::sanitize((string) $meta['redirect_url']);
            if ($meta['redirect_url'] === null) {
                unset($meta['redirect_url']);
            }
        }

        return $meta;
    }
}
