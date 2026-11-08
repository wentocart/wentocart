<?php

namespace App\Domain\Settings\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'code', 'action', 'state', 'file_name', 'csv_content',
    'processed_rows_count', 'invalid_rows_count', 'errors_count',
    'errors_json', 'summary_json',
])]
class DataTransferImport extends Model
{
    public const STATES = [
        'pending',
        'validated',
        'processing',
        'linked',
        'indexed',
        'completed',
        'cancelled',
        'failed',
    ];

    protected function casts(): array
    {
        return [
            'processed_rows_count' => 'integer',
            'invalid_rows_count' => 'integer',
            'errors_count' => 'integer',
            'errors_json' => 'array',
            'summary_json' => 'array',
        ];
    }
}
