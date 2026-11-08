<?php

namespace App\Domain\Ops;

/**
 * Stripe-style signatures: header `X-Wento-Signature: t=<unix>,v1=<hmac>`.
 * HMAC is SHA-256 of `{t}.{raw_json_body}` using the endpoint secret.
 */
final class WebhookSigner
{
    public const HEADER = 'X-Wento-Signature';

    public const TOLERANCE_SECONDS = 300;

    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    public static function header(string $secret, string $body, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't='.$timestamp.',v1='.self::sign($secret, $timestamp, $body);
    }

    public static function verify(string $secret, string $header, string $body, int $tolerance = self::TOLERANCE_SECONDS, ?int $now = null): bool
    {
        $now ??= time();
        $parts = self::parse($header);
        if ($parts === null) {
            return false;
        }
        if (abs($now - $parts['t']) > $tolerance) {
            return false;
        }
        $expected = self::sign($secret, $parts['t'], $body);

        return hash_equals($expected, $parts['v1']);
    }

    /**
     * @return array{t: int, v1: string}|null
     */
    public static function parse(string $header): ?array
    {
        $t = null;
        $v1 = null;
        foreach (explode(',', $header) as $piece) {
            $piece = trim($piece);
            if (str_starts_with($piece, 't=')) {
                $t = (int) substr($piece, 2);
            }
            if (str_starts_with($piece, 'v1=')) {
                $v1 = substr($piece, 3);
            }
        }
        if (! $t || $v1 === null || $v1 === '' || ! preg_match('/^[a-f0-9]{64}$/', $v1)) {
            return null;
        }

        return ['t' => $t, 'v1' => $v1];
    }
}
