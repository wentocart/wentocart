<?php

namespace App\Domain\Health;

use App\Domain\Settings\ShopSetting;

final class HealthSettings
{
    public const KEYS = [
        'health.enabled' => true,
        'health.scan_schedule' => 'weekly',
        'health.notify_critical' => true,
        'health.notify_score_drop' => true,
        'health.ai_enabled' => true,
        'health.ai_require_approval' => true,
        'health.ai_tone' => 'professional',
        'health.images_auto_webp' => true,
        'health.images_keep_original' => true,
        'health.images_max_edge' => 2048,
        'health.retention_days' => 90,
    ];

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        $out = [];
        foreach (self::KEYS as $key => $default) {
            $short = str_replace('health.', '', $key);
            $out[$short] = $default;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $out = self::defaults();
        foreach (self::KEYS as $key => $default) {
            $short = str_replace('health.', '', $key);
            if (is_bool($default)) {
                $out[$short] = ShopSetting::bool($key, $default);
            } elseif (is_int($default)) {
                $out[$short] = (int) ShopSetting::get($key, $default);
            } else {
                $out[$short] = (string) ShopSetting::get($key, $default);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function save(array $data): void
    {
        foreach (self::KEYS as $key => $default) {
            $short = str_replace('health.', '', $key);
            if (! array_key_exists($short, $data)) {
                continue;
            }
            $value = $data[$short];
            if (is_bool($default)) {
                ShopSetting::put($key, filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0');
            } else {
                ShopSetting::put($key, (string) $value);
            }
        }
        HealthOverview::flush();
    }
}
