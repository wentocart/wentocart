<?php

namespace App\Domain\Ai;

use App\Domain\Settings\ShopSetting;

/**
 * Floating admin assistant settings (separate from content-field AI).
 */
final class AssistantSettings
{
    public const TABS = ['all', 'dashboard', 'orders', 'customers', 'products'];

    /**
     * @return array<string, mixed>
     */
    public static function forAdmin(): array
    {
        return [
            'assistant_enabled' => self::storedEnabled(),
            'timeout' => self::timeout(),
            'result_limit' => self::resultLimit(),
            'logging' => self::logging(),
            'writes_enabled' => self::writesEnabled(),
            'accessible_tabs' => self::accessibleTabs(),
            'fallback_message' => self::fallbackMessage(),
            'icon_type' => self::iconType(),
            'icon_text' => self::iconText(),
        ];
    }

    /**
     * Shared to every admin page for the floating widget.
     *
     * @return array<string, mixed>
     */
    public static function forInertia(): array
    {
        if (! self::enabled()) {
            return ['assistant_enabled' => false];
        }

        return [
            'assistant_enabled' => true,
            'configured' => AiSettings::enabled() && AiSettings::activeConnection() !== null,
            'accessible_tabs' => self::accessibleTabs(),
            'fallback_message' => self::fallbackMessage(),
            'icon_type' => self::iconType(),
            'icon_text' => self::iconText(),
            'writes_enabled' => self::writesEnabled(),
            'result_limit' => self::resultLimit(),
        ];
    }

    public static function storedEnabled(): bool
    {
        return ShopSetting::bool('ai.assistant_enabled', false);
    }

    public static function enabled(): bool
    {
        return AiSettings::enabled() && self::storedEnabled();
    }

    public static function timeout(): int
    {
        return max(10, min(120, (int) ShopSetting::get('ai.assistant_timeout', 45)));
    }

    public static function resultLimit(): int
    {
        return max(1, min(100, (int) ShopSetting::get('ai.assistant_result_limit', 10)));
    }

    public static function logging(): bool
    {
        return ShopSetting::bool('ai.assistant_logging', false);
    }

    public static function writesEnabled(): bool
    {
        return ShopSetting::bool('ai.assistant_writes', false);
    }

    /** @return list<string> */
    public static function accessibleTabs(): array
    {
        $tabs = ShopSetting::json('ai.assistant_tabs', ['all']);
        if (! is_array($tabs)) {
            return ['all'];
        }

        return array_values(array_intersect(self::TABS, array_map('strval', $tabs)));
    }

    public static function fallbackMessage(): string
    {
        $msg = trim((string) ShopSetting::get(
            'ai.assistant_fallback',
            'Sorry, I could not find that information in your shop data. Please try rephrasing.'
        ));

        return $msg !== '' ? $msg : 'Sorry, I could not find that information in your shop data. Please try rephrasing.';
    }

    public static function iconType(): string
    {
        $type = strtolower(trim((string) ShopSetting::get('ai.assistant_icon_type', 'text')));

        return in_array($type, ['text', 'icon'], true) ? $type : 'text';
    }

    public static function iconText(): string
    {
        $text = trim((string) ShopSetting::get('ai.assistant_icon_text', 'Ask AI'));

        return $text !== '' ? $text : 'Ask AI';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function save(array $data): void
    {
        if (array_key_exists('assistant_enabled', $data)) {
            ShopSetting::put('ai.assistant_enabled', (bool) $data['assistant_enabled']);
        }
        if (array_key_exists('timeout', $data)) {
            ShopSetting::put('ai.assistant_timeout', max(10, min(120, (int) $data['timeout'])));
        }
        if (array_key_exists('result_limit', $data)) {
            ShopSetting::put('ai.assistant_result_limit', max(1, min(100, (int) $data['result_limit'])));
        }
        if (array_key_exists('logging', $data)) {
            ShopSetting::put('ai.assistant_logging', (bool) $data['logging']);
        }
        if (array_key_exists('writes_enabled', $data)) {
            ShopSetting::put('ai.assistant_writes', (bool) $data['writes_enabled']);
        }
        if (array_key_exists('accessible_tabs', $data) && is_array($data['accessible_tabs'])) {
            $tabs = array_values(array_intersect(self::TABS, array_map('strval', $data['accessible_tabs'])));
            ShopSetting::put('ai.assistant_tabs', $tabs);
        }
        if (array_key_exists('fallback_message', $data)) {
            ShopSetting::put('ai.assistant_fallback', mb_substr(trim((string) $data['fallback_message']), 0, 500));
        }
        if (array_key_exists('icon_type', $data)) {
            $type = strtolower(trim((string) $data['icon_type']));
            ShopSetting::put('ai.assistant_icon_type', in_array($type, ['text', 'icon'], true) ? $type : 'text');
        }
        if (array_key_exists('icon_text', $data)) {
            ShopSetting::put('ai.assistant_icon_text', mb_substr(trim((string) $data['icon_text']), 0, 40));
        }
    }
}
