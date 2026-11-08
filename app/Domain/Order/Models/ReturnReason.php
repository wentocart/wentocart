<?php

namespace App\Domain\Order\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

#[Fillable(['code', 'name', 'is_enabled', 'position'])]
class ReturnReason extends Model
{
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** @return list<array{id: int, code: string, name: string}> */
    public static function catalog(): array
    {
        if (! Schema::hasTable('return_reasons')) {
            return [];
        }

        return static::query()
            ->where('is_enabled', true)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'code', 'name'])
            ->map(fn (self $row) => [
                'id' => (int) $row->id,
                'code' => $row->code,
                'name' => $row->name,
            ])
            ->all();
    }
}
