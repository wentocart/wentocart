<?php

namespace App\Domain\Settings;

/**
 * Idle session lengths in minutes. Laravel session config uses the same unit.
 */
final class SessionLifetimes
{
    public const ADMIN_DEFAULT_MINUTES = 120;

    public const CUSTOMER_DEFAULT_MINUTES = 480;

    public const MIN_MINUTES = 1;

    /** Staff idle time cannot run past the same working day. */
    public const ADMIN_MAX_MINUTES = 720;

    /** Shopper idle time cannot run past a week. */
    public const CUSTOMER_MAX_MINUTES = 10080;

    public static function adminMinutes(): int
    {
        return self::clampMinutes(
            self::read('session.admin_lifetime', self::configMinutes('admin_lifetime', self::ADMIN_DEFAULT_MINUTES)),
            self::ADMIN_MAX_MINUTES,
        );
    }

    public static function customerMinutes(): int
    {
        return self::clampMinutes(
            self::read('session.customer_lifetime', self::configMinutes('customer_lifetime', self::CUSTOMER_DEFAULT_MINUTES)),
            self::CUSTOMER_MAX_MINUTES,
        );
    }

    /**
     * @return array{admin_minutes: int, customer_minutes: int}
     */
    public static function forAdmin(): array
    {
        return [
            'admin_minutes' => self::adminMinutes(),
            'customer_minutes' => self::customerMinutes(),
        ];
    }

    public static function save(array $data): void
    {
        if (array_key_exists('admin_minutes', $data)) {
            ShopSetting::put('session.admin_lifetime', self::clampMinutes((int) $data['admin_minutes'], self::ADMIN_MAX_MINUTES));
        }
        if (array_key_exists('customer_minutes', $data)) {
            ShopSetting::put('session.customer_lifetime', self::clampMinutes((int) $data['customer_minutes'], self::CUSTOMER_MAX_MINUTES));
        }
    }

    protected static function configMinutes(string $key, int $fallback): int
    {
        return (int) config('session.'.$key, $fallback);
    }

    protected static function read(string $key, int $fallback): int
    {
        try {
            $raw = ShopSetting::get($key, $fallback);
        } catch (\Throwable) {
            return $fallback;
        }

        return (int) $raw;
    }

    protected static function clampMinutes(int $minutes, int $max): int
    {
        return max(self::MIN_MINUTES, min($max, $minutes));
    }
}
