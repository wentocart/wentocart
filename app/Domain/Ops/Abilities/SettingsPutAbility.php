<?php

namespace App\Domain\Ops\Abilities;

use App\Domain\Ops\Ability;
use App\Domain\Settings\ShopSetting;
use InvalidArgumentException;

final class SettingsPutAbility implements Ability
{
    public function code(): string
    {
        return 'settings.put';
    }

    public function label(): string
    {
        return 'Update shop setting';
    }

    public function permission(): string
    {
        return 'settings.store';
    }

    public function plan(array $input): array
    {
        $key = trim((string) ($input['key'] ?? ''));
        if ($key === '' || strlen($key) > 120) {
            throw new InvalidArgumentException('A setting key is required.');
        }
        $before = ShopSetting::get($key, null);

        return [[
            'entity_type' => 'setting',
            'entity_id' => 0,
            'action' => 'update',
            'label' => $key,
            'before' => ['key' => $key, 'value' => $before],
            'after' => ['key' => $key, 'value' => $input['value'] ?? null],
            'reversible' => true,
        ]];
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
