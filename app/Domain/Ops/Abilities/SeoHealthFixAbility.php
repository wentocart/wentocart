<?php

namespace App\Domain\Ops\Abilities;

use App\Domain\Ops\Ability;
use App\Domain\Settings\ShopSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class SeoHealthFixAbility implements Ability
{
    public function code(): string
    {
        return 'seo.health.fix';
    }

    public function label(): string
    {
        return 'Store health fix';
    }

    public function permission(): string
    {
        return 'ops.health';
    }

    public function plan(array $input): array
    {
        return [];
    }

    public function execute(array $item, array $input): array
    {
        return $item['after'] ?? [];
    }

    public function rollback(array $item): void
    {
        $before = is_array($item['before'] ?? null) ? $item['before'] : [];
        $after = is_array($item['after'] ?? null) ? $item['after'] : [];
        $table = (string) ($before['table'] ?? $after['table'] ?? '');
        $fields = is_array($before['fields'] ?? null) ? $before['fields'] : [];
        if ($table === 'settings') {
            $key = (string) ($fields['key'] ?? '');
            if ($key !== '') {
                ShopSetting::put($key, $fields['value'] ?? null);
            }

            return;
        }
        if ($table === 'storage') {
            $created = is_array($after['fields']['created'] ?? null) ? $after['fields']['created'] : [];
            foreach ($created as $path) {
                if (is_string($path) && $path !== '') {
                    Storage::disk('public')->delete($path);
                }
            }

            return;
        }
        $id = (int) ($before['id'] ?? $item['entity_id'] ?? 0);
        if ($table === '' || $id < 1 || $fields === [] || ! Schema::hasTable($table)) {
            return;
        }
        $safe = [];
        foreach ($fields as $column => $value) {
            if (is_string($column) && Schema::hasColumn($table, $column)) {
                $safe[$column] = $value;
            }
        }
        if ($safe !== []) {
            DB::table($table)->where('id', $id)->update($safe);
        }
    }

    public function eventName(): ?string
    {
        return 'seo.health.fixed';
    }
}
