<?php

namespace App\Domain\Ops\Abilities;

use App\Domain\Ops\Ability;
use App\Domain\Ops\StaffChangeJournal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class StaffEntityChangeAbility implements Ability
{
    public function code(): string
    {
        return StaffChangeJournal::ABILITY;
    }

    public function label(): string
    {
        return 'Staff change';
    }

    public function permission(): string
    {
        return 'ops.activity';
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
        if (($item['action'] ?? '') !== 'update') {
            return;
        }
        $before = is_array($item['before'] ?? null) ? $item['before'] : [];
        $table = (string) ($before['table'] ?? '');
        $fields = is_array($before['fields'] ?? null) ? $before['fields'] : [];
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
        return null;
    }
}
