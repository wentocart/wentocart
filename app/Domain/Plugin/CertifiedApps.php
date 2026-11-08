<?php

namespace App\Domain\Plugin;

final class CertifiedApps
{
    /**
     * Community extensions a merchant can install after the scanner.
     * schema.tables and unsigned “everything certified” still need an explicit flag or shop signature.
     */
    public static function communityUnsignedAllowed(PluginManifest $manifest): bool
    {
        if ($manifest->type !== 'certified') {
            return false;
        }
        $caps = $manifest->capabilities;
        if (in_array(Capabilities::SCHEMA_TABLES, $caps, true)) {
            return false;
        }

        $allowed = array_merge(Capabilities::SAFE, [
            Capabilities::PAYMENT_DRIVER,
            Capabilities::SHIPPING_DRIVER,
            Capabilities::HTTP_OUTBOUND,
            Capabilities::ORDERS_READ,
            // Needed for on-checkout Stripe.js / Elements + CSP allowlist (e.g. weblytic/wtsstripe).
            Capabilities::CHECKOUT_PAYMENT_UI,
            Capabilities::SECURITY_CSP,
        ]);

        return array_diff($caps, $allowed) === [];
    }

    public static function shopSignedAllowed(PluginManifest $manifest): bool
    {
        return $manifest->type === 'certified' && PluginSigner::verify($manifest);
    }

    public static function mayInstall(PluginManifest $manifest): bool
    {
        return $manifest->type === 'safe'
            || (bool) config('wento.apps.allow_certified_unsigned', false)
            || (bool) config('wento.apps.allow_unsigned', false)
            || self::communityUnsignedAllowed($manifest)
            || self::shopSignedAllowed($manifest);
    }
}
