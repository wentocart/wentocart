<?php

namespace App\Domain\Security;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Outbound URL guard against SSRF (localhost, RFC1918, link-local, metadata).
 */
final class SafeUrl
{
    /**
     * @throws InvalidArgumentException
     */
    public static function assertPublicHttp(string $url, bool $httpsOnly = true): void
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048) {
            throw new InvalidArgumentException('URL is not allowed.');
        }
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new InvalidArgumentException('URL is not allowed.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('URL is not allowed.');
        }
        $scheme = strtolower((string) $parts['scheme']);
        if ($httpsOnly && $scheme !== 'https') {
            throw new InvalidArgumentException('Only https URLs are allowed.');
        }
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('URL is not allowed.');
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('URL is not allowed.');
        }
        $host = strtolower(trim((string) $parts['host'], '[]'));
        if ($host === '' || str_contains($host, '..') || str_contains($host, '%') || str_contains($host, '@')) {
            throw new InvalidArgumentException('URL is not allowed.');
        }
        if (preg_match('/^[0-9]+$/', $host) || preg_match('/^0x[0-9a-f.]+$/i', $host)) {
            throw new InvalidArgumentException('URL host is not allowed.');
        }
        if (in_array($host, ['localhost', 'localhost.localdomain', 'metadata.google.internal', 'metadata'], true)) {
            throw new InvalidArgumentException('URL is not allowed.');
        }
        if (str_ends_with($host, '.localhost') || str_ends_with($host, '.internal') || str_ends_with($host, '.local')) {
            throw new InvalidArgumentException('URL is not allowed.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (self::isBlockedIp($host)) {
                throw new InvalidArgumentException('URL host is not allowed.');
            }

            return;
        }

        if (self::runningTests()) {
            return;
        }

        $ips = self::resolve($host);
        if ($ips === []) {
            throw new InvalidArgumentException('URL host could not be resolved.');
        }
        foreach ($ips as $ip) {
            if (self::isBlockedIp($ip)) {
                throw new InvalidArgumentException('URL host is not allowed.');
            }
        }
    }

    /**
     * HTTP client options that disable redirects and pin DNS to a public IP (anti-rebinding).
     *
     * @return array<string, mixed>
     */
    public static function outboundOptions(string $url, bool $httpsOnly = true): array
    {
        self::assertPublicHttp($url, $httpsOnly);
        $options = ['allow_redirects' => false];
        if (self::runningTests()) {
            return $options;
        }
        $parts = parse_url($url);
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            return $options;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ips = self::resolve($host);
        if ($ips === []) {
            throw new InvalidArgumentException('URL host could not be resolved.');
        }
        foreach ($ips as $ip) {
            if (self::isBlockedIp($ip)) {
                throw new InvalidArgumentException('URL host is not allowed.');
            }
        }
        $options['curl'] = [CURLOPT_RESOLVE => [$host.':'.$port.':'.$ips[0]]];

        return $options;
    }

    public static function get(string $url, int $timeout = 8, bool $httpsOnly = false): Response
    {
        return Http::timeout($timeout)->withOptions(self::outboundOptions($url, $httpsOnly))->get($url);
    }

    public static function isSafe(string $url, bool $httpsOnly = true): bool
    {
        try {
            self::assertPublicHttp($url, $httpsOnly);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    protected static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $records = @dns_get_record($host, DNS_A + DNS_AAAA) ?: [];
        $ips = [];
        foreach ($records as $row) {
            if (! empty($row['ip'])) {
                $ips[] = (string) $row['ip'];
            }
            if (! empty($row['ipv6'])) {
                $ips[] = (string) $row['ipv6'];
            }
        }
        if ($ips === []) {
            $fallback = @gethostbynamel($host) ?: [];
            $ips = array_map('strval', $fallback);
        }

        return array_values(array_unique($ips));
    }

    public static function isBlockedIp(string $ip): bool
    {
        $ip = trim($ip);
        if (str_starts_with(strtolower($ip), '::ffff:')) {
            return self::isBlockedIp(substr($ip, 7));
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long === false) {
                return true;
            }
            $ranges = [
                ['0.0.0.0', '0.255.255.255'],
                ['10.0.0.0', '10.255.255.255'],
                ['127.0.0.0', '127.255.255.255'],
                ['169.254.0.0', '169.254.255.255'],
                ['172.16.0.0', '172.31.255.255'],
                ['192.168.0.0', '192.168.255.255'],
                ['100.64.0.0', '100.127.255.255'],
                ['192.0.0.0', '192.0.0.255'],
                ['192.0.2.0', '192.0.2.255'],
                ['198.18.0.0', '198.19.255.255'],
                ['198.51.100.0', '198.51.100.255'],
                ['203.0.113.0', '203.0.113.255'],
                ['224.0.0.0', '255.255.255.255'],
            ];
            foreach ($ranges as [$from, $to]) {
                $start = ip2long($from);
                $end = ip2long($to);
                if ($start !== false && $end !== false && $long >= $start && $long <= $end) {
                    return true;
                }
            }

            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = @inet_pton($ip);
            if ($packed === false) {
                return true;
            }
            $bin = unpack('n*', $packed);
            $first = (int) ($bin[1] ?? 0);
            if ($packed === str_repeat("\0", 16) || $ip === '::1') {
                return true;
            }
            if (substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
                $mapped = @inet_ntop(substr($packed, 12));

                return $mapped === false || self::isBlockedIp($mapped);
            }
            if ($first === 0x64 && (int) ($bin[2] ?? 0) === 0xff9b) {
                $mapped = @inet_ntop(substr($packed, 12));

                return $mapped === false || self::isBlockedIp((string) $mapped);
            }
            if ($first === 0x2001 && (int) ($bin[2] ?? 0) === 0x0db8) {
                return true;
            }
            if (($first & 0xfe00) === 0xfc00) {
                return true;
            }
            if (($first & 0xffc0) === 0xfe80) {
                return true;
            }
            if (($first & 0xff00) === 0xff00) {
                return true;
            }

            return false;
        }

        return true;
    }

    protected static function runningTests(): bool
    {
        try {
            return function_exists('app') && app()->runningUnitTests();
        } catch (\Throwable) {
            return false;
        }
    }
}
