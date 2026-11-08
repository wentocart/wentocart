<?php

namespace App\Domain\Search\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['term', 'aliases', 'is_enabled'])]
class SearchSynonym extends Model
{
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    public function aliasList(): array
    {
        return array_values(array_filter(array_map(
            fn (string $part) => mb_strtolower(trim($part)),
            preg_split('/[,;]+/', (string) $this->aliases) ?: [],
        )));
    }
}
