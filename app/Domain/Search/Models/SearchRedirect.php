<?php

namespace App\Domain\Search\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

#[Fillable(['query', 'target_path', 'is_enabled'])]
class SearchRedirect extends Model
{
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    public static function targetFor(string $q): ?string
    {
        if ($q === '' || ! Schema::hasTable('search_redirects')) {
            return null;
        }

        $row = static::query()
            ->where('is_enabled', true)
            ->whereRaw('LOWER(query) = ?', [mb_strtolower(trim($q))])
            ->first();

        return $row?->target_path;
    }
}
