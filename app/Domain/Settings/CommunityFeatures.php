<?php

namespace App\Domain\Settings;

final class CommunityFeatures
{
    public static function contactForm(): bool
    {
        return ShopSetting::bool('shop.contact_form', true);
    }

    public static function guestOrderLookup(): bool
    {
        return OrderSettings::guestLookup();
    }

    public static function productVideo(): bool
    {
        return ShopSetting::bool('catalog.product_video', true);
    }

    public static function meilisearch(): bool
    {
        return ShopSetting::bool('search.meilisearch', false);
    }

    public static function smsOtp(): bool
    {
        return ShopSetting::bool('auth.sms_otp', false);
    }

    /**
     * @return array<string, bool|string>
     */
    public static function payload(): array
    {
        return [
            'contact_form' => self::contactForm(),
            'guest_lookup' => self::guestOrderLookup(),
            'product_video' => self::productVideo(),
            'meilisearch' => self::meilisearch(),
            'sms_otp' => self::smsOtp(),
            'sms_webhook' => (string) ShopSetting::get('auth.sms_webhook', ''),
            'sms_driver' => SmsSender::driverKey(),
            'sms_configured' => self::smsConfigured(),
            'sms_twilio_sid' => (string) ShopSetting::get('auth.sms_twilio_sid', ''),
            'sms_twilio_from' => (string) ShopSetting::get('auth.sms_twilio_from', ''),
            'sms_twilio_token_set' => trim((string) ShopSetting::get('auth.sms_twilio_token', '')) !== '',
            'login_email_password' => CustomerSettings::loginEmailPassword(),
            'login_mobile_password' => CustomerSettings::loginMobilePassword(),
            'login_mobile_otp' => CustomerSettings::loginMobileOtp(),
            'otp_expiry_minutes' => CustomerSettings::otpExpiryMinutes(),
            'otp_max_attempts' => CustomerSettings::otpMaxAttempts(),
            'otp_resend_seconds' => CustomerSettings::otpResendSeconds(),
        ];
    }

    public static function smsConfigured(): bool
    {
        $driver = SmsSender::driverKey();
        if ($driver === 'twilio') {
            return trim((string) ShopSetting::get('auth.sms_twilio_sid', '')) !== ''
                && trim((string) ShopSetting::get('auth.sms_twilio_token', '')) !== ''
                && trim((string) ShopSetting::get('auth.sms_twilio_from', '')) !== '';
        }
        if ($driver === 'webhook') {
            return trim((string) ShopSetting::get('auth.sms_webhook', '')) !== '';
        }

        return true;
    }

    public static function videoEmbed(?string $raw): ?string
    {
        if (! self::productVideo()) {
            return null;
        }
        $url = trim((string) $raw);
        if ($url === '') {
            return null;
        }
        if (preg_match('~(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/)([A-Za-z0-9_-]{6,})~i', $url, $match)) {
            return 'https://www.youtube-nocookie.com/embed/'.$match[1];
        }
        if (preg_match('~vimeo\.com/(?:video/)?(\d+)~i', $url, $match)) {
            return 'https://player.vimeo.com/video/'.$match[1];
        }

        return null;
    }
}
