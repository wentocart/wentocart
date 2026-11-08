<?php

namespace App\Domain\Ai;

/**
 * Shared HTTP options for provider calls (Windows local often lacks CA bundle).
 */
final class AiHttp
{
    /**
     * Guzzle/Laravel verify option: true, false, or absolute path to cacert.pem.
     */
    public static function verify(): bool|string
    {
        $configured = config('ai.ssl_verify', true);

        if ($configured === false || $configured === 0 || $configured === '0' || $configured === 'false') {
            return false;
        }

        if (is_string($configured) && ! in_array($configured, ['1', 'true', ''], true) && is_file($configured)) {
            return $configured;
        }

        $bundled = storage_path('app/certs/cacert.pem');
        if (is_file($bundled)) {
            return $bundled;
        }

        $ini = (string) (ini_get('curl.cainfo') ?: ini_get('openssl.cafile') ?: '');
        if ($ini !== '' && is_file($ini)) {
            return $ini;
        }

        return true;
    }

    /**
     * @return array{verify: bool|string, timeout: int}
     */
    public static function options(int $timeout = 60): array
    {
        return [
            'verify' => self::verify(),
            'timeout' => $timeout,
        ];
    }
}
