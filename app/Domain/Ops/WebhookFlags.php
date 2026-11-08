<?php

namespace App\Domain\Ops;

use App\Domain\Settings\ShopSetting;

/**
 * Shop-wide on/off for each catalog event. Off = never sent to destinations.
 * Missing key uses the catalog `open` default so a newly added event stays closed
 * until the merchant turns it on (unless `open` is true).
 */
final class WebhookFlags
{
    public const SETTING = 'webhooks.event_flags';

    public static function isEnabled(string $id): bool
    {
        $flags = ShopSetting::json(self::SETTING, []);
        if (array_key_exists($id, $flags)) {
            return (bool) $flags[$id];
        }
        $row = WebhookCatalog::find($id);

        return (bool) ($row['open'] ?? false);
    }

    public static function set(string $id, bool $enabled): void
    {
        $flags = ShopSetting::json(self::SETTING, []);
        $flags[$id] = $enabled;
        ShopSetting::put(self::SETTING, $flags);
        CommerceTopics::flushListeners();
    }

    /**
     * @return array<string, bool>
     */
    public static function map(): array
    {
        $out = [];
        foreach (WebhookCatalog::events() as $row) {
            $out[$row['id']] = self::isEnabled($row['id']);
        }

        return $out;
    }
}
