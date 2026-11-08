<?php

namespace App\Domain\Settings;

final class PrivacySettings
{
    public static function cookieConsent(): bool
    {
        return ShopSetting::bool('privacy.cookie_consent', true);
    }

    public static function ga4Id(): string
    {
        $id = strtoupper(trim((string) ShopSetting::get('privacy.ga4_id', '')));

        return preg_match('/^G-[A-Z0-9]+$/', $id) ? $id : '';
    }

    public static function gtmId(): string
    {
        $id = strtoupper(trim((string) ShopSetting::get('privacy.gtm_id', '')));

        return preg_match('/^GTM-[A-Z0-9]+$/', $id) ? $id : '';
    }

    public static function metaPixelId(): string
    {
        $id = trim((string) ShopSetting::get('privacy.meta_pixel_id', ''));

        return preg_match('/^[0-9]{5,20}$/', $id) ? $id : '';
    }

    public static function adminMfaRequired(): bool
    {
        return ShopSetting::bool('privacy.admin_mfa', false);
    }

    public static function customerMfaRequired(): bool
    {
        return ShopSetting::bool('privacy.customer_mfa', false);
    }

    public static function abandonedCartMinutes(): int
    {
        $value = (int) ShopSetting::get('privacy.abandoned_cart_minutes', 60);

        return max(15, min(60 * 24 * 14, $value));
    }

    /**
     * @return array<string, mixed>
     */
    public static function storefrontPayload(): array
    {
        return [
            'cookie_consent' => self::cookieConsent(),
            'ga4_id' => self::ga4Id(),
            'gtm_id' => self::gtmId(),
            'meta_pixel_id' => self::metaPixelId(),
            'age_gate' => ShopSetting::bool('privacy.age_gate', false),
            'age_min' => max(13, min(21, (int) ShopSetting::get('privacy.age_min', 18))),
        ];
    }
}
