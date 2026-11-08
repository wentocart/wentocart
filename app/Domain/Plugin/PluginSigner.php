<?php

namespace App\Domain\Plugin;

final class PluginSigner
{
    public static function payload(PluginManifest $manifest): string
    {
        $caps = $manifest->capabilities;
        sort($caps);

        return implode("\n", [
            $manifest->vendor,
            $manifest->name,
            $manifest->version,
            $manifest->type,
            implode(',', $caps),
        ]);
    }

    public static function sign(PluginManifest $manifest, ?string $key = null): string
    {
        return hash_hmac('sha256', self::payload($manifest), $key ?? (string) config('app.key'));
    }

    public static function verify(PluginManifest $manifest, ?string $key = null): bool
    {
        $got = trim((string) ($manifest->signature ?? ''));
        if ($got === '') {
            return false;
        }
        $got = preg_replace('/^hmac-sha256:/i', '', $got) ?? $got;

        return hash_equals(self::sign($manifest, $key), $got);
    }
}
