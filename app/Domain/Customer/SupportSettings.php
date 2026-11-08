<?php

namespace App\Domain\Customer;

use App\Domain\Settings\ShopSetting;
use App\Domain\Store\StoreContext;

final class SupportSettings
{
    public static function notifyEnabled(): bool
    {
        return ShopSetting::bool('support.notify_email', true);
    }

    public static function replyEmailEnabled(): bool
    {
        return ShopSetting::bool('support.reply_email', true);
    }

    /**
     * @return list<string>
     */
    public static function notifyEmails(): array
    {
        $raw = trim((string) ShopSetting::get('support.notify_addresses', ''));
        $emails = self::parseEmails($raw);
        if ($emails !== []) {
            return $emails;
        }

        $store = app(StoreContext::class)->store();
        $fallback = is_string($store?->email) ? trim($store->email) : '';
        if ($fallback !== '' && filter_var($fallback, FILTER_VALIDATE_EMAIL)) {
            return [$fallback];
        }

        return [];
    }

    /**
     * @return array{notify_email: bool, reply_email: bool, notify_addresses: string}
     */
    public static function forAdmin(): array
    {
        return [
            'notify_email' => self::notifyEnabled(),
            'reply_email' => self::replyEmailEnabled(),
            'notify_addresses' => (string) ShopSetting::get('support.notify_addresses', ''),
        ];
    }

    /**
     * @param  array{notify_email?: mixed, reply_email?: mixed, notify_addresses?: mixed}  $data
     */
    public static function save(array $data): void
    {
        ShopSetting::put('support.notify_email', (bool) ($data['notify_email'] ?? false));
        ShopSetting::put('support.reply_email', (bool) ($data['reply_email'] ?? false));
        $emails = self::parseEmails((string) ($data['notify_addresses'] ?? ''));
        ShopSetting::put('support.notify_addresses', implode("\n", $emails));
    }

    /**
     * @return list<string>
     */
    public static function parseEmails(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $email = strtolower(trim($part));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $out[$email] = $email;
            }
        }

        return array_values($out);
    }
}
