<?php

namespace App\Domain\Localization\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['type', 'status', 'filename', 'preview', 'result', 'row_count'])]
class LocalizationImportBatch extends Model
{
    protected function casts(): array
    {
        return [
            'preview' => 'array',
            'result' => 'array',
            'row_count' => 'integer',
        ];
    }
}
