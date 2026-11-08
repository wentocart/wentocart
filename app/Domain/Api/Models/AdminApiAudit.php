<?php

namespace App\Domain\Api\Models;

use Illuminate\Database\Eloquent\Model;

class AdminApiAudit extends Model
{
    public $timestamps = false;

    protected $table = 'admin_api_audits';

    protected $fillable = [
        'admin_id',
        'token_id',
        'method',
        'path',
        'operation',
        'status',
        'ip_address',
        'user_agent',
        'request_payload',
        'response_summary',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'admin_id' => 'integer',
            'token_id' => 'integer',
            'status' => 'integer',
            'request_payload' => 'array',
            'response_summary' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
