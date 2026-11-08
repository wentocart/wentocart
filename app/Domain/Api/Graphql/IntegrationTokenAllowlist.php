<?php

namespace App\Domain\Api\Graphql;

use Laravel\Sanctum\PersonalAccessToken;

/**
 */
final class IntegrationTokenAllowlist
{
    public const ABILITY_PREFIX = 'ip:';

    /**
     * @return list<string>
     */
    public static function parse(mixed $raw): array
    {
        if (is_array($raw)) {
            $ips = $raw;
        } else {
            $text = trim((string) $raw);
            if ($text === '') {
                return [];
            }
            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                $ips = $decoded;
            } else {
                $ips = preg_split('/[\s,]+/', $text) ?: [];
            }
        }

        $out = [];
        foreach ($ips as $ip) {
            $ip = trim((string) $ip);
            if ($ip === '') {
                continue;
            }
            if (str_contains($ip, '/')) {
                [$addr, $mask] = array_pad(explode('/', $ip, 2), 2, '');
                $mask = (int) $mask;
                $validV4 = filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && $mask >= 0 && $mask <= 32;
                $validV6 = filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false && $mask >= 0 && $mask <= 128;
                if (! $validV4 && ! $validV6) {
                    continue;
                }
                $out[] = $addr.'/'.$mask;
                continue;
            }
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            $out[] = $ip;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<string>  $abilities
     * @return list<string>
     */
    public static function extract(array $abilities): array
    {
        $ips = [];
        foreach ($abilities as $ability) {
            $ability = (string) $ability;
            if (str_starts_with($ability, self::ABILITY_PREFIX)) {
                $ip = substr($ability, strlen(self::ABILITY_PREFIX));
                // Reuse parse validation for exact IP + CIDR.
                $parsed = self::parse($ip);
                foreach ($parsed as $entry) {
                    $ips[] = $entry;
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * @param  list<string>  $abilities
     * @param  list<string>  $ips
     * @return list<string>
     */
    public static function mergeAbilities(array $abilities, array $ips): array
    {
        $kept = [];
        foreach ($abilities as $ability) {
            $ability = (string) $ability;
            if (str_starts_with($ability, self::ABILITY_PREFIX)) {
                continue;
            }
            $kept[] = $ability;
        }
        if ($kept === []) {
            $kept[] = 'admin:graphql';
        }
        foreach ($ips as $ip) {
            $kept[] = self::ABILITY_PREFIX.$ip;
        }

        return array_values(array_unique($kept));
    }

    public static function allows(PersonalAccessToken $token, ?string $clientIp): bool
    {
        $abilities = is_array($token->abilities) ? $token->abilities : [];
        $allowed = self::extract($abilities);
        if ($allowed === []) {
            return true;
        }
        $clientIp = trim((string) $clientIp);
        if ($clientIp === '') {
            return false;
        }
        if ($clientIp === '127.0.0.1' || $clientIp === '::1') {
            return true;
        }

        foreach ($allowed as $entry) {
            if (self::ipMatches($clientIp, $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Exact IP or CIDR match (Integration allowlist parity).
     */
    public static function ipMatches(string $clientIp, string $rule): bool
    {
        $rule = trim($rule);
        if ($rule === '') {
            return false;
        }
        if (! str_contains($rule, '/')) {
            return $clientIp === $rule;
        }

        [$subnet, $mask] = array_pad(explode('/', $rule, 2), 2, '');
        $mask = (int) $mask;
        if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($clientIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if ($mask < 0 || $mask > 32) {
                return false;
            }
            $clientLong = ip2long($clientIp);
            $subnetLong = ip2long($subnet);
            if ($clientLong === false || $subnetLong === false) {
                return false;
            }
            $maskLong = $mask === 0 ? 0 : (~0 << (32 - $mask));

            return ($clientLong & $maskLong) === ($subnetLong & $maskLong);
        }

        if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && filter_var($clientIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if ($mask < 0 || $mask > 128) {
                return false;
            }
            $clientBin = inet_pton($clientIp);
            $subnetBin = inet_pton($subnet);
            if ($clientBin === false || $subnetBin === false) {
                return false;
            }
            $bytes = intdiv($mask, 8);
            $bits = $mask % 8;
            if ($bytes > 0 && substr($clientBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
                return false;
            }
            if ($bits === 0) {
                return true;
            }
            $clientByte = ord($clientBin[$bytes]);
            $subnetByte = ord($subnetBin[$bytes]);
            $maskByte = (~0 << (8 - $bits)) & 0xFF;

            return ($clientByte & $maskByte) === ($subnetByte & $maskByte);
        }

        return false;
    }
}
