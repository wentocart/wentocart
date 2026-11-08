<?php

namespace App\Domain\Security\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'store_id',
    'action',
    'actor_type',
    'actor_id',
    'actor_email',
    'ip_address',
    'user_agent',
    'entity_type',
    'entity_id',
    'payload',
])]
class SecurityAuditLog extends Model
{
    public const UPDATED_AT = null; // Immutable append-only log

    protected $table = 'security_audit_logs';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
