<?php

namespace App\Domain\Security;

/**
 * Browser redirect allowlist for checkout / payment / module flows.
 * Blocks open redirects (protocol-relative, javascript:, foreign hosts).
 */
final class SafeRedirect
{
    /**
     * @return list<string>
     */
    public static function allowlistHosts(): array
    {
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $hosts = array_values(array_filter([$appHost !== '' ? $appHost : null]));

        return array_values(array_unique(array_merge($hosts, [
            'paypal.com',
            'paypalobjects.com',
            'razorpay.com',
            'rzp.io',
            'stripe.com',
        ])));
    }

    public static function sanitize(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '' || strlen($url) > 2048) {
            return null;
        }
        if (str_starts_with($url, '//') || (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) && ! preg_match('#^https?:#i', $url))) {
            return null;
        }
        if (str_starts_with($url, '/')) {
            if (str_starts_with($url, '//') || str_contains($url, '\\')) {
                return null;
            }

            return $url;
        }
        if (! preg_match('#^https?://#i', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $host = strtolower(trim((string) $parts['host'], '[]'));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($host === '' || ! self::hostAllowed($host)) {
            return null;
        }

        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $isAppHost = $appHost !== '' && ($host === $appHost || str_ends_with($host, '.'.$appHost));
        // Same-shop redirects may be http in local/tests; PSP redirects must be https.
        if (! $isAppHost && $scheme !== 'https') {
            return null;
        }

        return $url;
    }

    public static function hostAllowed(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        foreach (self::allowlistHosts() as $allowed) {
            $allowed = strtolower($allowed);
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }
}
