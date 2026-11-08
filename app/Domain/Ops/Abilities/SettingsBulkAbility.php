<?php

namespace App\Domain\Ops\Abilities;

use App\Domain\Ops\Ability;
use App\Domain\Settings\ShopSetting;
use InvalidArgumentException;

final class SettingsBulkAbility implements Ability
{
    public function code(): string
    {
        return 'settings.bulk';
    }

    public function label(): string
    {
        return 'Update shop settings';
    }

    public function permission(): string
    {
        return 'settings.store';
    }

    public function plan(array $input): array
    {
        $values = is_array($input['values'] ?? null) ? $input['values'] : [];
        if ($values === []) {
            throw new InvalidArgumentException('No settings to change.');
        }

        $items = [];
        foreach ($values as $key => $value) {
            $key = trim((string) $key);
            if ($key === '' || strlen($key) > 120) {
                continue;
            }
            $before = ShopSetting::get($key, null);
            $items[] = [
                'entity_type' => 'setting',
                'entity_id' => 0,
                'action' => 'update',
                'label' => $key,
                'before' => ['key' => $key, 'value' => $before],
                'after' => ['key' => $key, 'value' => $value],
                'reversible' => true,
            ];
        }
        if ($items === []) {
            throw new InvalidArgumentException('No valid setting keys.');
        }

        return $items;
    }

    public function execute(array $item, array $input): array
    {
        $after = $item['after'] ?? [];
        ShopSetting::put((string) $after['key'], $after['value'] ?? null);

        return $after;
    }

    public function rollback(array $item): void
    {
        $before = $item['before'] ?? [];
        ShopSetting::put((string) $before['key'], $before['value'] ?? null);
    }

    public function eventName(): ?string
    {
        return 'settings.updated';
    }
}
